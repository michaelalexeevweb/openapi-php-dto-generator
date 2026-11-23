<?php

declare(strict_types=1);

/*
 * What does NOT agree with the JSON-Schema-Test-Suite yet, per file › group › test › mode:
 * [status, reason]. Status is one of diverges, crashes, generation-fails, skipped,
 * not-representable; the test key "*" covers the whole group. Read by
 * tests/Parity/JsonSchemaTestSuiteTest.php — burn it down, and re-record with JSTS_RECORD=1.
 */

return [
    'additionalProperties.json' => [
        'additionalProperties does not look in applicators' => [
            'properties defined in allOf are not examined' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
    ],
    'contains.json' => [
        'contains keyword validation' => [
            'not array is valid' => [
                'symfony' => ['diverges', 'documented: the data is {}, which the framework hands over as an empty list (README.support-matrix, empty JSON array row)'],
                'laravel' => ['diverges', 'documented: the data is {}, which the framework hands over as an empty list (README.support-matrix, empty JSON array row)'],
                'laravel-data' => ['diverges', 'documented: the data is {}, which the framework hands over as an empty list (README.support-matrix, empty JSON array row)'],
                'yii3' => ['diverges', 'documented: the data is {}, which the framework hands over as an empty list (README.support-matrix, empty JSON array row)'],
            ],
        ],
    ],
    'content.json' => [
        'validation of string-encoded content based on media type' => [
            'an invalid JSON document; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
        ],
        'validation of binary string-encoding' => [
            'an invalid base64 string (% is not a valid character); validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
        ],
        'validation of binary-encoded media type documents' => [
            'a validly-encoded invalid JSON document; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
            'an invalid base64 string that is valid JSON; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
        ],
        'validation of binary-encoded media type documents with schema' => [
            'an invalid base64-encoded JSON document; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
            'an empty object as a base64-encoded JSON document; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
            'an empty array as a base64-encoded JSON document' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
            'a validly-encoded invalid JSON document; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
            'an invalid base64 string that is valid JSON; validates true' => [
                'runtime' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'symfony' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'laravel-data' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
                'yii3' => ['diverges', 'deliberate: contentEncoding/contentMediaType/contentSchema are asserted (README.support-matrix); the suite treats them as annotations'],
            ],
        ],
    ],
    'defs.json' => [
        'validate definition against metaschema' => [
            '*' => [
                'runtime' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'symfony' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'laravel' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'laravel-data' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'yii3' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
            ],
        ],
    ],
    'dynamicRef.json' => [
        'after leaving a dynamic scope, it is not used by a $dynamicRef' => [
            'string matches /$defs/thingy, but the $dynamicRef does not stop here' => [
                'runtime' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'symfony' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'laravel' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'laravel-data' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'yii3' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
            ],
            'first_scope is not in dynamic scope for the $dynamicRef' => [
                'runtime' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'symfony' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'laravel' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'laravel-data' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
                'yii3' => ['diverges', 'generator: an untyped allOf/if becomes an object DTO or its branches are folded together'],
            ],
        ],
        'strict-tree schema, guards against misspelled properties' => [
            'instance with misspelled field' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'instance with correct field' => [
                'runtime' => ['diverges', 'generator: $dynamicRef resolves statically, so the properties the outermost dynamic anchor evaluates are never seen'],
                'laravel' => ['diverges', 'generator: $dynamicRef resolves statically, so the properties the outermost dynamic anchor evaluates are never seen'],
                'laravel-data' => ['diverges', 'generator: $dynamicRef resolves statically, so the properties the outermost dynamic anchor evaluates are never seen'],
            ],
        ],
        'tests for implementation dynamic anchor and reference link' => [
            'incorrect extended schema' => [
                'symfony' => ['diverges', 'accepts data the suite rejects (untriaged)'],
            ],
        ],
        '$ref and $dynamicAnchor are independent of order - $defs first' => [
            'incorrect extended schema' => [
                'symfony' => ['diverges', 'accepts data the suite rejects (untriaged)'],
            ],
        ],
        '$ref and $dynamicAnchor are independent of order - $ref first' => [
            'incorrect extended schema' => [
                'symfony' => ['diverges', 'accepts data the suite rejects (untriaged)'],
            ],
        ],
    ],
    'enum.json' => [
        'enum with 0 does not match false' => [
            'float zero is valid' => [
                'symfony' => ['diverges', 'documented: the Symfony serializer refuses a float for an int-backed enum, as for an int property (README.support-matrix, 42.0 row)'],
            ],
        ],
        'enum with 1 does not match true' => [
            'float one is valid' => [
                'symfony' => ['diverges', 'documented: the Symfony serializer refuses a float for an int-backed enum, as for an int property (README.support-matrix, 42.0 row)'],
            ],
        ],
    ],
    'format.json' => [
        'email format' => [
            'invalid email string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'idn-email format' => [
            'invalid idn-email string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'regex format' => [
            'invalid regex string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'ipv4 format' => [
            'invalid ipv4 string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'ipv6 format' => [
            'invalid ipv6 string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'idn-hostname format' => [
            'invalid idn-hostname string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'hostname format' => [
            'invalid hostname string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'date format' => [
            'invalid date string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'date-time format' => [
            'invalid date-time string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'time format' => [
            'invalid time string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'json-pointer format' => [
            'invalid json-pointer string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'relative-json-pointer format' => [
            'invalid relative-json-pointer string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'uri format' => [
            'invalid uri string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'uri-template format' => [
            'invalid uri-template string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'uuid format' => [
            'invalid uuid string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
        'duration format' => [
            'invalid duration string is only an annotation by default' => [
                'runtime' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'symfony' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'laravel-data' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
                'yii3' => ['diverges', 'deliberate: format is asserted, not an annotation (README.validation, extended string formats)'],
            ],
        ],
    ],
    'items.json' => [
        'nested items' => [
            'nested array with invalid type' => [
                'laravel-data' => ['diverges', 'documented: lenient scalar types in this mode — the numeric string "1" four lists down (README.support-matrix)'],
            ],
        ],
    ],
    'minProperties.json' => [
        'minProperties validation' => [
            'ignores arrays' => [
                'symfony' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel-data' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'yii3' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
            ],
        ],
    ],
    'ref.json' => [
        'root pointer ref' => [
            'match' => [
                'runtime' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'symfony' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel-data' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'yii3' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
            ],
            'recursive match' => [
                'runtime' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'symfony' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel' => ['crashes', 'TypeError: ProbeF::fromValidated(): Argument #1 ($data) must be of type array, false given, called in ProbeF.php on line 398'],
                'laravel-data' => ['crashes', 'CannotCreateData: Could not create a `ProbeF` object from value with type `boolean`, no normalizer was found'],
                'yii3' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
            ],
            'mismatch' => [
                'symfony' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'yii3' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
            ],
        ],
        'relative pointer ref to object' => [
            '*' => [
                'runtime' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/ProbeF/pro...'],
                'symfony' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/ProbeF/pro...'],
                'laravel' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/ProbeF/pro...'],
                'laravel-data' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/ProbeF/pro...'],
                'yii3' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/ProbeF/pro...'],
            ],
        ],
        'remote ref, containing refs itself' => [
            '*' => [
                'runtime' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'symfony' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'laravel' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'laravel-data' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
                'yii3' => ['skipped', '$ref "https://json-schema.org/draft/2020-12/schema" needs the json-schema.org metaschema, not resolvable offline'],
            ],
        ],
        '$ref to boolean schema true' => [
            '*' => [
                'runtime' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'symfony' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'laravel' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'laravel-data' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'yii3' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
            ],
        ],
        '$ref to boolean schema false' => [
            '*' => [
                'runtime' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'symfony' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'laravel' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'laravel-data' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
                'yii3' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas/bool (woul...'],
            ],
        ],
        'ref creates new scope when adjacent to keywords' => [
            'referenced subschema doesn\'t see annotations from properties' => [
                'symfony' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'yii3' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
            ],
        ],
        'refs with relative uris and defs' => [
            'invalid on inner field' => [
                'runtime' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'symfony' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel-data' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'yii3' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
            ],
        ],
        'relative refs with absolute uris and defs' => [
            'invalid on inner field' => [
                'runtime' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'symfony' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'laravel-data' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
                'yii3' => ['diverges', 'generator: a $ref below an untyped schema, an escaped/encoded pointer or a root pointer is ignored or mis-resolved'],
            ],
        ],
        'empty tokens in $ref json-pointer' => [
            '*' => [
                'runtime' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas//$defs/ (w...'],
                'symfony' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas//$defs/ (w...'],
                'laravel' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas//$defs/ (w...'],
                'laravel-data' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas//$defs/ (w...'],
                'yii3' => ['generation-fails', 'RuntimeException: Unresolvable reference: the document declares no such schema, so the generated code would name a class that is never emitted. #/components/schemas//$defs/ (w...'],
            ],
        ],
    ],
    'required.json' => [
        'required validation' => [
            'ignores arrays' => [
                'symfony' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel-data' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'yii3' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
            ],
        ],
        'required properties whose names are Javascript object property names' => [
            'ignores arrays' => [
                'symfony' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel-data' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'yii3' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
            ],
        ],
    ],
    'type.json' => [
        'integer type matches integers' => [
            'a float with zero fractional part is an integer' => [
                'symfony' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
            ],
            'a string is still not an integer, even if it looks like one' => [
                'laravel-data' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
                'yii3' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
            ],
        ],
        'number type matches numbers' => [
            'a string is still not a number, even if it looks like one' => [
                'laravel-data' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
                'yii3' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
            ],
        ],
        'object type matches objects' => [
            'an array is not an object' => [
                'symfony' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel-data' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'yii3' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
            ],
        ],
        'array type matches arrays' => [
            'an object is not an array' => [
                'symfony' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'laravel-data' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
                'yii3' => ['diverges', 'documented: the framework hands the body over decoded, [] and {} one empty array (README.support-matrix, empty JSON array row)'],
            ],
        ],
        'boolean type matches booleans' => [
            'an integer is not a boolean' => [
                'laravel-data' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
                'yii3' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
            ],
            'zero is not a boolean' => [
                'laravel-data' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
                'yii3' => ['diverges', 'documented: lenient scalar types in this mode (README.support-matrix)'],
            ],
        ],
        'type: array, object or null' => [
            'null is valid' => [
                'symfony' => ['diverges', 'generator: a type list of array/object (and null) is not enforced in this mode'],
            ],
        ],
    ],
    'unevaluatedProperties.json' => [
        'unevaluatedProperties with nested properties' => [
            'with additional properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'unevaluatedProperties with nested patternProperties' => [
            'with additional properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'unevaluatedProperties with $ref' => [
            'with unevaluated properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'unevaluatedProperties before $ref' => [
            'with unevaluated properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'unevaluatedProperties with $dynamicRef' => [
            'with no unevaluated properties' => [
                'runtime' => ['diverges', 'generator: $dynamicRef resolves statically, so the properties the outermost dynamic anchor evaluates are never seen'],
                'laravel' => ['diverges', 'generator: $dynamicRef resolves statically, so the properties the outermost dynamic anchor evaluates are never seen'],
                'laravel-data' => ['diverges', 'generator: $dynamicRef resolves statically, so the properties the outermost dynamic anchor evaluates are never seen'],
            ],
            'with unevaluated properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'nested unevaluatedProperties, outer true, inner false, properties inside' => [
            'with nested unevaluated properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'cousin unevaluatedProperties, true and false, false with properties' => [
            'with nested unevaluated properties' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'in-place applicator siblings, allOf has unevaluated' => [
            'base case: both properties present' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'in place applicator siblings, foo is missing' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'unevaluatedProperties + single cyclic ref' => [
            'Unevaluated on 1st level is invalid' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'Unevaluated on 2nd level is invalid' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'Unevaluated on 3rd level is invalid' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'unevaluatedProperties + ref inside allOf / oneOf' => [
            'Empty is invalid (no x or y)' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'a and b are invalid (no x or y)' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'x and y are invalid' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
            'a and x are valid' => [
                'runtime' => ['diverges', 'runtime: the keys the oneOf branches require (x, y) are not declared by the class, so the property-level check of the hydrated value cannot see them'],
            ],
            'a and y are valid' => [
                'runtime' => ['diverges', 'runtime: the keys the oneOf branches require (x, y) are not declared by the class, so the property-level check of the hydrated value cannot see them'],
            ],
            'a and b and x are valid' => [
                'runtime' => ['diverges', 'runtime: the keys the oneOf branches require (x, y) are not declared by the class, so the property-level check of the hydrated value cannot see them'],
            ],
            'a and b and y are valid' => [
                'runtime' => ['diverges', 'runtime: the keys the oneOf branches require (x, y) are not declared by the class, so the property-level check of the hydrated value cannot see them'],
            ],
            'a and b and x and y are invalid' => [
                'symfony' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
                'yii3' => ['diverges', 'documented: a closed object is not enforceable once hydrated (README.support-matrix, closed DTO-shaped schema)'],
            ],
        ],
        'dynamic evaluation inside nested refs' => [
            'b is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
            'c is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
            'd is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
            'xx is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
            'xx + foox is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
            'all is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
            'all + foo is valid' => [
                'symfony' => ['diverges', 'symfony: the keys a $ref/$dynamicRef target evaluates are not declared by the hydrated class (untriaged)'],
            ],
        ],
    ],
    'vocabulary.json' => [
        'schema that uses custom metaschema with with no validation vocabulary' => [
            '*' => [
                'runtime' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-no-validation.json (suite remotes/, not resolvable offline)'],
                'symfony' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-no-validation.json (suite remotes/, not resolvable offline)'],
                'laravel' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-no-validation.json (suite remotes/, not resolvable offline)'],
                'laravel-data' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-no-validation.json (suite remotes/, not resolvable offline)'],
                'yii3' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-no-validation.json (suite remotes/, not resolvable offline)'],
            ],
        ],
        'ignore unrecognized optional vocabulary' => [
            '*' => [
                'runtime' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-optional-vocabulary.json (suite remotes/, not resolvable offline)'],
                'symfony' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-optional-vocabulary.json (suite remotes/, not resolvable offline)'],
                'laravel' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-optional-vocabulary.json (suite remotes/, not resolvable offline)'],
                'laravel-data' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-optional-vocabulary.json (suite remotes/, not resolvable offline)'],
                'yii3' => ['skipped', 'declares the remote metaschema http://localhost:1234/draft2020-12/metaschema-optional-vocabulary.json (suite remotes/, not resolvable offline)'],
            ],
        ],
    ],
];
