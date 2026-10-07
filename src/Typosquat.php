<?php

namespace EduLazaro\Laradomains;

/**
 * A domain one typing slip away from a brand, and which slip it was. The kinds do not weigh
 * the same: a lookalike letter or a pair that reads as one (`goog1e`, `arnazon`) is rarely an
 * accident; two letters swapped (`paypla`) is a medium sign; a letter added or dropped
 * (`paypall`, `payal`) is the weakest, and where ordinary words land (`apples` next to apple).
 */
final class Typosquat
{
    /** A letter replaced by a lookalike, or two letters that read as one. */
    public const LOOKALIKE = 'lookalike';

    /** Two neighbouring letters swapped. */
    public const SWAP = 'swap';

    /** A letter (or hyphen) added. */
    public const EXTRA = 'extra';

    /** A letter dropped. */
    public const MISSING = 'missing';

    /**
     * @param string $brand The brand domain, e.g. "paypal.com".
     * @param string $kind One of the class constants.
     */
    public function __construct(
        public readonly string $brand,
        public readonly string $kind,
    ) {}

    /**
     * @return bool Whether this is the strong kind of slip.
     */
    public function isLookalike(): bool
    {
        return $this->kind === self::LOOKALIKE;
    }
}
