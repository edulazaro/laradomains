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
 * A domain's age: the registration date over RDAP and, only when asked for, the first Wayback
 * Machine capture for TLDs whose registry publishes no date (.es, .de, .it, .io...).
 *
 * The Wayback fallback is off by default: the CDX server is slow and allows about a dozen
 * requests a minute, which is fine in a queued job and wrong on a request path. With
 * `age.cache_for` set, each answer is remembered in a persistent cache store.
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
        $store = $this->store();

        if ($store === null) {
            return $this->lookup($domain, $wayback);
        }

        // Only found ages are kept: a lookup that failed this time should be asked again, and
        // a TLD without RDAP costs no request anyway (the bootstrap is read from disk).
        $key = 'laradomains.age.'.($wayback ? 'w.' : '').$domain->ascii;

        if (is_array($cached = $store->get($key))) {
            return new Age(CarbonImmutable::parse($cached['since']), $cached['source']);
        }

        $age = $this->lookup($domain, $wayback);

        if ($age !== null) {
            $store->put($key, ['since' => $age->since->toIso8601String(), 'source' => $age->source], (int) config('laradomains.age.cache_for'));
        }

        return $age;
    }

    /**
     * @param Domain $domain
     * @param bool $wayback
     * @return Age|null
     */
    private function lookup(Domain $domain, bool $wayback): ?Age
    {
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
     * @return CarbonImmutable|null
     */
    public function firstCapture(Domain|string $domain): ?CarbonImmutable
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);

        try {
            $response = Http::for('wayback')
                ->retry((int) config('laradomains.wayback.retries', 2), (int) config('laradomains.wayback.retry_delay', 5000), throw: false)
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
