<?php
/**
 * GIZTOP Review Enhance
 *
 * Minimal, dependency-free HTTPS client for review webhook delivery.
 *
 * Deliberately not using Magento\Framework\HTTP\Client\Curl: the raw cURL
 * handle gives us explicit control over redirects, connect vs total timeout
 * and TLS verification, which is what the security contract of this feature
 * needs. No composer package is involved.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Webhook;

use Giztop\ReviewEnhance\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Signed JSON POST client for the review webhook.
 */
class HttpClient
{
    /** Fixed connect timeout (seconds); the total timeout comes from config. */
    public const CONNECT_TIMEOUT = 3;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var UrlGuard
     */
    private UrlGuard $urlGuard;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param UrlGuard $urlGuard
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, UrlGuard $urlGuard, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->urlGuard = $urlGuard;
        $this->logger = $logger;
    }

    /**
     * Deliver one signed JSON payload.
     *
     * Never throws: transport problems are reported through the return value so
     * the caller can decide between retry and parking on the error queue.
     *
     * @param string $url
     * @param string $rawBody already JSON-encoded payload (signed verbatim)
     * @param string $eventName e.g. "giztop.review.submitted"
     * @param string $eventId uuid4, stable across retries
     * @return array{ok: bool, http_code: int, error: string}
     */
    public function post(string $url, string $rawBody, string $eventName, string $eventId): array
    {
        try {
            $this->urlGuard->assertAllowed($url, $this->config->isPrivateEndpointAllowed());
        } catch (LocalizedException $e) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'guard: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'guard: ' . get_class($e)];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'curl extension is not available'];
        }

        $secret = $this->config->getWebhookSecret();
        $timestamp = (string)time();
        $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Giztop-Event: ' . $eventName,
            'X-Giztop-Event-Id: ' . $eventId,
            'X-Giztop-Timestamp: ' . $timestamp,
            'X-Giztop-Signature: ' . $signature,
            'User-Agent: ' . $this->config->getWebhookUserAgent(),
        ];

        $handle = curl_init();
        if ($handle === false) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'curl_init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $this->config->getWebhookTimeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $responseBody = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        // No curl_close(): PHP 8.5 deprecates it (a no-op since PHP 8.0) and this
        // Magento error handler escalates deprecations to exceptions, which used
        // to turn every Turnstile POST into a 500. The handle is freed when the
        // variable goes out of scope.

        if ($errorNumber !== 0) {
            // cURL messages never contain our payload, but truncate anyway.
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'curl(' . $errorNumber . '): ' . mb_substr((string)$errorMessage, 0, 120, 'UTF-8'),
            ];
        }

        $ok = $httpCode >= 200 && $httpCode < 300;

        if (!$ok) {
            $snippet = is_string($responseBody)
                ? mb_substr(preg_replace('/\s+/', ' ', $responseBody) ?? '', 0, 120, 'UTF-8')
                : '';

            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'http ' . $httpCode . ($snippet !== '' ? ': ' . $snippet : ''),
            ];
        }

        return ['ok' => true, 'http_code' => $httpCode, 'error' => ''];
    }
}