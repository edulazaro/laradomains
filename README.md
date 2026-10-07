![Laradomains](art/banner.png)

# Laradomains

<p align="center">
    <a href="https://github.com/edulazaro/laradomains/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/laradomains/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/laradomains"><img src="https://img.shields.io/packagist/v/edulazaro/laradomains" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/laradomains"><img src="https://img.shields.io/packagist/php-v/edulazaro/laradomains" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/laradomains/blob/main/LICENSE"><img src="https://img.shields.io/packagist/l/edulazaro/laradomains" alt="License"></a>
</p>

Everything you can learn about a domain **without visiting it**: parsing with the Public Suffix List, registration data over RDAP, age, DNS over HTTPS and malware or adult screening. Every answer comes from a third party (the registry, a DNS resolver, the Wayback Machine), never from the domain itself, which matters when the domain was typed by a stranger and may be hostile.

## Installation

```bash
composer require edulazaro/laradomains
```

Requires PHP 8.4+ with the `intl` extension and Laravel 12 or newer. Optionally publish the config:

```bash
php artisan vendor:publish --tag=laradomains-config
```

## Parsing

`Domain::parse()` takes what people paste and keeps the host, lower-cased, in both spellings:

```php
use EduLazaro\Laradomains\Domain;

$domain = Domain::parse('https://News.BBC.co.uk:8080/path?x=1');

$domain->ascii;          // "news.bbc.co.uk"
$domain->suffix();       // "co.uk"
$domain->registrable();  // "bbc.co.uk"
$domain->subdomain();    // "news"
$domain->tld();          // "uk"

$idn = Domain::parse('ñandú.es');
$idn->ascii;             // "xn--and-6ma2c.es" (what DNS and registries use)
$idn->unicode;           // "ñandú.es" (what a person reads)

Domain::tryParse('127.0.0.1');   // null: IP addresses are not domains
```

