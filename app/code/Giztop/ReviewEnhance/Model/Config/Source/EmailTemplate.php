<?php
/**
 * GIZTOP Review Enhance
 *
 * Source model for the reply-notification email template.
 *
 * Why not Magento\Config\Model\Config\Source\Email\Template:
 * that source lists templates for the *current area* and calls
 * Config::getTemplateLabel() on the stored value. Our template is declared
 * with area="frontend" (it is sent from the frontend area), so in adminhtml
 * the lookup throws UnexpectedTemplateIdValueException and the whole
 * configuration section renders empty.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Returns the module's own reply notification template.
 */
class EmailTemplate implements OptionSourceInterface
{
    /**
     * Template id declared in etc/email_templates.xml.
     */
    public const DEFAULT_TEMPLATE = 'giztop_review_reply_template';

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::DEFAULT_TEMPLATE,
                'label' => __('Giztop Review Reply Notification'),
            ],
        ];
    }
}
