<?php
/**
 * GIZTOP Review Enhance
 *
 * Email helpers: validation, masking (no full email may ever leave the server),
 * gravatar hash and initials fallback.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

/**
 * Email helper.
 */
class Email extends AbstractHelper
{
    /**
     * Placeholder used when a value cannot be masked safely.
     */
    public const MASK_FALLBACK = '***';

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * Whether the given string is a syntactically valid email address.
     *
     * @param string $email
     * @return bool
     */
    public function isEmail(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Mask an email address for public output.
     *
     * Examples:
     *  - john.doe@gmail.com     -> j***@g***.com
     *  - jo@example.com         -> ***@e***.com
     *  - user+tag@gmail.com     -> u***@g***.com  (+ alias stripped)
     *  - a@mail.google.co.uk    -> a***@m***.uk   (TLD kept verbatim)
     *
     * Guarantees: neither the local part nor the domain appears in full.
     *
     * @param string $email
     * @return string
     */
    public function mask(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return self::MASK_FALLBACK;
        }

        [$local, $domain] = explode('@', $email, 2);

        // Strip + aliases: treat everything before '+' as the real local part.
        $plusPos = strpos($local, '+');
        if ($plusPos !== false) {
            $local = substr($local, 0, $plusPos);
        }
        if ($local === '') {
            return self::MASK_FALLBACK;
        }

        if (mb_strlen($local, 'UTF-8') <= 2) {
            $maskedLocal = self::MASK_FALLBACK;
        } else {
            $maskedLocal = mb_substr($local, 0, 1, 'UTF-8') . self::MASK_FALLBACK;
        }

        $domain = trim($domain);
        if ($domain === '') {
            return $maskedLocal . '@' . self::MASK_FALLBACK;
        }

        // Domain: first character + *** + last-level suffix (".com" etc. kept as-is).
        $dotPos = strrpos($domain, '.');
        $suffix = $dotPos !== false ? substr($domain, $dotPos) : '';
        $maskedDomain = mb_substr($domain, 0, 1, 'UTF-8') . self::MASK_FALLBACK . $suffix;

        return $maskedLocal . '@' . $maskedDomain;
    }

    /**
     * Deterministic md5 of a normalized email, used for gravatar lookup
     * and as a cache-safe file name on disk.
     *
     * @param string $email
     * @return string
     */
    public function md5(string $email): string
    {
        return md5(strtolower(trim($email)));
    }

    /**
     * Initials of a non-email nickname (max 2 uppercase characters),
     * used as the fallback avatar when no gravatar exists.
     *
     * @param string $name
     * @return string
     */
    public function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        if (is_array($words)) {
            foreach ($words as $word) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
                if (mb_strlen($initials, 'UTF-8') >= 2) {
                    break;
                }
            }
        }

        if ($initials === '') {
            // Name contained no letters/word boundaries; take the first character as-is.
            $initials = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
        }

        return $initials !== '' ? $initials : '?';
    }
}
