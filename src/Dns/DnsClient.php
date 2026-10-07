<?php

namespace EduLazaro\Laradomains\Dns;

use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http as Client;

/**
 * DNS lookups, by default over HTTPS (the JSON API Cloudflare and Google expose), so the answer
 * does not depend on the resolver of the machine running the code. The `system` driver uses
 * PHP's dns_get_record() instead.
 *
 * A failed lookup and a name with no records both come back as an empty list: for the
 * questions these describe (does it resolve, what TXT does it publish) the caller acts the
 * same way. acceptsMail() and query() keep the two apart: null is "could not tell".
 */
final class DnsClient
{
    /** @var array<string, int> DNS record type codes, as the JSON API returns them. */
    private const TYPES = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28];

    /**
     * Raw record data of one type: IPs for A/AAAA, host names for NS/CNAME, "priority host"
     * for MX and the joined string for TXT.
     *
     * @param Domain|string $domain
     * @param string $type A, AAAA, NS, CNAME, MX or TXT.
     * @return list<string>
     */
    public function records(Domain|string $domain, string $type): array
    {
        $host = $domain instanceof Domain ? $domain->ascii : Domain::parse($domain)->ascii;
        $type = strtoupper($type);

        return $this->lookup($host, $type) ?? [];
    }

    /**
     * Records of one type with the configured driver, or null when the lookup failed.
     *
     * @param string $host
     * @param string $type
     * @param float|null $timeout
     * @param int|null $retries
     * @return list<string>|null
     */
    private function lookup(string $host, string $type, ?float $timeout = null, ?int $retries = null): ?array
    {
        return config('laradomains.dns.driver') === 'system'
            ? $this->system($host, $type)
            : $this->query((string) config('laradomains.dns.doh_endpoint'), $host, $type, 'dns', $timeout, $retries);
    }

    /**
     * IPv4 and IPv6 addresses.
     *
     * @param Domain|string $domain
     * @return list<string>
     */
    public function addresses(Domain|string $domain): array
    {
        return [...$this->records($domain, 'A'), ...$this->records($domain, 'AAAA')];
    }

    /**
     * @param Domain|string $domain
     * @return bool
     */
    public function resolves(Domain|string $domain): bool
    {
        return $this->records($domain, 'A') !== [] || $this->records($domain, 'AAAA') !== [];
    }

    /**
     * @param Domain|string $domain
     * @return list<string>
     */
    public function nameservers(Domain|string $domain): array
    {
        return array_map(fn (string $ns) => strtolower(rtrim($ns, '.')), $this->records($domain, 'NS'));
    }

    /**
     * @param Domain|string $domain
     * @return list<string>
     */
    public function txt(Domain|string $domain): array
    {
        return $this->records($domain, 'TXT');
    }

    /**
     * Mail exchangers, lowest priority first.
     *
     * @param Domain|string $domain
     * @return list<string>
     */
    public function mx(Domain|string $domain): array
    {
        return $this->exchangers($this->records($domain, 'MX'));
    }

    /**
     * @param list<string> $records "priority host" pairs.
     * @return list<string>
     */
    private function exchangers(array $records): array
    {
        usort($records, fn (string $a, string $b) => (int) $a <=> (int) $b);

        return array_values(array_map(fn (string $r) => strtolower(rtrim(preg_replace('/^\d+\s+/', '', $r), '.')), $records));
    }

    /**
     * Whether the domain takes mail, as a mail server would decide it (RFC 5321 §5.1): its MX
     * hosts, or with no MX at all the domain's own address. A null MX (RFC 7505, a single
     * "0 .") refuses mail. Null when a lookup failed, so "could not tell" is never "no".
     *
     * @param Domain|string $domain
     * @param float|null $timeout Seconds for each lookup; otherwise `timeouts.dns`.
     * @param int|null $retries For each lookup; otherwise `retries.dns`.
     * @return bool|null
     */
    public function acceptsMail(Domain|string $domain, ?float $timeout = null, ?int $retries = null): ?bool
    {
        $host = $domain instanceof Domain ? $domain->ascii : Domain::parse($domain)->ascii;
        $mx = $this->lookup($host, 'MX', $timeout, $retries);

        if ($mx === null) {
            return null;
        }

        if ($mx !== []) {
            return array_filter($this->exchangers($mx), fn (string $exchanger) => $exchanger !== '') !== [];
        }

        // No MX: mail goes to the address of the domain itself (the implicit MX).
        $a = $this->lookup($host, 'A', $timeout, $retries);

        if ($a !== null && $a !== []) {
            return true;
        }

        $aaaa = $this->lookup($host, 'AAAA', $timeout, $retries);

        if ($aaaa !== null && $aaaa !== []) {
            return true;
        }

        return $a === null || $aaaa === null ? null : false;
    }

    /**
     * A DNS-over-HTTPS lookup that tells failure from emptiness: the records (possibly none,
     * for a name that does not exist), or null when the resolver could not be asked or did
     * not answer properly (timeout, HTTP error, SERVFAIL). Callers that decide something on
     * the answer, such as screening, must not read null as "no records".
     *
     * @param string $endpoint
     * @param string $host
     * @param string $type
     * @param string $service
     * @param float|null $timeout
     * @param int|null $retries
     * @return list<string>|null
     */
    public function query(string $endpoint, string $host, string $type, string $service = 'dns', ?float $timeout = null, ?int $retries = null): ?array
    {
        try {
            $response = Http::withRetries(Http::for($service, $timeout)->accept('application/dns-json'), $service, $retries)
                ->get($endpoint, ['name' => $host, 'type' => $type]);
        } catch (ConnectionException) {
            return null;
        }

        return $this->parse($response, $type);
    }

    /**
     * The same question to several resolvers at once, in parallel: key => records, or null for
     * a resolver that did not answer. Unanswered ones are asked again, as a group, up to the
     * service's retries.
     *
     * @param array<string, string> $endpoints key => resolver URL
     * @param string $host
     * @param string $type
     * @param string $service
     * @param float|null $timeout
     * @param int|null $retries Rounds after the first; otherwise the service's config.
     * @return array<string, list<string>|null>
     */
    public function queryEach(array $endpoints, string $host, string $type, string $service = 'dns', ?float $timeout = null, ?int $retries = null): array
    {
        [$retries, $delay] = Http::retryPolicy($service, $retries);
        $results = array_fill_keys(array_keys($endpoints), null);
        $pending = $endpoints;

        for ($attempt = 0; $pending !== [] && $attempt <= $retries; $attempt++) {
            if ($attempt > 0 && $delay > 0) {
                usleep($delay * 1000);
            }

            $responses = Client::pool(function (Pool $pool) use ($pending, $host, $type, $service, $timeout) {
                foreach ($pending as $key => $endpoint) {
                    Http::configure($pool->as($key), $service, $timeout)
                        ->accept('application/dns-json')
                        ->get($endpoint, ['name' => $host, 'type' => $type]);
                }
            });

            foreach ($pending as $key => $endpoint) {
                $response = $responses[$key] ?? null;
                $results[$key] = $response instanceof Response ? $this->parse($response, $type) : null;

                if ($results[$key] !== null) {
                    unset($pending[$key]);
                }
            }
        }

        return $results;
    }

    /**
     * Records from a DNS-over-HTTPS answer, or null when it is not an answer. DNS status 0 is
     * an answer and 3 (NXDOMAIN) a definite "no such name"; anything else (SERVFAIL, REFUSED)
     * or an HTTP error means the question went unanswered.
     *
     * @param Response $response
     * @param string $type
     * @return list<string>|null
     */
    private function parse(Response $response, string $type): ?array
    {
        $status = $response->successful() ? $response->json('Status') : null;

        if (! in_array($status, [0, 3], true)) {
            return null;
        }

        $code = self::TYPES[$type] ?? null;

        return collect($response->json('Answer', []))
            ->filter(fn (array $answer) => $code === null || ($answer['type'] ?? null) === $code)
            ->map(fn (array $answer) => $type === 'TXT'
                ? $this->joinTxt((string) $answer['data'])
                : rtrim((string) $answer['data'], $type === 'MX' ? '' : '.'))
            ->values()
            ->all();
    }

    /**
     * Like query(), with a failure read as no records: right for lookups that only describe.
     *
     * @param string $endpoint
     * @param string $host
     * @param string $type
     * @param string $service
     * @return list<string>
     */
    public function doh(string $endpoint, string $host, string $type, string $service = 'dns'): array
    {
        return $this->query($endpoint, $host, $type, $service) ?? [];
    }

    /**
     * dns_get_record() answers [] both for a name with no records and for one that does not
     * exist, and false only when the resolver failed (SERVFAIL, TRY_AGAIN): that is null here.
     *
     * @param string $host
     * @param string $type
     * @return list<string>|null
     */
    private function system(string $host, string $type): ?array
    {
        $flags = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT][$type] ?? null;

        if ($flags === null) {
            return [];
        }

        $records = @dns_get_record($host, $flags);

        if ($records === false) {
            return null;
        }

        return array_values(array_filter(array_map(fn (array $r) => match ($type) {
            'A' => $r['ip'] ?? null,
            'AAAA' => $r['ipv6'] ?? null,
            'NS', 'CNAME' => $r['target'] ?? null,
            'MX' => isset($r['target']) ? ($r['pri'] ?? 0).' '.$r['target'] : null,
            'TXT' => implode('', $r['entries'] ?? [$r['txt'] ?? '']),
        }, $records), fn ($value) => $value !== null));
    }

    /**
     * A TXT answer arrives as one or more quoted strings: "v=spf1 " "include:_spf.google.com".
     *
     * @param string $data
     * @return string
     */
    private function joinTxt(string $data): string
    {
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $parts)) {
            return stripslashes(implode('', $parts[1]));
        }

        return $data;
    }
}
