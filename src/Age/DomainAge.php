<?php

namespace EduLazaro\Laradomains\Age;

use Carbon\CarbonImmutable;
use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Rdap\RdapClient;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * A domain's age: the registration date over RDAP and, only when asked for, the first Wayback
 * Machine capture for TLDs whose registry publishes no date (.es, .de, .it, .io...).
 *
 * The Wayback fallback is off by default: the CDX server is slow and allows about a dozen
 * requests a minute, which is fine in a queued job and wrong on a request path.
 */
final class DomainAge
{
    /**
     * @param RdapClient $rdap
     */
    public function __construct(private readonly RdapClient $rdap) {}

    /**
     * @param Domain|string $domain
     * @param bool $wayback Fall back to the first Wayback capture.
     * @return Age|null
     */
    public function of(Domain|string $domain, bool $wayback = false): ?Age
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);
        $registration = $this->rdap->lookup($domain);

        if ($registration->registeredAt !== null) {
            return new Age($registration->registeredAt, Age::RDAP);
        }

        if (! $wayback || $registration->registered === false) {
            return null;
        }

        $first = $this->firstCapture($domain);

        return $first === null ? null : new Age($first, Age::WAYBACK);
    }

    /**
     * @param Domain|string $domain
     * @return CarbonImmutable|null
     */
    public function firstCapture(Domain|string $domain): ?CarbonImmutable
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);

        try {
            $response = Http::for('wayback')
                ->retry(2, 2000, throw: false)
                ->get((string) config('laradomains.wayback.endpoint'), [
                    'url' => $domain->registrable() ?? $domain->ascii,
                    'limit' => 1,
                    'output' => 'json',
                    'fl' => 'timestamp',
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $timestamp = $response->successful() ? $response->json('1.0') : null;

        if (! is_string($timestamp)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('YmdHis', $timestamp, 'UTC') ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
