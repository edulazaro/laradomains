<?php

namespace EduLazaro\Laradomains\Support;

/**
 * Whether a name is one typing slip away from another: a letter added (`paypall`), a letter
 * dropped (`payal`), two neighbours swapped (`paypla`), or one letter replaced by another
 * that looks like it (`paypa1`, `goog1e`). Letter pairs that read as one letter count too:
 * `rn` for `m` (`arnazon`), `vv` for `w`, `cl` for `d`.
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
        if ($name === $target) {
            return false;
        }

        foreach (self::readings($name) as $reading) {
            if ($reading === $target || self::distance($reading, $target) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name as written, and with every letter pair that reads as one letter collapsed.
     *
     * @param string $name
     * @return list<string>
     */
    private static function readings(string $name): array
    {
        return array_values(array_unique([$name, strtr($name, self::LOOKALIKE_PAIRS)]));
    }

    /**
     * Edit distance where insertions, deletions and swaps of neighbours cost 1, a replacement
     * by a lookalike letter costs 1 and any other replacement costs 2 (so it never counts as
     * a single slip). Optimal string alignment, enough for a distance of one.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    private static function distance(string $a, string $b): int
    {
        $n = strlen($a);
        $m = strlen($b);

        if (abs($n - $m) > 1) {
            return 2;
        }

        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $replace = $a[$i - 1] === $b[$j - 1] ? 0 : (self::lookalike($a[$i - 1], $b[$j - 1]) ? 1 : 2);
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $replace);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
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
