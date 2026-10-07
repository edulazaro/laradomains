<?php

namespace EduLazaro\Laradomains\Screen;

use EduLazaro\Laradomains\Dns\DnsClient;
use EduLazaro\Laradomains\Domain;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Whether a domain is known for malware, phishing or adult content, asked of Cloudflare's
 * filtering resolvers over DNS: they answer 0.0.0.0 for the names they block. Nothing reaches
 * the domain itself, which is the point when the domain may be hostile.
 *
 * Fails closed: when a resolver cannot be asked (timeout, HTTP error, SERVFAIL) its part of the
 * verdict is unknown, never clean. Both resolvers are asked in parallel, so the wait is the
 * slower of the two, not their sum.
 *
 * The adult category is broad (it also catches piracy, cannabis shops and the odd false
 * positive), so treat ADULT as a flag for review rather than as proof.
 *
 * With `screen.cache_for` set, complete verdicts are kept that long per host and incomplete
 * ones for `screen.retry_after` seconds, in a persistent cache store.
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
     * @param float|null $timeout Seconds for the resolvers; otherwise `timeouts.screen`.
     * @param int|null $retries Rounds after the first; otherwise `retries.screen`.
     * @return string One of the class constants.
     */
    public function check(Domain|string $domain, bool $adult = true, ?float $timeout = null, ?int $retries = null): string
    {
        return $this->verdict($domain, $adult, $timeout, $retries)->decision();
    }

    /**
     * Both answers, each on its own.
     *
     * @param Domain|string $domain
     * @param bool $adult
     * @param float|null $timeout
     * @param int|null $retries
     * @return Verdict
     */
    public function verdict(Domain|string $domain, bool $adult = true, ?float $timeout = null, ?int $retries = null): Verdict
    {
        $host = $domain instanceof Domain ? $domain->ascii : Domain::parse($domain)->ascii;
        $store = $this->store();
        $key = 'laradomains.screen.'.($adult ? 'a.' : 'm.').$host;

        if ($store !== null && is_array($cached = $store->get($key))) {
            return Verdict::fromArray($cached);
        }

        $endpoints = ['malware' => (string) config('laradomains.screen.malware')];

        if ($adult) {
            $endpoints['adult'] = (string) config('laradomains.screen.adult');
        }

        $answers = $this->dns->queryEach($endpoints, $host, 'A', 'screen', $timeout, $retries);
        $blocked = fn (?array $records) => $records === null ? null : in_array('0.0.0.0', $records, true);

        $verdict = new Verdict($host, $blocked($answers['malware']), $adult ? $blocked($answers['adult']) : null, $adult);

        if ($store !== null) {
            $ttl = (int) ($verdict->complete() ? config('laradomains.screen.cache_for') : config('laradomains.screen.retry_after', 60));

            if ($ttl > 0) {
                $store->put($key, $verdict->toArray(), $ttl);
            }
        }

        return $verdict;
    }

    /**
     * True for malware or phishing, false when checked and clean, null when it could not be
     * checked. Only the malware resolver is asked.
     *
     * @param Domain|string $domain
     * @param float|null $timeout
     * @param int|null $retries
     * @return bool|null
     */
    public function isMalware(Domain|string $domain, ?float $timeout = null, ?int $retries = null): ?bool
    {
        return $this->verdict($domain, adult: false, timeout: $timeout, retries: $retries)->malware;
    }

    /**
     * @return Repository|null
     */
    private function store(): ?Repository
    {
        if (! config('laradomains.screen.cache_for')) {
            return null;
        }

        $store = Cache::store(config('laradomains.cache_store'));

        return $store->getStore() instanceof ArrayStore || $store->getStore() instanceof NullStore ? null : $store;
    }
}
