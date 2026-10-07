<?php

namespace EduLazaro\Laradomains\Screen;

use EduLazaro\Laradomains\Dns\DnsClient;
use EduLazaro\Laradomains\Domain;

/**
 * Whether a domain is known for malware, phishing or adult content, asked of Cloudflare's
 * filtering resolvers over DNS: they answer 0.0.0.0 for the names they block. Nothing reaches
 * the domain itself, which is the point when the domain may be hostile.
 *
 * The `family` resolver blocks malware and adult content together, so it is asked only after
 * the `security` one has said the name is not malware. Its adult category is broad (it also
 * catches piracy, cannabis shops and the odd false positive), so treat `adult` as a flag for
 * review rather than as proof.
 */
final class Screen
{
    public const CLEAN = 'clean';

    public const MALWARE = 'malware';

    public const ADULT = 'adult';

    /**
     * @param DnsClient $dns
     */
    public function __construct(private readonly DnsClient $dns) {}

    /**
     * @param Domain|string $domain
     * @param bool $adult Also ask the family resolver.
     * @return string One of the class constants.
     */
    public function check(Domain|string $domain, bool $adult = true): string
    {
        $host = $domain instanceof Domain ? $domain->ascii : Domain::parse($domain)->ascii;

        if ($this->blockedBy((string) config('laradomains.screen.malware'), $host)) {
            return self::MALWARE;
        }

        if ($adult && $this->blockedBy((string) config('laradomains.screen.adult'), $host)) {
            return self::ADULT;
        }

        return self::CLEAN;
    }

    /**
     * @param Domain|string $domain
     * @return bool
     */
    public function isMalware(Domain|string $domain): bool
    {
        return $this->check($domain, adult: false) === self::MALWARE;
    }

    /**
     * @param string $endpoint
     * @param string $host
     * @return bool
     */
    private function blockedBy(string $endpoint, string $host): bool
    {
        return in_array('0.0.0.0', $this->dns->doh($endpoint, $host, 'A', 'screen'), true);
    }
}
