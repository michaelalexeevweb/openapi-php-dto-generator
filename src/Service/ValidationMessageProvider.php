<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\ValidationMessageProviderInterface;

final class ValidationMessageProvider implements ValidationMessageProviderInterface
{
    /**
     * @var array<string, string>
     */
    private array $messages;

    /**
     * @param array<string, string> $messages
     */
    public function __construct(array $messages = [])
    {
        $this->messages = array_replace(self::defaults(), $messages);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function format(string|ValidationMessageKey $key, array $parameters = []): string
    {
        $resolvedKey = $key instanceof ValidationMessageKey ? $key->value : $key;
        $template = $this->messages[$resolvedKey] ?? $resolvedKey;
        $replacements = [];

        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value === null ? 'null' : (string)$value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            ValidationMessageKey::DTO_HAS_NO_CONSTRUCTOR->value => 'DTO {dtoClass} has no constructor.',
            ValidationMessageKey::PARAMETER_HAS_UNSUPPORTED_TYPE->value => 'Parameter ${paramName} in {dtoClass} has unsupported type.',
            ValidationMessageKey::REQUIRED_PARAMETER_NOT_FOUND->value => 'Required parameter "{paramName}" not found in request.',
            ValidationMessageKey::PARAM_EXPECTS_TYPE->value => 'param "{paramPath}" expects {expectedType}, got {actualType}',
            ValidationMessageKey::CANNOT_CAST_NULL_TO_NON_NULLABLE_TYPE->value => 'Cannot cast null to non-nullable type {typeName}.',
            ValidationMessageKey::PARAM_EXPECTS_DATE_STRING->value => 'param "{paramPath}" expects a date string, got {actualType}',
            ValidationMessageKey::EXPECTED_UPLOADED_FILE->value => 'Expected UploadedFile but got something else.',
            ValidationMessageKey::CANNOT_DESERIALIZE_NESTED_DTO_FROM_NON_ARRAY->value => 'Cannot deserialize nested DTO {typeName} from non-array value.',
            ValidationMessageKey::UNSUPPORTED_TYPE->value => 'Unsupported type: {typeName}',
            ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_EMPTY_STRING->value => 'param "{paramPath}" expects a valid date{formatHint}, got empty string',
            ValidationMessageKey::PARAM_EXPECTS_DATE_IN_FORMAT->value => 'param "{paramPath}" expects a date in {format} format (e.g. {example}), got "{value}"',
            ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_TIME->value => 'param "{paramPath}" expects a valid date-time (e.g. {example}), got "{value}"',
            ValidationMessageKey::PARAM_EXPECTS_VALID_DATE_TIME_GENERIC->value => 'param "{paramPath}" expects a valid date/time, got "{value}"',
            ValidationMessageKey::PARAM_EXPECTS_ENUM->value => 'param "{paramPath}" expects enum {enumClass}, got "{value}". Allowed: {allowed}',
            ValidationMessageKey::INVALID_ENUM_VALUE->value => 'Invalid enum value "{value}" for {enumClass}.',
            ValidationMessageKey::DTO_HAS_INVALID_DISCRIMINATOR_METADATA->value => 'DTO {baseClass} has invalid discriminator metadata.',
            ValidationMessageKey::PARAM_WAS_NOT_PROVIDED->value => 'param "{paramPath}" wasn\'t provided',
            ValidationMessageKey::PARAM_EXPECTS_DISCRIMINATOR_VALUE->value => 'param "{paramPath}" expects string|int discriminator value, got {actualType}',
            ValidationMessageKey::PARAM_HAS_INVALID_DISCRIMINATOR_VALUE->value => 'param "{paramPath}" has invalid discriminator value "{value}". Allowed: {allowed}',
            ValidationMessageKey::DISCRIMINATOR_MAPPING_UNKNOWN_CLASS->value => 'Discriminator mapping for "{paramPath}" points to unknown class "{targetClass}".',
            ValidationMessageKey::DISCRIMINATOR_MAPPING_MUST_EXTEND->value => 'Discriminator mapping class {targetClass} must extend or implement {baseClass}.',
            ValidationMessageKey::EXPECTS_BINARY_DATA->value => '{subject} expects binary data, got {actualType}',
            ValidationMessageKey::ONE_OF_MULTIPLE_MATCHES->value => '{subject} matches more than one allowed oneOf branch',
            ValidationMessageKey::UNION_NO_MATCHING_BRANCH->value => '{subject} does not match any {kind} branch',
            ValidationMessageKey::NUMERIC_MUST_BE_GREATER_THAN->value => '{subject} must be greater than {value}',
            ValidationMessageKey::NUMERIC_MUST_BE_GREATER_THAN_OR_EQUAL->value => '{subject} must be greater than or equal to {value}',
            ValidationMessageKey::NUMERIC_MUST_BE_LESS_THAN->value => '{subject} must be less than {value}',
            ValidationMessageKey::NUMERIC_MUST_BE_LESS_THAN_OR_EQUAL->value => '{subject} must be less than or equal to {value}',
            ValidationMessageKey::NUMERIC_MUST_BE_MULTIPLE_OF->value => '{subject} must be a multiple of {value}',
            ValidationMessageKey::STRING_LENGTH_MIN->value => '{subject} length must be at least {value} characters',
            ValidationMessageKey::STRING_LENGTH_MAX->value => '{subject} length must be at most {value} characters',
            ValidationMessageKey::STRING_INVALID_REGEX_PATTERN->value => '{subject} has invalid regex pattern in schema: {pattern}',
            ValidationMessageKey::STRING_MUST_MATCH_PATTERN->value => '{subject} must match pattern {pattern}',
            ValidationMessageKey::STRING_MUST_MATCH_FORMAT->value => '{subject} must match format {format}',
            ValidationMessageKey::ARRAY_MIN_ITEMS->value => '{subject} must contain at least {value} items',
            ValidationMessageKey::ARRAY_MAX_ITEMS->value => '{subject} must contain at most {value} items',
            ValidationMessageKey::ARRAY_UNIQUE_ITEMS->value => '{subject} must contain unique items',
            ValidationMessageKey::CANNOT_GET_DTO_FROM_FAILED_RESULT->value => 'Cannot get DTO from failed validation result.',
        ];
    }
}
