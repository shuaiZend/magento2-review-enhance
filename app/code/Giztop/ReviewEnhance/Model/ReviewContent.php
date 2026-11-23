<?php
/**
 * GIZTOP Review Enhance
 *
 * Splits review_detail.detail into buyer question and store answer.
 * Legacy convention inside the detail column:
 *
 *     {buyer content}
 *     ----------------   (a line of 3+ hyphens, real data: 15-18)
 *     Re: {answer}
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model;

/**
 * Review content splitter.
 */
class ReviewContent
{
    /**
     * Separator line: a whole line consisting of 3+ hyphens (whitespace tolerated).
     */
    public const SEPARATOR_PATTERN = '/^\s*-{3,}\s*$/mu';

    /**
     * Leading "Re:" / "Re :" / "RE:" marker on the answer part.
     */
    public const REPLY_PREFIX_PATTERN = '/^\s*re\s*:\s*/i';

    /**
     * Split the raw review detail into question and answer.
     *
     * - The question keeps its original newlines (template handles escaping),
     *   only trimmed.
     * - The answer has the leading "Re:" marker removed and is trimmed;
     *   an empty answer part is normalized to null (no empty bubble).
     * - No separator found => answer is null.
     *
     * @param string $detail
     * @return array ['question' => string, 'answer' => string|null]
     */
    public function split(string $detail): array
    {
        $parts = preg_split(self::SEPARATOR_PATTERN, $detail, 2);

        if (!is_array($parts) || count($parts) < 2) {
            return [
                'question' => trim($detail),
                'answer' => null,
            ];
        }

        $question = trim($parts[0]);
        $answer = trim((string)preg_replace(self::REPLY_PREFIX_PATTERN, '', $parts[1]));

        return [
            'question' => $question,
            'answer' => $answer !== '' ? $answer : null,
        ];
    }
}
