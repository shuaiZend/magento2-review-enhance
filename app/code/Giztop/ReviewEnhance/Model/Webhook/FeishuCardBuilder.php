<?php
/**
 * GIZTOP Review Enhance
 *
 * Turns the internal review payload into a Feishu (Lark) custom-bot interactive
 * card, including the timestamp/sign pair the bot requires when "signature
 * verification" is enabled on the group bot.
 *
 * Feishu signature algorithm (documented by Feishu):
 *   string_to_sign = "<timestamp>\n<secret>"
 *   sign           = base64( HMAC-SHA256( key = string_to_sign, data = "" ) )
 *
 * Note the unusual part: the HMAC *key* is the string-to-sign and the message
 * is empty. This differs from the generic X-Giztop-Signature header, which
 * signs the raw body with the secret as key.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model\Webhook;

/**
 * Feishu interactive-card payload builder.
 */
class FeishuCardBuilder
{
    /** Default accent colour when the review status is unknown. */
    private const DEFAULT_COLOR = 'blue';

    /** Status code => Feishu card header colour. */
    private const STATUS_COLORS = [
        'pending' => 'orange',
        'approved' => 'green',
        'not_approved' => 'red',
    ];

    /** Placeholder pattern used in the admin-configurable templates. */
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * Build the complete JSON body sent to the Feishu bot.
     *
     * @param array<string, mixed> $payload internal review payload
     * @param string $titleTemplate admin template for the card header
     * @param string $bodyTemplate admin template for the card body
     * @param string $secret Feishu bot signing secret ('' disables signing)
     * @param int|null $timestamp unix time, injectable for tests
     * @return string
     */
    public function buildEnvelope(
        array $payload,
        string $titleTemplate,
        string $bodyTemplate,
        string $secret,
        ?int $timestamp = null
    ): string {
        $vars = $this->buildVariables($payload);

        $title = trim($this->render($titleTemplate, $vars));
        if ($title === '') {
            $title = trim($this->render('New review: {{product_name}}', $vars));
        }

        $body = trim($this->render($bodyTemplate, $vars));
        if ($body === '') {
            $body = trim($this->render('{{review_title}}', $vars));
        }

        $elements = [
            [
                'tag' => 'div',
                'fields' => [
                    $this->field('Rating', $this->stars($vars['rating'] ?? '')),
                    $this->field('Reviewer', (string)($vars['nickname'] ?? '')),
                    $this->field('Time', (string)($vars['created_at'] ?? '')),
                    $this->field('Product', $this->stars($vars['product_rating'] ?? '')),
                ],
            ],
            ['tag' => 'hr'],
            [
                'tag' => 'div',
                'text' => ['tag' => 'lark_md', 'content' => $body],
            ],
        ];

        $actions = [];
        $adminUrl = (string)($vars['admin_url'] ?? '');
        $productUrl = (string)($vars['product_url'] ?? '');
        if ($adminUrl !== '') {
            $actions[] = [
                'tag' => 'button',
                'text' => ['tag' => 'plain_text', 'content' => 'Open in admin'],
                'type' => 'primary',
                'url' => $adminUrl,
            ];
        }
        if ($productUrl !== '') {
            $actions[] = [
                'tag' => 'button',
                'text' => ['tag' => 'plain_text', 'content' => 'View product'],
                'type' => 'default',
                'url' => $productUrl,
            ];
        }
        if ($actions !== []) {
            $elements[] = ['tag' => 'action', 'actions' => $actions];
        }

        $footer = 'Review #' . (string)($vars['review_id'] ?? '');
        $storeCode = (string)($vars['store_code'] ?? '');
        if ($storeCode !== '') {
            $footer .= ' · ' . $storeCode;
        }
        $elements[] = [
            'tag' => 'note',
            'elements' => [['tag' => 'plain_text', 'content' => $footer]],
        ];

        $envelope = [
            'msg_type' => 'interactive',
            'card' => [
                'config' => ['wide_screen_mode' => true, 'enable_forward' => true],
                'header' => [
                    'template' => $this->statusColor((string)($vars['status'] ?? '')),
                    'title' => ['tag' => 'plain_text', 'content' => $title],
                ],
                'elements' => $elements,
            ],
        ];

        if ($secret !== '') {
            $ts = $timestamp ?? time();
            $envelope['timestamp'] = (string)$ts;
            $envelope['sign'] = $this->sign($secret, $ts);
        }

        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }

