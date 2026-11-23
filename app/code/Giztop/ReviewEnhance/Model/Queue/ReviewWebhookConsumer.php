<?php
/**
 * GIZTOP Review Enhance
 *
 * Consumer for the giztop.review.webhook queue.
 *
 * Retry model (Magento has none built in): when the handler throws, the
 * framework consumer rejects the message without requeueing, i.e. the event is
 * lost silently. To get at-least-once delivery with backoff we therefore
 * re-publish the *same* payload (same event_id, so the receiver can deduplicate
 * on it) with an incremented delivery_attempt, and park the payload on the
 * error queue once max_attempts is exhausted.
 *
 * The method never throws: an unhandled exception would drop the message.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Queue;

use Giztop\ReviewEnhance\Model\Config;
use Giztop\ReviewEnhance\Model\Webhook\HttpClient;
use Giztop\ReviewEnhance\Model\Webhook\PayloadBuilder;
use Giztop\ReviewEnhance\Model\Webhook\WebhookLog;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivers review webhook payloads with exponential backoff.
 */
class ReviewWebhookConsumer
{
    /** Same topic the observer publishes to (re-published for retries). */
    public const TOPIC = 'giztop.review.submitted';

    /** Dead-letter topic declared in etc/communication.xml. */
    public const ERROR_TOPIC = 'giztop.review.webhook.error';

    /** Event name sent in the X-Giztop-Event header. */
    public const EVENT_HEADER_NAME = 'review.submitted';

    /** Upper bound of the backoff delay in seconds. */
    public const MAX_BACKOFF_SECONDS = 30;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var HttpClient
     */
    private HttpClient $httpClient;

    /**
     * @var PayloadBuilder
     */
    private PayloadBuilder $payloadBuilder;

    /**
     * @var WebhookLog
     */
    private WebhookLog $webhookLog;

