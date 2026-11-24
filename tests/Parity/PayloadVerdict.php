<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Parity;

/**
 * What a mode's real request flow did with one payload.
 *
 * Three answers, not two: a parity suite that only asks "accepted?" folds a crash into a refusal, and a
 * crash is a 500 in production — a defect even when the payload deserved to be refused.
 */
enum PayloadVerdict: string
{
    /** The flow ran to the end: the DTO was built and validated. */
    case Accepted = 'accepted';

    /** The framework's own validation answer — a 422 (or the runtime's `RuntimeException`). */
    case Refused = 'refused';

    /** Anything else escaped the flow: a `TypeError`, an `Error`, a non-validation exception — a 500. */
    case Crashed = 'crashed';
}
