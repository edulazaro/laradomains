<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Facades\Domains;
use Illuminate\Support\Facades\Http;

class RdapTest extends TestCase
{
    private function bootstrap(): array
    {
        return ['services' => [
            [['com', 'net'], ['http://rdap.verisign.com/com/v1/', 'https://rdap.verisign.com/com/v1/']],
            [['uk'], ['https://rdap.nominet.uk/uk/']],
        ]];
    }

    public function test_it_asks_the_registry_for_the_registrable_domain(): void
    {
        Http::fake([
            'data.iana.org/*' => Http::response($this->bootstrap()),
            'rdap.verisign.com/com/v1/domain/example.com' => Http::response([
                'events' => [
                    ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
                    ['eventAction' => 'expiration', 'eventDate' => '2027-08-13T04:00:00Z'],
                ],
                'status' => ['client transfer prohibited'],
                'nameservers' => [['ldhName' => 'A.IANA-SERVERS.NET']],
                'secureDNS' => ['delegationSigned' => true],
                'entities' => [[
                    'roles' => ['registrar'],
                    'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'RESERVED-Internet Assigned Numbers Authority']]],
                    'publicIds' => [['type' => 'IANA Registrar ID', 'identifier' => '376']],
                    'entities' => [['roles' => ['abuse'], 'vcardArray' => ['vcard', [['email', [], 'text', 'abuse@example.net']]]]],
                ]],
            ]),
        ]);

        $registration = Domains::rdap('https://www.example.com/page');

        $this->assertTrue($registration->registered);
        $this->assertSame('example.com', $registration->domain);
        $this->assertSame('1995-08-14', $registration->registeredAt->toDateString());
        $this->assertSame('2027-08-13', $registration->expiresAt->toDateString());
        $this->assertSame('RESERVED-Internet Assigned Numbers Authority', $registration->registrar);
        $this->assertSame('376', $registration->registrarIanaId);
        $this->assertSame('abuse@example.net', $registration->abuseEmail);
        $this->assertSame(['a.iana-servers.net'], $registration->nameservers);
        $this->assertTrue($registration->dnssec);
        $this->assertSame('https://rdap.verisign.com/com/v1/', $registration->server);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rdap.org'));
    }

    public function test_a_registry_404_means_not_registered(): void
    {
        Http::fake([
            'data.iana.org/*' => Http::response($this->bootstrap()),
            'rdap.nominet.uk/*' => Http::response([], 404),
        ]);

        $registration = Domains::rdap('nobody-has-this.co.uk');

        $this->assertTrue($registration->supported);
        $this->assertFalse($registration->registered);
        $this->assertSame('nobody-has-this.co.uk', $registration->domain);
    }

    public function test_unlisted_tlds_go_to_the_fallback_relay_or_are_unsupported(): void
    {
        Http::fake([
            'data.iana.org/*' => Http::response($this->bootstrap()),
            'rdap.org/*' => Http::response([], 404),
        ]);

        $this->assertFalse(Domains::rdap('ejemplo.es')->supported);
        Http::assertSent(fn ($request) => $request->url() === 'https://rdap.org/domain/ejemplo.es');

        config(['laradomains.rdap.fallback' => null]);
        Http::fake(['data.iana.org/*' => Http::response($this->bootstrap())]);
        $this->assertFalse(Domains::rdap('ejemplo.de')->supported);
    }

    public function test_failures_are_reported_and_the_bootstrap_is_cached(): void
    {
        Http::fake([
            'data.iana.org/*' => Http::response($this->bootstrap()),
            'rdap.verisign.com/*' => Http::response('oops', 503),
        ]);

        $first = Domains::rdap('example.com');
        Domains::rdap('example.net');

        $this->assertTrue($first->failed());
        $this->assertSame('HTTP 503', $first->error);
        Http::assertSentCount(3); // one bootstrap, two lookups
    }

    public function test_an_unreachable_bootstrap_is_a_failure_not_an_unsupported_tld(): void
    {
        Http::fake(['data.iana.org/*' => Http::response('down', 500)]);

        $registration = Domains::rdap('example.com');

        $this->assertTrue($registration->failed());
        $this->assertTrue($registration->supported);
    }

    public function test_the_before_hook_sees_every_request(): void
    {
        $seen = [];
        Domains::beforeRequest(function (string $service) use (&$seen) {
            $seen[] = $service;
        });
        Http::fake([
            'data.iana.org/*' => Http::response($this->bootstrap()),
            'rdap.verisign.com/*' => Http::response([], 404),
        ]);

        Domains::rdap('example.com');

        $this->assertSame(['rdap', 'rdap'], $seen);
    }

    public function test_hooks_do_not_survive_a_new_application(): void
    {
        $calls = 0;
        Domains::beforeRequest(function () use (&$calls) {
            $calls++;
        });

        $this->refreshApplication();
        Http::fake(['data.iana.org/*' => Http::response($this->bootstrap()), 'rdap.verisign.com/*' => Http::response([], 404)]);
        Domains::rdap('example.com');

        $this->assertSame(0, $calls);
    }
}
