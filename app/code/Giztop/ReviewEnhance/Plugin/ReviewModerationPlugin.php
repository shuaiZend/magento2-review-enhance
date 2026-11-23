<?php
/**
 * GIZTOP Review Enhance
 *
 * Blocks review submissions whose text hits the moderation blacklists.
 * Admin-area saves are never blocked. Moderation failures degrade to
 * "allowed" (logged) instead of breaking submission with a 500.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\Review;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Model\Moderation;

/**
 * Review text moderation on validate().
 */
class ReviewModerationPlugin
{
    /**
     * @var State
     */
    private State $appState;

    /**
     * @var Moderation
     */
    private Moderation $moderation;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param State $appState
     * @param Moderation $moderation
     * @param LoggerInterface $logger
     */
    public function __construct(
        State $appState,
        Moderation $moderation,
        LoggerInterface $logger
    ) {
        $this->appState = $appState;
        $this->moderation = $moderation;
        $this->logger = $logger;
    }

    /**
     * Save-time guard. The GraphQL createProductReview mutation saves the model
     * without ever calling validate(), so moderation has to run here as well.
     * Admin-area saves stay exempt.
     *
     * @param Review $subject
     * @return null
     * @throws LocalizedException
     */
    public function beforeSave(Review $subject)
    {
        try {
            try {
                if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                    return null;
                }
            } catch (\Throwable $e) {
                // Area not set (CLI / setup): fall through and enforce.
            }

            if (!$this->moderation->isEnabled()) {
                return null;
            }

            $detail = (string)$subject->getDetail();
            if (trim($detail) === '') {
                return null;
            }

            $error = $this->moderation->check($detail);
            if ($error !== null) {
                throw $this->guardException(__($error));
            }
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('reviewenhance_moderation_guard: ' . get_class($e));
        }

        return null;
    }

    /**
     * GraphQL clients only receive messages from ClientAware exceptions; a plain
     * LocalizedException is flattened to "Internal server error".
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
     * After validate(): when the base validation passed, reject the review
     * when the detail text matches the moderation rules.
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

        // Admins editing reviews are never blocked.
        try {
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                return $result;
            }
        } catch (\Throwable $e) {
            // Area code not set (e.g. CLI) — treat as frontend and continue.
        }

        try {
            $rejection = $this->moderation->check((string)$subject->getDetail());
            if ($rejection !== null) {
                return [$rejection];
            }
        } catch (\Throwable $e) {
            // Moderation must never turn a submission into a 500: fail open,
            // log the exception class only (no user content, no emails).
            $this->logger->error(
                'reviewenhance_moderation: check skipped, ' . get_class($e)
            );
        }

        return $result;
    }
}
