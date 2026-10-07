<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Facades\Domains;
use EduLazaro\Laradomains\Screen\Screen;
use Illuminate\Support\Facades\Http;

class DnsAndScreenTest extends TestCase
{
    private function answer(array $records): array
    {
        return ['Status' => 0, 'Answer' => $records];
    }

    public function test_dns_over_https_records(): void
    {
        Http::fake(function ($request) {
            return match ($request['type']) {
                'A' => Http::response($this->answer([['type' => 5, 'data' => 'alias.example.com.'], ['type' => 1, 'data' => '93.184.215.14']])),
                'AAAA' => Http::response($this->answer([['type' => 28, 'data' => '2606:2800:21f:cb07:6820:80da:af6b:8b2c']])),
                'MX' => Http::response($this->answer([['type' => 15, 'data' => '20 alt.mx.example.com.'], ['type' => 15, 'data' => '10 mx.example.com.']])),
                'TXT' => Http::response($this->answer([['type' => 16, 'data' => '"v=spf1 " "include:_spf.example.com ~all"']])),
                'NS' => Http::response($this->answer([['type' => 2, 'data' => 'NS1.Example.com.']])),
            };
        });

        $dns = Domains::dns();

        $this->assertSame(['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'], $dns->addresses('example.com'));
        $this->assertTrue($dns->resolves('example.com'));
        $this->assertSame(['mx.example.com', 'alt.mx.example.com'], $dns->mx('example.com'));
        $this->assertTrue($dns->acceptsMail('example.com'));
        $this->assertSame(['v=spf1 include:_spf.example.com ~all'], $dns->txt('example.com'));
        $this->assertSame(['ns1.example.com'], $dns->nameservers('example.com'));
    }

    public function test_a_null_mx_refuses_mail_and_failures_are_empty(): void
    {
        Http::fake(fn ($request) => $request['type'] === 'MX'
            ? Http::response($this->answer([['type' => 15, 'data' => '0 .']]))
            : Http::response('', 500));

        $this->assertFalse(Domains::dns()->acceptsMail('nomail.example'));
        $this->assertSame([], Domains::dns()->addresses('nomail.example'));
    }

    public function test_screen_asks_the_security_resolver_first_then_the_family_one(): void
    {
        Http::fake([
            'security.cloudflare-dns.com/*' => fn ($request) => Http::response($this->answer([
                ['type' => 1, 'data' => $request['name'] === 'bad.example' ? '0.0.0.0' : '93.184.215.14'],
            ])),
            'family.cloudflare-dns.com/*' => fn ($request) => Http::response($this->answer([
                ['type' => 1, 'data' => $request['name'] === 'adult.example' ? '0.0.0.0' : '93.184.215.14'],
            ])),
        ]);

        $this->assertSame(Screen::MALWARE, Domains::screen('bad.example'));
        $this->assertSame(Screen::ADULT, Domains::screen('adult.example'));
        $this->assertSame(Screen::CLEAN, Domains::screen('adult.example', adult: false));
        $this->assertSame(Screen::CLEAN, Domains::screen('fine.example'));
    }
}
