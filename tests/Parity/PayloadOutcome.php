<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

use Throwable;

/**
 * One payload's verdict in one mode, with the evidence: which step answered and what it threw.
 */
final readonly class PayloadOutcome
{
    /**
     * @param string $stage the step that answered: `decode`, `deserialize`, `validate`, `hydrate`, `normalize`, `done`
     * @param bool $fromValidationEngine the throwable came out of the framework VALIDATOR itself (Symfony's
     *     `validate()`, Laravel's `fails()`); the boolean parity verdicts have always let such a throwable
     *     surface as a test error rather than count it as a refusal, and `accepted()` keeps that
     * @param object|null $dto the object an accepted payload was built into, where the mode hands one back
     * @param string|null $encoded the JSON the accepted DTO is written back as, where the mode writes one
     */
    private function __construct(
        public PayloadVerdict $verdict,
        public string $stage,
        public ?Throwable $throwable,
        public string $detail,
        public bool $fromValidationEngine,
        public ?object $dto = null,
        public ?string $encoded = null,
    ) {
    }

    public static function accepted(?object $dto = null, ?string $encoded = null): self
    {
        return new self(
            verdict: PayloadVerdict::Accepted,
            stage: 'done',
            throwable: null,
            detail: '',
            fromValidationEngine: false,
            dto: $dto,
            encoded: $encoded,
        );
    }

    public static function refused(string $stage, string $detail, ?Throwable $throwable = null): self
    {
        return new self(
            verdict: PayloadVerdict::Refused,
            stage: $stage,
            throwable: $throwable,
            detail: $detail,
            fromValidationEngine: false,
        );
    }

    public static function crashed(string $stage, Throwable $throwable, bool $fromValidationEngine = false): self
    {
        return new self(
            verdict: PayloadVerdict::Crashed,
            stage: $stage,
            throwable: $throwable,
            detail: $throwable::class . ': ' . $throwable->getMessage(),
            fromValidationEngine: $fromValidationEngine,
        );
    }

    /**
     * The two-valued verdict the parity suites compare: a refusal and a crash are both "not accepted",
     * except a crash of the validator itself, which is rethrown — exactly what those suites did before
     * the three-valued verdict existed.
     */
    public function isAccepted(): bool
    {
        if ($this->verdict === PayloadVerdict::Crashed && $this->fromValidationEngine && $this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->verdict === PayloadVerdict::Accepted;
    }
}
