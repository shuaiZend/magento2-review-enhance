<?php
/**
 * GIZTOP Review Enhance
 *
 * ViewModel for review templates. Public method signatures are a contract
 * with the frontend templates - do not change them without a coordinated
 * template update.
 *
 * SECURITY: reviewer nicknames are emails on the new site. Every output path
 * here is masked (see Helper\Email::mask) - never return a raw nickname to
 * templates, JSON or logs.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Element\BlockFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Block\Html\Header\Logo;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Helper\Email as EmailHelper;
use Giztop\ReviewEnhance\Model\ReviewContent;

/**
 * Review view model.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReviewView implements ArgumentInterface
{
    /** Config paths. */
    public const XML_PATH_CHAT_ENABLED = 'giztop_review/chat/enabled';
    public const XML_PATH_CHAT_STORE_NAME = 'giztop_review/chat/store_name';
    public const XML_PATH_CHAT_STORE_BADGE = 'giztop_review/chat/store_badge';
    public const XML_PATH_CHAT_STORE_AVATAR = 'giztop_review/chat/store_avatar';

    /** Fallbacks (also defined in etc/config.xml). */
    public const DEFAULT_STORE_NAME = 'Giztop Support';
    public const DEFAULT_STORE_BADGE = 'Official Reply';

    /** Max length of a display name that is not an email. */
    public const MAX_AUTHOR_NAME_LENGTH = 40;

    /**
     * Whitelisted source table for review counters.
     */
    public const TABLE_REVIEW_ENTITY_SUMMARY = 'review_entity_summary';

    /**
     * Whitelisted lookup table for review entity type ids.
     */
    public const TABLE_REVIEW_ENTITY_TYPE = 'review_entity_type';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var AssetRepository
     */
    private $assetRepository;

    /**
     * @var BlockFactory
     */
    private $blockFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EmailHelper
     */
    private $emailHelper;

    /**
     * @var ReviewContent
     */
    private $reviewContent;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param EncryptorInterface $encryptor
     * @param AssetRepository $assetRepository
     * @param BlockFactory $blockFactory
     * @param LoggerInterface $logger
     * @param EmailHelper $emailHelper
     * @param ReviewContent $reviewContent
     * @param ResourceConnection $resourceConnection
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        EncryptorInterface $encryptor,
        AssetRepository $assetRepository,
        BlockFactory $blockFactory,
        LoggerInterface $logger,
        EmailHelper $emailHelper,
        ReviewContent $reviewContent,
        ResourceConnection $resourceConnection
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->encryptor = $encryptor;
        $this->assetRepository = $assetRepository;
        $this->blockFactory = $blockFactory;
        $this->logger = $logger;
        $this->emailHelper = $emailHelper;
        $this->reviewContent = $reviewContent;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Whether the Q&A chat bubble feature is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CHAT_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Buyer question part of the review detail ('' when none).
     *
     * @param string $detail
     * @return string
     */
    public function getQuestion(string $detail): string
    {
        return $this->reviewContent->split($detail)['question'] ?? '';
    }

    /**
     * Store answer part of the review detail ('' when no answer exists).
     *
     * @param string $detail
     * @return string
     */
    public function getAnswer(string $detail): string
    {
        return $this->reviewContent->split($detail)['answer'] ?? '';
    }

    /**
     * Whether the review has a non-empty store answer.
     *
     * @param string $detail
     * @return bool
     */
    public function hasAnswer(string $detail): bool
    {
        return $this->reviewContent->split($detail)['answer'] !== null;
    }

    /**
     * Safe display name: masked email, or trimmed/truncated plain nickname.
     * Never returns the full email.
     *
     * @param string $nickname
     * @return string
     */
    public function getAuthorName(string $nickname): string
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            return '';
        }

        if ($this->isEmailAuthor($nickname)) {
            return $this->emailHelper->mask($nickname);
        }

        $name = $nickname;
        if (mb_strlen($name, 'UTF-8') > self::MAX_AUTHOR_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_AUTHOR_NAME_LENGTH, 'UTF-8');
        }

        return $name;
    }

    /**
     * Whether the reviewer nickname is an email address.
     *
     * @param string $nickname
     * @return bool
     */
    public function isEmailAuthor(string $nickname): bool
    {
        return $this->emailHelper->isEmail(trim($nickname));
    }

    /**
     * Signed avatar URL for a review.
     * Format: {baseUrl}giztopreview/avatar/view/id/{reviewId}/h/{sig}/
     * The signature is bound to the site crypt key and is stable/reproducible.
     *
     * @param int $reviewId
     * @return string
     */
    public function getAvatarUrl(int $reviewId): string
    {
        if ($reviewId <= 0) {
            return '';
        }

        $sig = substr($this->encryptor->hash((string)$reviewId), 0, 16);

        return $this->urlBuilder->getUrl(
            'giztopreview/avatar/view',
            ['id' => $reviewId, 'h' => $sig]
        );
    }

    /**
     * Store name shown on the official reply bubble.
     *
     * @return string
     */
    public function getStoreName(): string
    {
        $value = (string)$this->scopeConfig->getValue(
            self::XML_PATH_CHAT_STORE_NAME,
            ScopeInterface::SCOPE_STORE
        );

        return trim($value) !== '' ? $value : self::DEFAULT_STORE_NAME;
    }

    /**
     * Store badge label shown on the official reply bubble.
     *
     * @return string
     */
    public function getStoreBadge(): string
    {
        $value = (string)$this->scopeConfig->getValue(
            self::XML_PATH_CHAT_STORE_BADGE,
            ScopeInterface::SCOPE_STORE
        );

        return trim($value) !== '' ? $value : self::DEFAULT_STORE_BADGE;
    }

    /**
     * Store avatar URL. Priority:
     *  1. giztop_review/chat/store_avatar config (absolute URL or
     *     "Vendor_Module::path" static asset reference);
     *  2. theme logo via Magento\Theme\Block\Html\Header\Logo::getLogoSrc();
     *  3. empty string (template falls back to a text avatar).
     * Any failure at one level falls through to the next.
     *
     * @return string
     */
    public function getStoreAvatarUrl(): string
    {
        $configured = trim(
            (string)$this->scopeConfig->getValue(
                self::XML_PATH_CHAT_STORE_AVATAR,
                ScopeInterface::SCOPE_STORE
            )
        );

        if ($configured !== '') {
            // Absolute URL or root-relative path: use as-is.
            if (preg_match('#^(https?://|/)#i', $configured)) {
                return $configured;
            }
            // Static asset reference "Vendor_Module::path/file.png".
            if (str_contains($configured, '::')) {
                try {
                    $asset = $this->assetRepository->createAsset(
                        $configured,
                        ['area' => Area::AREA_FRONTEND]
                    );
                    $url = $asset->getUrl();
                    if (is_string($url) && $url !== '') {
                        return $url;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'reviewenhance_store_avatar: static asset resolution failed, '
                        . get_class($e)
                    );
                }
            }
            // Unknown format: pass through verbatim, template decides.
            return $configured;
        }

        // Fallback: theme logo.
        try {
            $logo = $this->blockFactory->createBlock(Logo::class);
            $src = $logo->getLogoSrc();
            if (is_string($src) && trim($src) !== '') {
                return $src;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'reviewenhance_store_avatar: logo block fallback failed, '
                . get_class($e)
            );
        }

        return '';
    }

    /**
     * Average rating percent of the review's rating votes (0 when none).
     *
     * @param \Magento\Review\Model\Review $review
     * @return int
     */
    public function getRatingPercent($review): int
    {
        $votes = null;
        try {
            $votes = $review->getRatingVotes();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'reviewenhance_rating_votes: failed to load votes, ' . get_class($e)
            );
            return 0;
        }

        if (!$votes instanceof \Traversable) {
            return 0;
        }

        $total = 0;
        $count = 0;
        foreach ($votes as $vote) {
            $percent = $vote->getPercent();
            if (is_numeric($percent)) {
                $total += (float)$percent;
                $count++;
            }
        }

        if ($count === 0) {
            return 0;
        }

        return (int)round($total / $count);
    }

    /**
     * Number of approved reviews for a product in the current store
     * (falls back to the store_id = 0 row).
     * Source: review_entity_summary.reviews_count.
     *
     * @param int $productId
     * @return int
     */
    public function getReviewCount(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $e) {
            $storeId = 0;
        }

        // Handled by Model\ReviewSummaryProvider to keep table access in one place.
        return $this->fetchReviewCount($productId, $storeId);
    }

    /**
     * Query review_entity_summary with a whitelisted table name and
     * bound parameters only.
     * Prefers the current store row; falls back to the store_id = 0 row.
     *
     * @param int $productId
     * @param int $storeId
     * @return int
     */
    private function fetchReviewCount(int $productId, int $storeId): int
    {
        try {
            $connection = $this->resourceConnection->getConnection();

            $entityTypeSelect = $connection->select()
                ->from(self::TABLE_REVIEW_ENTITY_TYPE, ['entity_id'])
                ->where('entity_code = :entity_code');

            $select = $connection->select()
                ->from(self::TABLE_REVIEW_ENTITY_SUMMARY, ['reviews_count'])
                ->where('entity_pk_value = :product_id')
                ->where('entity_type = (?)', $entityTypeSelect)
                ->where('store_id IN (:store_id, 0)')
                ->order(new \Laminas\Db\Sql\Expression('FIELD(store_id, ' . $storeId . ', 0)'))
                ->limit(1);

            $count = $connection->fetchOne($select, [
                ':product_id' => $productId,
                ':store_id' => $storeId,
                ':entity_code' => \Magento\Review\Model\Review::ENTITY_PRODUCT_CODE,
            ]);

            return $count === false || $count === null ? 0 : (int)$count;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'reviewenhance_review_count: query failed for product, ' . get_class($e)
            );
            return 0;
        }
    }
}
