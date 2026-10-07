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
 * that is the cache key. With `age.cache_for` set, complete answers (found or none, every
 * source answered) are kept that long, and the rest (unknown, or found while a source failed)
 * for `age.retry_after` seconds: a spam campaign sends the same new domain in every message,
 * and must not cost one registry request per message. The whole AgeCheck is cached.
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
        return $this->check($domain, $wayback, $timeout, $certificates, $retries)->age;
    }

    /**
     * The age with how it was settled: found, none (a definite answer without a date) or
     * unknown (a lookup failed), the reason and the sources that failed.
     *
     * @param Domain|string $domain
     * @param bool $wayback
     * @param float|null $timeout
     * @param bool $certificates
     * @param int|null $retries
     * @return AgeCheck
     */
    public function check(Domain|string $domain, bool $wayback = false, ?float $timeout = null, bool $certificates = false, ?int $retries = null): AgeCheck
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);
        $store = $this->store();

        if ($store === null) {
            return $this->lookup($domain, $wayback, $timeout, $certificates, $retries);
        }

        $key = 'laradomains.age.'.($wayback ? 'w.' : '').($certificates ? 'c.' : '').($domain->registrable() ?? $domain->ascii);
        $cached = $store->get($key);

        if (is_array($cached) && ($check = AgeCheck::fromArray($cached)) !== null) {
            return $check;
        }

        $check = $this->lookup($domain, $wayback, $timeout, $certificates, $retries);

        // A found age with a failed source may change once that source answers (RDAP down,
        // a date from certificates meanwhile), so it is kept as briefly as a failure.
        $ttl = (int) ($check->definite() && $check->complete()
            ? config('laradomains.age.cache_for')
            : config('laradomains.age.retry_after', 300));

        if ($ttl > 0) {
            $store->put($key, $check->toArray(), $ttl);
        }

        return $check;
    }

    /**
     * RDAP first. Without a registration date, the fallbacks asked for are tried, also when
     * RDAP failed, and the earliest date wins: both are lower bounds. Any date is `found`, with
     * the sources that failed alongside; no date and a failure is `unknown`; `none` only when
     * every source asked answered. Its reason is that of the last source asked.
     *
     * @param Domain $domain
     * @param bool $wayback
     * @param float|null $timeout
     * @param bool $certificates
     * @param int|null $retries
     * @return AgeCheck
     */
    private function lookup(Domain $domain, bool $wayback, ?float $timeout, bool $certificates, ?int $retries): AgeCheck
    {
        $registration = $this->rdap->lookup($domain, $timeout, $retries);

        if ($registration->registeredAt !== null) {
            return AgeCheck::withAge(new Age($registration->registeredAt, Age::RDAP));
        }

        if ($registration->registered === false) {
            return AgeCheck::withoutAge(AgeCheck::NOT_REGISTERED);
        }

        $failed = $registration->failed() ? [Age::RDAP] : [];
        $reason = $registration->supported ? AgeCheck::NO_REGISTRATION_DATE : AgeCheck::UNSUPPORTED_TLD;
        $found = [];

        $fallbacks = array_filter([
            Age::CERTIFICATES => $certificates ? fn () => $this->certificates($domain, $timeout, $retries) : null,
            Age::WAYBACK => $wayback ? fn () => $this->captures($domain, $timeout, $retries) : null,
        ]);

        foreach ($fallbacks as $source => $probe) {
            [$date, $answered] = $probe();

            if (! $answered) {
                $failed[] = $source;
            } elseif ($date !== null) {
                $found[$source] = $date;
            } else {
                $reason = $source === Age::CERTIFICATES ? AgeCheck::NO_CERTIFICATES : AgeCheck::NO_CAPTURES;
            }
        }

        if ($found !== []) {
            asort($found);

            return AgeCheck::withAge(new Age(reset($found), (string) key($found)), $failed);
        }

        return $failed === [] ? AgeCheck::withoutAge($reason) : AgeCheck::lookupFailed($failed);
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
        return $this->certificates($domain instanceof Domain ? $domain : Domain::parse($domain), $timeout, $retries)[0];
    }

    /**
     * The first certificate, and whether crt.sh answered (false: the date is unknown, not absent).
     *
     * @param Domain $domain
     * @param float|null $timeout
     * @param int|null $retries
     * @return array{CarbonImmutable|null, bool}
     */
    private function certificates(Domain $domain, ?float $timeout, ?int $retries): array
    {
        try {
            $response = Http::withRetries(Http::for('certificates', $timeout)->accept('application/json')->withOptions(['stream' => true]), 'certificates', $retries)
                ->get((string) config('laradomains.certificates.endpoint', 'https://crt.sh/'), [
                    'identity' => $domain->registrable() ?? $domain->ascii,
                    'match' => '=',
                    'deduplicate' => 'Y',
                    'output' => 'json',
                ]);
        } catch (ConnectionException) {
            return [null, false];
        }

        if (! $response->successful()) {
            return [null, false];
        }

        // Read as a stream, up to a limit, and pick the dates out of what arrived instead of
        // decoding the whole answer: a big domain has megabytes of certificates. Any certificate
        // proves the domain existed on its date, so the earliest one read is a valid lower
        // bound even when the rest is cut off, and a domain with that many is not new anyway.
        $body = $response->toPsrResponse()->getBody();
        $limit = (int) config('laradomains.certificates.max_bytes', 2_000_000);
        $read = '';
        $complete = true;

        try {
            while (! $body->eof() && strlen($read) < $limit) {
                $read .= $body->read(min(65536, $limit - strlen($read)));
            }
        } catch (Throwable) {
            // A connection dropped halfway still leaves what was read.
            $complete = false;
        } finally {
            $body->close();
        }

        preg_match_all('/"not_before"\s*:\s*"(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $read, $matches);

        if ($matches[1] === []) {
            // An answer cut off before its first certificate says nothing; a whole one, "none".
            return [null, $complete && trim($read) !== ''];
        }

        sort($matches[1]);

        try {
            return [CarbonImmutable::parse($matches[1][0], 'UTC'), true];
        } catch (Throwable) {
            return [null, false];
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
        return $this->captures($domain instanceof Domain ? $domain : Domain::parse($domain), $timeout, $retries)[0];
    }

    /**
     * The first Wayback capture, and whether the CDX server answered.
     *
     * @param Domain $domain
     * @param float|null $timeout
     * @param int|null $retries
     * @return array{CarbonImmutable|null, bool}
     */
    private function captures(Domain $domain, ?float $timeout, ?int $retries): array
    {
        try {
            $response = Http::withRetries(Http::for('wayback', $timeout), 'wayback', $retries)
                ->get((string) config('laradomains.wayback.endpoint'), [
                    'url' => $domain->registrable() ?? $domain->ascii,
                    'limit' => 1,
                    'output' => 'json',
                    'fl' => 'timestamp',
                ]);
        } catch (ConnectionException) {
            return [null, false];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [null, false];
        }

        // An answer with no captures is [] (or only the header row).
        $timestamp = $response->json('1.0');

        if (! is_string($timestamp)) {
            return [null, true];
        }

        try {
            return [CarbonImmutable::createFromFormat('YmdHis', $timestamp, 'UTC') ?: null, true];
        } catch (Throwable) {
            return [null, false];
        }
    }
}
