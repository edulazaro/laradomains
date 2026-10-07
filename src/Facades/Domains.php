<?php

namespace EduLazaro\Laradomains\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \EduLazaro\Laradomains\Domain parse(string $input)
 * @method static \EduLazaro\Laradomains\Domain|null tryParse(?string $input)
 * @method static \EduLazaro\Laradomains\Rdap\Registration rdap(\EduLazaro\Laradomains\Domain|string $domain, ?float $timeout = null)
 * @method static \EduLazaro\Laradomains\Age\Age|null age(\EduLazaro\Laradomains\Domain|string $domain, bool $wayback = false, ?float $timeout = null)
 * @method static \EduLazaro\Laradomains\Dns\DnsClient dns()
 * @method static string screen(\EduLazaro\Laradomains\Domain|string $domain, bool $adult = true, ?float $timeout = null)
 * @method static \EduLazaro\Laradomains\Screen\Verdict verdict(\EduLazaro\Laradomains\Domain|string $domain, bool $adult = true, ?float $timeout = null)
 * @method static void beforeRequest(callable $hook)
 *
 * @see \EduLazaro\Laradomains\Laradomains
 */
class Domains extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'laradomains';
    }
}
