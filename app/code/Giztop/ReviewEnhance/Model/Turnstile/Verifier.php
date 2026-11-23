<?php
/**
 * GIZTOP Review Enhance
 *
 * Server-side verification of a Cloudflare Turnstile token.
 *
 * Contract:
 *  - POST https://challenges.cloudflare.com/turnstile/v0/siteverify
 *  - body: secret, response, remoteip
 *  - accept only when success === true, hostname matches the expected host and
 *    action === "review_submit"
 *  - a transport failure (DNS, TLS, timeout, 5xx) is handled according to
 *    giztop_review/turnstile/fail_mode; an explicit Cloudflare rejection is
 *    always a rejection.
 *
 * Logging never contains the token, the secret or the full IP.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Turnstile;

use Giztop\ReviewEnhance\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Turnstile siteverify client.
 */
class Verifier
{
    /** Cloudflare siteverify endpoint (fixed host, never user controlled). */
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Fixed timeouts required by the design. */
    public const CONNECT_TIMEOUT = 3;
    public const TOTAL_TIMEOUT = 5;

    /** Outcome reasons. */
    public const REASON_OK = 'ok';
    public const REASON_EMPTY_TOKEN = 'empty_token';
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_REJECTED = 'rejected';
    public const REASON_HOSTNAME_MISMATCH = 'hostname_mismatch';
    public const REASON_ACTION_MISMATCH = 'action_mismatch';
    public const REASON_TRANSPORT = 'transport_error';
    public const REASON_TRANSPORT_FAIL_OPEN = 'transport_error_fail_open';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Verify one token.
     *
     * @param string $token value of the cf-turnstile-response POST field
     * @param string $remoteIp client IP (may be '')
     * @param string $expectedHostname hostname the token must have been issued for
     * @return array{ok: bool, reason: string, hostname: string, error_codes: array<int, string>}
     */
    public function verify(string $token, string $remoteIp, string $expectedHostname): array
    {
        $token = trim($token);
        if ($token === '') {
            return $this->result(false, self::REASON_EMPTY_TOKEN);
        }

        $secret = $this->config->getTurnstileSecret();
        if ($secret === '') {
            // Should not happen: Config::isTurnstileEnabled() requires a secret.
            return $this->result(false, self::REASON_NOT_CONFIGURED);
        }

        $response = $this->request($secret, $token, $remoteIp);
        if ($response === null) {
            return $this->handleTransportFailure($expectedHostname);
        }

        $success = ($response['success'] ?? false) === true;
        $hostname = isset($response['hostname']) && is_string($response['hostname'])
            ? strtolower($response['hostname'])
            : '';
        $action = isset($response['action']) && is_string($response['action']) ? $response['action'] : '';
        $errorCodes = $this->normalizeErrorCodes($response['error-codes'] ?? []);

        if (!$success) {
            $this->logger->warning(
                'reviewenhance_turnstile: rejected, error-codes=' . ($errorCodes === [] ? 'none' : implode(',', $errorCodes))
                . ', hostname=' . ($hostname === '' ? 'n/a' : $hostname)
                . ', ip=' . $this->maskIp($remoteIp)
            );

            return $this->result(false, self::REASON_REJECTED, $hostname, $errorCodes);
        }

        if ($expectedHostname !== '' && $hostname !== '' && $hostname !== strtolower($expectedHostname)) {
            $this->logger->warning(
                'reviewenhance_turnstile: hostname mismatch, expected=' . $expectedHostname
                . ', got=' . $hostname . ', ip=' . $this->maskIp($remoteIp)
            );

            return $this->result(false, self::REASON_HOSTNAME_MISMATCH, $hostname, $errorCodes);
        }

        if ($action !== '' && $action !== Config::TURNSTILE_ACTION) {
            $this->logger->warning(
                'reviewenhance_turnstile: action mismatch, expected=' . Config::TURNSTILE_ACTION
                . ', got=' . $action . ', ip=' . $this->maskIp($remoteIp)
            );

            return $this->result(false, self::REASON_ACTION_MISMATCH, $hostname, $errorCodes);
        }

        return $this->result(true, self::REASON_OK, $hostname, $errorCodes);
    }

    /**
     * Call siteverify. Returns null on any transport-level failure.
     *
     * @param string $secret
     * @param string $token
     * @param string $remoteIp
     * @return array<string, mixed>|null
     */
    private function request(string $secret, string $token, string $remoteIp)
    {
        if (!function_exists('curl_init')) {
            $this->logger->error('reviewenhance_turnstile: curl extension missing, cannot verify');

            return null;
        }

        $fields = [
            'secret' => $secret,
            'response' => $token,
        ];
        if ($remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $handle = curl_init();
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => self::SITEVERIFY_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&'),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $body = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        // No curl_close(): PHP 8.5 deprecates it (a no-op since PHP 8.0) and this
        // Magento error handler escalates deprecations to exceptions, which used
        // to turn every Turnstile POST into a 500. The handle is freed when the
        // variable goes out of scope.

        if ($errorNumber !== 0) {
            // Never log the token or the secret: only the cURL error class.
            $this->logger->warning(
                'reviewenhance_turnstile: transport failure curl(' . $errorNumber . ') '
                . mb_substr((string)$errorMessage, 0, 80, 'UTF-8')
            );

            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300 || !is_string($body) || $body === '') {
            $this->logger->warning('reviewenhance_turnstile: unexpected http status ' . $httpCode);

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->logger->warning('reviewenhance_turnstile: response is not valid JSON');

            return null;
        }

        return $decoded;
    }

    /**
     * Apply fail_mode to a transport failure.
     *
     * @param string $expectedHostname
     * @return array{ok: bool, reason: string, hostname: string, error_codes: array<int, string>}
     */
    private function handleTransportFailure(string $expectedHostname): array
    {
        if ($this->config->isTurnstileFailOpen()) {
            $this->logger->warning(
                'reviewenhance_turnstile: siteverify unreachable, fail_mode=open -> allowing submission'
            );

            return $this->result(true, self::REASON_TRANSPORT_FAIL_OPEN);
        }

        $this->logger->error(
            'reviewenhance_turnstile: siteverify unreachable, fail_mode=closed -> rejecting submission'
        );

        return $this->result(false, self::REASON_TRANSPORT);
    }

    /**
     * Normalise Cloudflare's error-codes array to a list of short strings.
     *
     * @param mixed $codes
     * @return array<int, string>
     */
    private function normalizeErrorCodes($codes): array
    {
        if (!is_array($codes)) {
            return [];
        }

        $result = [];
        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $result[] = mb_substr($code, 0, 64, 'UTF-8');
            }
        }

        return $result;
    }

    /**
     * Build the result array.
     *
     * @param bool $ok
     * @param string $reason
     * @param string $hostname
     * @param array<int, string> $errorCodes
     * @return array{ok: bool, reason: string, hostname: string, error_codes: array<int, string>}
     */
    private function result(bool $ok, string $reason, string $hostname = '', array $errorCodes = []): array
    {
        return [
            'ok' => $ok,
            'reason' => $reason,
            'hostname' => $hostname,
            'error_codes' => $errorCodes,
        ];
    }

    /**
     * Mask an IP for logging: keep the first octet only.
     *
     * @param string $ip
     * @return string
     */
    private function maskIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 'n/a';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return ($parts[0] ?? '0') . '.***.***.***';
        }

        return substr($ip, 0, 4) . ':***';
    }
}