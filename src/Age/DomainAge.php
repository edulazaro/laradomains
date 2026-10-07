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
     * @param int|null $retries For each request of this call; otherwise each service's config.
     * @return Age|null Null when the age is unknown, for whatever reason.
     */
    public function of(Domain|string $domain, bool $wayback = false, ?float $timeout = null, bool $certificates = false, ?int $retries = null): ?Age
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);
        $store = $this->store();

        if ($store === null) {
            return $this->lookup($domain, $wayback, $timeout, $certificates, $retries)[0];
        }

        $key = 'laradomains.age.'.($wayback ? 'w.' : '').($certificates ? 'c.' : '').($domain->registrable() ?? $domain->ascii);
        $cached = $store->get($key);

        if ($cached !== null) {
            return is_array($cached) ? new Age(CarbonImmutable::parse($cached['since']), $cached['source']) : null;
        }

        [$age, $definite] = $this->lookup($domain, $wayback, $timeout, $certificates, $retries);
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
     * @param int|null $retries
     * @return array{Age|null, bool}
     */
    private function lookup(Domain $domain, bool $wayback, ?float $timeout, bool $certificates = false, ?int $retries = null): array
    {
        $registration = $this->rdap->lookup($domain, $timeout, $retries);

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
            Age::CERTIFICATES => $certificates ? $this->firstCertificate($domain, $timeout, $retries) : null,
            Age::WAYBACK => $wayback ? $this->firstCapture($domain, $timeout, $retries) : null,
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
     * @param int|null $retries
     * @return CarbonImmutable|null
     */
    public function firstCertificate(Domain|string $domain, ?float $timeout = null, ?int $retries = null): ?CarbonImmutable
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);

        try {
            $response = Http::withRetries(Http::for('certificates', $timeout)->accept('application/json')->withOptions(['stream' => true]), 'certificates', $retries)
                ->get((string) config('laradomains.certificates.endpoint', 'https://crt.sh/'), [
                    'identity' => $domain->registrable() ?? $domain->ascii,
                    'match' => '=',
                    'deduplicate' => 'Y',
                    'output' => 'json',
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // Read as a stream, up to a limit, and pick the dates out of what arrived instead of
        // decoding the whole answer: a big domain has megabytes of certificates. Any certificate
        // proves the domain existed on its date, so the earliest one read is a valid lower
        // bound even when the rest is cut off, and a domain with that many is not new anyway.
        $body = $response->toPsrResponse()->getBody();
        $limit = (int) config('laradomains.certificates.max_bytes', 2_000_000);
        $read = '';

        try {
            while (! $body->eof() && strlen($read) < $limit) {
                $read .= $body->read(min(65536, $limit - strlen($read)));
            }
        } catch (Throwable) {
            // A connection dropped halfway still leaves what was read.
        } finally {
            $body->close();
        }

        preg_match_all('/"not_before"\s*:\s*"(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $read, $matches);

        if ($matches[1] === []) {
            return null;
        }

        sort($matches[1]);

        try {
            return CarbonImmutable::parse($matches[1][0], 'UTC');
        } catch (Throwable) {
            return null;
        }
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
     * @param int|null $retries
     * @return CarbonImmutable|null
     */
    public function firstCapture(Domain|string $domain, ?float $timeout = null, ?int $retries = null): ?CarbonImmutable
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);

        try {
            $response = Http::withRetries(Http::for('wayback', $timeout), 'wayback', $retries)
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