The registrable domain follows the [Public Suffix List](https://publicsuffix.org), not a guess at "the last two labels", so `gob.es`, `com.mx` and wildcard rules are handled. Suffixes run by hosting platforms are opt-in:

```php
$site = Domain::parse('edulazaro.github.io');

$site->registrable();               // "github.io" (what the registry knows)
$site->registrable(private: true);  // "edulazaro.github.io" (the site the platform gave out)
```

`www` is kept, since it is a host of its own; `withoutWww()` drops it when you treat both as one site.

### Lookalikes

```php
Domain::parse('аpple.com')->isLookalike();   // true: the first "а" is Cyrillic
Domain::parse('аррӏе.com')->isLookalike();   // true: all Cyrillic, but every letter has a Latin twin
Domain::parse('ñandú.es')->isLookalike();    // false: accented, but one script
Domain::parse('яндекс.com')->isLookalike();  // false: a real Cyrillic word
Domain::parse('аррӏе.ru')->isLookalike();    // false: Cyrillic is the norm under .ru
```

`skeleton()` gives the Latin reading of a name, and `imitates()` checks it against the brands you care about, also catching the `0`/`o` and `1`/`l` swaps:

```php
Domain::parse('аррӏе.com')->skeleton();                          // "apple.com"
Domain::parse('www.paypa1.com')->imitates(['paypal.com']);       // "paypal.com"
Domain::parse('paypal.com')->imitates(['paypal.com']);           // null: it is the brand
```

### Keeping the lists current

The package ships a copy of the Public Suffix List and of the IANA RDAP bootstrap, so it works with no download and no cache. `laradomains:update` fetches fresh copies to `storage/app/laradomains/`, which win over the bundled ones, and compiles the suffix list to a PHP array that opcache keeps in memory. Schedule it monthly:

```php
Schedule::command('laradomains:update')->monthly();
```

A download that does not look like the real list is discarded and the current copy kept.

## Registration data (RDAP)

RDAP is the protocol that replaced WHOIS. There is no central database: each registry runs its own server, and IANA publishes which one serves each TLD. Laradomains reads that bootstrap from disk and asks the registry directly, for the registrable domain, so a lookup is a single request:

```php
use EduLazaro\Laradomains\Facades\Domains;

$registration = Domains::rdap('news.bbc.co.uk');   // asks Nominet about bbc.co.uk

$registration->registeredAt;   // CarbonImmutable
$registration->expiresAt;
$registration->registrar;      // the registrar's name
$registration->registrarIanaId;
$registration->abuseEmail;
$registration->status;         // ["client transfer prohibited", ...]
$registration->nameservers;
$registration->dnssec;
$registration->ageInDays();
```

Three outcomes are kept apart, because a caller handles each one differently:

| | Meaning |
|---|---|
| `supported === false` | The TLD has no RDAP service (`.es`, `.de`, `.io` and others). |
| `registered === false` | The registry answered that nobody holds the name. |
| `failed()` | The lookup did not complete; `error` says why. The only one worth retrying. |

TLDs missing from the IANA bootstrap are sent to a relay, `rdap.org` by default. Set `LARADOMAINS_RDAP_FALLBACK=` empty to never use one.

## Age

```php
$age = Domains::age('example.com');                 // from RDAP
$age = Domains::age('ejemplo.es', wayback: true);   // first Wayback capture when the registry has no date

$age->days();             // 10950
$age->years();            // 30.0
$age->since;              // CarbonImmutable
$age->source;             // "rdap" or "wayback"
$age->isNewerThan(30);    // registered in the last month?
```

The Wayback fallback is off by default: its CDX server allows about a dozen requests a minute, which suits a queued job and not a request path. A refused request waits `wayback.retry_delay` milliseconds (5000 by default) before each of `wayback.retries` retries. A first capture is a lower bound, not a registration date.

To remember ages, set `LARADOMAINS_AGE_CACHE_FOR` to a number of seconds. Only found ages are cached (a failed lookup is asked again next time), and only in a persistent store: with the `array` or `null` cache store nothing would survive the request, so the package does not cache there at all. `LARADOMAINS_CACHE_STORE` picks a store other than the default.

## DNS

Over HTTPS by default (`LARADOMAINS_DNS_DRIVER=doh`), so answers do not depend on the resolver of the machine; `system` uses `dns_get_record()`.

```php
$dns = Domains::dns();

$dns->resolves('example.com');      // true
$dns->addresses('example.com');     // ["93.184.215.14", "2606:2800:..."]
$dns->nameservers('example.com');
$dns->mx('example.com');            // lowest priority first
$dns->acceptsMail('example.com');   // false for no MX or a null MX (RFC 7505)
$dns->txt('example.com');           // multi-string records joined
$dns->records('example.com', 'CNAME');
```

## Screening

Cloudflare's filtering resolvers answer `0.0.0.0` for names they block. Laradomains asks them over DNS, so nothing reaches the domain:

```php
use EduLazaro\Laradomains\Screen\Screen;

Domains::screen('example.com');                // Screen::CLEAN, Screen::MALWARE or Screen::ADULT
Domains::screen('example.com', adult: false);  // malware and phishing only
```

The adult category is broad (it also catches piracy, cannabis shops and the odd false positive): treat it as a flag for review rather than as proof.

## Rate limits

Every request passes through a hook first, with the service name (`rdap`, `dns`, `screen`, `wayback`). It is the place for a rate limiter shared across workers:

```php
use Illuminate\Support\Facades\RateLimiter;

Domains::beforeRequest(function (string $service) {
    $limits = ['wayback' => 12, 'rdap' => 120];

    if (! isset($limits[$service])) {
        return;
    }

    while (! RateLimiter::attempt("laradomains:{$service}", $limits[$service], fn () => true, 60)) {
        sleep(1);
    }
});
```

## Testing

Every call goes through Laravel's HTTP client, so `Http::fake()` covers the whole package:

```php
Http::fake([
    'data.iana.org/*' => Http::response(['services' => [[['com'], ['https://rdap.verisign.com/com/v1/']]]]),
    'rdap.verisign.com/*' => Http::response(['events' => [
        ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
    ]]),
]);
```

## Sponsors

Laradomains is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" height="28" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" height="28" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://valorweb.org"><img src="art/logo-valorweb.png" height="28" alt="Valor Web"></a>&nbsp;<a href="https://valorweb.org">Valor Web</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://toxicfilter.com"><img src="art/logo-toxicfilter.png" height="28" alt="ToxicFilter"></a>&nbsp;<a href="https://toxicfilter.com">ToxicFilter</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Laradomains is open-sourced software licensed under the [MIT license](LICENSE). The bundled Public Suffix List (`resources/public_suffix_list.dat`) is distributed under the [Mozilla Public License 2.0](https://mozilla.org/MPL/2.0/).
