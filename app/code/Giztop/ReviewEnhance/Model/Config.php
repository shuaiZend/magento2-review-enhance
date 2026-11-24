<?php
/**
 * GIZTOP Review Enhance
 *
 * Central typed accessors for the two opt-in features added in this phase:
 *  - Cloudflare Turnstile on the review form (feature 1)
 *  - Review webhook delivery over RabbitMQ (feature 2)
 *
 * Every accessor resolves at STORE scope so the settings stay per-store
 * overridable, and every accessor degrades to a safe default instead of
 * throwing: a mis-configured store must never break review submission.
 *
 * Implements ArgumentInterface because an instance is handed to a block in
 * catalog_product_view.xml. The layout argument interpreter validates every
 * object argument against that marker interface and logs a CRITICAL for any
 * other object, once per render (action arguments are validated too). The
 * interface is empty, so this adds no behaviour.

 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Giztop\ReviewEnhance\Model\Config\Source\WebhookChannel;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Typed configuration reader for Turnstile and the review webhook.
 */
class Config implements ArgumentInterface
{
    /** Turnstile config paths. */
    public const XML_PATH_TURNSTILE_ENABLED = 'giztop_review/turnstile/enabled';
    public const XML_PATH_TURNSTILE_SITE_KEY = 'giztop_review/turnstile/site_key';
    public const XML_PATH_TURNSTILE_SECRET_KEY = 'giztop_review/turnstile/secret_key';
    public const XML_PATH_TURNSTILE_THEME = 'giztop_review/turnstile/theme';
    public const XML_PATH_TURNSTILE_SIZE = 'giztop_review/turnstile/size';
    public const XML_PATH_TURNSTILE_FAIL_MODE = 'giztop_review/turnstile/fail_mode';
    public const XML_PATH_TURNSTILE_EXPECTED_HOSTNAME = 'giztop_review/turnstile/expected_hostname';

    /** Webhook config paths. */
    public const XML_PATH_WEBHOOK_ENABLED = 'giztop_review/webhook/enabled';
    public const XML_PATH_WEBHOOK_ENDPOINT = 'giztop_review/webhook/endpoint_url';
    public const XML_PATH_WEBHOOK_SECRET = 'giztop_review/webhook/secret';
    public const XML_PATH_WEBHOOK_CHANNEL = 'giztop_review/webhook/channel';
    public const XML_PATH_WEBHOOK_FEISHU_CARD_TITLE = 'giztop_review/webhook/feishu_card_title';
    public const XML_PATH_WEBHOOK_FEISHU_CARD_TEMPLATE = 'giztop_review/webhook/feishu_card_template';
    public const XML_PATH_WEBHOOK_TIMEOUT = 'giztop_review/webhook/timeout';
    public const XML_PATH_WEBHOOK_MAX_ATTEMPTS = 'giztop_review/webhook/max_attempts';
    public const XML_PATH_WEBHOOK_INCLUDE_DETAIL = 'giztop_review/webhook/include_detail';
    public const XML_PATH_WEBHOOK_DETAIL_MAX_LENGTH = 'giztop_review/webhook/detail_max_length';
    public const XML_PATH_WEBHOOK_INCLUDE_REVIEWER_EMAIL = 'giztop_review/webhook/include_reviewer_email';
    public const XML_PATH_WEBHOOK_USER_AGENT = 'giztop_review/webhook/user_agent';
    /**
     * Deliberately NOT exposed in system.xml: test-environment escape hatch for
     * the SSRF guard. Only settable via `bin/magento config:set --lock-env` or
     * app/etc/env.php. Production MUST keep this at 0.
     */
    public const XML_PATH_WEBHOOK_ALLOW_PRIVATE_ENDPOINT = 'giztop_review/webhook/allow_private_endpoint';

    /** Defaults (mirrored in etc/config.xml). */
    public const DEFAULT_TURNSTILE_THEME = 'auto';
    public const DEFAULT_TURNSTILE_SIZE = 'flexible';
    public const DEFAULT_TURNSTILE_FAIL_MODE = 'open';
    public const DEFAULT_WEBHOOK_TIMEOUT = 10;
    public const DEFAULT_WEBHOOK_MAX_ATTEMPTS = 5;
    public const DEFAULT_WEBHOOK_DETAIL_MAX_LENGTH = 1000;
    public const DEFAULT_WEBHOOK_USER_AGENT = 'Giztop-ReviewEnhance/1.0';

