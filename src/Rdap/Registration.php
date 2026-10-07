<?php

namespace EduLazaro\Laradomains\Rdap;

use Carbon\CarbonImmutable;

/**
 * What a registry says about a domain over RDAP.
 *
 * Three outcomes that a caller usually needs to tell apart: the TLD has no RDAP service at all
 * (`supported` false: .es, .de, .io and others), the registry answered that the name is not
 * registered (`registered` false), or the lookup failed (`failed` true, with the reason). Only
 * the last one is worth retrying.
 */
final class Registration
{
    /**
     * @param string $domain The registrable name that was asked about.
     * @param bool $supported
     * @param bool|null $registered Null when unknown (unsupported or failed).
     * @param string|null $error
     * @param CarbonImmutable|null $registeredAt
     * @param CarbonImmutable|null $expiresAt
     * @param CarbonImmutable|null $updatedAt
     * @param string|null $registrar
     * @param string|null $registrarUrl
     * @param string|null $registrarIanaId
     * @param string|null $abuseEmail
     * @param list<string> $status EPP status values, e.g. "client transfer prohibited".
     * @param list<string> $nameservers
     * @param bool|null $dnssec
     * @param string|null $server The RDAP server that answered.
     */
    public function __construct(
        public readonly string $domain,
        public readonly bool $supported,
        public readonly ?bool $registered = null,
        public readonly ?string $error = null,
        public readonly ?CarbonImmutable $registeredAt = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?CarbonImmutable $updatedAt = null,
        public readonly ?string $registrar = null,
        public readonly ?string $registrarUrl = null,
        public readonly ?string $registrarIanaId = null,
        public readonly ?string $abuseEmail = null,
        public readonly array $status = [],
        public readonly array $nameservers = [],
        public readonly ?bool $dnssec = null,
        public readonly ?string $server = null,
    ) {}

    /**
     * @return bool
     */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Whole days since registration, or null without a date.
     *
     * @return int|null
     */
    public function ageInDays(): ?int
    {
        return $this->registeredAt === null
            ? null
            : (int) $this->registeredAt->diffInDays(CarbonImmutable::now(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'supported' => $this->supported,
            'registered' => $this->registered,
            'error' => $this->error,
            'registered_at' => $this->registeredAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'registrar' => $this->registrar,
            'registrar_url' => $this->registrarUrl,
            'registrar_iana_id' => $this->registrarIanaId,
            'abuse_email' => $this->abuseEmail,
            'status' => $this->status,
            'nameservers' => $this->nameservers,
            'dnssec' => $this->dnssec,
            'server' => $this->server,
        ];
    }
}
