<?php

namespace EduLazaro\Laradomains\Rdap;

use Carbon\CarbonImmutable;
use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Registration data over RDAP, the protocol that replaced WHOIS.
 *
 * There is no central RDAP database: every registry runs its own server, and IANA publishes
 * which one serves each TLD (the bootstrap file). The package ships a copy of that file and
 * `laradomains:update` refreshes it, so a lookup is one request straight to the registry, with
 * no cache needed and no relay seeing the names asked about. A relay (rdap.org by default) is
 * used only for TLDs the bootstrap does not list.
 *
 * Lookups are made for the registrable domain, since a registry knows `bbc.co.uk`, not
 * `news.bbc.co.uk`.
 */
final class RdapClient
{
    /** @var array<string, array<string, string>> bootstrap file => TLD map */
    private static array $maps = [];

    /**
     * @param Domain|string $domain
     * @param float|null $timeout Seconds for this lookup; otherwise `timeouts.rdap`.
     * @return Registration
     */
    public function lookup(Domain|string $domain, ?float $timeout = null): Registration
    {
        $domain = $domain instanceof Domain ? $domain : Domain::parse($domain);
        $name = $domain->registrable() ?? $domain->ascii;

        $server = $this->serverFor($domain->tld());

        $relay = $server === null;
        $server ??= config('laradomains.rdap.fallback');

        if ($server === null || $server === '') {
            return new Registration($name, supported: false);
        }

        try {
            $response = Http::withRetries(Http::for('rdap', $timeout)->accept('application/rdap+json'), 'rdap')
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
        $path = (string) config('laradomains.rdap.bootstrap_path');

        if ($path === '' || ! is_file($path)) {
            $path = self::bundledPath();
        }

        return (self::$maps[$path] ??= self::map((string) file_get_contents($path)) ?? [])[strtolower($tld)] ?? null;
    }

    /**
     * @return string
     */
    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2).'/resources/rdap_dns.json';
    }

    /**
     * Forget the loaded bootstrap files, so the next lookup reads them again (after an update).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$maps = [];
    }

    /**
     * TLD => server URL (https preferred) from the contents of an IANA bootstrap file. Null when
     * the contents are not a bootstrap file at all, which the update command uses as a check.
     *
     * @param string $json
     * @return array<string, string>|null
     */
    public static function map(string $json): ?array
    {
        $services = json_decode($json, true)['services'] ?? null;

        if (! is_array($services) || $services === []) {
            return null;
        }

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
