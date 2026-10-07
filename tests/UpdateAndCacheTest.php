<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Facades\Domains;
use EduLazaro\Laradomains\Support\PublicSuffixList;
use Illuminate\Support\Facades\Http;

class UpdateAndCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/laradomains-'.uniqid();
        config([
            'laradomains.public_suffix_list' => $this->dir.'/public_suffix_list.dat',
            'laradomains.rdap.bootstrap_path' => $this->dir.'/rdap_dns.json',
        ]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_update_downloads_both_lists_and_compiles_the_suffixes(): void
    {
        Http::fake([
            'publicsuffix.org/*' => Http::response("// ===BEGIN ICANN DOMAINS===\ncom\nexample-suffix.com\n// ===BEGIN PRIVATE DOMAINS===\n// ===END PRIVATE DOMAINS===\n"),
            'data.iana.org/*' => Http::response(['services' => [[['com'], ['https://rdap.updated.test/']]]]),
        ]);

        $this->artisan('laradomains:update')->assertSuccessful();

        $this->assertFileExists($this->dir.'/public_suffix_list.dat');
        $this->assertFileExists($this->dir.'/public_suffix_list.php');
        $this->assertFileExists($this->dir.'/rdap_dns.json');
        // The compiled list is the one in use now.
        $this->assertSame('a.example-suffix.com', Domain::parse('www.a.example-suffix.com')->registrable());
        $this->assertSame('https://rdap.updated.test/', app(\EduLazaro\Laradomains\Rdap\RdapClient::class)->serverFor('com'));
    }

    public function test_the_old_command_name_still_works(): void
    {
        Http::fake([
            'publicsuffix.org/*' => Http::response("com\n// ===END PRIVATE DOMAINS===\n"),
            'data.iana.org/*' => Http::response(['services' => [[['com'], ['https://rdap.updated.test/']]]]),
        ]);

        $this->artisan('laradomains:update-suffixes')->assertSuccessful();
    }

    public function test_a_broken_download_keeps_the_current_lists(): void
    {
        Http::fake([
            'publicsuffix.org/*' => Http::response('<html>Service unavailable</html>'),
            'data.iana.org/*' => Http::response('not json'),
        ]);

        $this->artisan('laradomains:update')->assertFailed();

        $this->assertFileDoesNotExist($this->dir.'/public_suffix_list.dat');
        $this->assertFileDoesNotExist($this->dir.'/rdap_dns.json');
        $this->assertSame('bbc.co.uk', Domain::parse('news.bbc.co.uk')->registrable());
    }

    public function test_the_age_is_cached_by_registrable_domain(): void
    {
        $this->fileCache();
        Http::fake(['rdap.verisign.com/*' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2010-01-01T00:00:00Z']]])]);

        $this->assertSame('2010-01-01', Domains::age('a.spam.com')->since->toDateString());
        $this->assertSame('2010-01-01', Domains::age('b.spam.com')->since->toDateString());

        Http::assertSentCount(1);
    }

    public function test_a_failed_lookup_is_kept_for_retry_after_so_a_campaign_does_not_hammer_the_registry(): void
    {
        $this->fileCache(retryAfter: 300);
        Http::fake(['rdap.verisign.com/*' => Http::response([], 503)]);

        foreach (range(1, 5) as $_) {
            $this->assertNull(Domains::age('new-spam.com'));
        }

        Http::assertSentCount(1);
    }

    public function test_with_no_retry_after_a_failure_is_asked_again(): void
    {
        $this->fileCache(retryAfter: 0);
        Http::fake(['rdap.verisign.com/*' => Http::sequence()
            ->push([], 503)
            ->push(['events' => [['eventAction' => 'registration', 'eventDate' => '2010-01-01T00:00:00Z']]])]);

        $this->assertNull(Domains::age('example.com'));
        $this->assertSame('2010-01-01', Domains::age('example.com')->since->toDateString());
    }

    public function test_a_definite_no_is_cached_too(): void
    {
        $this->fileCache();
        Http::fake(['rdap.verisign.com/*' => Http::response([], 404)]);

        $this->assertNull(Domains::age('nobody-owns-this.com'));
        $this->assertNull(Domains::age('nobody-owns-this.com'));

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

    public function test_an_array_store_is_not_used_as_a_cache(): void
    {
        config(['laradomains.age.cache_for' => 3600, 'laradomains.cache_store' => 'array']);
        Http::fake(['rdap.verisign.com/*' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2010-01-01T00:00:00Z']]])]);

        Domains::age('example.com');
        Domains::age('example.com');

        Http::assertSentCount(2);
    }

    public function test_wayback_retry_delay_is_configurable(): void
    {
        config(['laradomains.retries.wayback' => 1, 'laradomains.retry_delay.wayback' => 1]);
        Http::fake(['web.archive.org/*' => Http::sequence()->push('busy', 503)->push([['timestamp'], ['20101015083000']])]);

        $this->assertSame('2010-10-15', app(\EduLazaro\Laradomains\Age\DomainAge::class)->firstCapture('ejemplo.es')->toDateString());
        Http::assertSentCount(2);
    }
}
