<?php
/**
 * GIZTOP Review Enhance
 *
 * Sends a "your question got a reply" email to the reviewer when a review
 * with an email nickname is approved with a store answer.
 *
 * Idempotency: a row in giztop_review_reply_notification (UNIQUE review_id)
 * is inserted BEFORE sending; the email is only sent when the insert won.
 * No plaintext email is ever persisted or logged.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilderFactory;
use Magento\Review\Model\Review;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Helper\Email as EmailHelper;

/**
 * Review reply notification sender.
 */
class ReplyNotifier
{
    private const CONFIG_ENABLED = 'giztop_review/notify/enabled';
    private const CONFIG_TEMPLATE = 'giztop_review/notify/template';
    private const CONFIG_SENDER_IDENTITY = 'giztop_review/notify/sender_identity';
    private const CONFIG_SENDER_EMAIL = 'giztop_review/notify/sender_email';
    private const CONFIG_SENDER_NAME = 'giztop_review/notify/sender_name';

    private const DEFAULT_TEMPLATE = 'giztop_review_reply_template';
    private const DEFAULT_SENDER_IDENTITY = 'support';
    private const IDENTITY_EMAIL_PATH_TEMPLATE = 'trans_email/ident_%s/email';

    private const TABLE_NOTIFICATION = 'giztop_review_reply_notification';
    private const STATUS_SENT = 'sent';
    private const STATUS_FAILED = 'failed';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var EmailHelper
     */
    private EmailHelper $emailHelper;

