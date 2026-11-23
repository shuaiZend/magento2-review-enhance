<?php
/**
 * GIZTOP Review Enhance
 *
 * Purges the local Gravatar cache (var/gravatar/{md5}.*) when a review's
 * nickname changes to an email address, so a stale ".miss" marker or an old
 * cached avatar does not survive the edit.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Plugin;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Helper\Email as EmailHelper;

/**
 * Gravatar cache invalidation on nickname change.
 */
class ReviewNicknameCachePlugin
{
    private const TABLE_REVIEW_DETAIL = 'review_detail';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var DirectoryList
     */
    private DirectoryList $directoryList;

    /**
     * @var EmailHelper
     */
    private EmailHelper $emailHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Old nickname per review object (keyed by spl_object_id), captured in
     * beforeSave() and consumed in afterSave().
     *
     * @var array<int, string|null>
     */
    private array $oldNicknames = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param DirectoryList $directoryList
     * @param EmailHelper $emailHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        DirectoryList $directoryList,
        EmailHelper $emailHelper,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->directoryList = $directoryList;
        $this->emailHelper = $emailHelper;
        $this->logger = $logger;
    }

    /**
     * Before save(): remember the nickname currently stored in review_detail.
     *
     * @param Review $subject
     * @return array|null null = do not alter the save arguments
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(Review $subject): ?array
    {
        try {
            $reviewId = (int)$subject->getId();
            if ($reviewId > 0) {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()
                    ->from(
                        $this->resourceConnection->getTableName(self::TABLE_REVIEW_DETAIL),
                        ['nickname']
                    )
                    ->where('review_id = :review_id');

                $old = $connection->fetchOne($select, [':review_id' => $reviewId]);
                $this->oldNicknames[spl_object_id($subject)] = is_string($old) ? $old : null;
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_nickname_cache: old nickname lookup failed, ' . get_class($e)
            );
        }

        return null;
    }

    /**
     * After save(): when the nickname changed, purge the Gravatar cache for
     * the affected md5 hashes.
     *
     * @param Review $subject
     * @param mixed $result
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(Review $subject, $result)
    {
        $key = spl_object_id($subject);

        try {
            $old = $this->oldNicknames[$key] ?? null;
            unset($this->oldNicknames[$key]);

            if ($old === null || trim($old) === '') {
                // New review or lookup failed — nothing cached to purge.
                return $result;
            }

            $new = trim((string)$subject->getNickname());
            if ($new === '' || $new === trim($old)) {
                return $result;
            }

            if ($this->emailHelper->isEmail($new)) {
                $this->purgeGravatarCache($this->emailHelper->md5($new));
            }
            if ($this->emailHelper->isEmail($old)) {
                $this->purgeGravatarCache($this->emailHelper->md5($old));
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviewenhance_nickname_cache: purge skipped, ' . get_class($e)
            );
        }

        return $result;
    }

    /**
     * Delete var/gravatar/{hash}.* (image files and ".miss" markers).
     *
     * @param string $hash lowercase hex md5 — safe to embed in a glob pattern
     * @return void
     */
    private function purgeGravatarCache(string $hash): void
    {
        $pattern = $this->directoryList->getPath(DirectoryList::VAR_DIR)
            . '/gravatar/' . $hash . '.*';

        $files = glob($pattern);
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }
    }
}
