<?php

namespace EduLazaro\Laradomains\Tests;

use Carbon\CarbonImmutable;
use EduLazaro\Laradomains\Age\Age;
use EduLazaro\Laradomains\Facades\Domains;
use Illuminate\Support\Facades\Http;

class CertificatesAgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-07 12:00:00');
        config(['laradomains.rdap.fallback' => null, 'laradomains.retries.certificates' => 0]);
    }

    private function crtsh(array $notBefore): array
    {
        return array_map(fn (string $date) => ['id' => random_int(1, 1_000_000), 'name_value' => 'ejemplo.es', 'not_before' => $date], $notBefore);
    }

    public function test_a_registry_without_rdap_gets_its_age_from_the_first_certificate(): void
    {
        Http::fake(['crt.sh/*' => Http::response($this->crtsh(['2026-10-05T10:00:00', '2026-10-06T08:00:00']))]);

        $age = Domains::age('www.ejemplo.es', certificates: true);

        $this->assertSame(Age::CERTIFICATES, $age->source);
        $this->assertSame('2026-10-05', $age->since->toDateString());
        $this->assertTrue($age->isNewerThan(30));
        Http::assertSent(fn ($request) => $request['identity'] === 'ejemplo.es' && $request['match'] === '=');
    }

    public function test_with_both_fallbacks_the_earliest_date_wins(): void
    {
        Http::fake([
            'crt.sh/*' => Http::response($this->crtsh(['2019-03-01T00:00:00'])),
            'web.archive.org/*' => Http::response([['timestamp'], ['20101015083000']]),
        ]);

        $age = Domains::age('ejemplo.es', wayback: true, certificates: true);

        $this->assertSame(Age::WAYBACK, $age->source);
        $this->assertSame('2010-10-15', $age->since->toDateString());
    }

    public function test_crtsh_down_is_unknown_never_new(): void
    {
        Http::fake(['crt.sh/*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $this->assertNull(Domains::age('ejemplo.es', certificates: true));
    }

    public function test_certificates_are_not_asked_unless_requested_or_when_rdap_has_the_date(): void
    {
        Http::fake([
            'crt.sh/*' => Http::response($this->crtsh(['2026-10-05T10:00:00'])),
            'rdap.verisign.com/*' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z']]]),
        ]);

        $this->assertNull(Domains::age('ejemplo.es'));
        $this->assertSame(Age::RDAP, Domains::age('example.com', certificates: true)->source);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'crt.sh'));
    }

    public function test_a_huge_answer_is_read_only_up_to_the_limit_and_still_gives_a_lower_bound(): void
    {
        config(['laradomains.certificates.max_bytes' => 4096]);
        $entries = [];
        for ($i = 0; $i < 500; $i++) {
            $entries[] = ['id' => $i, 'name_value' => 'ejemplo.es', 'not_before' => sprintf('2024-%02d-01T00:00:00', ($i % 12) + 1)];
        }
        // The real first certificate sits at the end, past the limit.
        $entries[] = ['id' => 999, 'name_value' => 'ejemplo.es', 'not_before' => '2012-01-01T00:00:00'];
        Http::fake(['crt.sh/*' => Http::response($entries)]);

        $age = Domains::age('ejemplo.es', certificates: true);

        $this->assertSame(Age::CERTIFICATES, $age->source);
        $this->assertSame('2024-01-01', $age->since->toDateString());   // earliest of what was read
        $this->assertFalse($age->isNewerThan(30));
    }

    public function test_an_answer_with_no_certificates_is_unknown(): void
    {
        Http::fake(['crt.sh/*' => Http::response([])]);

        $this->assertNull(Domains::age('ejemplo.es', certificates: true));
    }
}
