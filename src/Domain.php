<?php

namespace EduLazaro\Laradomains;

use EduLazaro\Laradomains\Support\Confusables;
use EduLazaro\Laradomains\Support\PublicSuffixList;
use EduLazaro\Laradomains\Support\Typos;
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
     * Whether a reader could take this name for another one. Two cases: a label that mixes
     * scripts (`аpple.com` with a Cyrillic а), and a label written wholly in Cyrillic or Greek
     * whose every letter has a Latin twin (`аррӏе.com`), unless the TLD belongs to that script.
     * Accented names in one script (`ñandú.es`, `münchen.de`) and real words in another script
     * (`яндекс.com`, `日本語.jp`) are not suspicious.
     *
     * @return bool
     */
    public function isLookalike(): bool
    {
        if (! $this->isIdn()) {
            return false;
        }

        $labels = explode('.', $this->unicode);
        $tld = array_pop($labels);

        foreach ($labels as $label) {
            if (Confusables::isWholeScriptLookalike($label, $this->tld())) {
                return true;
            }
        }

        if (! class_exists(\Spoofchecker::class)) {
            return false;
        }

        // Highly restrictive: one script per label, or Latin plus the CJK combinations real
        // names use. ICU's older confusable checks no longer flag mixed labels.
        $checker = new \Spoofchecker;
        $checker->setRestrictionLevel(\Spoofchecker::HIGHLY_RESTRICTIVE);

        foreach ([...$labels, $tld] as $label) {
            if ($checker->isSuspicious($label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How the name reads in Latin letters: `аррӏе.com` gives `apple.com`. Letters without a
     * Latin twin are kept, so a real Cyrillic word stays Cyrillic.
     *
     * @return string
     */
    public function skeleton(): string
    {
        return Confusables::skeleton($this->unicode);
    }

    /**
     * The brand this name passes for, or null: a different domain whose skeleton matches one of
     * the given ones, also after swapping 0 for o and 1 for l (`paypa1.com`), or that Unicode's
     * confusables data says reads the same (`gօօgle.com` with Armenian օ, `paypɑl.com`). `www`
     * is ignored on both sides.
     *
     * @param iterable<string> $brands Domains such as "paypal.com".
     * @return string|null
     */
    public function imitates(iterable $brands): ?string
    {
        $self = $this->withoutWww();
        $mine = [$self->skeleton(), strtr($self->skeleton(), ['0' => 'o', '1' => 'l'])];
        $checker = $self->isIdn() && class_exists(\Spoofchecker::class) ? new \Spoofchecker : null;

        foreach ($brands as $brand) {
            $target = self::tryParse($brand)?->withoutWww();

            if ($target === null || $target->ascii === $self->ascii) {
                continue;
            }

            if (in_array($target->unicode, $mine, true) || in_array(strtr($target->unicode, ['0' => 'o', '1' => 'l']), $mine, true)) {
                return $target->ascii;
            }

            // Unicode's full confusables data, for the scripts the table above leaves out:
            // Armenian (`gօօgle`), dotless ı, Latin alpha (`paypɑl`) and the rest.
            if ($checker !== null && $checker->areConfusable($self->unicode, $target->unicode)) {
                return $target->ascii;
            }
        }

        return null;
    }

    /**
     * The brand this host trades on without being it, or null. Covers what imitates() does
     * (a copy of the whole name) plus the usual phishing shapes: the brand as a label of
     * someone else's domain (`paypal.com.secure-login.io`) or as a hyphenated part of it
     * (`paypal-secure.com`, `secure-paypa1.com`). The brand's own domain and its subdomains
     * (`www.paypal.com`, `paypal.co.uk` if listed) never match.
     *
     * Matching is on whole parts, not substrings, so `paypalooza.com` is left alone; and brand
     * names shorter than four letters only match as full copies, since `bbc` or `x` turn up
     * inside ordinary names.
     *
     * @param iterable<string> $brands Domains such as "paypal.com".
     * @return string|null
     */
    public function impersonates(iterable $brands): ?string
    {
        $brands = is_array($brands) ? $brands : iterator_to_array($brands, false);

        if ($copied = $this->imitates($brands)) {
            return $copied;
        }

        $mine = $this->registrable() ?? $this->ascii;
        $skeleton = $this->skeleton();
        $parts = array_unique([
            ...preg_split('/[.-]/', $skeleton),
            ...preg_split('/[.-]/', strtr($skeleton, ['0' => 'o', '1' => 'l'])),
        ]);

        foreach ($brands as $brand) {
            $target = self::tryParse($brand);
            $theirs = $target?->registrable() ?? $target?->ascii;

            if ($target === null || $theirs === $mine) {
                continue;
            }

            $name = explode('.', $theirs)[0];

            if (strlen($name) >= 4 && in_array($name, $parts, true)) {
                return $target->withoutWww()->ascii;
            }
        }

        return null;
    }

    /**
     * The brand this name is one typing slip away from, or null: a letter added (`paypall`),
     * dropped (`payal`), swapped with its neighbour (`paypla`), replaced by a lookalike
     * (`goog1e`), or a pair of letters that reads as one (`arnazon` for amazon). Compares the
     * registrable name, so `paypall.com` and `paypall.net` both match `paypal.com`.
     *
     * Only for brand names of five letters or more: shorter names sit one slip away from too
     * many ordinary words. A weaker signal than imitates(), good for review rather than block;
     * typosquat() says which kind of slip it was, and `$kinds` keeps only some kinds.
     *
     * @param iterable<string> $brands Domains such as "paypal.com".
     * @param list<string>|null $kinds Typosquat kinds to accept; null for all.
     * @return string|null
     */
    public function typosquats(iterable $brands, ?array $kinds = null): ?string
    {
        return $this->typosquat($brands, $kinds)?->brand;
    }

    /**
     * Like typosquats(), with the kind of slip: Typosquat::LOOKALIKE (strong), SWAP (medium),
     * EXTRA or MISSING (weak, where ordinary words such as `apples` land).
     *
     * @param iterable<string> $brands
     * @param list<string>|null $kinds
     * @return Typosquat|null
     */
    public function typosquat(iterable $brands, ?array $kinds = null): ?Typosquat
    {
        $mine = $this->registrable() ?? $this->ascii;
        $name = explode('.', Confusables::skeleton((string) idn_to_utf8($mine, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46) ?: $mine))[0];

        foreach ($brands as $brand) {
            $target = self::tryParse($brand);
            $theirs = $target?->registrable() ?? $target?->ascii;

            if ($target === null || $theirs === $mine) {
                continue;
            }

            $brandName = explode('.', $theirs)[0];
            $kind = strlen($brandName) >= 5 ? Typos::slip($name, $brandName) : null;

            if ($kind !== null && ($kinds === null || in_array($kind, $kinds, true))) {
                return new Typosquat($target->withoutWww()->ascii, $kind);
            }
        }

        return null;
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
        // Browsers treat "\" as "/" in web URLs (WHATWG URL standard), so it ends the host:
        // `https://evil.example\@paypal.com` goes to evil.example, and must parse as such.
        $value = str_replace('\\', '/', mb_strtolower(trim($input)));
        // A web scheme may come without slashes (`http:evil.example`) and browsers still go
        // to the host; other schemes only count when followed by a slash.
        $value = preg_replace('#^(?:(?:https?|wss?|ftp):|[a-z][a-z0-9+.-]*:(?=/))#', '', $value);
        $value = ltrim($value, '/');
        $value = preg_replace('#[/?\#].*$#s', '', $value);
        $value = preg_replace('#^.*@#', '', $value);
        $value = preg_replace('#:\d+$#', '', $value);

        return rtrim($value, '.');
    }
}
