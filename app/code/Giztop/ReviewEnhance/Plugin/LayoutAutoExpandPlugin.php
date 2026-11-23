<?php
/**
 * GIZTOP Review Enhance
 *
 * Injects the "expand our configuration groups" bootstrap into the rendered
 * Stores > Configuration page.
 *
 * Magento renders every configuration group collapsed (only the group headers
 * are visible) and expands them on click. For this module the expectation is
 * "open the page and see the settings", so a small script opens the five groups
 * once the page is ready.
 *
 * The injection happens on Layout::getOutput() because the admin layout handle
 * for this page is not reliable for referenceContainer injection.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Layout;
use Psr\Log\LoggerInterface;

/**
 * Appends the auto-expand bootstrap to the configuration page output.
 */
class LayoutAutoExpandPlugin
{
    private const ACTION_NAME = 'adminhtml_system_config_edit';
    private const SECTION_MARKER = 'giztop_review_chat';
    private const SCRIPT_MARKER = 'Giztop_ReviewEnhance/js/auto-expand';

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     */
    public function __construct(RequestInterface $request, LoggerInterface $logger)
    {
        $this->request = $request;
        $this->logger = $logger;
    }

    /**
     * @param Layout $subject
     * @param string $result
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetOutput(Layout $subject, $result): string
    {
        try {
            if ($this->request->getFullActionName() !== self::ACTION_NAME) {
                return $result;
            }

            if (strpos($result, self::SECTION_MARKER) === false
                || strpos($result, self::SCRIPT_MARKER) !== false
            ) {
                return $result;
            }

            $script = '<script type="text/x-magento-init">'
                . '{"*":{"' . self::SCRIPT_MARKER . '":{}}}'
                . '</script>';

            $position = strrpos($result, '</body>');

            return $position === false
                ? $result . $script
                : substr($result, 0, $position) . $script . substr($result, $position);
        } catch (\Throwable $e) {
            $this->logger->error('reviewenhance_autoexpand: ' . get_class($e));

            return $result;
        }
    }
}
