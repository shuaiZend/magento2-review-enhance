<?php
/**
 * GIZTOP Review Enhance
 *
 * Signed gravatar-proxy endpoint:
 *   /giztopreview/avatar/view/id/{reviewId}/h/{sig}/
 *
 * SECURITY CONTRACT:
 * - The signature is bound to the site crypt key; unsigned requests get 404.
 * - The email is looked up server-side only; the gravatar md5 is used as the
 *   file name. No email, no md5 and no hash ever reaches the output or logs.
 * - No redirects to third-party hosts: failed fetches serve 404 and templates
 *   fall back to an initials avatar.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Controller\Avatar;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Giztop\ReviewEnhance\Helper\Email as EmailHelper;

/**
 * Avatar proxy controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class View extends Action implements HttpGetActionInterface
{
    /** Config paths. */
    public const XML_PATH_AVATAR_ENABLED = 'giztop_review/avatar/enabled';
    public const XML_PATH_AVATAR_TTL = 'giztop_review/avatar/ttl';

    /** Defaults (mirrored in etc/config.xml). */
    public const DEFAULT_AVATAR_TTL = 2592000;

    /** Negative-cache marker TTL (seconds). */
    public const MISS_TTL = 86400;

    /** Max accepted remote image size (bytes). */
    public const MAX_IMAGE_BYTES = 204800;

    /** Whitelisted source table. */
    public const TABLE_REVIEW_DETAIL = 'review_detail';

    /** Gravatar endpoint (no protocol-relative, no redirect following). */
    public const GRAVATAR_URL_TEMPLATE = 'https://www.gravatar.com/avatar/%s?s=96&d=identicon&r=g';

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Curl
     */
    private $curlClient;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EmailHelper
     */
    private $emailHelper;

    /**
     * @param Context $context
     * @param EncryptorInterface $encryptor
     * @param ResourceConnection $resourceConnection
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $curlClient
     * @param DirectoryList $directoryList
     * @param LoggerInterface $logger
     * @param EmailHelper $emailHelper
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        ResourceConnection $resourceConnection,
        ScopeConfigInterface $scopeConfig,
        Curl $curlClient,
        DirectoryList $directoryList,
        LoggerInterface $logger,
        EmailHelper $emailHelper
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->curlClient = $curlClient;
        $this->directoryList = $directoryList;
        $this->logger = $logger;
        $this->emailHelper = $emailHelper;
    }

    /**
     * Serve a cached or freshly fetched gravatar image, or 404.
     *
     * @return ResponseInterface
     */
    public function execute()
    {
        try {
            // 0. Params must be scalars: "?id[]=1&h[]=2" would otherwise raise
            //    "Array to string conversion" outside the try/catch (HTTP 500) and
            //    let anonymous visitors flood the logs.
            $idParam = $this->_request->getParam('id');
            $sigParam = $this->_request->getParam('h');
            if (!is_scalar($idParam) || !is_scalar($sigParam)) {
                return $this->notFound();
            }

            $reviewId = (int)$idParam;
            $sig = (string)$sigParam;

            // 1. Signature gate: anything unsigned/mismatched is a plain 404.
            if ($reviewId <= 0
                || $sig === ''
                || !hash_equals(substr($this->encryptor->hash((string)$reviewId), 0, 16), $sig)
            ) {
                return $this->notFound();
            }

            // 2. Resolve the reviewer email from review_detail (bound params only).
            $nickname = $this->fetchNickname($reviewId);
            if ($nickname === null || $nickname === false) {
                return $this->notFound();
            }

            // 2b. Gravatar only applies to real email addresses. Legacy nicknames
            // (plain names) must never be forwarded to a third party, so they are
            // answered with 404 and the template renders an initial based avatar.
            if (!$this->emailHelper->isEmail((string)$nickname)) {
                return $this->notFound();
            }

            // 3. Feature switch: off => 404 (template falls back to initials).
            if (!$this->scopeConfig->isSetFlag(
                self::XML_PATH_AVATAR_ENABLED,
                ScopeInterface::SCOPE_STORE
            )) {
                return $this->notFound();
            }

            $md5 = $this->emailHelper->md5((string)$nickname);
            $directory = $this->ensureDirectory();

            $ttl = (int)$this->scopeConfig->getValue(
                self::XML_PATH_AVATAR_TTL,
                ScopeInterface::SCOPE_STORE
            );
            if ($ttl <= 0) {
                $ttl = self::DEFAULT_AVATAR_TTL;
            }

            // 4. Positive cache: fresh local file => stream and done.
            $cached = $this->findCachedFile($directory, $md5, $ttl);
            if ($cached !== null) {
                return $this->serveImage($cached['file'], $cached['content_type'], $ttl);
            }

            // 4b. Negative cache: recent failed fetch => 404, no remote retry.
            if ($this->isNegativeCached($directory, $md5)) {
                return $this->notFound();
            }

            // 5. Fetch from gravatar. Failure => mark negative cache + 404.
            $fetched = $this->fetchFromGravatar($md5);
            if ($fetched === null) {
                $this->writeMissMarker($directory, $md5);
                return $this->notFound();
            }

            // 6. Persist and stream.
            $file = $directory . '/' . $md5 . '.' . $fetched['ext'];
            if (file_put_contents($file, $this->curlClient->getBody()) === false
                || !chmod($file, 0644)
            ) {
                throw new FileSystemException(__('Unable to persist avatar cache file'));
            }

            return $this->serveImage($file, $fetched['content_type'], $ttl);
        } catch (\Throwable $e) {
            // 7. Never leak emails or hashes into logs: review id + exception type only.
            $this->logger->warning(
                'reviewenhance_avatar: review_id=' . (isset($reviewId) ? $reviewId : 0)
                . ' exception=' . get_class($e)
            );
            return $this->notFound();
        }
    }

    /**
     * Look up the reviewer nickname. Table name whitelisted, bound parameters,
     * scalar fetch only.
     *
     * @param int $reviewId
     * @return string|false
     */
    private function fetchNickname(int $reviewId)
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(self::TABLE_REVIEW_DETAIL, ['nickname'])
            ->where('review_id = :review_id')
            ->order('store_id ASC')
            ->limit(1);

        return $connection->fetchOne($select, [':review_id' => $reviewId]);
    }

    /**
     * Ensure the var/gravatar cache directory exists (0775, www-owned via deploy).
     *
     * @return string Absolute directory path
     * @throws FileSystemException
     */
    private function ensureDirectory(): string
    {
        $directory = $this->directoryList->getPath('var') . '/gravatar';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new FileSystemException(__('Unable to create avatar cache directory'));
        }

        return $directory;
    }

    /**
     * Find a fresh cached image file.
     *
     * @param string $directory
     * @param string $md5
     * @param int $ttl
     * @return array|null ['file' => string, 'content_type' => string]
     */
    private function findCachedFile(string $directory, string $md5, int $ttl): ?array
    {
        $pattern = $directory . '/' . $md5 . '.*';
        $candidates = glob($pattern) ?: [];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (filemtime($file) !== false && filemtime($file) + $ttl <= time()) {
                continue;
            }
            $contentType = $this->contentTypeForFile($file);
            if ($contentType === null) {
                continue;
            }

            return ['file' => $file, 'content_type' => $contentType];
        }

        return null;
    }

    /**
     * Whether a recent negative-cache marker exists. Expired markers are removed.
     *
     * @param string $directory
     * @param string $md5
     * @return bool
     */
    private function isNegativeCached(string $directory, string $md5): bool
    {
        $marker = $directory . '/' . $md5 . '.miss';
        if (!is_file($marker)) {
            return false;
        }

        $mtime = filemtime($marker);
        if ($mtime === false || $mtime + self::MISS_TTL <= time()) {
            @unlink($marker);
            return false;
        }

        return true;
    }

    /**
     * Write the negative-cache marker (best effort).
     *
     * @param string $directory
     * @param string $md5
     * @return void
     */
    private function writeMissMarker(string $directory, string $md5): void
    {
        @file_put_contents($directory . '/' . $md5 . '.miss', (string)time());
    }

    /**
     * Fetch the avatar from gravatar with strict acceptance rules:
     * HTTP 200, Content-Type image/*, body <= 200KB, redirects NOT followed.
     *
     * @param string $md5
     * @return array|null ['ext' => string, 'content_type' => string]
     */
    private function fetchFromGravatar(string $md5): ?array
    {
        $url = sprintf(self::GRAVATAR_URL_TEMPLATE, $md5);

        $this->curlClient->setTimeout(3);
        $this->curlClient->setOption(CURLOPT_CONNECTTIMEOUT, 2);
        $this->curlClient->setOption(CURLOPT_FOLLOWLOCATION, 0);
        $this->curlClient->get($url);

        if ($this->curlClient->getStatus() !== 200) {
            return null;
        }

        $contentType = $this->findHeader($this->curlClient->getHeaders(), 'Content-Type');
        if ($contentType === null || stripos($contentType, 'image/') !== 0) {
            return null;
        }

        $body = (string)$this->curlClient->getBody();
        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $ext = $this->extensionForContentType($contentType);
        if ($ext === null) {
            return null;
        }

        return ['ext' => $ext, 'content_type' => $contentType];
    }

    /**
     * Case-insensitive header lookup. Accepts both associative arrays
     * (name => value) and lists of "Name: value" strings.
     *
     * @param array $headers
     * @param string $name
     * @return string|null
     */
    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            $value = (string)$value;
            if (is_string($key)) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
                continue;
            }
            // "Content-Type: image/png" style entry.
            $separatorPos = strpos($value, ':');
            if ($separatorPos !== false
                && strcasecmp(trim(substr($value, 0, $separatorPos)), $name) === 0
            ) {
                return trim(substr($value, $separatorPos + 1));
            }
        }

        return null;
    }

    /**
     * Map a Content-Type to a safe file extension; only well-known image
     * types are accepted (anything else is treated as a failed fetch).
     *
     * @param string $contentType
     * @return string|null
     */
    private function extensionForContentType(string $contentType): ?string
    {
        $type = strtolower(trim(strtok($contentType, ';') ?: ''));

        switch ($type) {
            case 'image/png':
                return 'png';
            case 'image/jpeg':
            case 'image/jpg':
                return 'jpg';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
            default:
                return null;
        }
    }

    /**
     * Content-Type for a cached file, derived from its extension.
     *
     * @param string $file
     * @return string|null
     */
    private function contentTypeForFile(string $file): ?string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'png':
                return 'image/png';
            case 'jpg':
                return 'image/jpeg';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            default:
                return null;
        }
    }

    /**
     * Stream an image with cache-friendly headers.
     *
     * @param string $file
     * @param string $contentType
     * @param int $ttl
     * @return ResponseInterface
     */
    private function serveImage(string $file, string $contentType, int $ttl)
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return $this->notFound();
        }

        return $this->_response
            ->setHttpResponseCode(200)
            ->setHeader('Content-Type', $contentType, true)
            ->setHeader('Cache-Control', 'public, max-age=' . $ttl . ', immutable', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setBody($contents);
    }

    /**
     * Empty 404: no body, no redirect.
     *
     * @return ResponseInterface
     */
    private function notFound()
    {
        return $this->_response->setHttpResponseCode(404);
    }
}
