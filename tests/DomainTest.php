<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Domain;
use InvalidArgumentException;

class DomainTest extends TestCase
{
    public function test_it_keeps_only_the_host_from_what_people_paste(): void
    {
        $this->assertSame('ejemplo.com', Domain::parse('https://Ejemplo.COM:8080/ruta?x=1#a')->ascii);
        $this->assertSame('ejemplo.com', Domain::parse('user@ejemplo.com')->ascii);
        $this->assertSame('ejemplo.com', Domain::parse('ejemplo.com.')->ascii);
        $this->assertSame('www.ejemplo.com', Domain::parse('www.ejemplo.com')->ascii);
    }

    public function test_it_carries_both_spellings_of_an_internationalised_name(): void
    {
        $domain = Domain::parse('ñandú.es');

        $this->assertSame('xn--and-6ma2c.es', $domain->ascii);
        $this->assertSame('ñandú.es', $domain->unicode);
        $this->assertTrue($domain->isIdn());
        $this->assertSame('ñandú.es', Domain::parse('xn--and-6ma2c.es')->unicode);
    }

    public function test_it_refuses_what_is_not_a_domain(): void
    {
        foreach (['', 'localhost', '127.0.0.1', '[::1]', 'no spaces.com', 'a..b.com'] as $input) {
            $this->assertNull(Domain::tryParse($input), $input);
        }

        $this->expectException(InvalidArgumentException::class);
        Domain::parse('not a domain');
    }

    public function test_registrable_domain_follows_the_public_suffix_list(): void
    {
        $this->assertSame('bbc.co.uk', Domain::parse('news.bbc.co.uk')->registrable());
        $this->assertSame('co.uk', Domain::parse('news.bbc.co.uk')->suffix());
        $this->assertSame('news', Domain::parse('news.bbc.co.uk')->subdomain());
        $this->assertSame('ejemplo.gob.es', Domain::parse('www.ejemplo.gob.es')->registrable());
        $this->assertSame('example.com', Domain::parse('a.b.example.com')->registrable());
        $this->assertNull(Domain::parse('co.uk')->registrable());
    }

    public function test_private_suffixes_are_opt_in(): void
    {
        $domain = Domain::parse('edulazaro.github.io');

        $this->assertSame('github.io', $domain->registrable());
        $this->assertSame('edulazaro.github.io', $domain->registrable(private: true));
        $this->assertTrue($domain->isRegistrable(private: true));
        $this->assertFalse($domain->isRegistrable());
    }

    public function test_wildcard_and_exception_rules(): void
    {
        // *.ck is a suffix, but www.ck is an exception and registrable itself.
        $this->assertSame('foo.bar.ck', Domain::parse('x.foo.bar.ck')->registrable());
        $this->assertSame('www.ck', Domain::parse('www.ck')->registrable());
    }

    public function test_unlisted_tlds_fall_back_to_the_last_label(): void
    {
        $this->assertSame('example.zzzz', Domain::parse('www.example.zzzz')->registrable());
    }

    public function test_without_www_and_equality(): void
    {
        $this->assertSame('ejemplo.com', Domain::parse('www.ejemplo.com')->withoutWww()->ascii);
        $this->assertSame('www.com', Domain::parse('www.com')->withoutWww()->ascii);
        $this->assertTrue(Domain::parse('https://Ejemplo.com/x')->equals('ejemplo.com'));
    }

    public function test_lookalikes_are_told_apart_from_accented_names(): void
    {
        $this->assertTrue(Domain::parse('аpple.com')->isLookalike()); // Cyrillic а
        $this->assertFalse(Domain::parse('ñandú.es')->isLookalike());
        $this->assertFalse(Domain::parse('apple.com')->isLookalike());
    }
}