    /** Turnstile action name the widget is rendered with; verified server side. */
    public const TURNSTILE_ACTION = 'review_submit';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the Turnstile challenge is active.
     *
     * An empty site key or secret key means "not configured yet" and therefore
     * behaves exactly like enabled=0, so a half-configured store never blocks
     * real customers out of the review form.
     *
     * @return bool
     */
    public function isTurnstileEnabled(): bool
    {
        if (!$this->isFlag(self::XML_PATH_TURNSTILE_ENABLED)) {
            return false;
        }

        return $this->getTurnstileSiteKey() !== '' && $this->getTurnstileSecret() !== '';
    }

    /**
     * Public site key rendered into the widget.
     *
     * @return string
     */
    public function getTurnstileSiteKey(): string
    {
        return $this->getValue(self::XML_PATH_TURNSTILE_SITE_KEY);
    }

    /**
     * Secret key used for siteverify. Stored encrypted in the database.
     *
     * @return string
     */
    public function getTurnstileSecret(): string
    {
        $raw = $this->getValue(self::XML_PATH_TURNSTILE_SECRET_KEY);
        if ($raw === '') {
            return '';
        }

        return $this->maybeDecrypt($raw);
    }

    /**
     * Widget theme: auto | light | dark.
     *
     * @return string
     */
    public function getTurnstileTheme(): string
    {
        $value = $this->getValue(self::XML_PATH_TURNSTILE_THEME);

        return in_array($value, ['auto', 'light', 'dark'], true) ? $value : self::DEFAULT_TURNSTILE_THEME;
    }

    /**
     * Widget size: flexible | normal | compact.
     *
     * @return string
     */
    public function getTurnstileSize(): string
    {
        $value = $this->getValue(self::XML_PATH_TURNSTILE_SIZE);

        return in_array($value, ['flexible', 'normal', 'compact'], true) ? $value : self::DEFAULT_TURNSTILE_SIZE;
    }

    /**
     * What to do when siteverify cannot be reached (network/transport error).
     *
     * open   -> let the review through (availability first)
     * closed -> reject the review (security first)
     *
     * Only transport failures are affected; an explicit Cloudflare
     * `success: false` always rejects.
     *
     * @return string
     */
    public function getTurnstileFailMode(): string
    {
        $value = $this->getValue(self::XML_PATH_TURNSTILE_FAIL_MODE);

        return $value === 'closed' ? 'closed' : self::DEFAULT_TURNSTILE_FAIL_MODE;
    }

    /**
     * Whether transport failures should let the submission through.
     *
     * @return bool
     */
    public function isTurnstileFailOpen(): bool
    {
        return $this->getTurnstileFailMode() !== 'closed';
    }

    /**
     * Hostname expected in the siteverify response.
     *
     * Empty config means "use the current store host" (production behaviour).
     * A non-empty value overrides it, which is what the Cloudflare test keys
     * need because they always answer `hostname: example.com`.
     *
     * @return string
     */
    public function getTurnstileExpectedHostname(): string
    {
        $configured = strtolower($this->getValue(self::XML_PATH_TURNSTILE_EXPECTED_HOSTNAME));
        if ($configured !== '') {
            return $configured;
        }

        try {
            $baseUrl = (string)$this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        } catch (\Throwable $e) {
            // No store resolvable (CLI): fall through.
        }

        return '';
    }

