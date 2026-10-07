<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Facades\Domains;
use EduLazaro\Laradomains\Screen\Screen;
use Illuminate\Support\Facades\Http;

class VerdictTest extends TestCase
{
    private function answer(string $ip): array
    {
        return ['Status' => 0, 'Answer' => [['type' => 1, 'data' => $ip]]];
    }

    public function test_one_resolver_failing_does_not_hide_what_the_other_settled(): void
    {
        Http::fake([
            'security.cloudflare-dns.com/*' => Http::response($this->answer('93.184.215.14')),
            'family.cloudflare-dns.com/*' => Http::response('', 503),
        ]);

        $verdict = Domains::verdict('example.com');

        $this->assertFalse($verdict->malware);       // the malware resolver cleared it
        $this->assertNull($verdict->adult);          // the adult one did not answer
        $this->assertFalse($verdict->complete());
        $this->assertSame(Screen::UNKNOWN, $verdict->decision());
    }

    public function test_malware_wins_even_when_the_adult_resolver_fails(): void
    {
        Http::fake([
            'security.cloudflare-dns.com/*' => Http::response($this->answer('0.0.0.0')),
            'family.cloudflare-dns.com/*' => Http::response('', 503),
        ]);

        $this->assertSame(Screen::MALWARE, Domains::screen('bad.example'));
    }

    public function test_both_resolvers_are_asked_together(): void
    {
        Http::fake([
            'security.cloudflare-dns.com/*' => Http::response($this->answer('0.0.0.0')),
            'family.cloudflare-dns.com/*' => Http::response($this->answer('0.0.0.0')),
        ]);

        $verdict = Domains::verdict('bad.example');

        $this->assertTrue($verdict->malware);
        $this->assertTrue($verdict->adult);
        Http::assertSentCount(2);
    }

    public function test_unanswered_resolvers_are_retried_up_to_the_service_retries(): void
    {
        config(['laradomains.retries.screen' => 1, 'laradomains.retry_delay.screen' => 0]);
        Http::fake([
            'security.cloudflare-dns.com/*' => Http::sequence()->push('', 503)->push($this->answer('93.184.215.14')),
            'family.cloudflare-dns.com/*' => Http::response($this->answer('93.184.215.14')),
        ]);

        $this->assertSame(Screen::CLEAN, Domains::screen('example.com'));
        Http::assertSentCount(3); // both, then only the one that failed
    }

    public function test_the_screen_cache_keeps_complete_verdicts_long_and_incomplete_ones_briefly(): void
    {
        $dir = sys_get_temp_dir().'/laradomains-screen-'.uniqid();
        config([
            'laradomains.screen.cache_for' => 3600,
            'laradomains.screen.retry_after' => 60,
            'laradomains.cache_store' => 'file',
            'cache.stores.file.path' => $dir,
        ]);
        Http::fake([
            'security.cloudflare-dns.com/*' => Http::response($this->answer('93.184.215.14')),
            'family.cloudflare-dns.com/*' => Http::response($this->answer('93.184.215.14')),
        ]);

        Domains::screen('example.com');
        Domains::screen('example.com');
        Domains::screen('example.com', adult: false);   // a different question

        Http::assertSentCount(3);
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
    }

    public function test_no_screen_cache_unless_configured(): void
    {
        Http::fake(['*.cloudflare-dns.com/*' => Http::response($this->answer('93.184.215.14'))]);

        Domains::screen('example.com', adult: false);
        Domains::screen('example.com', adult: false);

        Http::assertSentCount(2);
    }
}
