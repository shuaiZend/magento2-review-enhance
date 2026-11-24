<?php
/**
 * GIZTOP Review Enhance
 *
 * Admin source model for the webhook channel.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Delivery channel for the review webhook.
 */
class WebhookChannel implements OptionSourceInterface
{
    /** Raw JSON payload signed with X-Giztop-Signature. */
    public const GENERIC = 'generic';

    /** Feishu / Lark custom bot: interactive card plus timestamp/sign. */
    public const FEISHU = 'feishu';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::GENERIC, 'label' => __('Generic JSON (X-Giztop-Signature)')],
            ['value' => self::FEISHU, 'label' => __('Feishu / Lark custom bot (interactive card)')],
        ];
    }
}