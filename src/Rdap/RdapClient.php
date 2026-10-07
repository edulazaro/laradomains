<?php

namespace EduLazaro\Laradomains\Rdap;

use Carbon\CarbonImmutable;
use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Registration data over RDAP, the protocol that replaced WHOIS.
 *
 * There is no central RDAP database: every registry runs its own server, and IANA publishes
 * which one serves each TLD (the bootstrap file). This client reads that file once a week and
 * asks the registry directly, so a lookup is one request and no relay sees the names asked
 * about. A relay (rdap.org by default) is used only for TLDs the bootstrap does not list.
 *
 * Lookups are made for the registrable domain, since a registry knows `bbc.co.uk`, not
 * `news.bbc.co.uk`.
 */
final class RdapClient
{
    private const CACHE_KEY = 'laradomains.rdap.bootstrap';

    /**
     * @param Domain|string $domain
     * @return Registration
     */
    public function lookup(Domain|string $domain): Registration
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);
        $name = $domain->registrable() ?? $domain->ascii;

        try {
            $server = $this->serverFor($domain->tld());
        } catch (Throwable $e) {
            return new Registration($name, supported: true, error: 'RDAP bootstrap unavailable: '.$e->getMessage());
        }

        $relay = $server === null;
        $server ??= config('laradomains.rdap.fallback');

        if ($server === null || $server === '') {
            return new Registration($name, supported: false);
        }

        try {
            $response = Http::for('rdap')
                ->accept('application/rdap+json')
                ->get(rtrim($server, '/').'/domain/'.$name);
        } catch (ConnectionException $e) {
            return new Registration($name, supported: true, error: $e->getMessage(), server: $server);
        }

        if ($response->status() === 404) {
            // A relay answers 404 both for unknown names and for TLDs it cannot route.
            return $relay
                ? new Registration($name, supported: false, server: $server)
                : new Registration($name, supported: true, registered: false, server: $server);
        }

        if ($response->failed()) {
            return new Registration($name, supported: true, error: "HTTP {$response->status()}", server: $server);
        }

        return $this->registration($name, $response, $server);
    }

    /**
     * The RDAP base URL for a TLD, or null when IANA lists none.
     *
     * @param string $tld
     * @return string|null
     */
    public function serverFor(string $tld): ?string
    {
        $store = Cache::store(config('laradomains.cache_store'));
        $map = $store->get(self::CACHE_KEY);

        if (! is_array($map)) {
            $map = $this->bootstrap();
            $store->put(self::CACHE_KEY, $map, now()->addWeek());
        }

        return $map[strtolower($tld)] ?? null;
    }

    /**
     * @return array<string, string> TLD => server URL (https preferred)
     */
    private function bootstrap(): array
    {
        $services = Http::for('rdap')
            ->get((string) config('laradomains.rdap.bootstrap'))
            ->throw()
            ->json('services', []);

        $map = [];
        foreach ($services as [$tlds, $urls]) {
            $url = collect($urls)->first(fn (string $u) => str_starts_with($u, 'https://')) ?? $urls[0];
            foreach ($tlds as $tld) {
                $map[strtolower($tld)] = $url;
            }
        }

        return $map;
    }

    /**
     * @param string $name
     * @param Response $response
     * @param string $server
     * @return Registration
     */
    private function registration(string $name, Response $response, string $server): Registration
    {
        $events = collect($response->json('events', []))->pluck('eventDate', 'eventAction');
        $registrar = collect($response->json('entities', []))
            ->first(fn ($entity) => in_array('registrar', $entity['roles'] ?? [], true));
        $abuse = collect($registrar['entities'] ?? [])
            ->first(fn ($entity) => in_array('abuse', $entity['roles'] ?? [], true));

        return new Registration(
            domain: $name,
            supported: true,
            registered: true,
            registeredAt: $this->date($events['registration'] ?? null),
            expiresAt: $this->date($events['expiration'] ?? null),
            updatedAt: $this->date($events['last changed'] ?? null),
            registrar: $this->vcard($registrar, 'fn') ?? data_get($registrar, 'handle'),
            registrarUrl: collect($registrar['links'] ?? [])->firstWhere('rel', 'about')['href'] ?? null,
            registrarIanaId: collect($registrar['publicIds'] ?? [])->firstWhere('type', 'IANA Registrar ID')['identifier'] ?? null,
            abuseEmail: $this->vcard($abuse, 'email'),
            status: array_values((array) $response->json('status', [])),
            nameservers: collect($response->json('nameservers', []))->pluck('ldhName')->filter()->map(fn ($n) => strtolower($n))->values()->all(),
            dnssec: $response->json('secureDNS.delegationSigned'),
            server: $server,
        );
    }

    /**
     * Value of a property in an RDAP jCard: ["vcard", [[name, params, type, value], ...]].
     *
     * @param array<string, mixed>|null $entity
     * @param string $property
     * @return string|null
     */
    private function vcard(?array $entity, string $property): ?string
    {
        foreach (data_get($entity, 'vcardArray.1', []) as $entry) {
            if (($entry[0] ?? null) === $property && is_string($entry[3] ?? null) && $entry[3] !== '') {
                return $entry[3];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return CarbonImmutable|null
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
