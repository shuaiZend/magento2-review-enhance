<?php
/**
 * GIZTOP Review Enhance
 *
 * Builds the review webhook payload (pure transformation, no I/O besides the
 * product/store lookups Magento already caches).
 *
 * Payload contract (field names are frozen - receivers depend on them):
 *   event, event_id, delivery_attempt, occurred_at,
 *   store{id,code},
 *   review{id,status_id,status_code,title,detail,detail_truncated,
 *          nickname_masked,has_email,created_at},
 *   product{id,sku,name,url},
 *   ratings[{code,value,label}],
 *   admin_url
 *
 * Privacy: the reviewer nickname is ALWAYS masked (Helper\Email::mask) and the
 * plaintext email is only added when giztop_review/webhook/include_reviewer_email
 * is explicitly enabled.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Webhook;

use Giztop\ReviewEnhance\Helper\Email as EmailHelper;
use Giztop\ReviewEnhance\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Payload builder for the review.submitted webhook event.
 */
class PayloadBuilder
{
    /** Event name carried in the payload and in the X-Giztop-Event header. */
    public const EVENT_NAME = 'review.submitted';

    /** Status id => stable status code mapping (Magento review status ids). */
    private const STATUS_CODES = [
        Review::STATUS_PENDING => 'pending',
        Review::STATUS_APPROVED => 'approved',
        Review::STATUS_NOT_APPROVED => 'not_approved',
    ];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var EmailHelper
     */
    private EmailHelper $emailHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param ProductRepositoryInterface $productRepository
     * @param EmailHelper $emailHelper
     * @param LoggerInterface $logger
     */
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        ResourceConnection $resource,
        EmailHelper $emailHelper,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->resource = $resource;
        $this->emailHelper = $emailHelper;
        $this->logger = $logger;
    }

    /**
     * Build the payload for a freshly created review.
     *
     * @param Review $review
     * @param int $deliveryAttempt 0 for the first delivery, incremented per retry
     * @param string|null $eventId reuse the id of a retried event
     * @return array<string, mixed>
     */
    public function build(Review $review, int $deliveryAttempt = 0, ?string $eventId = null): array
    {
        $storeId = (int)$review->getStoreId();
        $storeCode = '';
        try {
            $storeCode = (string)$this->storeManager->getStore($storeId)->getCode();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'reviewenhance_webhook_payload: store ' . $storeId . ' not resolvable, ' . get_class($e)
            );
        }

        $nickname = (string)$review->getNickname();
        $isEmail = $nickname !== '' && $this->emailHelper->isEmail($nickname);

        [$detail, $truncated] = $this->prepareDetail((string)$review->getDetail());

        $ratings = $this->buildRatings($review);

        $reviewData = [
            'id' => (int)$review->getId(),
            'status_id' => (int)$review->getStatusId(),
            'status_code' => self::STATUS_CODES[(int)$review->getStatusId()] ?? 'unknown',
            'title' => (string)$review->getTitle(),
            'detail' => $detail,
            'detail_truncated' => $truncated,
            'nickname_masked' => $this->maskNickname($nickname),
            'has_email' => $isEmail,
            'created_at' => $this->formatDate((string)$review->getCreatedAt()),
            'rating' => $this->averageRating($ratings),
        ];

        if ($this->config->isWebhookReviewerEmailIncluded() && $isEmail) {
            $reviewData['email'] = $nickname;
        }

        return [
            'event' => self::EVENT_NAME,
            'event_id' => $eventId !== null && $eventId !== '' ? $eventId : $this->uuid4(),
            'delivery_attempt' => max(0, $deliveryAttempt),
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'store' => [
                'id' => $storeId,
                'code' => $storeCode,
            ],
            'review' => $reviewData,
            'product' => $this->buildProductWithRating((int)$review->getEntityPkValue(), $storeId),
            'ratings' => $ratings,
            'admin_url' => $this->buildAdminUrl((int)$review->getId()),
        ];
    }

    /**
     * Encode the payload exactly as it will be signed and delivered.
     *
     * @param array<string, mixed> $payload
     * @return string
     */
    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }

    /**
     * Mask a nickname: emails become j***@g***.com, plain names are truncated.
     *
     * @param string $nickname
     * @return string
     */
    private function maskNickname(string $nickname): string
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            return '';
        }

        if ($this->emailHelper->isEmail($nickname)) {
            return $this->emailHelper->mask($nickname);
        }

        return mb_substr($nickname, 0, 40, 'UTF-8');
    }

    /**
     * Apply the configured length cap to the review body.
     *
     * @param string $detail
     * @return array{0: string, 1: bool}
     */
    private function prepareDetail(string $detail): array
    {
        if (!$this->config->isWebhookDetailIncluded()) {
            return ['', false];
        }

        $max = $this->config->getWebhookDetailMaxLength();
        if (mb_strlen($detail, 'UTF-8') <= $max) {
            return [$detail, false];
        }

        return [mb_substr($detail, 0, $max, 'UTF-8'), true];
    }

    /**
     * Normalise a MySQL datetime to ISO-8601 (UTC).
     *
     * @param string $value
     * @return string
     */
    private function formatDate(string $value): string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Product block. Failures degrade to empty strings, never to an exception.
     *
     * @param int $productId
     * @param int $storeId
     * @return array{id: int, sku: string, name: string, url: string}
     */
    private function buildProduct(int $productId, int $storeId): array
    {
        $result = ['id' => $productId, 'sku' => '', 'name' => '', 'url' => ''];

        if ($productId <= 0) {
            return $result;
        }

        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
            $result['sku'] = (string)$product->getSku();
            $result['name'] = (string)$product->getName();
            $result['url'] = (string)$product->getProductUrl();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'reviewenhance_webhook_payload: product ' . $productId . ' not resolvable, ' . get_class($e)
            );
        }

        return $result;
    }

    /**
     * Product block including the aggregate rating.
     *
     * @param int $productId
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function buildProductWithRating(int $productId, int $storeId): array
    {
        $product = $this->buildProduct($productId, $storeId);
        $product['rating'] = $this->buildProductRating($productId, $storeId);

        return $product;
    }

    /**
     * Aggregate product rating in stars (0 when the product has no approved
     * reviews yet). Derived from review_entity_summary.rating_summary, which
     * Magento stores as a 0-100 percentage.
     *
     * @param int $productId
     * @param int $storeId
     * @return float
     */
    private function buildProductRating(int $productId, int $storeId): float
    {
        if ($productId <= 0) {
            return 0.0;
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resource->getTableName('review_entity_summary'),
                    ['rating_summary']
                )
                ->where('entity_pk_value = ?', $productId)
                ->where('store_id = ?', $storeId)
                ->where('entity_type = ?', Review::ENTITY_PRODUCT)
                ->limit(1);

            $percent = (int)$connection->fetchOne($select);

            return $percent > 0 ? round($percent / 20, 1) : 0.0;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'reviewenhance_webhook_payload: product rating lookup failed, ' . get_class($e)
            );

            return 0.0;
        }
    }

    /**
     * Overall score of this review: the mean of its rating dimensions.
     *
     * @param array<int, array{code: string, value: int, label: string}> $ratings
     * @return float
     */
    private function averageRating(array $ratings): float
    {
        if ($ratings === []) {
            return 0.0;
        }

        $sum = 0;
        foreach ($ratings as $rating) {
            $sum += (int)($rating['value'] ?? 0);
        }

        return round($sum / count($ratings), 1);
    }

    /**
     * Rating votes of the review.
     *
     * @param Review $review
     * @return array<int, array{code: string, value: int, label: string}>
     */
    private function buildRatings(Review $review): array
    {
        $ratings = [];

        try {
            $votes = $review->getRatingVotes();
        } catch (\Throwable $e) {
            return $ratings;
        }

        if (!$votes instanceof \Traversable) {
            return $ratings;
        }

        foreach ($votes as $vote) {
            $code = (string)$vote->getRatingCode();
            $label = '';
            try {
                $label = (string)$vote->getData('option_label');
            } catch (\Throwable $e) {
                $label = '';
            }

            $ratings[] = [
                'code' => $code,
                'value' => (int)$vote->getValue(),
                'label' => $label !== '' ? $label : $code,
            ];
        }

        return $ratings;
    }

    /**
     * Admin edit URL for the review, or '' when the backend URL cannot be built
     * (e.g. frontend-only area without backend routing).
     *
     * @param int $reviewId
     * @return string
     */
    private function buildAdminUrl(int $reviewId): string
    {
        if ($reviewId <= 0) {
            return '';
        }

        try {
            $backendUrl = \Magento\Framework\App\ObjectManager::getInstance()->get(
                \Magento\Backend\Model\UrlInterface::class
            );

            return (string)$backendUrl->getUrl('review/product/edit', ['id' => $reviewId]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * RFC 4122 version 4 UUID from a CSPRNG (no composer dependency).
     *
     * @return string
     */
    private function uuid4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Throwable $e) {
            // random_bytes only fails when no CSPRNG exists; fall back to a
            // time-based identifier rather than failing the whole event.
            $bytes = md5(uniqid('', true) . (string)mt_rand(), true);
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}