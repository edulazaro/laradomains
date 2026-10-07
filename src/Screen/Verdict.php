<?php

namespace EduLazaro\Laradomains\Screen;

/**
 * What the two filtering resolvers said, each on its own: true (blocked), false (checked and
 * not blocked) or null (could not be checked, or not asked). Keeping them apart means a
 * failure of one does not hide what the other already settled: a domain the malware resolver
 * cleared is not malware, even if the adult resolver timed out.
 */
final class Verdict
{
    /**
     * @param string $domain
     * @param bool|null $malware
     * @param bool|null $adult
     * @param bool $adultAsked Whether the adult resolver was asked at all.
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?bool $malware,
        public readonly ?bool $adult,
        public readonly bool $adultAsked = true,
    ) {}

    /**
     * One word for the whole verdict: MALWARE wins whenever it was found, then ADULT, then
     * CLEAN when everything asked was answered, and UNKNOWN when something asked was not.
     *
     * @return string Screen::CLEAN, MALWARE, ADULT or UNKNOWN.
     */
    public function decision(): string
    {
        if ($this->malware === true) {
            return Screen::MALWARE;
        }

        if ($this->adultAsked && $this->adult === true) {
            return Screen::ADULT;
        }

        if ($this->malware === false && (! $this->adultAsked || $this->adult === false)) {
            return Screen::CLEAN;
        }

        return Screen::UNKNOWN;
    }

    /**
     * Whether every question asked got an answer.
     *
     * @return bool
     */
    public function complete(): bool
    {
        return $this->malware !== null && (! $this->adultAsked || $this->adult !== null);
    }

    /**
     * @return array{domain: string, malware: bool|null, adult: bool|null, adult_asked: bool}
     */
    public function toArray(): array
    {
        return ['domain' => $this->domain, 'malware' => $this->malware, 'adult' => $this->adult, 'adult_asked' => $this->adultAsked];
    }

    /**
     * @param array{domain: string, malware: bool|null, adult: bool|null, adult_asked: bool} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data['domain'], $data['malware'], $data['adult'], $data['adult_asked']);
    }
}
