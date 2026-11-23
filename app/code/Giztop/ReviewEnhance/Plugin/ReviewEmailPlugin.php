<?php
/**
 * GIZTOP Review Enhance
 *
 * Nickname-or-email validation on review submission (phase 2).
 *
 * The nickname field is required and accepts either:
 *  - an email address (the reviewer avatar then comes from Gravatar), or
 *  - a plain display name.
 *
 * Empty values are left to the core validate() ("Please enter a nickname.").
 * Legacy rows are untouched: this runs only on submission validate().
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\Review;
use Giztop\ReviewEnhance\Helper\Email as EmailHelper;
use Psr\Log\LoggerInterface;

/**
 * Validates the review nickname field (email or display name).
 */
class ReviewEmailPlugin
{
    /**
     * Hard length cap. review_detail.nickname is varchar(128) and MySQL runs in
     * STRICT_TRANS_TABLES, so anything above 128 would raise SQLSTATE[22001]
     * (HTTP 500) instead of a friendly validation error.
     */
    public const MAX_NICKNAME_LENGTH = 128;

    /**
     * Review body cap. Keeps the moderation regexes far away from the PCRE JIT
     * stack limit (a 20k+ character body used to abort domain extraction).
     */
    public const MAX_DETAIL_LENGTH = 8192;

    private const EMAIL_ERROR = 'Please enter a valid email address.';
    private const NAME_ERROR = 'Please enter a valid name or email address.';
    private const DETAIL_ERROR = 'Your review is too long. Please keep it under 8000 characters.';

    /**
     * Control characters and angle brackets have no place in a display name.
     */
    private const NAME_FORBIDDEN_CHARS_PATTERN = '/[\x00-\x1F\x7F<>]/';

    /**
     * @var EmailHelper
     */
    private EmailHelper $emailHelper;

    /**
     * @var AppState
     */
    private AppState $appState;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EmailHelper $emailHelper
     */
    public function __construct(
        EmailHelper $emailHelper,
        AppState $appState,
        LoggerInterface $logger
    ) {
        $this->emailHelper = $emailHelper;
        $this->appState = $appState;
        $this->logger = $logger;
    }

    /**
     * Save-time guard: covers entry points that never call validate(), most
     * notably the GraphQL createProductReview mutation. Admin edits are exempt.
     *
     * @param Review $subject
     * @return null
     * @throws LocalizedException
     */
    public function beforeSave(Review $subject)
    {
        try {
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                return null;
            }

            $errors = $this->afterValidate($subject, true);
            if (is_array($errors) && $errors !== []) {
                $phrase = array_shift($errors);
                $phrase = $phrase instanceof \Magento\Framework\Phrase ? $phrase : __((string)$phrase);
                throw $this->guardException($phrase);
            }
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Never break the save because of the guard itself.
            $this->logger->error('reviewenhance_email_guard: ' . get_class($e));
        }

        return null;
    }

    /**
     * GraphQL clients only receive messages from ClientAware exceptions.
     *
     * @param \Magento\Framework\Phrase $phrase
     * @return LocalizedException
     */
    private function guardException(\Magento\Framework\Phrase $phrase): LocalizedException
    {
        try {
            if ($this->appState->getAreaCode() === Area::AREA_GRAPHQL) {
                return new \Magento\Framework\GraphQl\Exception\GraphQlInputException($phrase);
            }
        } catch (\Throwable $e) {
            // fall through to the generic exception
        }

        return new LocalizedException($phrase);
    }

    /**
     * After validate(): when the base validation passed, additionally enforce
     * the nickname-or-email contract:
     *
     *  - empty            -> accepted here (core validate() already rejected it)
     *  - length > 128     -> rejected (DB column width protection)
     *  - contains '@'     -> must be a syntactically valid email address
     *  - no '@'           -> display name; no control chars / '<' '>' / URLs
     *
     * @param Review $subject
     * @param bool|array $result
     * @return bool|array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterValidate(Review $subject, $result)
    {
        if ($result !== true) {
            return $result;
        }

        // Body length cap: protects the moderation regexes and the DB TEXT column.
        if (mb_strlen((string)$subject->getDetail(), 'UTF-8') > self::MAX_DETAIL_LENGTH) {
            return [__(self::DETAIL_ERROR)];
        }

        $nickname = trim((string)$subject->getNickname());
        // Empty nickname is already rejected by the base validate()
        // ("Please enter a nickname."); nothing to add here.
        if ($nickname === '') {
            return $result;
        }

        // DB column width protection (also covers valid emails).
        if (mb_strlen($nickname, 'UTF-8') > self::MAX_NICKNAME_LENGTH) {
            return [__(self::NAME_ERROR)];
        }

        // Anything containing '@' must be a real, valid email address.
        if (str_contains($nickname, '@')) {
            return $this->emailHelper->isEmail($nickname)
                ? $result
                : [__(self::EMAIL_ERROR)];
        }

        // Plain display name path: reject control characters / angle brackets.
        if (preg_match(self::NAME_FORBIDDEN_CHARS_PATTERN, $nickname) === 1) {
            return [__(self::NAME_ERROR)];
        }

        // Plain display name path: reject links ("://") and "www." prefixes.
        if (str_contains($nickname, '://') || str_starts_with(mb_strtolower($nickname, 'UTF-8'), 'www.')) {
            return [__(self::NAME_ERROR)];
        }

        return $result;
    }
}
