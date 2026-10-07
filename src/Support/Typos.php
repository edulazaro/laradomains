<?php

namespace EduLazaro\Laradomains\Support;

use EduLazaro\Laradomains\Typosquat;

/**
 * Whether a name is one typing slip away from another, and which slip: a letter replaced by
 * one that looks like it (`paypa1`, `goog1e`) or two letters that read as one (`rn` for `m`,
 * `vv` for `w`, `cl` for `d`); two neighbours swapped (`paypla`); a letter added (`paypall`) or
 * dropped (`payal`).
 *
 * Any other replacement does not count: `paypay` is one letter from `paypal` and is a real
 * company, and so are plenty of names one keystroke away from a brand.
 */
final class Typos
{
    /** @var list<array{string, string}> Letters that are hard to tell apart in a URL bar. */
    private const LOOKALIKE_LETTERS = [['i', 'l'], ['l', '1'], ['i', '1'], ['o', '0'], ['u', 'v'], ['g', 'q']];

    /** @var array<string, string> Two letters that read as one. */
    private const LOOKALIKE_PAIRS = ['rn' => 'm', 'vv' => 'w', 'cl' => 'd'];

    /**
     * @param string $name
     * @param string $target
     * @return bool True when $name is not $target but one slip away from it.
     */
    public static function oneSlipFrom(string $name, string $target): bool
    {
        return self::slip($name, $target) !== null;
    }

    /**
     * The kind of slip that turns $target into $name, or null when there is none (or they are
     * the same). The strongest kind wins when more than one would explain it.
     *
     * @param string $name
     * @param string $target
     * @return string|null A Typosquat kind.
     */
    public static function slip(string $name, string $target): ?string
    {
        if ($name === $target) {
            return null;
        }

        $collapsed = strtr($name, self::LOOKALIKE_PAIRS);

        if ($collapsed !== $name && $collapsed === $target) {
            return Typosquat::LOOKALIKE;
        }

        foreach (array_unique([$name, $collapsed]) as $reading) {
            if ($kind = self::singleEdit($reading, $target)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param string $a
     * @param string $b
     * @return string|null
     */
    private static function singleEdit(string $a, string $b): ?string
    {
        $n = strlen($a);
        $m = strlen($b);

        if ($n === $m) {
            $diff = [];
            for ($i = 0; $i < $n; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $diff[] = $i;
                }
            }

            if (count($diff) === 1 && self::lookalike($a[$diff[0]], $b[$diff[0]])) {
                return Typosquat::LOOKALIKE;
            }

            if (count($diff) === 2 && $diff[1] === $diff[0] + 1
                && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]]) {
                return Typosquat::SWAP;
            }

            return null;
        }

        if ($n === $m + 1 && self::dropsToTarget($a, $b)) {
            return Typosquat::EXTRA;
        }

        if ($n + 1 === $m && self::dropsToTarget($b, $a)) {
            return Typosquat::MISSING;
        }

        return null;
    }

    /**
     * Whether removing one character from $longer gives $shorter.
     *
     * @param string $longer
     * @param string $shorter
     * @return bool
     */
    private static function dropsToTarget(string $longer, string $shorter): bool
    {
        for ($i = 0, $len = strlen($longer); $i < $len; $i++) {
            if (substr($longer, 0, $i).substr($longer, $i + 1) === $shorter) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $x
     * @param string $y
     * @return bool
     */
    private static function lookalike(string $x, string $y): bool
    {
        foreach (self::LOOKALIKE_LETTERS as [$p, $q]) {
            if (($x === $p && $y === $q) || ($x === $q && $y === $p)) {
                return true;
            }
        }

        return false;
    }
}
