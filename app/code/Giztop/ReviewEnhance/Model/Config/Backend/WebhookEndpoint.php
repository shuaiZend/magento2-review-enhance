<?php
/**
 * GIZTOP Review Enhance
 *
 * Save-time validation for the webhook endpoint URL.
 *
 * The send-time check in Model\Webhook\HttpClient is the authoritative SSRF
 * guard (DNS can change between save and send). This backend model exists so an
 * administrator gets an immediate, readable error instead of discovering the
 * problem only when the first event silently fails hours later.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Config\Backend;

use Giztop\ReviewEnhance\Model\Config;
use Giztop\ReviewEnhance\Model\Webhook\UrlGuard;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Validates giztop_review/webhook/endpoint_url on save.
 */
class WebhookEndpoint extends Value
{
    /**
     * @var UrlGuard
     */
    private UrlGuard $urlGuard;

    /**
     * @var Config
     */
    private Config $moduleConfig;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param UrlGuard $urlGuard
     * @param Config $moduleConfig
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        UrlGuard $urlGuard,
        Config $moduleConfig,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->urlGuard = $urlGuard;
        $this->moduleConfig = $moduleConfig;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Reject non-https URLs and private/reserved destinations before saving.
     *
     * An empty value is allowed: it simply disables delivery.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());

        if ($value !== '') {
            $this->urlGuard->assertAllowed($value, $this->moduleConfig->isPrivateEndpointAllowed());
        }

        return parent::beforeSave();
    }
}