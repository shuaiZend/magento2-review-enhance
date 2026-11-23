<?php
/**
 * GIZTOP Review Enhance
 *
 * Remembers, at save time, whether the review being saved is a brand new row.
 *
 * Why this exists: the webhook observer runs on `review_save_commit_after`,
 * i.e. after the transaction commits. By then the model has been through
 * ResourceModel\Db\AbstractDb::save() and its `isObjectNew()` flag is not a
 * reliable indicator any more (it is reset inside saveNewObject() whenever
 * `_useIsObjectNew` is enabled, and future core versions may change that).
 * Capturing the value in beforeSave() makes the decision deterministic and
 * independent of core internals.
 *
 * Runs last (sortOrder 50) so it sees the final state produced by the title /
 * email / moderation / nickname plugins.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Review\Model\Review;

/**
 * Flags new reviews for the commit-after webhook observer.
 */
class ReviewWebhookFlagPlugin
{
    /** Transient model flag consumed by Observer\ReviewSubmittedObserver. */
    public const FLAG_NEW_REVIEW = 'giztop_is_new_review';

    /**
     * Before save(): snapshot isObjectNew() into a transient data flag.
     *
     * @param Review $subject
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(Review $subject): array
    {
        try {
            $subject->setData(self::FLAG_NEW_REVIEW, $subject->isObjectNew());
        } catch (\Throwable $e) {
            // Never break a save because of the flag itself.
            $subject->setData(self::FLAG_NEW_REVIEW, false);
        }

        return [];
    }
}