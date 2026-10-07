<?php

namespace EduLazaro\Laradomains\Age;

use Carbon\CarbonImmutable;

/**
 * How old a domain is, and how we know: `rdap` is the registration date from the registry,
 * `wayback` the first capture in the Wayback Machine, a lower bound used where the registry
 * has no RDAP.
 */
final class Age
{
    public const RDAP = 'rdap';

    public const WAYBACK = 'wayback';

    /**
     * @param CarbonImmutable $since
     * @param string $source
     */
    public function __construct(
        public readonly CarbonImmutable $since,
        public readonly string $source,
    ) {}

    /**
     * @return int
     */
    public function days(): int
    {
        return (int) $this->since->diffInDays(CarbonImmutable::now(), true);
    }

    /**
     * @return float
     */
    public function years(): float
    {
        return round($this->days() / 365.25, 1);
    }

    /**
     * Registered (or first seen) within the last given days.
     *
     * @param int $days
     * @return bool
     */
    public function isNewerThan(int $days): bool
    {
        return $this->days() < $days;
    }
}
