<?php

namespace EduLazaro\Laradomains\Tests;

use EduLazaro\Laradomains\Domain;
use EduLazaro\Laradomains\Typosquat;

class TyposquatTest extends TestCase
{
    private array $brands = ['paypal.com', 'amazon.com', 'google.com', 'apple.com', 'x.com'];

    public function test_one_typing_slip_away_from_a_brand(): void
    {
        foreach ([
            'paypall.com' => 'paypal.com',   // a letter added
            'payal.com' => 'paypal.com',     // a letter dropped
            'paypla.com' => 'paypal.com',    // neighbours swapped
            'pay-pal.com' => 'paypal.com',   // a hyphen added
            'goog1e.com' => 'google.com',    // a lookalike letter
            'gooqle.es' => 'google.com',
            'arnazon.com' => 'amazon.com',   // rn reads as m
            'appel.com' => 'apple.com',
        ] as $domain => $brand) {
            $this->assertSame($brand, Domain::parse($domain)->typosquats($this->brands), $domain);
        }
    }

    public function test_real_names_one_ordinary_letter_away_are_left_alone(): void
    {
        $this->assertNull(Domain::parse('paypay.com')->typosquats($this->brands));    // a real company
        $this->assertNull(Domain::parse('amazom.com')->typosquats($this->brands));    // m and n do not look alike
        $this->assertNull(Domain::parse('example.com')->typosquats($this->brands));
    }

    public function test_the_brand_itself_and_short_brands_never_match(): void
    {
        $this->assertNull(Domain::parse('www.paypal.com')->typosquats($this->brands));
        $this->assertNull(Domain::parse('paypal.net')->typosquats($this->brands));    // same name, another TLD: not a slip
        $this->assertNull(Domain::parse('xx.com')->typosquats($this->brands));        // brands under five letters
    }

    public function test_typosquat_says_which_kind_of_slip_it_was(): void
    {
        foreach ([
            'goog1e.com' => Typosquat::LOOKALIKE,
            'arnazon.com' => Typosquat::LOOKALIKE,
            'paypla.com' => Typosquat::SWAP,
            'paypall.com' => Typosquat::EXTRA,
            'payal.com' => Typosquat::MISSING,
        ] as $domain => $kind) {
            $typosquat = Domain::parse($domain)->typosquat($this->brands);
            $this->assertSame($kind, $typosquat->kind, $domain);
        }

        $this->assertTrue(Domain::parse('goog1e.com')->typosquat($this->brands)->isLookalike());
        $this->assertSame('google.com', Domain::parse('goog1e.com')->typosquat($this->brands)->brand);
    }

    public function test_kinds_keeps_only_the_slips_asked_for(): void
    {
        $strong = [Typosquat::LOOKALIKE];

        $this->assertSame('google.com', Domain::parse('goog1e.com')->typosquats($this->brands, kinds: $strong));
        $this->assertNull(Domain::parse('apples.com')->typosquats($this->brands, kinds: $strong));   // a word, weak kind
        $this->assertSame('apple.com', Domain::parse('apples.com')->typosquats($this->brands));       // matched without the filter
    }
}
