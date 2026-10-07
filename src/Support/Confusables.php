<?php

namespace EduLazaro\Laradomains\Support;

/**
 * Cyrillic and Greek letters that look like a Latin one, from Unicode's confusables data
 * (UTS #39), limited to the lower-case letters that are indistinguishable in common fonts.
 *
 * It catches the lookalike that mixing scripts does not: a label written wholly in Cyrillic,
 * `аррӏе`, reads as "apple" without mixing anything. The rule is the one browsers apply before
 * showing such a name in Unicode: if every letter of a non-Latin label has a Latin twin, the
 * label can pass for a Latin word, unless the TLD itself belongs to that script (`.рф`, `.ru`,
 * `.gr`), where writing in it is the norm.
 */
final class Confusables
{
    /** @var array<string, string> */
    private const LATIN = [
        // Cyrillic
        'а' => 'a', 'с' => 'c', 'ԁ' => 'd', 'е' => 'e', 'һ' => 'h', 'і' => 'i', 'ј' => 'j',
        'ӏ' => 'l', 'о' => 'o', 'р' => 'p', 'ԛ' => 'q', 'ѕ' => 's', 'у' => 'y', 'ү' => 'y',
        'х' => 'x', 'ԝ' => 'w',
        // Greek
        'α' => 'a', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'υ' => 'u',
        'χ' => 'x', 'γ' => 'y',
    ];

    /** ccTLDs where Cyrillic or Greek names are the norm; IDN TLDs (.рф, .ελ) count too. */
    private const NATIVE_TLDS = ['ru', 'su', 'ua', 'by', 'kz', 'bg', 'mk', 'rs', 'me', 'mn', 'uz', 'kg', 'tj', 'gr', 'cy'];

    /**
     * The Latin reading of a Unicode string: each confusable letter replaced by its twin.
     *
     * @param string $unicode
     * @return string
     */
    public static function skeleton(string $unicode): string
    {
        return strtr(mb_strtolower($unicode), self::LATIN);
    }

    /**
     * Whether a label written wholly in another script can pass for a Latin word.
     *
     * @param string $label Unicode, one label.
     * @param string $tld The TLD in ASCII; an internationalised one (`xn--p1ai`, .рф) exempts.
     * @return bool
     */
    public static function isWholeScriptLookalike(string $label, string $tld): bool
    {
        if (preg_match('/[a-z]/i', $label) || in_array($tld, self::NATIVE_TLDS, true) || str_starts_with($tld, 'xn--')) {
            return false;
        }

        $letters = preg_replace('/[0-9-]/', '', mb_strtolower($label));

        if ($letters === '') {
            return false;
        }

        foreach (mb_str_split($letters) as $char) {
            if (! isset(self::LATIN[$char])) {
                return false;
            }
        }

        return true;
    }
}
