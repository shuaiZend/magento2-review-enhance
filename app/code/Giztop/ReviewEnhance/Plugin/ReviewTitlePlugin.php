<?php
/**
 * GIZTOP Review Enhance
 *
 * Server-side guarantee of a non-empty review title.
 * The frontend removes the "Summary" field, but
 * Magento\Review\Model\Review::validate() requires a non-empty title
 * ("Please enter a review summary."). This plugin derives the title from
 * the review body on every validation/save path.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Review\Model\Review;

/**
 * Fills an empty review title from the review body.
 */
class ReviewTitlePlugin
{
    /**
     * Maximum title length in characters (does not cut words in half).
     */
    public const MAX_TITLE_LENGTH = 60;

    /**
     * Fallback title when the body yields nothing usable.
     */
    public const FALLBACK_TITLE = 'Customer review';

    /**
     * Before validate(): fill an empty title from the detail body.
     *
     * @param Review $subject
     * @return array
     */
    public function beforeValidate(Review $subject): array
    {
        $this->ensureTitle($subject);

        return [];
    }

    /**
     * Before save(): safety net for write paths that bypass validate().
     * Never overwrites an existing non-empty title.
     *
     * @param Review $subject
     * @return array
     */
    public function beforeSave(Review $subject): array
    {
        $this->ensureTitle($subject);

        return [];
    }

    /**
     * @param Review $subject
     * @return void
     */
    private function ensureTitle(Review $subject): void
    {
        if (trim((string)$subject->getTitle()) !== '') {
            return;
        }

        $title = $this->deriveTitle((string)$subject->getDetail());
        if ($title !== '') {
            $subject->setTitle($title);
        }
    }

    /**
     * Derive a title from the raw review body:
     * strip HTML, collapse whitespace, cap at 60 characters without cutting
     * a word in half (a single word longer than the cap is hard-cut).
     * Empty input yields the fallback title.
     *
     * @param string $detail
     * @return string
     */
    private function deriveTitle(string $detail): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($detail)));

        if ($text === '') {
            return self::FALLBACK_TITLE;
        }

        if (mb_strlen($text, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_TITLE_LENGTH, 'UTF-8');

        // Do not cut a word in half: back up to the last space inside the cap.
        $spacePos = mb_strrpos($cut, ' ');
        if ($spacePos !== false && $spacePos > 0) {
            $cut = trim(mb_substr($cut, 0, $spacePos, 'UTF-8'));
        }

        return $cut !== '' ? $cut : self::FALLBACK_TITLE;
    }
}
