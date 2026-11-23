<?php
/**
 * GIZTOP Review Enhance
 *
 * Source model for the Turnstile widget theme.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Turnstile theme options (Cloudflare supported values only).
 */
class TurnstileTheme implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Auto (match the page)')],
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'dark', 'label' => __('Dark')],
        ];
    }
}
