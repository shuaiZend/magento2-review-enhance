<?php
/**
 * GIZTOP Review Enhance
 *
 * Decorates the admin review edit form:
 *  - relabels "Nickname" to "Name / Email" with an explanatory note,
 *  - shows the same-origin avatar preview for email nicknames (no inline JS,
 *    admin CSP safe).
 *
 * Mounted on Magento\Review\Block\Adminhtml\Edit\Form — a plugin on
 * Magento\Framework\Data\Form::afterGetForm would never fire because the
 * admin template calls the BLOCK getForm(), not the form object's own
 * getForm() (which merely returns $this).
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Framework\Data\Form;
use Magento\Framework\Escaper;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Review\Block\Adminhtml\Edit\Form as ReviewEditForm;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Helper\Email as EmailHelper;

/**
 * Admin review edit form decoration.
 */
class AdminReviewFormPlugin
{
    private const AVATAR_PATH_TEMPLATE = 'giztopreview/avatar/view/id/%d/h/%s';

    private const NICKNAME_LABEL = 'Name / Email';

    private const NICKNAME_NOTE = 'If this is an email address, the avatar comes from Gravatar. '
        . 'Ask the customer to register / upload a photo at https://gravatar.com/ '
        . '— otherwise the first letter of the name is shown.';

    private const NICKNAME_IS_EMAIL_HTML = '<div style="margin-top:4px;font-size:11px;color:#666">'
        . 'Avatar served from Gravatar — <a href="https://gravatar.com/" target="_blank">manage at gravatar.com</a>'
        . '</div>';

    private const NICKNAME_IS_NAME_HTML = '<div style="margin-top:4px;font-size:11px;color:#666">'
        . 'This review uses a plain nickname — no Gravatar avatar is shown.'
        . '</div>';

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var EmailHelper
     */
    private EmailHelper $emailHelper;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Escaper
     */
    private Escaper $escaper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EncryptorInterface $encryptor
     * @param EmailHelper $emailHelper
     * @param StoreManagerInterface $storeManager
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        EncryptorInterface $encryptor,
        EmailHelper $emailHelper,
        StoreManagerInterface $storeManager,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        $this->encryptor = $encryptor;
        $this->emailHelper = $emailHelper;
        $this->storeManager = $storeManager;
        $this->escaper = $escaper;
        $this->logger = $logger;
    }

    /**
     * After the review edit block builds its form: decorate the nickname field.
     * Any failure is logged and the untouched form is returned.
     *
     * @param ReviewEditForm $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetForm(ReviewEditForm $subject, $result)
    {
        try {
            $this->decorate($subject, $result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_admin_form: decorate skipped, ' . get_class($e)
            );
        }

        return $result;
    }

    /**
     * Apply label, note and avatar preview to the nickname element.
     *
     * @param ReviewEditForm $subject
     * @param mixed $result
     * @return void
     */
    private function decorate(ReviewEditForm $subject, $result): void
    {
        if (!$result instanceof Form) {
            return;
        }

        $element = $result->getElement('nickname');
        if ($element === null) {
            return;
        }

        $element->setLabel(__(self::NICKNAME_LABEL));
        $element->setNote(__(self::NICKNAME_NOTE));

        $value = trim((string)$element->getValue());
        $reviewId = (int)$subject->getRequest()->getParam('id');
        if ($reviewId <= 0) {
            return;
        }

        if ($this->emailHelper->isEmail($value)) {
            $sig = substr($this->encryptor->hash((string)$reviewId), 0, 16);
            $src = $this->escaper->escapeUrl($this->avatarUrl($reviewId, $sig));

            $element->setAfterElementHtml(
                '<img src="' . $src . '" width="64" height="64" '
                . 'style="border-radius:50%;margin-top:6px" alt="">'
                . self::NICKNAME_IS_EMAIL_HTML
            );
        } else {
            $element->setAfterElementHtml(self::NICKNAME_IS_NAME_HTML);
        }
    }

    /**
     * Frontend base URL + avatar endpoint (no inline JS, CSP safe).
     *
     * @param int $reviewId
     * @param string $sig
     * @return string
     */
    private function avatarUrl(int $reviewId, string $sig): string
    {
        try {
            $baseUrl = trim(
                (string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB)
            );
        } catch (\Throwable $e) {
            $baseUrl = '';
        }

        $path = sprintf(self::AVATAR_PATH_TEMPLATE, $reviewId, $sig);

        return $baseUrl === '' ? $path : $baseUrl . $path;
    }
}
