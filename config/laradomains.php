<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Suffix List
    |--------------------------------------------------------------------------
    |
    | The list that says where the registrable part of a name starts (co.uk,
    | github.io, gob.es). The package ships a copy; `php artisan
    | laradomains:update-suffixes` downloads a fresh one to this path, which
    | wins over the bundled copy when it exists.
    |
    */

    'public_suffix_list' => storage_path('app/laradomains/public_suffix_list.dat'),

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    |
    | Every lookup goes to a third party, never to the domain itself. The
    | timeout applies to each request, and the user agent identifies you to
    | services such as the Wayback Machine that ask for one.
    |
    */

    'timeout' => (int) env('LARADOMAINS_TIMEOUT', 10),

    'user_agent' => env('LARADOMAINS_USER_AGENT', 'Laradomains (+https://github.com/edulazaro/laradomains)'),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Store used for the IANA RDAP bootstrap (refreshed weekly). Null uses the
    | application's default store.
    |
    */

    'cache_store' => env('LARADOMAINS_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | RDAP
    |--------------------------------------------------------------------------
    |
    | Lookups go straight to the registry's own server, found through the IANA
    | bootstrap. `fallback` is asked only for TLDs the bootstrap does not list;
    | set it to null to never use a relay.
    |
    */

    'rdap' => [
        'bootstrap' => 'https://data.iana.org/rdap/dns.json',
        'fallback' => env('LARADOMAINS_RDAP_FALLBACK', 'https://rdap.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DNS
    |--------------------------------------------------------------------------
    |
    | `doh` asks a DNS-over-HTTPS resolver (JSON API), so answers do not depend
    | on the resolver of the machine; `system` uses PHP's dns_get_record().
    |
    */

    'dns' => [
        'driver' => env('LARADOMAINS_DNS_DRIVER', 'doh'),
        'doh_endpoint' => 'https://cloudflare-dns.com/dns-query',
    ],

    /*
    |--------------------------------------------------------------------------
    | Screening
    |--------------------------------------------------------------------------
    |
    | Cloudflare's filtering resolvers answer 0.0.0.0 for names they block:
    | `security` blocks malware and phishing, `family` adds adult content.
    |
    */

    'screen' => [
        'malware' => 'https://security.cloudflare-dns.com/dns-query',
        'adult' => 'https://family.cloudflare-dns.com/dns-query',
    ],

    /*
    |--------------------------------------------------------------------------
    | Wayback Machine
    |--------------------------------------------------------------------------
    |
    | Used for the age of domains whose registry has no RDAP (.es, .de, .io).
    | Its CDX server is strict with request rates, so it is opt-in per call.
    |
    */

    'wayback' => [
        'endpoint' => 'https://web.archive.org/cdx/search/cdx',
    ],

];
