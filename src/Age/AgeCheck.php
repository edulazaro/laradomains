<?php

namespace EduLazaro\Laradomains\Age;

use Carbon\CarbonImmutable;

/**
 * The outcome of an age lookup, which age() alone cannot tell apart: a date (`found`), a
 * definite answer without one (`none`), or a lookup that failed (`unknown`).
 *
 * `failed` lists the sources that did not answer, also when another one found a date: a date
 * from certificates or the Wayback Machine is a lower bound, and whoever uses it should know
 * whether the registry was even reached.
 */
final class AgeCheck
{
    public const FOUND = 'found';

    public const NONE = 'none';

    public const UNKNOWN = 'unknown';

    /** The TLD's registry publishes no RDAP and no fallback was asked for. */
    public const UNSUPPORTED_TLD = 'unsupported_tld';

    /** The registry has no data for the name (an RDAP 404). Not proof that it is free. */
    public const NOT_REGISTERED = 'not_registered';

    /** The registry answered, without a registration date. */
    public const NO_REGISTRATION_DATE = 'no_registration_date';

    /** crt.sh answered, with no certificate for the domain. */
    public const NO_CERTIFICATES = 'no_certificates';

    /** The Wayback Machine answered, with no capture of the domain. */
    public const NO_CAPTURES = 'no_captures';

    /** A source did not answer and none found a date. */
    public const LOOKUP_FAILED = 'lookup_failed';

    /**
     * @param Age|null $age
     * @param string $status
     * @param string|null $reason Why there is no date; null when found.
     * @param list<string> $failed Sources that did not answer: Age::RDAP, CERTIFICATES, WAYBACK.
     */
    public function __construct(
        public readonly ?Age $age,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $failed = [],
    ) {}

    /**
     * @param Age $age
     * @param list<string> $failed
     * @return self
     */
    public static function withAge(Age $age, array $failed = []): self
    {
        return new self($age, self::FOUND, null, $failed);
    }

    /**
     * @param string $reason
     * @return self
     */
    public static function withoutAge(string $reason): self
    {
        return new self(null, self::NONE, $reason);
    }

    /**
     * @param list<string> $failed
     * @return self
     */
    public static function lookupFailed(array $failed): self
    {
        return new self(null, self::UNKNOWN, self::LOOKUP_FAILED, $failed);
    }

    /**
     * @return bool
     */
    public function found(): bool
    {
        return $this->status === self::FOUND;
    }

    /**
     * A date, or a definite "there is none": not worth asking again soon.
     *
     * @return bool
     */
    public function definite(): bool
    {
        return $this->status !== self::UNKNOWN;
    }

    /**
     * @return bool
     */
    public function failed(): bool
    {
        return $this->status === self::UNKNOWN;
    }

    /**
     * Complete: every source asked answered. A found age with a failed source is not.
     *
     * @return bool
     */
    public function complete(): bool
    {
        return $this->failed === [];
    }

    /**
     * @return array{since: string|null, source: string|null, status: string, reason: string|null, failed: list<string>}
     */
    public function toArray(): array
    {
        return [
            'since' => $this->age?->since->toIso8601String(),
            'source' => $this->age?->source,
            'status' => $this->status,
            'reason' => $this->reason,
            'failed' => $this->failed,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self|null Null for data that is not a stored check (an entry from an older version).
     */
    public static function fromArray(array $data): ?self
    {
        if (! in_array($data['status'] ?? null, [self::FOUND, self::NONE, self::UNKNOWN], true)) {
            return null;
        }

        $age = isset($data['since'], $data['source'])
            ? new Age(CarbonImmutable::parse($data['since']), (string) $data['source'])
            : null;

        return new self($age, $data['status'], $data['reason'] ?? null, array_values((array) ($data['failed'] ?? [])));
    }
}
