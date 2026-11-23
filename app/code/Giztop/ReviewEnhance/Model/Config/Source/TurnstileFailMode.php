<?php
/**
 * GIZTOP Review Enhance
 *
 * Source model for the Turnstile transport-failure policy.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Turnstile fail-mode options.
 */
class TurnstileFailMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'open',
                'label' => __('Open - allow the review when Cloudflare cannot be reached (availability first)'),
            ],
            [
                'value' => 'closed',
                'label' => __('Closed - reject the review when Cloudflare cannot be reached (security first)'),
            ],
        ];
    }
}
