<?php
/**
 * GIZTOP Review Enhance
 *
 * Masks the reviewer identifier in GraphQL review payloads. Since phase 2 the
 * nickname column may hold a customer email address, and the GraphQL review
 * mapper used to return it verbatim to anonymous visitors.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Giztop\ReviewEnhance\Helper\Email as EmailHelper;
use Magento\ReviewGraphQl\Mapper\ReviewDataMapper;

/**
 * Masks email-shaped reviewer names in GraphQL responses.
 */
class GraphQlReviewNicknamePlugin
{
    /**
     * @var EmailHelper
     */
    private EmailHelper $emailHelper;

    /**
     * @param EmailHelper $emailHelper
     */
    public function __construct(EmailHelper $emailHelper)
    {
        $this->emailHelper = $emailHelper;
    }

    /**
     * @param ReviewDataMapper $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterMap(ReviewDataMapper $subject, array $result): array
    {
        $nickname = (string)($result['nickname'] ?? '');
        if ($nickname !== '' && $this->emailHelper->isEmail($nickname)) {
            $result['nickname'] = $this->emailHelper->mask($nickname);
        }

        return $result;
    }
}
