<?php

namespace EduLazaro\Laradomains\Screen;

/**
 * What the two filtering resolvers said, each on its own: true (blocked), false (checked and
 * not blocked) or null (could not be checked, or not asked). Keeping them apart means a
 * failure of one does not hide what the other already settled: a domain the malware resolver
 * cleared is not malware, even if the adult resolver timed out.
 *
 * `adult` is the family resolver's answer, and that resolver blocks malware as well as adult
 * content: its block alone says "malware or adult", which decision() reads as UNKNOWN unless
 * the malware resolver has answered. blocked() says whether anything blocked it at all.
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
     * One word for the whole verdict: MALWARE whenever the malware resolver found it; ADULT
     * only when the malware resolver cleared the domain and the family one blocked it (that
     * resolver blocks malware too, so without the first answer its block could be either);
     * CLEAN when everything asked was answered and nothing blocked; UNKNOWN otherwise.
     *
     * @return string Screen::CLEAN, MALWARE, ADULT or UNKNOWN.
     */
    public function decision(): string
    {
        if ($this->malware === true) {
            return Screen::MALWARE;
        }

        if ($this->malware === false && $this->adultAsked && $this->adult === true) {
            return Screen::ADULT;
        }

        if ($this->malware === false && (! $this->adultAsked || $this->adult === false)) {
            return Screen::CLEAN;
        }

        return Screen::UNKNOWN;
    }

    /**
     * Whether any resolver blocked the domain, for a caller that only needs to know if it is
     * blocked, whatever the reason: true as soon as one did, false when every one asked
     * answered without blocking, null otherwise.
     *
     * @return bool|null
     */
    public function blocked(): ?bool
    {
        if ($this->malware === true || ($this->adultAsked && $this->adult === true)) {
            return true;
        }

        return $this->complete() ? false : null;
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
