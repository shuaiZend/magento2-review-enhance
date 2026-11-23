<?php
/**
 * GIZTOP Review Enhance
 *
 * Source model for the moderation whitelist priority select.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Whitelist priority options for system.xml.
 */
class WhitelistPriority implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'domain_only',
                'label' => __('Domain only — whitelisted domains skip the blacklist check'),
            ],
            [
                'value' => 'full_bypass',
                'label' => __('Full bypass — any whitelisted domain lets the whole review through'),
            ],
        ];
    }
}
