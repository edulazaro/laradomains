<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Age\Age;
use EduLazaro\Laradomains\Age\AgeCheck;
use EduLazaro\Laradomains\Facades\Domains;
use Illuminate\Support\Facades\Http;

class AgeCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/laradomains-age-'.uniqid();
        config(['laradomains.rdap.fallback' => null, 'laradomains.retries' => []]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function registration(string $date): array
    {
        return ['events' => [['eventAction' => 'registration', 'eventDate' => $date]]];
    }

    private function crtsh(string $notBefore): array
    {
        return [['id' => 1, 'not_before' => $notBefore, 'not_after' => '2030-01-01T00:00:00']];
    }

    public function test_a_date_from_rdap_is_found(): void
    {
        Http::fake(['rdap.verisign.com/*' => Http::response($this->registration('2010-01-01T00:00:00Z'))]);

        $check = Domains::ageCheck('example.com');

        $this->assertSame(AgeCheck::FOUND, $check->status);
        $this->assertTrue($check->found());
        $this->assertTrue($check->definite());
        $this->assertFalse($check->failed());
        $this->assertNull($check->reason);
        $this->assertSame([], $check->failed);
        $this->assertSame(Age::RDAP, $check->age->source);
        $this->assertSame('2010-01-01', Domains::age('example.com')->since->toDateString());
    }

    public function test_a_tld_without_rdap_and_no_fallback_is_none(): void
    {
        Http::fake();

        $check = Domains::ageCheck('ejemplo.es');

        $this->assertSame(AgeCheck::NONE, $check->status);
        $this->assertSame(AgeCheck::UNSUPPORTED_TLD, $check->reason);
        $this->assertTrue($check->definite());
        $this->assertNull($check->age);
        Http::assertNothingSent();
    }

    public function test_a_404_from_the_registry_is_none_not_registered(): void
    {
        Http::fake(['rdap.verisign.com/*' => Http::response([], 404)]);

        $check = Domains::ageCheck('nobody-owns-this.com', certificates: true);

        $this->assertSame(AgeCheck::NONE, $check->status);
        $this->assertSame(AgeCheck::NOT_REGISTERED, $check->reason);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'crt.sh'));
    }

    public function test_a_registry_error_is_unknown(): void
    {
        Http::fake(['rdap.verisign.com/*' => Http::response([], 500)]);

        $check = Domains::ageCheck('example.com');

        $this->assertSame(AgeCheck::UNKNOWN, $check->status);
        $this->assertSame(AgeCheck::LOOKUP_FAILED, $check->reason);
        $this->assertSame([Age::RDAP], $check->failed);
        $this->assertTrue($check->failed());
        $this->assertNull(Domains::age('example.com'));
    }

    public function test_a_fallback_date_is_found_even_when_rdap_failed_and_says_so(): void
    {
        Http::fake([
            'rdap.verisign.com/*' => Http::response([], 500),
            'crt.sh/*' => Http::response($this->crtsh('2015-05-05T00:00:00')),
        ]);

        $check = Domains::ageCheck('example.com', certificates: true);

        $this->assertSame(AgeCheck::FOUND, $check->status);
        $this->assertSame(Age::CERTIFICATES, $check->age->source);
        $this->assertSame([Age::RDAP], $check->failed);
        $this->assertFalse($check->complete());
    }

    public function test_the_earliest_fallback_wins_and_one_failing_does_not_hide_it(): void
    {
        Http::fake([
            'crt.sh/*' => Http::response('<html>502</html>', 502),
            'web.archive.org/*' => Http::response([['timestamp'], ['20120101000000']]),
        ]);

        $check = Domains::ageCheck('ejemplo.es', wayback: true, certificates: true);

        $this->assertSame(AgeCheck::FOUND, $check->status);
        $this->assertSame(Age::WAYBACK, $check->age->source);
        $this->assertSame([Age::CERTIFICATES], $check->failed);
    }

    public function test_fallbacks_that_answer_without_a_date_are_none_with_the_last_reason(): void
    {
        Http::fake([
            'crt.sh/*' => fn () => Http::response([]),
            'web.archive.org/*' => Http::response([]),
        ]);

        $this->assertSame(AgeCheck::NO_CERTIFICATES, Domains::ageCheck('ejemplo.es', certificates: true)->reason);

        $check = Domains::ageCheck('ejemplo.es', wayback: true, certificates: true);
        $this->assertSame(AgeCheck::NONE, $check->status);
        $this->assertSame(AgeCheck::NO_CAPTURES, $check->reason);
    }

    public function test_no_date_and_a_failed_fallback_is_unknown(): void
    {
        Http::fake([
            'crt.sh/*' => Http::response([]),
            'web.archive.org/*' => Http::response('', 503),
        ]);

        $check = Domains::ageCheck('ejemplo.es', wayback: true, certificates: true);

        $this->assertSame(AgeCheck::UNKNOWN, $check->status);
        $this->assertSame(AgeCheck::LOOKUP_FAILED, $check->reason);
        $this->assertSame([Age::WAYBACK], $check->failed);
    }

    public function test_the_cache_keeps_the_status_and_the_reason(): void
    {
        $this->fileCache();
        Http::fake(['rdap.verisign.com/*' => Http::response([], 404)]);

        Domains::ageCheck('nobody-owns-this.com');
        $cached = Domains::ageCheck('nobody-owns-this.com');

        $this->assertSame(AgeCheck::NONE, $cached->status);
        $this->assertSame(AgeCheck::NOT_REGISTERED, $cached->reason);
        Http::assertSentCount(1);
    }

    public function test_a_found_age_with_a_failed_source_is_kept_only_for_retry_after(): void
    {
        $this->fileCache(retryAfter: 0);
        Http::fake([
            'rdap.verisign.com/*' => Http::sequence()->push([], 500)->push($this->registration('2010-01-01T00:00:00Z')),
            'crt.sh/*' => fn () => Http::response($this->crtsh('2015-05-05T00:00:00')),
        ]);

        $first = Domains::ageCheck('example.com', certificates: true);
        $second = Domains::ageCheck('example.com', certificates: true);

        $this->assertSame(Age::CERTIFICATES, $first->age->source);
        $this->assertSame([Age::RDAP], $first->failed);
        $this->assertSame(Age::RDAP, $second->age->source);
        $this->assertSame([], $second->failed);
    }

    public function test_an_entry_cached_by_an_older_version_is_looked_up_again(): void
    {
        $this->fileCache();
        cache()->store('file')->put('laradomains.age.example.com', ['since' => '2010-01-01T00:00:00+00:00', 'source' => 'rdap'], 3600);
        Http::fake(['rdap.verisign.com/*' => Http::response($this->registration('2010-01-01T00:00:00Z'))]);

        $this->assertSame(AgeCheck::FOUND, Domains::ageCheck('example.com')->status);
        Http::assertSentCount(1);
    }

    private function fileCache(int $retryAfter = 300): void
    {
        config([
            'laradomains.age.cache_for' => 3600,
            'laradomains.age.retry_after' => $retryAfter,
            'cache.stores.file.path' => $this->dir.'/cache',
            'laradomains.cache_store' => 'file',
        ]);
    }
}
