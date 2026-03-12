<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

final class ValidationMessageKey
{
    public const DTO_HAS_NO_CONSTRUCTOR = 'dto_has_no_constructor';
    public const PARAMETER_HAS_UNSUPPORTED_TYPE = 'parameter_has_unsupported_type';
    public const REQUIRED_PARAMETER_NOT_FOUND = 'required_parameter_not_found';
    public const PARAM_EXPECTS_TYPE = 'param_expects_type';
    public const CANNOT_CAST_NULL_TO_NON_NULLABLE_TYPE = 'cannot_cast_null_to_non_nullable_type';
    public const PARAM_EXPECTS_DATE_STRING = 'param_expects_date_string';
    public const EXPECTED_UPLOADED_FILE = 'expected_uploaded_file';
    public const CANNOT_DESERIALIZE_NESTED_DTO_FROM_NON_ARRAY = 'cannot_deserialize_nested_dto_from_non_array';
    public const UNSUPPORTED_TYPE = 'unsupported_type';
    public const PARAM_EXPECTS_VALID_DATE_EMPTY_STRING = 'param_expects_valid_date_empty_string';
    public const PARAM_EXPECTS_DATE_IN_FORMAT = 'param_expects_date_in_format';
    public const PARAM_EXPECTS_VALID_DATE_TIME = 'param_expects_valid_date_time';
    public const PARAM_EXPECTS_VALID_DATE_TIME_GENERIC = 'param_expects_valid_date_time_generic';
    public const PARAM_EXPECTS_ENUM = 'param_expects_enum';
    public const INVALID_ENUM_VALUE = 'invalid_enum_value';
    public const DTO_HAS_INVALID_DISCRIMINATOR_METADATA = 'dto_has_invalid_discriminator_metadata';
    public const PARAM_WAS_NOT_PROVIDED = 'param_was_not_provided';
    public const PARAM_EXPECTS_DISCRIMINATOR_VALUE = 'param_expects_discriminator_value';
    public const PARAM_HAS_INVALID_DISCRIMINATOR_VALUE = 'param_has_invalid_discriminator_value';
    public const DISCRIMINATOR_MAPPING_UNKNOWN_CLASS = 'discriminator_mapping_unknown_class';
    public const DISCRIMINATOR_MAPPING_MUST_EXTEND = 'discriminator_mapping_must_extend';
    public const EXPECTS_BINARY_DATA = 'expects_binary_data';
    public const ONE_OF_MULTIPLE_MATCHES = 'one_of_multiple_matches';
    public const UNION_NO_MATCHING_BRANCH = 'union_no_matching_branch';
    public const NUMERIC_MUST_BE_GREATER_THAN = 'numeric_must_be_greater_than';
    public const NUMERIC_MUST_BE_GREATER_THAN_OR_EQUAL = 'numeric_must_be_greater_than_or_equal';
    public const NUMERIC_MUST_BE_LESS_THAN = 'numeric_must_be_less_than';
    public const NUMERIC_MUST_BE_LESS_THAN_OR_EQUAL = 'numeric_must_be_less_than_or_equal';
    public const NUMERIC_MUST_BE_MULTIPLE_OF = 'numeric_must_be_multiple_of';
    public const STRING_LENGTH_MIN = 'string_length_min';
    public const STRING_LENGTH_MAX = 'string_length_max';
    public const STRING_INVALID_REGEX_PATTERN = 'string_invalid_regex_pattern';
    public const STRING_MUST_MATCH_PATTERN = 'string_must_match_pattern';
    public const STRING_MUST_MATCH_FORMAT = 'string_must_match_format';
    public const ARRAY_MIN_ITEMS = 'array_min_items';
    public const ARRAY_MAX_ITEMS = 'array_max_items';
    public const ARRAY_UNIQUE_ITEMS = 'array_unique_items';
    public const CANNOT_GET_DTO_FROM_FAILED_RESULT = 'cannot_get_dto_from_failed_result';

    private function __construct()
    {
    }
}

