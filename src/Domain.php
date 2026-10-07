<?php

namespace EduLazaro\Laradomains;

use EduLazaro\Laradomains\Support\PublicSuffixList;
use InvalidArgumentException;
use Stringable;

/**
 * A host name, normalised and validated, in both of its spellings.
 *
 * Accepts what people paste: `https://Ejemplo.com:8080/ruta?x=1`, `user@ejemplo.com`,
 * `ejemplo.com.` or `ñandú.es`. Keeps the host only, lower-cased and without the trailing dot,
 * and carries it as ASCII (punycode, what DNS and registries use) and as Unicode (what a person
 * reads). IP addresses are not domains and are refused.
 *
 * `www` is kept, because `www.example.com` is a host of its own; `withoutWww()` drops it when
 * the caller treats both as the same site.
 */
final class Domain implements Stringable
{
    private const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /**
     * @param string $ascii
     * @param string $unicode
     */
    private function __construct(
        public readonly string $ascii,
        public readonly string $unicode,
    ) {}

    /**
     * @param string $input
     * @return self
     * @throws InvalidArgumentException
     */
    public static function parse(string $input): self
    {
        $host = self::extractHost($input);

        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException("Invalid domain: {$input}");
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || ! preg_match(self::HOST_PATTERN, $ascii)) {
            throw new InvalidArgumentException("Invalid domain: {$input}");
        }

        $unicode = idn_to_utf8($ascii, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46) ?: $ascii;

        return new self($ascii, $unicode);
    }

    /**
     * Like parse(), but null instead of an exception.
     *
     * @param string|null $input
     * @return self|null
     */
    public static function tryParse(?string $input): ?self
    {
        try {
            return self::parse((string) $input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The last label: `uk` for `bbc.co.uk`.
     *
     * @return string
     */
    public function tld(): string
    {
        return substr($this->ascii, strrpos($this->ascii, '.') + 1);
    }

    /**
     * The public suffix: `co.uk` for `news.bbc.co.uk`. With `$private`, suffixes run by
     * companies count too, so `github.io` is the suffix of `edulazaro.github.io`.
     *
     * @param bool $private
     * @return string
     */
    public function suffix(bool $private = false): string
    {
        return PublicSuffixList::instance()->suffix($this->ascii, $private);
    }

    /**
     * The part somebody registered: `bbc.co.uk` for `news.bbc.co.uk`, the name to ask a
     * registry about. With `$private`, the site a hosting platform gave out instead
     * (`edulazaro.github.io`). Null when the host is itself a suffix.
     *
     * @param bool $private
     * @return string|null
     */
    public function registrable(bool $private = false): ?string
    {
        return PublicSuffixList::instance()->registrable($this->ascii, $private);
    }

    /**
     * What comes before the registrable domain: `news` for `news.bbc.co.uk`, null for none.
     *
     * @param bool $private
     * @return string|null
     */
    public function subdomain(bool $private = false): ?string
    {
        $registrable = $this->registrable($private);

        if ($registrable === null || $registrable === $this->ascii) {
            return null;
        }

        return substr($this->ascii, 0, -strlen($registrable) - 1);
    }

    /**
     * Whether this is the registrable domain itself, not a subdomain of it.
     *
     * @param bool $private
     * @return bool
     */
    public function isRegistrable(bool $private = false): bool
    {
        return $this->registrable($private) === $this->ascii;
    }

    /**
     * Whether any label is internationalised (written in punycode).
     *
     * @return bool
     */
    public function isIdn(): bool
    {
        return $this->ascii !== $this->unicode || str_contains($this->ascii, 'xn--');
    }

    /**
     * Whether a reader could take this name for another one: an internationalised label that
     * mixes scripts (`аpple.com` with a Cyrillic а). Accented names in one script (`ñandú.es`,
     * `münchen.de`) and names wholly in another script (`日本語.jp`) are not suspicious.
     *
     * @return bool
     */
    public function isLookalike(): bool
    {
        if (! $this->isIdn() || ! class_exists(\Spoofchecker::class)) {
            return false;
        }

        // Highly restrictive: one script per label, or Latin plus the CJK combinations
        // that real names use. ICU's older confusable checks no longer flag mixed labels.
        $checker = new \Spoofchecker;
        $checker->setRestrictionLevel(\Spoofchecker::HIGHLY_RESTRICTIVE);

        foreach (explode('.', $this->unicode) as $label) {
            if ($checker->isSuspicious($label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return self
     */
    public function withoutWww(): self
    {
        return str_starts_with($this->ascii, 'www.') && substr_count($this->ascii, '.') > 1
            ? self::parse(substr($this->ascii, 4))
            : $this;
    }

    /**
     * @param Domain|string $other
     * @return bool
     */
    public function equals(Domain|string $other): bool
    {
        $other = $other instanceof self ? $other : self::tryParse($other);

        return $other !== null && $other->ascii === $this->ascii;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->ascii;
    }

    /**
     * @param string $input
     * @return string
     */
    private static function extractHost(string $input): string
    {
        $value = mb_strtolower(trim($input));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*$#s', '', $value);
        $value = preg_replace('#^.*@#', '', $value);
        $value = preg_replace('#:\d+$#', '', $value);

        return rtrim($value, '.');
    }
}