    /**
     * @var PublisherInterface
     */
    private PublisherInterface $publisher;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param HttpClient $httpClient
     * @param PayloadBuilder $payloadBuilder
     * @param WebhookLog $webhookLog
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Config $config,
        HttpClient $httpClient,
        PayloadBuilder $payloadBuilder,
        WebhookLog $webhookLog,
        PublisherInterface $publisher,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->payloadBuilder = $payloadBuilder;
        $this->webhookLog = $webhookLog;
        $this->publisher = $publisher;
        $this->logger = $logger;
    }

    /**
     * Consume one message.
     *
     * @param mixed $message JSON string (Magento encodes the topic payload)
     * @return void
     */
    public function process($message): void
    {
        $payload = $this->normalize($message);
        if ($payload === null) {
            // Unparseable message: acknowledge and log, retrying cannot help.
            $this->logger->error('reviewenhance_webhook_consumer: dropping unparseable message');

            return;
        }

        $eventId = isset($payload['event_id']) && is_string($payload['event_id']) ? $payload['event_id'] : '';
        $reviewId = isset($payload['review']['id']) ? (int)$payload['review']['id'] : 0;
        $attempt = isset($payload['delivery_attempt']) ? max(0, (int)$payload['delivery_attempt']) : 0;

        if ($eventId === '') {
            $this->logger->error('reviewenhance_webhook_consumer: message without event_id, review_id=' . $reviewId);

            return;
        }

        try {
            if (!$this->config->isWebhookEnabled()) {
                $this->logger->info(
                    'reviewenhance_webhook_consumer: webhook disabled, skipping event ' . $eventId
                );

                return;
            }

            $endpoint = $this->config->getWebhookEndpoint();
            if ($endpoint === '') {
                $this->logger->warning(
                    'reviewenhance_webhook_consumer: no endpoint configured, skipping event ' . $eventId
                );

                return;
            }

            $body = $this->payloadBuilder->encode($payload);
            $result = $this->httpClient->post($endpoint, $body, self::EVENT_HEADER_NAME, $eventId);

            if ($result['ok']) {
                $this->webhookLog->record(
                    $eventId,
                    $reviewId,
                    $attempt,
                    WebhookLog::STATUS_SENT,
                    $result['http_code'],
                    null
                );

                return;
            }

            $this->handleFailure($payload, $eventId, $reviewId, $attempt, $result);
        } catch (\Throwable $e) {
            // Absolute last resort: keep the consumer alive.
            $this->logger->error(
                'reviewenhance_webhook_consumer: unexpected error for event ' . $eventId
                . ', ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 160, 'UTF-8')
            );
        }
    }

    /**
     * Record the failure, then either schedule a retry or park the payload.
     *
     * @param array<string, mixed> $payload
     * @param string $eventId
     * @param int $reviewId
     * @param int $attempt
     * @param array{ok: bool, http_code: int, error: string} $result
     * @return void
     */
    private function handleFailure(
        array $payload,
        string $eventId,
        int $reviewId,
        int $attempt,
        array $result
    ): void {
        $this->webhookLog->record(
            $eventId,
            $reviewId,
            $attempt,
            WebhookLog::STATUS_FAILED,
            $result['http_code'],
            $result['error']
        );

        $nextAttempt = $attempt + 1;
        $maxAttempts = $this->config->getWebhookMaxAttempts();

        if ($nextAttempt >= $maxAttempts) {
            $this->park($payload, $eventId, $reviewId, $nextAttempt, $result['error']);

            return;
        }

        $delay = (int)min(2 ** $nextAttempt, self::MAX_BACKOFF_SECONDS);
        $this->logger->warning(
            'reviewenhance_webhook_consumer: delivery failed for event ' . $eventId
            . ' (attempt ' . $attempt . ', http ' . $result['http_code'] . '), retrying in ' . $delay . 's'
        );

        // Backoff before re-publishing so the receiver is not hammered.
        if ($delay > 0) {
            sleep($delay);
        }

        try {
            $payload['delivery_attempt'] = $nextAttempt;
            $this->publisher->publish(self::TOPIC, $this->payloadBuilder->encode($payload));
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_webhook_consumer: could not re-publish event ' . $eventId
                . ', ' . get_class($e)
            );
        }
    }

    /**
     * Move an exhausted payload to the dead-letter queue.
     *
     * @param array<string, mixed> $payload
     * @param string $eventId
     * @param int $reviewId
     * @param int $attempts
     * @param string $lastError
     * @return void
     */
    private function park(
        array $payload,
        string $eventId,
        int $reviewId,
        int $attempts,
        string $lastError
    ): void {
        $this->logger->error(
            'reviewenhance_webhook_consumer: giving up on event ' . $eventId . ' after ' . $attempts
            . ' attempts, moving to ' . self::ERROR_TOPIC
        );

        $envelope = [
            'payload' => $payload,
            'attempts' => $attempts,
            'last_error' => mb_substr($lastError, 0, 200, 'UTF-8'),
            'parked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        try {
            $this->publisher->publish(self::ERROR_TOPIC, $this->payloadBuilder->encode($envelope));
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_webhook_consumer: could not park event ' . $eventId . ', ' . get_class($e)
            );
        }
    }

    /**
     * Decode whatever the transport handed us into a payload array.
     *
     * Handles the three shapes Magento can produce depending on version and
     * transport: a JSON string, an already-decoded array, or an envelope object
     * exposing getBody().
     *
     * @param mixed $message
     * @return array<string, mixed>|null
     */
    private function normalize($message): ?array
    {
        if (is_array($message)) {
            return $message;
        }

        if (is_object($message) && method_exists($message, 'getBody')) {
            try {
                $message = $message->getBody();
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        $decoded = json_decode($message, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Tolerate one extra encoding layer (publisher encodes the topic payload
        // as a JSON string, so the body can be a JSON-encoded JSON string).
        if (is_string($decoded)) {
            $again = json_decode($decoded, true);
            if (is_array($again)) {
                return $again;
            }
        }

        return null;
    }
}