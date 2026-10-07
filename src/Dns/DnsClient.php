<?php

namespace EduLazaro\Laradomains\Dns;

use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * DNS lookups, by default over HTTPS (the JSON API Cloudflare and Google expose), so the answer
 * does not depend on the resolver of the machine running the code. The `system` driver uses
 * PHP's dns_get_record() instead.
 *
 * A failed lookup and a name with no records both come back as an empty list: for the
 * questions this answers (does it resolve, does it take mail, what TXT does it publish) the
 * caller acts the same way.
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

        return config('laradomains.dns.driver') === 'system'
            ? $this->system($host, $type)
            : $this->doh((string) config('laradomains.dns.doh_endpoint'), $host, $type);
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
        $records = $this->records($domain, 'MX');
        usort($records, fn (string $a, string $b) => (int) $a <=> (int) $b);

        return array_values(array_map(fn (string $r) => strtolower(rtrim(preg_replace('/^\d+\s+/', '', $r), '.')), $records));
    }

    /**
     * Whether the domain takes mail. A null MX (RFC 7505, a single "0 .") means it refuses mail.
     *
     * @param Domain|string $domain
     * @return bool
     */
    public function acceptsMail(Domain|string $domain): bool
    {
        $mx = $this->mx($domain);

        return $mx !== [] && $mx !== [''];
    }

    /**
     * @param string $endpoint
     * @param string $host
     * @param string $type
     * @param string $service
     * @return list<string>
     */
    public function doh(string $endpoint, string $host, string $type, string $service = 'dns'): array
    {
        try {
            $response = Http::for($service)
                ->accept('application/dns-json')
                ->retry(2, 300, throw: false)
                ->get($endpoint, ['name' => $host, 'type' => $type]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
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
     * @param string $host
     * @param string $type
     * @return list<string>
     */
    private function system(string $host, string $type): array
    {
        $flags = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT][$type] ?? null;

        if ($flags === null) {
            return [];
        }

        $records = @dns_get_record($host, $flags) ?: [];

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
