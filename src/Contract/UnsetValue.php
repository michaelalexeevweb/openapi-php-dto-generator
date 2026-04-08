<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

/**
 * Sentinel enum for detecting when optional parameters were not passed to constructor.
 *
 * This allows distinguishing between:
 * - Parameter not passed: UnsetValue::UNSET
 * - Parameter passed as null: null
 * - Parameter passed with value: actual value
 *
 * Works correctly with named arguments regardless of parameter order.
 */
enum UnsetValue
{
    case UNSET;
}

