<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

enum ValidationMessageKey: string
{
    case DTO_HAS_NO_CONSTRUCTOR = 'dto_has_no_constructor';
    case PARAMETER_HAS_UNSUPPORTED_TYPE = 'parameter_has_unsupported_type';
    case REQUIRED_PARAMETER_NOT_FOUND = 'required_parameter_not_found';
    case PARAM_EXPECTS_TYPE = 'param_expects_type';
    case CANNOT_CAST_NULL_TO_NON_NULLABLE_TYPE = 'cannot_cast_null_to_non_nullable_type';
    case PARAM_EXPECTS_DATE_STRING = 'param_expects_date_string';
    case EXPECTED_UPLOADED_FILE = 'expected_uploaded_file';
    case CANNOT_DESERIALIZE_NESTED_DTO_FROM_NON_ARRAY = 'cannot_deserialize_nested_dto_from_non_array';
    case UNSUPPORTED_TYPE = 'unsupported_type';
    case PARAM_EXPECTS_VALID_DATE_EMPTY_STRING = 'param_expects_valid_date_empty_string';
    case PARAM_EXPECTS_DATE_IN_FORMAT = 'param_expects_date_in_format';
    case PARAM_EXPECTS_VALID_DATE_TIME = 'param_expects_valid_date_time';
    case PARAM_EXPECTS_VALID_DATE_TIME_GENERIC = 'param_expects_valid_date_time_generic';
    case PARAM_EXPECTS_ENUM = 'param_expects_enum';
    case INVALID_ENUM_VALUE = 'invalid_enum_value';
    case DTO_HAS_INVALID_DISCRIMINATOR_METADATA = 'dto_has_invalid_discriminator_metadata';
    case PARAM_WAS_NOT_PROVIDED = 'param_was_not_provided';
    case PARAM_EXPECTS_DISCRIMINATOR_VALUE = 'param_expects_discriminator_value';
    case PARAM_HAS_INVALID_DISCRIMINATOR_VALUE = 'param_has_invalid_discriminator_value';
    case DISCRIMINATOR_MAPPING_UNKNOWN_CLASS = 'discriminator_mapping_unknown_class';
    case DISCRIMINATOR_MAPPING_MUST_EXTEND = 'discriminator_mapping_must_extend';
    case EXPECTS_BINARY_DATA = 'expects_binary_data';
    case ONE_OF_MULTIPLE_MATCHES = 'one_of_multiple_matches';
    case UNION_NO_MATCHING_BRANCH = 'union_no_matching_branch';
    case NUMERIC_MUST_BE_GREATER_THAN = 'numeric_must_be_greater_than';
    case NUMERIC_MUST_BE_GREATER_THAN_OR_EQUAL = 'numeric_must_be_greater_than_or_equal';
    case NUMERIC_MUST_BE_LESS_THAN = 'numeric_must_be_less_than';
    case NUMERIC_MUST_BE_LESS_THAN_OR_EQUAL = 'numeric_must_be_less_than_or_equal';
    case NUMERIC_MUST_BE_MULTIPLE_OF = 'numeric_must_be_multiple_of';
    case STRING_LENGTH_MIN = 'string_length_min';
    case STRING_LENGTH_MAX = 'string_length_max';
    case STRING_INVALID_REGEX_PATTERN = 'string_invalid_regex_pattern';
    case STRING_MUST_MATCH_PATTERN = 'string_must_match_pattern';
    case STRING_MUST_MATCH_FORMAT = 'string_must_match_format';
    case ARRAY_MIN_ITEMS = 'array_min_items';
    case ARRAY_MAX_ITEMS = 'array_max_items';
    case ARRAY_UNIQUE_ITEMS = 'array_unique_items';
    case CANNOT_GET_DTO_FROM_FAILED_RESULT = 'cannot_get_dto_from_failed_result';
}
