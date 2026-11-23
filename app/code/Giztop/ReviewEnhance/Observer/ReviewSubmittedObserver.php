<?php
/**
 * GIZTOP Review Enhance
 *
 * Publishes a review.submitted event to RabbitMQ once the review transaction
 * has committed.
 *
 * Event choice: `review_save_commit_after` is dispatched by
 * Magento\Framework\Model\AbstractModel::afterCommitCallback(), which
 * AbstractDb::save() registers as a commit callback. Listening here instead of
 * `review_save_after` guarantees we never announce a review that was rolled
 * back afterwards (no ghost events).
 *
 * Everything is wrapped: a queue outage, a bad payload or a missing config must
 * never turn a review submission into a 500.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Observer;

use Giztop\ReviewEnhance\Model\Config;
use Giztop\ReviewEnhance\Model\Webhook\PayloadBuilder;
use Giztop\ReviewEnhance\Plugin\ReviewWebhookFlagPlugin;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Review\Model\Review;
use Psr\Log\LoggerInterface;

/**
 * Publishes review.submitted after the review transaction commits.
 */
class ReviewSubmittedObserver implements ObserverInterface
{
    /** Topic declared in etc/communication.xml and etc/queue_publisher.xml. */
    public const TOPIC = 'giztop.review.submitted';

    /** Areas in which a customer-facing submission can happen. */
    private const ALLOWED_AREAS = [Area::AREA_FRONTEND, Area::AREA_GRAPHQL];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var PayloadBuilder
     */
    private PayloadBuilder $payloadBuilder;

    /**
     * @var PublisherInterface
     */
    private PublisherInterface $publisher;

    /**
     * @var AppState
     */
    private AppState $appState;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param PayloadBuilder $payloadBuilder
     * @param PublisherInterface $publisher
     * @param AppState $appState
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        PayloadBuilder $payloadBuilder,
        PublisherInterface $publisher,
        AppState $appState,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->payloadBuilder = $payloadBuilder;
        $this->publisher = $publisher;
        $this->appState = $appState;
        $this->logger = $logger;
    }

    /**
     * Publish the webhook event for a newly created review.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $review = $this->extractReview($observer);
            if (!$review instanceof Review) {
                return;
            }

            if (!$this->isAllowedArea()) {
                return;
            }

            // Admin edits, mass status updates and re-saves are not "submitted".
            if (!$review->getData(ReviewWebhookFlagPlugin::FLAG_NEW_REVIEW)) {
                return;
            }

            if (!$this->config->isWebhookEnabled()) {
                return;
            }

            $reviewId = (int)$review->getId();
            if ($reviewId <= 0) {
                return;
            }

            $payload = $this->payloadBuilder->build($review, 0);
            $body = $this->payloadBuilder->encode($payload);

            $this->publisher->publish(self::TOPIC, $body);

            $this->logger->info(
                'reviewenhance_webhook: published ' . self::TOPIC
                . ' event_id=' . (string)$payload['event_id'] . ' review_id=' . $reviewId
            );
        } catch (\Throwable $e) {
            // A webhook problem must never surface to the customer.
            $this->logger->error(
                'reviewenhance_webhook: publish skipped, ' . get_class($e) . ': '
                . mb_substr($e->getMessage(), 0, 160, 'UTF-8')
            );
        }
    }

    /**
     * Pull the review model out of the event payload.
     *
     * `_getEventData()` provides both `data_object` and `object`; different core
     * versions populate one or the other first, so both are checked.
     *
     * @param Observer $observer
     * @return Review|null
     */
    private function extractReview(Observer $observer): ?Review
    {
        $event = $observer->getEvent();

        $candidate = $event->getData('data_object') ?? $event->getData('object');
        if ($candidate instanceof Review) {
            return $candidate;
        }

        return null;
    }

    /**
     * Only customer-facing submissions are announced.
     *
     * @return bool
     */
    private function isAllowedArea(): bool
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Throwable $e) {
            // Area not set (CLI / setup): not a customer submission.
            return false;
        }

        return in_array($area, self::ALLOWED_AREAS, true);
    }
}