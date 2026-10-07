<?php

namespace EduLazaro\Laradomains;

use EduLazaro\Laradomains\Age\Age;
use EduLazaro\Laradomains\Age\DomainAge;
use EduLazaro\Laradomains\Dns\DnsClient;
use EduLazaro\Laradomains\Rdap\RdapClient;
use EduLazaro\Laradomains\Rdap\Registration;
use EduLazaro\Laradomains\Screen\Screen;
use EduLazaro\Laradomains\Screen\Verdict;
use EduLazaro\Laradomains\Support\Http;

/**
 * The entry point behind the `Domains` facade. Each method hands the work to one service, which
 * can also be resolved from the container on its own.
 */
class Laradomains
{
    /**
     * @param RdapClient $rdap
     * @param DnsClient $dns
     * @param Screen $screen
     * @param DomainAge $age
     */
    public function __construct(
        private readonly RdapClient $rdap,
        private readonly DnsClient $dns,
        private readonly Screen $screen,
        private readonly DomainAge $age,
    ) {}

    /**
     * @param string $input
     * @return Domain
     */
    public function parse(string $input): Domain
    {
        return Domain::parse($input);
    }

    /**
     * @param string|null $input
     * @return Domain|null
     */
    public function tryParse(?string $input): ?Domain
    {
        return Domain::tryParse($input);
    }

    /**
     * @param Domain|string $domain
     * @param float|null $timeout
     * @return Registration
     */
    public function rdap(Domain|string $domain, ?float $timeout = null): Registration
    {
        return $this->rdap->lookup($domain, $timeout);
    }

    /**
     * @param Domain|string $domain
     * @param bool $wayback
     * @param float|null $timeout
     * @return Age|null
     */
    public function age(Domain|string $domain, bool $wayback = false, ?float $timeout = null): ?Age
    {
        return $this->age->of($domain, $wayback, $timeout);
    }

    /**
     * @return DnsClient
     */
    public function dns(): DnsClient
    {
        return $this->dns;
    }

    /**
     * @param Domain|string $domain
     * @param bool $adult
     * @param float|null $timeout
     * @return string Screen::CLEAN, MALWARE, ADULT or UNKNOWN.
     */
    public function screen(Domain|string $domain, bool $adult = true, ?float $timeout = null): string
    {
        return $this->screen->check($domain, $adult, $timeout);
    }

    /**
     * Both screening answers, each on its own.
     *
     * @param Domain|string $domain
     * @param bool $adult
     * @param float|null $timeout
     * @return Verdict
     */
    public function verdict(Domain|string $domain, bool $adult = true, ?float $timeout = null): Verdict
    {
        return $this->screen->verdict($domain, $adult, $timeout);
    }

    /**
     * Run a callback before every outgoing request, with the service name (`rdap`, `dns`,
     * `screen`, `wayback`). The place for a rate limiter.
     *
     * @param callable(string): void $hook
     * @return void
     */
    public function beforeRequest(callable $hook): void
    {
        Http::before($hook);
    }
}
