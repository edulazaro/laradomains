<?php

namespace EduLazaro\Laradomains\Tests;

use Carbon\CarbonImmutable;
use EduLazaro\Laradomains\Age\Age;
use EduLazaro\Laradomains\Facades\Domains;
use Illuminate\Support\Facades\Http;

class AgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-07 12:00:00');
    }

    public function test_age_comes_from_rdap_when_the_registry_has_a_date(): void
    {
        Http::fake([
            'data.iana.org/*' => Http::response(['services' => [[['com'], ['https://rdap.verisign.com/com/v1/']]]]),
            'rdap.verisign.com/*' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2016-10-07T00:00:00Z']]]),
        ]);

        $age = Domains::age('example.com', wayback: true);

        $this->assertSame(Age::RDAP, $age->source);
        $this->assertSame(3652, $age->days());
        $this->assertSame(10.0, $age->years());
        $this->assertFalse($age->isNewerThan(30));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'web.archive.org'));
    }

    public function test_wayback_is_the_opt_in_fallback_for_registries_without_rdap(): void
    {
        config(['laradomains.rdap.fallback' => null]);
        Http::fake([
            'data.iana.org/*' => Http::response(['services' => []]),
            'web.archive.org/*' => Http::response([['timestamp'], ['20101015083000']]),
        ]);

        $this->assertNull(Domains::age('ejemplo.es'));

        $age = Domains::age('www.ejemplo.es', wayback: true);
        $this->assertSame(Age::WAYBACK, $age->source);
        $this->assertSame('2010-10-15', $age->since->toDateString());
        Http::assertSent(fn ($request) => ($request['url'] ?? null) === 'ejemplo.es');
    }
}
