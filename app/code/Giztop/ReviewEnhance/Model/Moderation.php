<?php
/**
 * GIZTOP Review Enhance
 *
 * Review text moderation: keyword blacklist + domain blacklist with a
 * whitelist escape hatch. Pure logic, no I/O beyond scope config reads —
 * safe to unit test.
 *
 * @category  Giztop
 * @package   Giztop_ReviewEnhance
 */
declare(strict_types=1);

namespace Giztop\ReviewEnhance\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keyword / domain moderation for review content.
 */
class Moderation
{
    public const CONFIG_ENABLED = 'giztop_review/moderation/enabled';
    public const CONFIG_KEYWORDS_BLACKLIST = 'giztop_review/moderation/keywords_blacklist';
    public const CONFIG_DOMAINS_BLACKLIST = 'giztop_review/moderation/domains_blacklist';
    public const CONFIG_DOMAINS_WHITELIST = 'giztop_review/moderation/domains_whitelist';
    public const CONFIG_WHITELIST_PRIORITY = 'giztop_review/moderation/whitelist_priority';
    public const CONFIG_MESSAGE = 'giztop_review/moderation/message';

    public const DEFAULT_MESSAGE = 'Your review contains content that is not allowed.';

    public const WHITELIST_PRIORITY_DOMAIN_ONLY = 'domain_only';
    public const WHITELIST_PRIORITY_FULL_BYPASS = 'full_bypass';

    /**
     * URL tokens: scheme + rest of the token (terminated by whitespace or
     * common delimiters). Host extraction is done via parse_url.
     */
    private const URL_PATTERN = '#[a-z][a-z0-9+.\-]*://[^\s<>"\'\)\]]+#i';

    /**
     * Email domains: everything after the last '@' of an address-looking token.
     * Applied before the bare-domain pass and removed from the text so the
     * local part ("first.last") is never mistaken for a domain.
     */
    private const EMAIL_DOMAIN_PATTERN = '/@([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+)/i';

    /**
     * Bare domains: dot-separated labels whose FINAL label is at least 2
     * letters. This keeps "5.5 inch" and "e.g" out ("5" / "g" are not a
     * 2+ letter TLD) while matching "example.com", "www.sub.example.com".
     */
    private const BARE_DOMAIN_PATTERN = '/(?<![\w.@-])([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,})(?![a-z0-9-])/i';

    /**
     * Domain validation: at least two labels, each starting/ending with an
     * alphanumeric character, final label 2+ letters (a TLD), no spaces.
     */
    private const DOMAIN_VALIDATION_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/';

    /**
     * Raw IPv4 literals (blocked through the same domain blacklist).
     */
    private const IPV4_PATTERN = '/(?<![\d.])(\d{1,3}(?:\.\d{1,3}){3})(?![\d.])/';

    /**
     * Common Cyrillic / Greek homoglyphs mapped onto their Latin twins so
     * "саsino" cannot slip past a "casino" keyword.
     */
    private const HOMOGLYPH_MAP = [
        'а' => 'a', 'в' => 'b', 'е' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h',
        'о' => 'o', 'р' => 'p', 'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x',
        'ѕ' => 's', 'і' => 'i', 'ј' => 'j', 'ԁ' => 'd', 'ɡ' => 'g', 'ӏ' => 'l',
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v',
        'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'χ' => 'x',
    ];

    /**
     * Leet-speak folding used only for the keyword skeleton comparison.
     */
    private const LEET_MAP = [
        '4' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '5' => 's', '7' => 't',
        '@' => 'a', '$' => 's',
    ];

