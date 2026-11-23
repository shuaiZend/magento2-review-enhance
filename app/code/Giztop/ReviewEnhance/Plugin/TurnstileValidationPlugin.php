<?php
/**
 * GIZTOP Review Enhance
 *
 * Blocks review submissions that do not carry a valid Cloudflare Turnstile
 * token. This is the only plugin in the module that uses `around`, because the
 * requirement is "do not write anything at all": the controller must be
 * prevented from running, not merely corrected afterwards.
 *
 * Order of checks (cheapest and safest first):
 *   1. feature disabled            -> proceed untouched (zero behaviour change)
 *   2. missing token               -> error message + redirect, no DB write
 *   3. siteverify rejection        -> error message + redirect, no DB write
 *   4. siteverify transport error  -> fail_mode decides (open = proceed)
 *
 * The redirect target mirrors the core controller: the review session's stored
 * redirect URL, then the referer.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Giztop\ReviewEnhance\Model\Config;
use Giztop\ReviewEnhance\Model\Turnstile\Verifier;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Review\Controller\Product\Post;
use Magento\Framework\Session\Generic as ReviewSession;
use Psr\Log\LoggerInterface;

/**
 * Turnstile gate in front of the review POST controller.
 */
class TurnstileValidationPlugin
{
    /** Name of the POST field rendered by the Turnstile widget. */
    public const TOKEN_FIELD = 'cf-turnstile-response';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Verifier
     */
    private Verifier $verifier;

    /**
     * @var MessageManagerInterface
     */
    private MessageManagerInterface $messageManager;

    /**
     * @var RedirectFactory
     */
    private RedirectFactory $resultRedirectFactory;

    /**
     * @var ReviewSession shared Generic session used by the core controller
     */
    private ReviewSession $reviewSession;

    /**
     * @var RedirectInterface
     */
    private RedirectInterface $redirect;

    /**
     * @var HttpRequest
     */
    private HttpRequest $request;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param Verifier $verifier
     * @param MessageManagerInterface $messageManager
     * @param RedirectFactory $resultRedirectFactory
     * @param ReviewSession $reviewSession
     * @param RedirectInterface $redirect
     * @param HttpRequest $request
     * @param LoggerInterface $logger
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Config $config,
        Verifier $verifier,
        MessageManagerInterface $messageManager,
        RedirectFactory $resultRedirectFactory,
        ReviewSession $reviewSession,
        RedirectInterface $redirect,
        HttpRequest $request,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->verifier = $verifier;
        $this->messageManager = $messageManager;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->reviewSession = $reviewSession;
        $this->redirect = $redirect;
        $this->request = $request;
        $this->logger = $logger;
    }

    /**
     * Gate the review POST controller.
     *
     * @param Post $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(Post $subject, callable $proceed)
    {
        // 1. Disabled (or half-configured) => completely transparent.
        if (!$this->config->isTurnstileEnabled()) {
            return $proceed();
        }

        $token = $this->readToken();
        $remoteIp = $this->readClientIp();

        // 2. Missing token: reject before any work happens.
        if ($token === '') {
            $this->logger->warning(
                'reviewenhance_turnstile: submission without token, ip=' . $this->maskIp($remoteIp)
            );

            return $this->reject(
                __('Please complete the human verification challenge and submit your review again.')
            );
        }

        // 3. Ask Cloudflare.
        $result = $this->verifier->verify(
            $token,
            $remoteIp,
            $this->config->getTurnstileExpectedHostname()
        );

        if (!$result['ok']) {
            $this->logger->warning(
                'reviewenhance_turnstile: verification failed, reason=' . $result['reason']
                . ', ip=' . $this->maskIp($remoteIp)
            );

            return $this->reject(
                __('We could not verify the human verification challenge. Please try again.')
            );
        }

        // 4. Verified (or fail-open) => let the core controller run unchanged.
        return $proceed();
    }

    /**
     * Read the widget token from the POST body.
     *
     * @return string
     */
    private function readToken(): string
    {
        try {
            $value = $this->request->getParam(self::TOKEN_FIELD);

            return is_string($value) ? trim($value) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Client IP used for the siteverify `remoteip` field.
     *
     * @return string
     */
    private function readClientIp(): string
    {
        try {
            return (string)$this->request->getClientIp();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Show an error and redirect back to the product page. Never writes.
     *
     * @param \Magento\Framework\Phrase $message
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function reject(\Magento\Framework\Phrase $message)
    {
        try {
            $this->messageManager->addErrorMessage($message);
        } catch (\Throwable $e) {
            $this->logger->error('reviewenhance_turnstile: message manager failed, ' . get_class($e));
        }

        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $resultRedirect->setUrl($this->resolveRedirectUrl());
        } catch (\Throwable $e) {
            $this->logger->error('reviewenhance_turnstile: redirect resolution failed, ' . get_class($e));
            $resultRedirect->setPath('/');
        }

        return $resultRedirect;
    }

    /**
     * Resolve where to send the customer back to.
     *
     * @return string
     */
    private function resolveRedirectUrl(): string
    {
        try {
            $url = $this->reviewSession->getRedirectUrl(true);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable $e) {
            // Session unavailable: fall through to the referer.
        }

        try {
            $referer = $this->redirect->getRefererUrl();
            if (is_string($referer) && $referer !== '') {
                return $referer;
            }
        } catch (\Throwable $e) {
            // No referer: fall through to the default redirect.
        }

        try {
            return (string)$this->redirect->getRedirectUrl();
        } catch (\Throwable $e) {
            return '/';
        }
    }

    /**
     * Mask an IP for logging.
     *
     * @param string $ip
     * @return string
     */
    private function maskIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 'n/a';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return ($parts[0] ?? '0') . '.***.***.***';
        }

        return substr($ip, 0, 4) . ':***';
    }
}