    /**
     * One key/value cell of the two-column meta block.
     *
     * @param string $label
     * @param string $value
     * @return array<string, mixed>
     */
    private function field(string $label, string $value): array
    {
        $value = $value !== '' ? $value : '—';

        return [
            'is_short' => true,
            'text' => ['tag' => 'lark_md', 'content' => '**' . $label . "**\n" . $value],
        ];
    }

    /**
     * Render a rating as a star string, or '' when unknown.
     *
     * @param string $rating
     * @return string
     */
    private function stars(string $rating): string
    {
        if ($rating === '') {
            return '';
        }

        return '⭐ ' . $rating . ' / 5';
    }

    /**
     * Feishu signature for a given timestamp.
     *
     * @param string $secret
     * @param int $timestamp
     * @return string
     */
    public function sign(string $secret, int $timestamp): string
    {
        $stringToSign = $timestamp . "\n" . $secret;

        return base64_encode(hash_hmac('sha256', '', $stringToSign, true));
    }

    /**
     * Flatten the payload into the placeholder map exposed to admins.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function buildVariables(array $payload): array
    {
        $review = is_array($payload['review'] ?? null) ? $payload['review'] : [];
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        $store = is_array($payload['store'] ?? null) ? $payload['store'] : [];

        $status = (string)($review['status_code'] ?? '');
        $rating = $this->formatRating($review['rating'] ?? 0);
        $productRating = $this->formatRating($product['rating'] ?? 0);

        return [
            'product_name' => (string)($product['name'] ?? ''),
            'product_sku' => (string)($product['sku'] ?? ''),
            'product_url' => (string)($product['url'] ?? ''),
            'product_rating' => $productRating,
            'rating' => $rating,
            'review_title' => (string)($review['title'] ?? ''),
            'review_detail' => (string)($review['detail'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'nickname' => (string)($review['nickname_masked'] ?? ''),
            'review_id' => (string)($review['id'] ?? ''),
            'created_at' => (string)($review['created_at'] ?? ''),
            'admin_url' => (string)($payload['admin_url'] ?? ''),
            'store_code' => (string)($store['code'] ?? ''),
        ];
    }

    /**
     * Replace {{placeholders}} in a template.
     *
     * @param string $template
     * @param array<string, string> $vars
     * @return string
     */
    public function render(string $template, array $vars): string
    {
        $result = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $m) use ($vars): string {
                $key = strtolower($m[1]);

                return $vars[$key] ?? '';
            },
            $template
        );

        return is_string($result) ? $result : $template;
    }

    /**
     * @param string $statusCode
     * @return string
     */
    private function statusColor(string $statusCode): string
    {
        return self::STATUS_COLORS[$statusCode] ?? self::DEFAULT_COLOR;
    }

    /**
     * @param string $statusCode
     * @return string
     */
    private function statusLabel(string $statusCode): string
    {
        switch ($statusCode) {
            case 'pending':
                return 'Pending moderation';
            case 'approved':
                return 'Approved';
            case 'not_approved':
                return 'Not approved';
            default:
                return $statusCode !== '' ? $statusCode : 'Unknown';
        }
    }

    /**
     * Normalise a rating to one decimal place, '' when unavailable.
     *
     * @param mixed $value
     * @return string
     */
    private function formatRating($value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $float = (float)$value;

        return $float > 0 ? number_format($float, 1) : '';
    }
}