    /**
     * Keyword skeleton matching only kicks in for keywords this long, so short
     * generic words ("asus", "app") cannot match across word boundaries.
     */
    private const SKELETON_MIN_KEYWORD_LENGTH = 5;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * Store id memoized per instance (one store per request).
     *
     * @var int|null
     */
    private ?int $storeId = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    /**
     * Set when a preg_* call aborts (JIT/backtrack limit) while extracting
     * domains - the caller then fails closed instead of accepting the review.
     *
     * @var bool
     */
    private bool $extractionFailed = false;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    /**
     * Whether moderation is enabled (store scope).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config(self::CONFIG_ENABLED) === '1';
    }

    /**
     * Check review text against keyword and domain blacklists.
     *
     * All configuration is resolved once per call.
     *
     * @param string $text
     * @return string|null null = accepted; string = rejection message to show
     */
    public function check(string $text): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $message = $this->config(self::CONFIG_MESSAGE);
        if ($message === null || $message === '') {
            $message = self::DEFAULT_MESSAGE;
        }

        // 0. Normalize once: entities, tags, fullwidth, zero-width chars and
        //    obfuscated dots. Both the spaced and the whitespace-free form are
        //    kept so "ca sino" cannot slip past a keyword entry.
        $text = $this->normalize($text);
        $compact = (string)preg_replace('/\s+/u', '', $text);

        // 1. Domain extraction (URLs, "www." tokens, bare domains, emails, IPs).
        $domains = $this->extractDomains($text);
        if ($this->extractionFailed) {
            // preg_* bailed out (JIT / backtrack limit): fail closed.
            return $message;
        }

        $whitelist = $this->readDomainList(self::CONFIG_DOMAINS_WHITELIST);
        $blacklist = $this->readDomainList(self::CONFIG_DOMAINS_BLACKLIST);
        $priority = $this->config(self::CONFIG_WHITELIST_PRIORITY)
            ?: self::WHITELIST_PRIORITY_DOMAIN_ONLY;

        // 2. Whitelist pass (self match or parent-domain match:
        //    "spam.example.com" matches "example.com").
        $whitelisted = [];
        foreach ($domains as $domain) {
            if ($this->matchList($whitelist, $domain) !== null) {
                $whitelisted[] = $domain;
            }
        }

        if ($whitelisted !== [] && $priority === self::WHITELIST_PRIORITY_FULL_BYPASS) {
            // full_bypass: a whitelisted domain lets the whole text through,
            // including keyword hits (this is the switch the admin chooses).
            return null;
        }

        // 3. Keyword blacklist: case-insensitive substring match on the
        //    lowercased text; the first hit rejects the whole review.
        $keywords = $this->readLines(self::CONFIG_KEYWORDS_BLACKLIST);
        if ($keywords !== []) {
            $lower = mb_strtolower($text, 'UTF-8');
            $skeleton = $this->skeleton($text);
            foreach ($keywords as $keyword) {
                if (mb_stripos($lower, $keyword, 0, 'UTF-8') !== false
                    || mb_stripos($compact, $keyword, 0, 'UTF-8') !== false
                ) {
                    return $message;
                }
                // Homoglyph / separator / leet obfuscation: compare against the
                // stripped-down skeleton, but only for reasonably long keywords
                // so short words do not match across word boundaries.
                if (mb_strlen($keyword, 'UTF-8') >= self::SKELETON_MIN_KEYWORD_LENGTH
                    && $skeleton !== ''
                    && mb_stripos($skeleton, $this->skeletonizeKeyword($keyword), 0, 'UTF-8') !== false
                ) {
                    return $message;
                }
            }
        }

        if ($domains === []) {
            return null;
        }

        // 4. Blacklist pass; whitelisted domains are skipped (domain_only).
        foreach ($domains as $domain) {
            if (in_array($domain, $whitelisted, true)) {
                continue;
            }
            if ($this->matchList($blacklist, $domain) !== null) {
                return $message;
            }
        }

