<?php
/**
 * GIZTOP Review Enhance
 *
 * Audit log for review webhook delivery attempts (giztop_review_webhook_log).
 *
 * One row per event_id: the first attempt inserts, every retry updates the same
 * row through INSERT ... ON DUPLICATE KEY UPDATE, which is why event_id carries
 * a UNIQUE key in etc/db_schema.xml.
 *
 * Writing the log is strictly best-effort: a failing INSERT must never break
 * review submission or the queue consumer, so every call is wrapped.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Best-effort writer for the webhook audit table.
 */
class WebhookLog
{
    public const TABLE_NAME = 'giztop_review_webhook_log';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** Hard cap matching the varchar(255) column. */
    public const MAX_ERROR_LENGTH = 255;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(ResourceConnection $resourceConnection, LoggerInterface $logger)
    {
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * Insert or update the audit row for one event.
     *
     * @param string $eventId
     * @param int $reviewId
     * @param int $attempt
     * @param string $status self::STATUS_SENT|self::STATUS_FAILED
     * @param int|null $httpCode
     * @param string|null $error already-safe message (will be sanitized again)
     * @return void
     */
    public function record(
        string $eventId,
        int $reviewId,
        int $attempt,
        string $status,
        ?int $httpCode = null,
        ?string $error = null
    ): void {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE_NAME);

            $connection->insertOnDuplicate(
                $table,
                [
                    'event_id' => mb_substr($eventId, 0, 32, 'UTF-8'),
                    'review_id' => max(0, $reviewId),
                    'delivery_attempt' => max(0, $attempt),
                    'status' => $status === self::STATUS_SENT ? self::STATUS_SENT : self::STATUS_FAILED,
                    'http_code' => $httpCode !== null && $httpCode > 0 ? $httpCode : null,
                    'error_message' => $error === null ? null : $this->sanitize($error),
                ],
                ['review_id', 'delivery_attempt', 'status', 'http_code', 'error_message']
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_webhook_log: write skipped for event ' . $eventId . ', ' . get_class($e)
            );
        }
    }

    /**
     * Strip anything that looks like an email address and clamp the length.
     *
     * @param string $message
     * @return string
     */
    private function sanitize(string $message): string
    {
        $message = (string)preg_replace('/\S+@\S+/', '[email]', $message);
        $message = (string)preg_replace('/\s+/', ' ', $message);

        return mb_substr(trim($message), 0, self::MAX_ERROR_LENGTH, 'UTF-8');
    }
}