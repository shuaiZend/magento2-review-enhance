<?php
/**
 * GIZTOP Review Enhance
 *
 * Password-style admin field used for the encrypted secrets (Turnstile secret
 * key, webhook signing secret).
 *
 * Rendering rules:
 *  - stored value present -> render "******" (six asterisks)
 *  - stored value empty    -> render an empty password input
 *
 * The asterisk placeholder is not cosmetic: Magento\Config\Model\Config\Backend\
 * Encrypted::beforeSave() skips writing when the submitted value matches
 * /^\*+$/, so an administrator who only toggles another field cannot wipe the
 * stored secret. Clearing the field and saving does erase it.
 *
 * The plaintext secret is never placed in the HTML.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Frontend;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Obscured (password) input for encrypted configuration values.
 */
class Obscure extends Field
{
    /** Placeholder that makes Backend\Encrypted keep the stored value. */
    public const MASK = '******';

    /**
     * Render a password input instead of exposing the stored secret.
     *
     * @param AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $stored = (string)$element->getValue();
        $display = $stored === '' ? '' : self::MASK;

        $htmlId = (string)$element->getHtmlId();
        $name = (string)$element->getName();

        $html = '<input type="password"'
            . ' id="' . $this->escapeHtmlAttr($htmlId) . '"'
            . ' name="' . $this->escapeHtmlAttr($name) . '"'
            . ' value="' . $this->escapeHtmlAttr($display) . '"'
            . ' class="input-text admin__control-text"'
            . ' autocomplete="new-password"'
            . ' spellcheck="false"';

        if ($element->getDisabled()) {
            $html .= ' disabled="disabled"';
        }

        $html .= '/>';

        if ($display !== '') {
            $html .= '<p class="note"><span>'
                . $this->escapeHtml(
                    __('A value is stored. Leave the asterisks untouched to keep it, or clear the field to remove it.')
                )
                . '</span></p>';
        }

        return $html;
    }
}