        // 5. Nothing matched.
        return null;
    }

    /**
     * Extract candidate domains from free text.
     *
     * Returns lowercase, deduplicated, normalized domains.
     *
     * @param string $text
     * @return array
     */
    public function extractDomains(string $text): array
    {
        $domains = [];
        $this->extractionFailed = false;
        $text = $this->normalize($text);

        // 1. URLs -> host (then removed from the text).
        $urls = preg_match_all(self::URL_PATTERN, $text, $matches);
        if ($urls === false) {
            $this->extractionFailed = true;
            return [];
        }
        if ($urls > 0) {
            foreach ($matches[0] as $url) {
                $host = $this->hostFromUrl($url);
                if ($host === null) {
                    continue;
                }
                // Raw IP hosts are consumed here (the URL is stripped below),
                // so they have to be captured before the text is rewritten.
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    $domains[] = $host;
                    continue;
                }
                if ($this->isValidDomain($host)) {
                    $domains[] = $host;
                }
            }
            $text = (string)preg_replace(self::URL_PATTERN, ' ', $text);
        }

        // 2. Email addresses -> "@domain" (then removed, so the local part
        //    like "first.last" can never be matched by the bare-domain pass).
        $emails = preg_match_all(self::EMAIL_DOMAIN_PATTERN, $text, $matches);
        if ($emails === false) {
            $this->extractionFailed = true;
            return [];
        }
        if ($emails > 0) {
            foreach ($matches[1] as $domain) {
                if ($this->isValidDomain($domain)) {
                    $domains[] = $domain;
                }
            }
            $text = (string)preg_replace(self::EMAIL_DOMAIN_PATTERN, ' ', $text);
        }

        // 3. Bare domains and "www." prefixed tokens.
        $bare = preg_match_all(self::BARE_DOMAIN_PATTERN, $text, $matches);
        if ($bare === false) {
            $this->extractionFailed = true;
            return [];
        }
        if ($bare > 0) {
            foreach ($matches[1] as $domain) {
                if ($this->isValidDomain($domain)) {
                    $domains[] = $domain;
                }
            }
        }

        // 4. IPv4 literals ("http://1.2.3.4/x") so raw-IP links can be blocked.
        $ips = preg_match_all(self::IPV4_PATTERN, $text, $ipMatches);
        if ($ips === false) {
            $this->extractionFailed = true;
            return [];
        }
        if ($ips > 0) {
            foreach ($ipMatches[1] as $ip) {
                $domains[] = $ip;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Normalize text so trivial obfuscation cannot hide keywords or domains:
     * HTML entities, inline tags, fullwidth forms, zero-width and bidi
     * characters, and bracketed/spaced dots.
     *
     * @param string $text
     * @return string
     */
    private function normalize(string $text): string
    {
        // "c&#97;sino" -> "casino"
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // "<b>ca</b>sino" -> "casino"
        $text = strip_tags($text);
        // "ｃａｓｉｎｏ" -> "casino"
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if ($normalized !== false) {
                $text = $normalized;
            }
        }
        // "casi\u{200B}no" -> "casino"
        $text = (string)preg_replace(
            '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u',
            '',
            $text
        );
        // "spam[.]test" / "spam . test" / "spam。test" -> "spam.test"
        $text = str_replace(['[.]', '(.)', '{.}', '。', '．', '｡'], '.', $text);
        // "spam[dot]test" / "spam(dot)test" / "name[at]mail.com"
        $text = (string)preg_replace('/[[({]\s*dot\s*[\])}]/iu', '.', $text);
        $text = (string)preg_replace('/[[({]\s*at\s*[\])}]/iu', '@', $text);
        // Only fold a space *before* the dot ("spam . test"). A space after the
        // dot is normal punctuation ("Great. Test") and must not be glued into
        // a fake domain.
        $text = (string)preg_replace('/\s+\.\s*/u', '.', $text);
        // "hxxp://" -> "http://"
        $text = (string)preg_replace('/\bhxxps?/i', 'http', $text);

        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * Whether a domain matches a configured list entry (exact or subdomain).
     *
     * @param array $list normalized domain entries
     * @param string $domain candidate domain
     * @return string|null the matching list entry, null when no match
     */
    private function matchList(array $list, string $domain): ?string
    {
        foreach ($list as $entry) {
            if ($domain === $entry || str_ends_with($domain, '.' . $entry)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Host part of a URL string; null when unparseable.
     *
     * @param string $url
     * @return string|null
     */
    private function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        // Strip IPv6 brackets and any residual port.
        $host = strtolower(trim($host, '[]'));
        $pos = strrpos($host, ':');
        if ($pos !== false && !str_contains($host, ']')) {
            $host = substr($host, 0, $pos);
        }
        return trim($host, '.');
    }

    /**
     * Whether a lowercase host is a plausible domain (not an IP, TLD >= 2 letters).
     *
     * @param string $host
     * @return bool
     */
    private function isValidDomain(string $host): bool
    {
        if ($host === '' || str_contains($host, ' ')) {
            return false;
        }
        // Plain IP addresses are not domain-matched.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        return preg_match(self::DOMAIN_VALIDATION_PATTERN, $host) === 1;
    }

    /**
     * Non-empty, non-comment lines of a textarea config value (lowercased).
     *
     * @param string $path
     * @return array
     */
    private function readLines(string $path): array
    {
        $raw = $this->config($path);
        if ($raw === null || $raw === '') {
            return [];
        }

        $lines = preg_split('/\R/', $raw) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $result[] = mb_strtolower($line, 'UTF-8');
        }

        return array_values(array_unique($result));
    }

    /**
     * Domain list from a textarea; entries may be "example.com",
     * "https://example.com/x" or "www.example.com".
     *
     * @param string $path
     * @return array
     */
    private function readDomainList(string $path): array
    {
        $result = [];
        foreach ($this->readLines($path) as $entry) {
            $domain = $this->normalizeDomainEntry($entry);
            if ($domain !== null) {
                $result[] = $domain;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * Normalize one configured domain entry to its bare host.
     *
     * @param string $entry already lowercased, trimmed line
     * @return string|null
     */
    private function normalizeDomainEntry(string $entry): ?string
    {
        if (str_contains($entry, '://')) {
            $host = $this->hostFromUrl($entry);
        } else {
            // Drop path, port and surrounding dots.
            $host = explode('/', $entry, 2)[0];
            $host = explode(':', $host, 2)[0];
            $host = trim($host, '.');
        }

        if ($host === null || $host === '') {
            return null;
        }

        // Raw IP entries are allowed so "http://1.2.3.4/x" can be blocked.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        return $this->isValidDomain($host) ? $host : null;
    }

    /**
     * Collapse a text to letters/digits only (after homoglyph and leet folding)
     * so "c-a-s-i-n-o", "c.a.s.i.n.o", "c4sino" and "саsino" all read "casino".
     *
     * @param string $text
     * @return string
     */
    private function skeleton(string $text): string
    {
        $text = $this->normalize($text);
        $text = strtr($text, self::HOMOGLYPH_MAP);
        $text = (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $text);

        return strtr($text, self::LEET_MAP);
    }

    /**
     * Same normalization applied to a configured keyword.
     *
     * @param string $keyword
     * @return string
     */
    private function skeletonizeKeyword(string $keyword): string
    {
        return $this->skeleton($keyword);
    }

    /**
     * Trimmed string config value (store scope); null when unset/empty.
     *
     * @param string $path
     * @return string|null
     */
    private function config(string $path): ?string
    {
        $value = $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $this->getStoreId()
        );

        return $value === null ? null : trim((string)$value);
    }

    /**
     * Current store id; 0 (fallback to default scope values) on failure.
     *
     * @return int
     */
    private function getStoreId(): int
    {
        if ($this->storeId === null) {
            try {
                $this->storeId = (int)$this->storeManager->getStore()->getId();
            } catch (\Throwable $e) {
                // No store resolvable (CLI, area not initialized, etc.) —
                // fall back to default-scope values.
                $this->storeId = 0;
            }
        }

        return $this->storeId;
    }
}
