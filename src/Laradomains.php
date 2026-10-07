<?php

namespace EduLazaro\Laradomains;

use EduLazaro\Laradomains\Age\Age;
use EduLazaro\Laradomains\Age\DomainAge;
use EduLazaro\Laradomains\Dns\DnsClient;
use EduLazaro\Laradomains\Rdap\RdapClient;
use EduLazaro\Laradomains\Rdap\Registration;
use EduLazaro\Laradomains\Screen\Screen;
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
     * @return Registration
     */
    public function rdap(Domain|string $domain): Registration
    {
        return $this->rdap->lookup($domain);
    }

    /**
     * @param Domain|string $domain
     * @param bool $wayback
     * @return Age|null
     */
    public function age(Domain|string $domain, bool $wayback = false): ?Age
    {
        return $this->age->of($domain, $wayback);
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
     * @return string
     */
    public function screen(Domain|string $domain, bool $adult = true): string
    {
        return $this->screen->check($domain, $adult);
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