    /**
     * @var ReviewContent
     */
    private ReviewContent $reviewContent;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var TransportBuilderFactory
     */
    private TransportBuilderFactory $transportBuilderFactory;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var Escaper
     */
    private Escaper $escaper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param EmailHelper $emailHelper
     * @param ReviewContent $reviewContent
     * @param ResourceConnection $resourceConnection
     * @param TransportBuilderFactory $transportBuilderFactory
     * @param ProductRepositoryInterface $productRepository
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        EmailHelper $emailHelper,
        ReviewContent $reviewContent,
        ResourceConnection $resourceConnection,
        TransportBuilderFactory $transportBuilderFactory,
        ProductRepositoryInterface $productRepository,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->emailHelper = $emailHelper;
        $this->reviewContent = $reviewContent;
        $this->resourceConnection = $resourceConnection;
        $this->transportBuilderFactory = $transportBuilderFactory;
        $this->productRepository = $productRepository;
        $this->escaper = $escaper;
        $this->logger = $logger;
    }

    /**
     * Send the reply notification for an approved review, when applicable.
     * Never throws.
     *
     * @param Review $review
     * @return void
     */
    public function notify(Review $review): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // 1. Approved only.
        if ((int)$review->getStatusId() !== Review::STATUS_APPROVED) {
            return;
        }

        // 2. Nickname must be a valid email address (the only recipient we have).
        $email = trim((string)$review->getNickname());
        $detail = (string)$review->getDetail();

        // Grid mass actions may load the review without review_detail data,
        // so fall back to the table when the model carries no payload.
        if ($email === '' || $detail === '') {
            [$fallbackEmail, $fallbackDetail] = $this->loadDetail((int)$review->getId());
            if ($email === '') {
                $email = $fallbackEmail;
            }
            if ($detail === '') {
                $detail = $fallbackDetail;
            }
        }

        if ($email === '' || !$this->emailHelper->isEmail($email)) {
            return;
        }

        // 3. A store answer must exist ({question} --- Re: {answer}).
        $parts = $this->reviewContent->split($detail);
        if (($parts['answer'] ?? null) === null || trim((string)$parts['answer']) === '') {
            return;
        }

        $reviewId = (int)$review->getId();
        if ($reviewId <= 0) {
            return;
        }

        // 4. Idempotency gate: only the process that wins the UNIQUE insert sends.
        $claimed = $this->claim(
            $reviewId,
            (int)$review->getEntityPkValue(),
            $this->emailHelper->md5($email)
        );
        if (!$claimed) {
            return;
        }

        try {
            $this->sendEmail($review, $email, $parts);
            $this->markStatus($reviewId, self::STATUS_SENT, null);
        } catch (\Throwable $e) {
            $this->markStatus($reviewId, self::STATUS_FAILED, $this->sanitizeError($e));
            $this->logger->error(
                'reviewenhance_reply_notify: review_id=' . $reviewId
                . ' email failed, ' . get_class($e)
            );
        }
    }

    /**
     * Whether reply notifications are enabled.
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Read nickname + detail straight from review_detail.
     *
     * Needed when the review model was loaded by a grid collection or a mass
     * action that does not join review_detail.
     *
     * @param int $reviewId
     * @return array{0: string, 1: string} [nickname, detail]
     */
    private function loadDetail(int $reviewId): array
    {
        if ($reviewId <= 0) {
            return ['', ''];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('review_detail'),
                    ['nickname', 'detail']
                )
                ->where('review_id = ?', $reviewId)
                ->limit(1);
            $row = $connection->fetchRow($select);

            return [
                $row ? (string)($row['nickname'] ?? '') : '',
                $row ? (string)($row['detail'] ?? '') : '',
            ];
        } catch (\Throwable $e) {
            return ['', ''];
        }
    }

    /**
     * Reserve the send by inserting the notification row first.
     * Returns false when the row already exists (already sent / in-flight)
     * or when the insert fails for any other reason (fail-closed).
     *
     * @param int $reviewId
     * @param int $productId
     * @param string $recipientHash md5 of the recipient email
     * @return bool
     */
    private function claim(int $reviewId, int $productId, string $recipientHash): bool
    {
        try {
            $this->resourceConnection->getConnection()->insert(
                $this->resourceConnection->getTableName(self::TABLE_NOTIFICATION),
                [
                    'review_id' => $reviewId,
                    'product_id' => $productId,
                    'recipient_hash' => $recipientHash,
                    // Flipped to "sent" after a successful send.
                    'status' => self::STATUS_FAILED,
                ]
            );
            return true;
        } catch (DuplicateException $e) {
            return false;
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_reply_notify: claim failed for review_id=' . $reviewId
                . ', ' . get_class($e)
            );
            return false;
        }
    }

    /**
     * Persist the final notification status (never throws).
     *
     * @param int $reviewId
     * @param string $status
     * @param string|null $errorMessage
     * @return void
     */
    private function markStatus(int $reviewId, string $status, ?string $errorMessage): void
    {
        try {
            $this->resourceConnection->getConnection()->update(
                $this->resourceConnection->getTableName(self::TABLE_NOTIFICATION),
                [
                    'status' => $status,
                    'error_message' => $errorMessage,
                ],
                ['review_id = ?' => $reviewId]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_reply_notify: status update failed for review_id=' . $reviewId
                . ', ' . get_class($e)
            );
        }
    }

    /**
     * Exception description safe for storage: exception class + first 100
     * characters of the message with any email address redacted.
     *
     * @param \Throwable $e
     * @return string
     */
    private function sanitizeError(\Throwable $e): string
    {
        $message = get_class($e);
        $raw = trim($e->getMessage());
        if ($raw !== '') {
            $raw = preg_replace('/\S+@\S+/', '[email]', $raw);
            $message .= ': ' . ($raw === null ? '[email]' : $raw);
        }

        return mb_substr($message, 0, 100, 'UTF-8');
    }

    /**
     * Build and send the notification email.
     *
     * @param Review $review
     * @param string $email validated recipient
     * @param array $parts result of ReviewContent::split()
     * @return void
     * @throws \Throwable transport failures bubble up to notify()
     */
    private function sendEmail(Review $review, string $email, array $parts): void
    {
        [$storeId, $storeName] = $this->resolveStore();

        $templateId = trim((string)$this->scopeConfig->getValue(
            self::CONFIG_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($templateId === '') {
            $templateId = self::DEFAULT_TEMPLATE;
        }

        $sender = $this->resolveSender($storeId, $storeName);

        $product = $this->resolveProduct((int)$review->getEntityPkValue());

        $templateVars = [
            'product_name' => $product['name'],
            'product_url' => $product['url'],
            'customer_name' => $this->emailHelper->mask($email),
            'review_html' => $this->asSafeHtml((string)($parts['question'] ?? '')),
            'reply_html' => $this->asSafeHtml((string)($parts['answer'] ?? '')),
            'store_name' => $storeName,
            'support_email' => $sender['email'],
        ];

        // Fresh builder instance on every send — never reuse/cached.
        $transportBuilder = $this->transportBuilderFactory->create();
        $transportBuilder->setTemplateIdentifier($templateId)
            ->setTemplateOptions([
                'area' => Area::AREA_FRONTEND,
                'store' => $storeId,
            ])
            ->setTemplateVars($templateVars)
            ->setFrom($sender['from'])
            ->addTo($email);

        $transportBuilder->getTransport()->sendMessage();
    }

    /**
     * Current store id and name; falls back to the default store, then 0.
     *
     * @return array [int storeId, string storeName]
     */
    private function resolveStore(): array
    {
        try {
            $store = $this->storeManager->getStore();
            return [(int)$store->getId(), (string)$store->getName()];
        } catch (\Throwable $e) {
            try {
                $default = $this->storeManager->getDefaultStoreView();
                if ($default !== null) {
                    return [(int)$default->getId(), (string)$default->getName()];
                }
            } catch (\Throwable $ignored) {
                // fall through
            }
        }

        return [0, 'Giztop'];
    }

    /**
     * Resolve the From address: custom sender email/name when configured,
     * otherwise a store-config email identity (default: support).
     *
     * @param int $storeId
     * @param string $storeName
     * @return array ['from' => array|string for TransportBuilder::setFrom(),
     *                'email' => string resolved sender email]
     */
    private function resolveSender(int $storeId, string $storeName): array
    {
        $customEmail = trim((string)$this->scopeConfig->getValue(
            self::CONFIG_SENDER_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        if ($customEmail !== '') {
            $customName = trim((string)$this->scopeConfig->getValue(
                self::CONFIG_SENDER_NAME,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
            if ($customName === '') {
                $customName = $storeName !== '' ? $storeName : 'Giztop';
            }

            return [
                'from' => ['email' => $customEmail, 'name' => $customName],
                'email' => $customEmail,
            ];
        }

        $identity = trim((string)$this->scopeConfig->getValue(
            self::CONFIG_SENDER_IDENTITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($identity === '') {
            $identity = self::DEFAULT_SENDER_IDENTITY;
        }

        $identityEmail = trim((string)$this->scopeConfig->getValue(
            sprintf(self::IDENTITY_EMAIL_PATH_TEMPLATE, $identity),
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return [
            'from' => $identity,
            'email' => $identityEmail,
        ];
    }

    /**
     * Product name and product URL ("#reviews" appended when absent).
     * Any failure degrades to empty strings — never blocks the email.
     *
     * @param int $productId
     * @return array ['name' => string, 'url' => string]
     */
    private function resolveProduct(int $productId): array
    {
        $name = '';
        $url = '';

        if ($productId > 0) {
            try {
                $product = $this->productRepository->getById($productId);
                $name = (string)$product->getName();

                $rawUrl = trim((string)$product->getProductUrl());
                if ($rawUrl !== '') {
                    $url = str_contains($rawUrl, '#') ? $rawUrl : $rawUrl . '#reviews';
                }
            } catch (\Throwable $e) {
                // Cosmetic only.
            }
        }

        return ['name' => $name, 'url' => $url];
    }

    /**
     * Escape and convert newlines: safe-to-embed HTML for the email template.
     *
     * @param string $text
     * @return string
     */
    private function asSafeHtml(string $text): string
    {
        return nl2br($this->escaper->escapeHtml($text));
    }
}
