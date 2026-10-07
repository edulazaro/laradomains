<?php

namespace EduLazaro\Laradomains\Age;

use Carbon\CarbonImmutable;
use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Rdap\RdapClient;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A domain's age: the registration date over RDAP and, only when asked for, two lower bounds
 * for TLDs whose registry publishes no date (.es, .de, .it, .io...): the first certificate in
 * the Certificate Transparency logs and the first Wayback Machine capture.
 *
 * The Wayback fallback is off by default: the CDX server is slow and allows about a dozen
 * requests a minute, which is fine in a queued job and wrong on a request path.
 *
 * Age belongs to the registrable domain, so `a.spam.io` and `b.spam.io` are one question, and
 * that is the cache key. With `age.cache_for` set, definite answers are kept that long and a
 * failed lookup for `age.retry_after` seconds: a spam campaign sends the same new domain in
 * every message, and must not cost one registry request per message.
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
     * @param float|null $timeout Seconds for each request of this call.
     * @param bool $certificates Fall back to the first certificate in the CT logs.
     * @return Age|null Null when the age is unknown, for whatever reason.
     */
    public function of(Domain|string $domain, bool $wayback = false, ?float $timeout = null, bool $certificates = false): ?Age
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);
        $store = $this->store();

        if ($store === null) {
            return $this->lookup($domain, $wayback, $timeout, $certificates)[0];
        }

        $key = 'laradomains.age.'.($wayback ? 'w.' : '').($certificates ? 'c.' : '').($domain->registrable() ?? $domain->ascii);
        $cached = $store->get($key);

        if ($cached !== null) {
            return is_array($cached) ? new Age(CarbonImmutable::parse($cached['since']), $cached['source']) : null;
        }

        [$age, $definite] = $this->lookup($domain, $wayback, $timeout, $certificates);
        $ttl = (int) ($definite ? config('laradomains.age.cache_for') : config('laradomains.age.retry_after', 300));

        if ($ttl > 0) {
            $store->put($key, $age === null ? 'unknown' : ['since' => $age->since->toIso8601String(), 'source' => $age->source], $ttl);
        }

        return $age;
    }

    /**
     * The age, and whether the answer is definite (a date, or a registry saying there is none
     * to give) rather than the result of a lookup that failed. Without a registration date,
     * the fallbacks asked for are tried and the earliest date wins: both are lower bounds.
     *
     * @param Domain $domain
     * @param bool $wayback
     * @param float|null $timeout
     * @param bool $certificates
     * @return array{Age|null, bool}
     */
    private function lookup(Domain $domain, bool $wayback, ?float $timeout, bool $certificates = false): array
    {
        $registration = $this->rdap->lookup($domain, $timeout);

        if ($registration->registeredAt !== null) {
            return [new Age($registration->registeredAt, Age::RDAP), true];
        }

        if ($registration->failed()) {
            return [null, false];
        }

        if ((! $wayback && ! $certificates) || $registration->registered === false) {
            return [null, true];
        }

        $found = array_filter([
            Age::CERTIFICATES => $certificates ? $this->firstCertificate($domain, $timeout) : null,
            Age::WAYBACK => $wayback ? $this->firstCapture($domain, $timeout) : null,
        ]);

        if ($found === []) {
            return [null, false];
        }

        asort($found);

        return [new Age(reset($found), (string) key($found)), true];
    }

    /**
     * The first certificate issued for the registrable domain, from crt.sh's copy of the
     * Certificate Transparency logs: every publicly trusted certificate is logged there, and a
     * phishing domain usually gets one the day it is registered, so this answers "how new is
     * it" for TLDs without RDAP. A domain that never used HTTPS has none.
     *
     * crt.sh is a free service and not always up; a failure is null, never "new".
     *
     * @param Domain|string $domain
     * @param float|null $timeout
     * @return CarbonImmutable|null
     */
    public function firstCertificate(Domain|string $domain, ?float $timeout = null): ?CarbonImmutable
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);

        try {
            $response = Http::withRetries(Http::for('certificates', $timeout)->accept('application/json'), 'certificates')
                ->get((string) config('laradomains.certificates.endpoint', 'https://crt.sh/'), [
                    'identity' => $domain->registrable() ?? $domain->ascii,
                    'match' => '=',
                    'deduplicate' => 'Y',
                    'output' => 'json',
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $entries = $response->successful() ? $response->json() : null;

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $first = null;
        foreach ($entries as $entry) {
            try {
                $date = is_array($entry) && is_string($entry['not_before'] ?? null) ? CarbonImmutable::parse($entry['not_before'], 'UTC') : null;
            } catch (Throwable) {
                $date = null;
            }

            if ($date !== null && ($first === null || $date->lt($first))) {
                $first = $date;
            }
        }

        return $first;
    }

    /**
     * The store for the age cache, or null when caching is off or would keep nothing: an
     * `array` or `null` store forgets everything when the request ends.
     *
     * @return Repository|null
     */
    private function store(): ?Repository
    {
        if (! config('laradomains.age.cache_for')) {
            return null;
        }

        $store = Cache::store(config('laradomains.cache_store'));

        return $store->getStore() instanceof ArrayStore || $store->getStore() instanceof NullStore ? null : $store;
    }

    /**
     * @param Domain|string $domain
     * @param float|null $timeout
     * @return CarbonImmutable|null
     */
    public function firstCapture(Domain|string $domain, ?float $timeout = null): ?CarbonImmutable
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);

        try {
            $response = Http::withRetries(Http::for('wayback', $timeout), 'wayback')
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
