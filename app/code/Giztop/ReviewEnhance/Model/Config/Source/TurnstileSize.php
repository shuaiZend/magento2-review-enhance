<?php
/**
 * GIZTOP Review Enhance
 *
 * Source model for the Turnstile widget size.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Turnstile size options (Cloudflare supported values only).
 */
class TurnstileSize implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'flexible', 'label' => __('Flexible (100% width)')],
            ['value' => 'normal', 'label' => __('Normal (300x65)')],
            ['value' => 'compact', 'label' => __('Compact (150x140)')],
        ];
    }
}
