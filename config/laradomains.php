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

    // Per service, in seconds, over `timeout`. Each call can also pass its own `timeout:`.
    // Screening and DNS are short on purpose: they sit on request paths.
    'timeouts' => [
        'rdap' => 10,
        'dns' => 3,
        'screen' => 2,
        'wayback' => 20,
        'certificates' => 20,
    ],

    // Tries after the first one, and the pause before each (ms). The Wayback CDX server
    // refuses bursts, so it waits long; screening does not retry, it answers "unknown".
    'retries' => [
        'rdap' => 0,
        'dns' => 1,
        'screen' => 0,
        'wayback' => 1,
        'certificates' => 1,
    ],

    'retry_delay' => [
        'dns' => 200,
        'wayback' => 5000,
        'certificates' => 3000,
    ],

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
    | Both are asked in parallel.
    |
    */

    'screen' => [
        'malware' => 'https://security.cloudflare-dns.com/dns-query',
        'adult' => 'https://family.cloudflare-dns.com/dns-query',
        // Seconds to keep a complete verdict per host, and an incomplete one (a resolver did
        // not answer). Null (the default) asks every time.
        'cache_for' => env('LARADOMAINS_SCREEN_CACHE_FOR'),
        'retry_after' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wayback Machine
    |--------------------------------------------------------------------------
    |
    | Used for the age of domains whose registry has no RDAP (.es, .de, .io).
    | Its CDX server is strict with request rates, so it is opt-in per call;
    | its retries are set above, under `retries` and `retry_delay`.
    |
    */

    'wayback' => [
        'endpoint' => 'https://web.archive.org/cdx/search/cdx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Transparency
    |--------------------------------------------------------------------------
    |
    | crt.sh's search over the CT logs, for the first certificate of a domain:
    | the other lower bound of its age, and the one that sees a phishing domain
    | registered yesterday. Opt-in per call, like Wayback. A free service that
    | is not always up; a failure is "unknown".
    |
    */

    'certificates' => [
        'endpoint' => 'https://crt.sh/',
        // The answer is read as a stream up to this many bytes: enough for any domain whose
        // age is in question, and a domain with more is years old.
        'max_bytes' => 2_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Age
    |--------------------------------------------------------------------------
    |
    | Seconds to remember a domain's age, keyed by its registrable domain, so
    | repeated checks (a.spam.io, b.spam.io) cost one request. `cache_for` keeps
    | definite answers (a date, not registered, no RDAP for the TLD);
    | `retry_after` keeps a failed lookup that long, so a campaign does not hit
    | the registry once per message. Null (the default) asks every time.
    |
    */

    'age' => [
        'cache_for' => env('LARADOMAINS_AGE_CACHE_FOR'),
        'retry_after' => 300,
    ],

];
