<?php
/**
 * GIZTOP Review Enhance
 *
 * SSRF guard for the review webhook endpoint.
 *
 * The webhook URL is administrator-controlled, which makes it a textbook SSRF
 * vector: a compromised or careless admin account could point it at
 * http://127.0.0.1:8080/admin or the cloud metadata service. This guard is
 * applied twice:
 *  - on save (Model\Config\Backend\WebhookEndpoint), to fail fast with a
 *    readable message while the administrator is still on the page;
 *  - on every send (Model\Webhook\HttpClient), because DNS can change between
 *    saving the config and delivering a message (DNS rebinding).
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * URL / IP allow-list checks for outbound webhook delivery.
 */
class UrlGuard
{
    /**
     * IPv4 ranges that must never be reachable from the webhook client.
     *
     * Each entry is [network, prefix length].
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const BLOCKED_IPV4_RANGES = [
        ['0.0.0.0', 8],       // "this" network
        ['10.0.0.0', 8],      // RFC 1918
        ['100.64.0.0', 10],   // RFC 6598 CGNAT
        ['127.0.0.0', 8],     // loopback
        ['169.254.0.0', 16],  // link-local / cloud metadata (169.254.169.254)
        ['172.16.0.0', 12],   // RFC 1918
        ['192.0.0.0', 24],    // IETF protocol assignments
        ['192.0.2.0', 24],    // TEST-NET-1
        ['192.168.0.0', 16],  // RFC 1918
        ['198.18.0.0', 15],   // benchmarking
        ['198.51.100.0', 24], // TEST-NET-2
        ['203.0.113.0', 24],  // TEST-NET-3
        ['224.0.0.0', 4],     // multicast
        ['240.0.0.0', 4],     // reserved + broadcast
    ];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Validate a webhook URL, throwing a readable exception when it is unsafe.
     *
     * @param string $url
     * @param bool $allowPrivate test-only escape hatch (see Config::isPrivateEndpointAllowed)
     * @return void
     * @throws LocalizedException
     */
    public function assertAllowed(string $url, bool $allowPrivate = false): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new LocalizedException(__('The webhook endpoint URL is empty.'));
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            throw new LocalizedException(
                __('The webhook endpoint must use https:// (got "%1").', $scheme === '' ? 'none' : $scheme)
            );
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new LocalizedException(__('The webhook endpoint URL has no host.'));
        }

        // A literal IP host needs no DNS resolution.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpAllowed($host, $allowPrivate);
            return;
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            // Unresolvable now: do not hard-fail the save, the send-time check
            // is authoritative and will reject if it resolves to a private IP.
            $this->logger->warning(
                'reviewenhance_webhook_guard: host "' . $host . '" did not resolve at validation time'
            );
            return;
        }

        foreach ($ips as $ip) {
            $this->assertIpAllowed($ip, $allowPrivate);
        }
    }

    /**
     * Resolve a hostname to all A/AAAA addresses.
     *
     * @param string $host
     * @return array<int, string>
     */
    public function resolveHostIps(string $host): array
    {
        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        $unique = [];
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $unique[$ip] = true;
            }
        }

        return array_keys($unique);
    }

    /**
     * Whether an IP is private, loopback, link-local or otherwise reserved.
     *
     * @param string $ip
     * @return bool
     */
    public function isBlockedIp(string $ip): bool
    {
        $ip = trim($ip, "[] \t\n\r\0\x0B");

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isBlockedIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->isBlockedIpv6($ip);
        }

        // Not an IP at all: treat as blocked (defensive).
        return true;
    }

    /**
     * Check one IP, logging (but not throwing) when the escape hatch is used.
     *
     * @param string $ip
     * @param bool $allowPrivate
     * @return void
     * @throws LocalizedException
     */
    private function assertIpAllowed(string $ip, bool $allowPrivate): void
    {
        if (!$this->isBlockedIp($ip)) {
            return;
        }

        if ($allowPrivate) {
            $this->logger->warning(
                'reviewenhance_webhook_guard: PRIVATE endpoint allowed by giztop_review/webhook/'
                . 'allow_private_endpoint=1 for ip ' . $this->maskIp($ip)
                . ' - test environments only, production must keep it disabled'
            );
            return;
        }

        throw new LocalizedException(
            __(
                'The webhook endpoint resolves to a private or reserved address (%1) and was refused. '
                . 'Use a public https endpoint.',
                $this->maskIp($ip)
            )
        );
    }

    /**
     * IPv4 range check.
     *
     * @param string $ip
     * @return bool
     */
    private function isBlockedIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        foreach (self::BLOCKED_IPV4_RANGES as $range) {
            [$network, $prefix] = $range;
            $networkLong = ip2long($network);
            if ($networkLong === false) {
                continue;
            }
            $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
            if (($long & $mask) === ($networkLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv6 range check (loopback, unique-local, link-local, v4-mapped).
     *
     * @param string $ip
     * @return bool
     */
    private function isBlockedIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        // ::1 loopback and :: unspecified.
        if ($packed === inet_pton('::1') || $packed === inet_pton('::')) {
            return true;
        }

        // ::ffff:a.b.c.d — evaluate the embedded IPv4 address.
        $mappedPrefix = inet_pton('::ffff:0:0');
        if ($mappedPrefix !== false && strncmp($packed, (string)$mappedPrefix, 10) === 0) {
            $embedded = substr($packed, 12, 4);
            $ipv4 = inet_ntop($embedded);
            if (is_string($ipv4) && $this->isBlockedIpv4($ipv4)) {
                return true;
            }
        }

        $firstByte = ord($packed[0]);

        // fc00::/7 unique local.
        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        // fe80::/10 link local.
        if ($firstByte === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return true;
        }

        return false;
    }

    /**
     * Mask an IP for logging: keep the first octet only.
     *
     * @param string $ip
     * @return string
     */
    private function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return ($parts[0] ?? '0') . '.***.***.***';
        }

        return substr($ip, 0, 4) . ':***';
    }
}