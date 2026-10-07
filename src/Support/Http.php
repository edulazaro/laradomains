<?php

namespace EduLazaro\Laradomains\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http as Client;

/**
 * The one way the package talks to the outside: a request with the configured timeout and
 * user agent, after the hook the application registered for that service (a rate limiter,
 * usually).
 *
 * Services are named `rdap`, `dns`, `screen` and `wayback`, so a hook can treat each one by
 * its own limits: the Wayback Machine's CDX server allows a dozen requests a minute, a DNS
 * resolver thousands.
 */
final class Http
{
    /** @var list<callable(string): void> */
    private static array $before = [];

    /**
     * @param callable(string): void $hook Receives the service name before each request.
     * @return void
     */
    public static function before(callable $hook): void
    {
        self::$before[] = $hook;
    }

    /**
     * @return void
     */
    public static function flushHooks(): void
    {
        self::$before = [];
    }

    /**
     * @param string $service
     * @return PendingRequest
     */
    public static function for(string $service): PendingRequest
    {
        foreach (self::$before as $hook) {
            $hook($service);
        }

        return Client::withUserAgent((string) config('laradomains.user_agent'))
            ->timeout((int) config('laradomains.timeout', 10));
    }
}
