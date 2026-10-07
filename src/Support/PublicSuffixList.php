<?php

namespace EduLazaro\Laradomains\Support;

use RuntimeException;

/**
 * The Public Suffix List, and the one question it answers: where does the part of a name that
 * somebody can register start?
 *
 * The list has two sections. ICANN suffixes are the ones a registry sells under (com, co.uk,
 * gob.es); private ones are run by companies that hand out subdomains (github.io, blogspot.com).
 * `x.blogspot.com` is therefore a site of its own for the private view, but its registrable
 * domain at the registry is `blogspot.com`, which is what RDAP needs.
 *
 * Rules are kept in ASCII (punycode), the form every Domain carries.
 */
final class PublicSuffixList
{
    /** @var array<string, self> */
    private static array $loaded = [];

    /**
     * @param array<string, bool> $rules rule => is private
     * @param array<string, bool> $exceptions
     */
    private function __construct(
        private readonly array $rules,
        private readonly array $exceptions,
    ) {}

    /**
     * The list in use: the downloaded copy when there is one, the bundled one otherwise.
     *
     * @return self
     */
    public static function instance(): self
    {
        $path = function_exists('config') && function_exists('app') && app()->bound('config')
            ? (string) config('laradomains.public_suffix_list')
            : '';

        if ($path === '' || ! is_file($path)) {
            $path = self::bundledPath();
        }

        return self::$loaded[$path] ??= self::fromFile($path);
    }

    /**
     * @return string
     */
    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2).'/resources/public_suffix_list.dat';
    }

    /**
     * Forget the parsed lists, so the next call reads the file again (after an update).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$loaded = [];
    }

    /**
     * @param string $path
     * @return self
     */
    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read the Public Suffix List at {$path}");
        }

        return self::fromString($contents);
    }

    /**
     * @param string $contents
     * @return self
     */
    public static function fromString(string $contents): self
    {
        $rules = [];
        $exceptions = [];
        $private = false;

        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim($line);

            if (str_contains($line, '===BEGIN PRIVATE DOMAINS===')) {
                $private = true;
            }

            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            $rule = strtolower(preg_split('/\s/', $line)[0]);
            $exception = str_starts_with($rule, '!');
            $ascii = self::toAscii(ltrim($rule, '!'));

            if ($ascii === null) {
                continue;
            }

            if ($exception) {
                $exceptions[$ascii] = $private;
            } else {
                $rules[$ascii] = $private;
            }
        }

        return new self($rules, $exceptions);
    }

    /**
     * The public suffix of an ASCII host, following the list's algorithm: an exception rule
     * wins, then the matching rule with most labels, and with no match at all the last label
     * (the "*" default rule).
     *
     * @param string $host
     * @param bool $private Whether private suffixes (github.io) count as suffixes.
     * @return string
     */
    public function suffix(string $host, bool $private = false): string
    {
        $labels = explode('.', $host);
        $count = count($labels);

        for ($i = 0; $i < $count; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            if (isset($this->exceptions[$candidate]) && ($private || ! $this->exceptions[$candidate])) {
                return implode('.', array_slice($labels, $i + 1));
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $wildcard = $i + 1 < $count ? '*.'.implode('.', array_slice($labels, $i + 1)) : null;

            foreach ([$candidate, $wildcard] as $rule) {
                if ($rule !== null && isset($this->rules[$rule]) && ($private || ! $this->rules[$rule])) {
                    return $candidate;
                }
            }
        }

        return $labels[$count - 1];
    }

    /**
     * The suffix plus one label, or null when the host is itself a public suffix.
     *
     * @param string $host
     * @param bool $private
     * @return string|null
     */
    public function registrable(string $host, bool $private = false): ?string
    {
        $suffix = $this->suffix($host, $private);

        if ($suffix === $host) {
            return null;
        }

        $rest = substr($host, 0, -strlen($suffix) - 1);
        $label = substr($rest, (int) strrpos('.'.$rest, '.'));

        return $label.'.'.$suffix;
    }

    /**
     * @param string $rule
     * @return string|null
     */
    private static function toAscii(string $rule): ?string
    {
        if (preg_match('/^[a-z0-9.*-]+$/', $rule)) {
            return $rule;
        }

        $ascii = idn_to_ascii($rule, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : $ascii;
    }
}