    /**
     * Whether the review webhook is active.
     *
     * Requires an endpoint as well: publishing to a queue nobody can deliver
     * from would only create noise and retry churn.
     *
     * @return bool
     */
    public function isWebhookEnabled(): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_ENABLED) && $this->getWebhookEndpoint() !== '';
    }

    /**
     * Receiver endpoint URL (https only, enforced again at send time).
     *
     * @return string
     */
    public function getWebhookEndpoint(): string
    {
        return $this->getValue(self::XML_PATH_WEBHOOK_ENDPOINT);
    }

    /**
     * HMAC-SHA256 signing secret.
     *
     * @return string
     */
    public function getWebhookSecret(): string
    {
        $raw = $this->getValue(self::XML_PATH_WEBHOOK_SECRET);
        if ($raw === '') {
            return '';
        }

        return $this->maybeDecrypt($raw);
    }

    /**
     * Delivery channel: generic JSON or a Feishu/Lark interactive card.
     *
     * @return string
     */
    public function getWebhookChannel(): string
    {
        $value = (string)$this->getValue(self::XML_PATH_WEBHOOK_CHANNEL);

        return $value === WebhookChannel::FEISHU ? WebhookChannel::FEISHU : WebhookChannel::GENERIC;
    }

    /**
     * Admin-configurable Feishu card title template.
     *
     * @return string
     */
    public function getFeishuCardTitle(): string
    {
        $value = (string)$this->getValue(self::XML_PATH_WEBHOOK_FEISHU_CARD_TITLE);

        return $value !== '' ? $value : 'New review: {{product_name}}';
    }

    /**
     * Admin-configurable Feishu card body template.
     *
     * @return string
     */
    public function getFeishuCardTemplate(): string
    {
        $value = (string)$this->getValue(self::XML_PATH_WEBHOOK_FEISHU_CARD_TEMPLATE);

        return $value !== ''
            ? $value
            : "**{{product_name}}**\nRating: {{rating}} / 5    Status: {{status_label}}\n\n{{review_detail}}";
    }

    /**
     * Total request timeout in seconds (connection timeout is fixed at 3s).
     *
     * @return int
     */
    public function getWebhookTimeout(): int
    {
        $value = (int)$this->getValue(self::XML_PATH_WEBHOOK_TIMEOUT);

        return $value > 0 ? $value : self::DEFAULT_WEBHOOK_TIMEOUT;
    }

    /**
     * Maximum delivery attempts before the payload is parked on the error queue.
     *
     * @return int
     */
    public function getWebhookMaxAttempts(): int
    {
        $value = (int)$this->getValue(self::XML_PATH_WEBHOOK_MAX_ATTEMPTS);

        return $value > 0 ? $value : self::DEFAULT_WEBHOOK_MAX_ATTEMPTS;
    }

    /**
     * Whether the review body may be included in the payload.
     *
     * @return bool
     */
    public function isWebhookDetailIncluded(): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_INCLUDE_DETAIL);
    }

    /**
     * Hard cap for the review body in the payload.
     *
     * @return int
     */
    public function getWebhookDetailMaxLength(): int
    {
        $value = (int)$this->getValue(self::XML_PATH_WEBHOOK_DETAIL_MAX_LENGTH);

        return $value > 0 ? $value : self::DEFAULT_WEBHOOK_DETAIL_MAX_LENGTH;
    }

    /**
     * Whether the reviewer email may be sent in plaintext.
     *
     * Off by default: the payload only ever carries a masked nickname.
     *
     * @return bool
     */
    public function isWebhookReviewerEmailIncluded(): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_INCLUDE_REVIEWER_EMAIL);
    }

    /**
     * User-Agent sent with the webhook request.
     *
     * @return string
     */
    public function getWebhookUserAgent(): string
    {
        $value = $this->getValue(self::XML_PATH_WEBHOOK_USER_AGENT);

        return $value !== '' ? $value : self::DEFAULT_WEBHOOK_USER_AGENT;
    }

    /**
     * Test-only escape hatch for the SSRF guard. Never exposed in the admin UI.
     *
     * @return bool
     */
    public function isPrivateEndpointAllowed(): bool
    {
        return $this->isFlag(self::XML_PATH_WEBHOOK_ALLOW_PRIVATE_ENDPOINT);
    }

    /**
     * Trimmed string config value at store scope ('' when unset).
     *
     * @param string $path
     * @return string
     */
    private function getValue(string $path): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);

        return $value === null ? '' : trim((string)$value);
    }

    /**
     * Boolean config flag at store scope.
     *
     * @param string $path
     * @return bool
     */
    private function isFlag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }
    /**
     * Decrypt a config value only when it is a real Magento encrypted payload.
     *
     * Encryptor::decrypt() happily "decrypts" arbitrary plaintext into garbage,
     * so the envelope prefix (keyVersion:cryptVersion:) is checked first. This
     * keeps values written by `config:set` (plaintext) usable as-is.
     *
     * @param string $value
     * @return string
     */
    private function maybeDecrypt(string $value): string
    {
        if (preg_match('/^\d+:\d+:/', $value) !== 1) {
            return $value;
        }

        try {
            $decrypted = $this->encryptor->decrypt($value);
            if (is_string($decrypted) && $decrypted !== '') {
                return $decrypted;
            }
        } catch (\Throwable $e) {
            // Fall through to the raw value.
        }

        return $value;
    }
}
