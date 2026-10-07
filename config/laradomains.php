<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Suffix List
    |--------------------------------------------------------------------------
    |
    | The list that says where the registrable part of a name starts (co.uk,
    | github.io, gob.es). The package ships a copy; `php artisan
    | laradomains:update` downloads a fresh one to this path, plus a compiled
    | PHP version next to it that opcache keeps in memory. Both win over the
    | bundled copy when they exist.
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
    | Store for the optional age cache (see `age.cache_for`). Null uses the
    | application's default store. An `array` or `null` store keeps nothing
    | between requests, so the package does not cache there at all.
    |
    */

    'cache_store' => env('LARADOMAINS_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | RDAP
    |--------------------------------------------------------------------------
    |
    | Lookups go straight to the registry's own server, found through the IANA
    | bootstrap. The package ships a copy of it and `laradomains:update`
    | downloads a fresh one to `bootstrap_path`, so a lookup never fetches it.
    | `fallback` is asked only for TLDs the bootstrap does not list; set it to
    | null to never use a relay.
    |
    */

    'rdap' => [
        'bootstrap' => 'https://data.iana.org/rdap/dns.json',
        'bootstrap_path' => storage_path('app/laradomains/rdap_dns.json'),
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
    | Its CDX server is strict with request rates, so it is opt-in per call,
    | and a refused request waits `retry_delay` milliseconds before retrying.
    |
    */

    'wayback' => [
        'endpoint' => 'https://web.archive.org/cdx/search/cdx',
        'retries' => 2,
        'retry_delay' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Age
    |--------------------------------------------------------------------------
    |
    | Seconds to remember a domain's age, so repeated checks of the same
    | domain cost no request. Null (the default) asks every time.
    |
    */

    'age' => [
        'cache_for' => env('LARADOMAINS_AGE_CACHE_FOR'),
    ],

];
