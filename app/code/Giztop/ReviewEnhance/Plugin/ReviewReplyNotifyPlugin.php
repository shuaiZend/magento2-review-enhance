<?php
/**
 * GIZTOP Review Enhance
 *
 * Bridges review save to ReplyNotifier. Any failure is logged (review id +
 * exception class) and swallowed so review saving can never break.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Review\Model\Review;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Model\ReplyNotifier;

/**
 * Triggers the reply notification after a review save.
 */
class ReviewReplyNotifyPlugin
{
    /**
     * @var ReplyNotifier
     */
    private ReplyNotifier $notifier;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ReplyNotifier $notifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        ReplyNotifier $notifier,
        LoggerInterface $logger
    ) {
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    /**
     * After save(): attempt the reply notification for the saved review.
     *
     * @param Review $subject
     * @param Review $result
     * @return Review
     */
    public function afterSave(Review $subject, Review $result): Review
    {
        try {
            $this->notifier->notify($subject);
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_reply_notify_plugin: review_id=' . (int)$subject->getId()
                . ' notify skipped, ' . get_class($e)
            );
        }

        return $result;
    }
}
