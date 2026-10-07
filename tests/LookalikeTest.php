<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Domain;

class LookalikeTest extends TestCase
{
    public function test_a_label_written_wholly_in_cyrillic_lookalikes_is_caught(): void
    {
        $this->assertTrue(Domain::parse('аррӏе.com')->isLookalike());   // all Cyrillic
        $this->assertTrue(Domain::parse('раураӏ.com')->isLookalike());
        $this->assertTrue(Domain::parse('ορο.com')->isLookalike());     // Greek omicron and rho
    }

    public function test_real_words_in_another_script_are_not(): void
    {
        $this->assertFalse(Domain::parse('яндекс.com')->isLookalike());
        $this->assertFalse(Domain::parse('münchen.de')->isLookalike());
        $this->assertFalse(Domain::parse('日本語.jp')->isLookalike());
    }

    public function test_the_tld_of_the_same_script_exempts(): void
    {
        $this->assertFalse(Domain::parse('аррӏе.ru')->isLookalike());
        $this->assertFalse(Domain::parse('аррӏе.рф')->isLookalike());
        $this->assertFalse(Domain::parse('ορο.gr')->isLookalike());
    }

    public function test_skeleton_reads_the_name_in_latin_letters(): void
    {
        $this->assertSame('apple.com', Domain::parse('аррӏе.com')->skeleton());
        $this->assertSame('яндeкc.com', Domain::parse('яндекс.com')->skeleton());   // letters without a twin stay
        $this->assertSame('ejemplo.com', Domain::parse('ejemplo.com')->skeleton());
    }

    public function test_imitates_names_the_brand_a_domain_passes_for(): void
    {
        $brands = ['apple.com', 'paypal.com', 'google.com'];

        $this->assertSame('apple.com', Domain::parse('аррӏе.com')->imitates($brands));
        $this->assertSame('paypal.com', Domain::parse('www.paypa1.com')->imitates($brands));
        $this->assertSame('google.com', Domain::parse('g00gle.com')->imitates($brands));
        $this->assertNull(Domain::parse('www.apple.com')->imitates($brands));   // the brand itself
        $this->assertNull(Domain::parse('ejemplo.com')->imitates($brands));
    }

    public function test_impersonates_catches_the_usual_phishing_shapes(): void
    {
        $brands = ['paypal.com', 'bbc.co.uk', 'x.com'];

        $this->assertSame('paypal.com', Domain::parse('paypal.com.secure-login.io')->impersonates($brands));
        $this->assertSame('paypal.com', Domain::parse('paypal-secure.com')->impersonates($brands));
        $this->assertSame('paypal.com', Domain::parse('secure-paypa1.com')->impersonates($brands));
        $this->assertSame('paypal.com', Domain::parse('раураl.com')->impersonates($brands));
    }

    public function test_impersonates_leaves_the_brand_and_ordinary_names_alone(): void
    {
        $brands = ['paypal.com', 'bbc.co.uk', 'x.com'];

        $this->assertNull(Domain::parse('www.paypal.com')->impersonates($brands));
        $this->assertNull(Domain::parse('news.bbc.co.uk')->impersonates($brands));
        $this->assertNull(Domain::parse('paypalooza.com')->impersonates($brands));   // a part, not a substring
        $this->assertNull(Domain::parse('bbc-news.com')->impersonates($brands));     // under four letters
        $this->assertNull(Domain::parse('x-files.com')->impersonates($brands));
    }
}
