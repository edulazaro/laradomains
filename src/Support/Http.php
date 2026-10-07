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
     * @param float|null $timeout Seconds for this call; otherwise `timeouts.<service>`, then `timeout`.
     * @return PendingRequest
     */
    public static function for(string $service, ?float $timeout = null): PendingRequest
    {
        foreach (self::$before as $hook) {
            $hook($service);
        }

        $timeout ??= (float) (config("laradomains.timeouts.{$service}") ?? config('laradomains.timeout', 10));

        return Client::withUserAgent((string) config('laradomains.user_agent'))
            ->connectTimeout(min($timeout, 5))
            ->timeout($timeout);
    }

    /**
     * Attach the service's retries: how many times to try again after the first attempt, and
     * the pause before each, in milliseconds. 1.1 configs kept them under `wayback.*`.
     *
     * @param PendingRequest $request
     * @param string $service
     * @return PendingRequest
     */
    public static function withRetries(PendingRequest $request, string $service): PendingRequest
    {
        $retries = (int) (config("laradomains.retries.{$service}") ?? config("laradomains.{$service}.retries", 0));
        $delay = (int) (config("laradomains.retry_delay.{$service}") ?? config("laradomains.{$service}.retry_delay", 200));

        return $retries > 0 ? $request->retry($retries + 1, $delay, throw: false) : $request;
    }
}
