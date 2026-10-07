<?php

namespace EduLazaro\Laradomains\Screen;

use EduLazaro\Laradomains\Dns\DnsClient;
use EduLazaro\Laradomains\Domain;

/**
 * Whether a domain is known for malware, phishing or adult content, asked of Cloudflare's
 * filtering resolvers over DNS: they answer 0.0.0.0 for the names they block. Nothing reaches
 * the domain itself, which is the point when the domain may be hostile.
 *
 * Fails closed: when a resolver cannot be asked (timeout, HTTP error, SERVFAIL) the answer is
 * UNKNOWN, never CLEAN. "Could not look" and "looked and found nothing" are different answers,
 * and a caller that blocks on MALWARE has to decide what UNKNOWN means for it.
 *
 * The `family` resolver blocks malware and adult content together, so it is asked only after
 * the `security` one has said the name is not malware. Its adult category is broad (it also
 * catches piracy, cannabis shops and the odd false positive), so treat ADULT as a flag for
 * review rather than as proof.
 */
final class Screen
{
    public const CLEAN = 'clean';

    public const MALWARE = 'malware';

    public const ADULT = 'adult';

    public const UNKNOWN = 'unknown';

    /**
     * @param DnsClient $dns
     */
    public function __construct(private readonly DnsClient $dns) {}

    /**
     * @param Domain|string $domain
     * @param bool $adult Also ask the family resolver.
     * @param float|null $timeout Seconds for each resolver; otherwise `timeouts.screen`.
     * @return string One of the class constants.
     */
    public function check(Domain|string $domain, bool $adult = true, ?float $timeout = null): string
    {
        $host = $domain instanceof Domain ? $domain->ascii : Domain::parse($domain)->ascii;

        $malware = $this->blockedBy((string) config('laradomains.screen.malware'), $host, $timeout);

        if ($malware !== false) {
            return $malware === true ? self::MALWARE : self::UNKNOWN;
        }

        if (! $adult) {
            return self::CLEAN;
        }

        return match ($this->blockedBy((string) config('laradomains.screen.adult'), $host, $timeout)) {
            true => self::ADULT,
            false => self::CLEAN,
            null => self::UNKNOWN,
        };
    }

    /**
     * True for malware or phishing, false when checked and clean, null when it could not be
     * checked.
     *
     * @param Domain|string $domain
     * @param float|null $timeout
     * @return bool|null
     */
    public function isMalware(Domain|string $domain, ?float $timeout = null): ?bool
    {
        return match ($this->check($domain, adult: false, timeout: $timeout)) {
            self::MALWARE => true,
            self::CLEAN => false,
            default => null,
        };
    }

    /**
     * @param string $endpoint
     * @param string $host
     * @param float|null $timeout
     * @return bool|null Null when the resolver did not answer.
     */
    private function blockedBy(string $endpoint, string $host, ?float $timeout): ?bool
    {
        $answers = $this->dns->query($endpoint, $host, 'A', 'screen', $timeout);

        return $answers === null ? null : in_array('0.0.0.0', $answers, true);
    }
}
