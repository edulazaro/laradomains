<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Facades\Domains;
use Illuminate\Support\Facades\Http;

class RetriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'laradomains.retries' => ['rdap' => 2, 'dns' => 2, 'screen' => 2, 'wayback' => 2, 'certificates' => 2],
            'laradomains.retry_delay' => ['rdap' => 0, 'dns' => 0, 'screen' => 0, 'wayback' => 0, 'certificates' => 0],
            'laradomains.rdap.fallback' => null,
        ]);
    }

    public function test_the_config_retries_apply_when_the_call_does_not_say(): void
    {
        Http::fake(['rdap.verisign.com/*' => Http::response([], 500)]);

        Domains::rdap('example.com');

        Http::assertSentCount(3);
    }

    public function test_retries_zero_makes_a_single_request(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        Domains::rdap('example.com', retries: 0);
        Http::assertSentCount(1);

        Domains::dns()->query('https://cloudflare-dns.com/dns-query', 'example.com', 'A', retries: 0);
        Http::assertSentCount(2);

        Domains::screen('example.com', adult: false, retries: 0);
        Http::assertSentCount(3);

        Domains::age('ejemplo.es', certificates: true, retries: 0);
        Http::assertSentCount(4);
    }

    public function test_a_call_can_ask_for_more_retries_than_the_config(): void
    {
        config(['laradomains.retries.screen' => 0]);
        Http::fake(['*' => Http::response('', 500)]);

        Domains::verdict('example.com', adult: false, retries: 1);

        Http::assertSentCount(2);
    }
}
