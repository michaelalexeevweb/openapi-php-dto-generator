<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Input\Http\AbstractInput;
use Yiisoft\Validator\Result;

/**
 * What the yii3 hydrator does to a value BEFORE any rule sees it, measured through the validating
 * hydrator README.yii3 makes mandatory, the whole way: payload in, verdict, the getter, the payload
 * written back, and that payload in again.
 *
 * The hydrator casts to the declared type and leaves a property unset when no cast fits, so the raw
 * `#[Validate]` checks are the only place the payload is judged as sent. Each case below is a shape
 * where that check was missing, too lenient, or where the hydrator stored a value other than the one
 * sent — runtime mode, which reads the payload itself, is the reference for every verdict.
 */
final class Yii3RawValueCheckTest extends TestCase
{
    use GeneratesYii3Input;

    /**
     * @param array<string, mixed> $property the schema of `u`
     * @param array<int, mixed> $accepted values that must pass, each written back as itself
     * @param array<int, mixed> $refused
     */
    #[DataProvider('temporalUnionProvider')]
    public function testATemporalUnionKeepsTheValueItWasSent(array $property, bool $required, array $accepted, array $refused): void
    {
        $class = $this->probe($property, $required);

        foreach ($accepted as $value) {
            $this->assertRoundTrip($class, $value, true);
        }
        foreach ($refused as $value) {
            $this->assertRefused($class, $value);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, array<int, mixed>, array<int, mixed>}>
     */
    public static function temporalUnionProvider(): iterable
    {
        $date = ['type' => 'string', 'format' => 'date'];
        $dateTime = ['type' => 'string', 'format' => 'date-time'];
        $null = ['type' => 'null'];

        // A `format` inside a union branch is the property's format: the date-time patterns alone
        // refused every date and took a date-time in its place.
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach ([true, false] as $required) {
                yield $keyword . ' date or null, ' . ($required ? 'required' : 'optional') => [
                    [$keyword => [$date, $null]],
                    $required,
                    ['2024-05-06', null],
                    ['2024-05-06T01:02:03Z', 'nonsense', 5, 1.5],
                ];
            }
        }
        yield 'null first' => [['oneOf' => [$null, $date]], true, ['2024-05-06', null], ['2024-05-06T01:02:03Z']];
        // Neighbours that already worked: the date-time member, and the type-list spelling.
        yield 'date-time or null' => [['oneOf' => [$dateTime, $null]], true, ['2024-05-06T01:02:03+00:00', null], ['2024-05-06', 5]];
        yield 'type list date or null' => [['type' => ['string', 'null'], 'format' => 'date'], true, ['2024-05-06', null], ['2024-05-06T01:02:03Z', 5]];
        yield 'plain date' => [$date, true, ['2024-05-06'], ['2024-05-06T01:02:03Z', 20240506, 5, null]];
        yield 'plain date-time' => [$dateTime, false, ['2024-05-06T01:02:03+00:00'], ['2024-05-06', 5, 1.5, null]];

        // A temporal member beside a scalar one: the date was cast to the scalar (`"2024-05-06"` to
        // 2024), the scalar read as a Unix timestamp, and anything at all was accepted.
        foreach (['oneOf', 'anyOf'] as $keyword) {
            yield $keyword . ' date or integer' => [
                [$keyword => [$date, ['type' => 'integer']]],
                true,
                ['2024-05-06', 7, 0],
                ['12abc', null, 1.5, 'x', '2024-05-06T01:02:03Z', true],
            ];
        }
        yield 'date-time or boolean' => [
            ['anyOf' => [$dateTime, ['type' => 'boolean']]],
            true,
            ['2024-05-06T01:02:03+00:00', true, false],
            ['2024-05-06', '12abc', 1.5, 'x', null],
        ];
        yield 'optional date or integer' => [
            ['oneOf' => [$date, ['type' => 'integer']]],
            false,
            ['2024-05-06', 7],
            ['12abc', 1.5, null],
        ];
        // KNOWN GAP, pinned so a fix shows: where the schema lets the union be null, `#[ToString]`
        // cannot go in front of `#[ToDateTime]` — it turns null into `""`, which the cast reads as 0 —
        // so an integer is still read as a Unix timestamp. The date, null and the refusals are right.
        yield 'date, integer or null' => [
            ['oneOf' => [$date, ['type' => 'integer'], $null]],
            true,
            ['2024-05-06', null],
            ['12abc', 1.5],
        ];
    }

    public function testANullableDateOrIntegerStillReadsAnIntegerAsATimestamp(): void
    {
        $class = $this->probe(
            property: ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'integer'], ['type' => 'null']]],
            required: true,
        );

        $input = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: ['u' => 7]);
        $this->assertSame([], $this->messages($this->verdictOf($input)));
        $this->assertSame(
            expected: ['u' => '1970-01-01'],
            actual: json_decode((string)json_encode($input), true),
            message: 'KNOWN GAP (see temporalUnionProvider): if this now reads 7, the gap is closed — move 7 back into the accepted values there',
        );
    }

    /**
     * A union of scalars holds the value it was sent, not a cast of it.
     *
     * @param array<string, mixed> $property the schema of `u`
     * @param array<int, mixed> $accepted
     * @param array<int, mixed> $refused
     */
    #[DataProvider('scalarUnionProvider')]
    public function testAScalarUnionRefusesWhatNoMemberTakes(array $property, array $accepted, array $refused): void
    {
        $class = $this->probe($property, true);

        foreach ($accepted as $value) {
            $this->assertRoundTrip($class, $value, false);
        }
        foreach ($refused as $value) {
            $this->assertRefused($class, $value);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<int, mixed>, array<int, mixed>}>
     */
    public static function scalarUnionProvider(): iterable
    {
        yield 'number or boolean' => [['anyOf' => [['type' => 'number'], ['type' => 'boolean']]], [1.5, true, false], ['x', [1], ['a' => 1], null]];
        yield 'type list integer or boolean' => [['type' => ['integer', 'boolean']], [3, true, false], ['x', 1.5, [1], null]];
        yield 'string or integer' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], ['x', 5], [1.5, true, [1], null]];
        yield 'string or integer, nullable' => [['type' => ['string', 'integer', 'null']], ['x', 5, null], [1.5, true]];
        yield 'number or string' => [['anyOf' => [['type' => 'number'], ['type' => 'string']]], [1.5, 'x'], [true, [1]]];
    }

    /**
     * The raw date check names the property, as every other rule does. `AnyRule` hands its message to
     * the result with no parameters, so a `{Property}` placeholder reached the client unreplaced.
     */
    public function testTheRawDateCheckNamesTheProperty(): void
    {
        $class = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => ['startsAt'],
                'properties' => [
                    'startsAt' => ['type' => 'string', 'format' => 'date-time'],
                    'day' => ['oneOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
                    'plain' => ['type' => 'string', 'format' => 'date'],
                ],
            ],
        ]) . '\Probe';

        $this->assertSame(
            expected: ['startsAt' => 'StartsAt must be a date.'],
            actual: $this->messagesFor($class, ['startsAt' => 'nonsense']),
        );
        // Not a string at all: said once, by the string check, and the date check skipped.
        $this->assertSame(
            expected: ['startsAt' => 'StartsAt must be a string.'],
            actual: $this->messagesFor($class, ['startsAt' => 1.5]),
        );
        $this->assertSame(
            expected: ['day' => 'Day must be a date.'],
            actual: $this->messagesFor($class, ['startsAt' => '2024-05-06T01:02:03Z', 'day' => 'nonsense']),
        );
        // An integer is refused once, as not a string — not twice, and not as a timestamp.
        $this->assertSame(
            expected: ['plain' => 'Plain must be a string.'],
            actual: $this->messagesFor($class, ['startsAt' => '2024-05-06T01:02:03Z', 'plain' => 5]),
        );
    }

    /**
     * A date that is not one is ONE mistake, so it is one message. The pattern and the parser both
     * answer "must be a date", and the `Composite` holding them ran both: `"nonsense"` was reported
     * twice — at the top and inside a nested object (README.yii3's nested recipe). The parser now
     * runs only on what the pattern took, so a calendar date that does not exist (`2024-02-30`) is
     * still refused, once, and a real one passes.
     */
    public function testAnInvalidDateIsReportedOnce(): void
    {
        $namespace = $this->generate([
            'Booking' => [
                'type' => 'object',
                'properties' => [
                    'startDate' => ['type' => 'string', 'format' => 'date'],
                    'startsAt' => ['type' => 'string', 'format' => 'date-time'],
                    'slot' => ['$ref' => '#/components/schemas/Slot'],
                ],
            ],
            'Slot' => [
                'type' => 'object',
                'properties' => [
                    'startDate' => ['type' => 'string', 'format' => 'date'],
                    'startsAt' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
        ]);
        $class = $namespace . '\Booking';

        $cases = [
            [['startDate' => 'nonsense'], ['startDate' => 'StartDate must be a date.']],
            [['startDate' => '2024-02-30'], ['startDate' => 'StartDate must be a date.']],
            [['startsAt' => 'nonsense'], ['startsAt' => 'StartsAt must be a date.']],
            [['startsAt' => '2024-02-30T01:02:03Z'], ['startsAt' => 'StartsAt must be a date.']],
            [['slot' => ['startDate' => 'nonsense']], ['slot.startDate' => 'StartDate must be a date.']],
            [['slot' => ['startDate' => '2024-02-30']], ['slot.startDate' => 'StartDate must be a date.']],
            [['slot' => ['startsAt' => 'nonsense']], ['slot.startsAt' => 'StartsAt must be a date.']],
            [['startDate' => '2024-02-29', 'startsAt' => '2024-05-06T01:02:03Z', 'slot' => ['startDate' => '2024-02-29']], []],
        ];
        foreach ($cases as [$payload, $expected]) {
            $result = (new Yii3Container())->hydrateValidated(class: $class, payload: $payload, nestedPaths: true);
            $reported = [];
            foreach ($result->getErrors() as $error) {
                $reported[] = [implode('.', $error->getValuePath()) => $error->getMessage()];
            }
            $this->assertSame(
                expected: array_map(static fn(string $path, string $message): array => [$path => $message], array_keys($expected), $expected),
                actual: $reported,
                message: (string)json_encode($payload),
            );
        }
    }

    /**
     * An empty map is `{}` on the way out, as the schema says: the class holds the PHP array, and
     * `{"widths":{}}` was written back `{"widths":[]}` — by `getData()` and by `json_encode()` — on its
     * own, inside a nested object, as the value of a map, as the item of a list and as a nullable map.
     * A map with keys is written as it was, and is still accepted again from what was written.
     */
    public function testAnEmptyMapIsWrittenAsAnObject(): void
    {
        $map = static fn(array $values): array => ['type' => 'object', 'additionalProperties' => $values];
        $namespace = $this->generate([
            'Crate' => [
                'type' => 'object',
                'required' => ['widths', 'grid', 'rows', 'box', 'maybe', 'shapes'],
                'properties' => [
                    'widths' => $map(['type' => 'integer']),
                    'grid' => $map($map(['type' => 'integer'])),
                    'rows' => ['type' => 'array', 'items' => $map(['type' => 'integer'])],
                    'box' => ['$ref' => '#/components/schemas/Box'],
                    'maybe' => ['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'integer']],
                    'shapes' => $map(['$ref' => '#/components/schemas/Box']),
                ],
            ],
            'Box' => [
                'type' => 'object',
                'properties' => ['widths' => $map(['type' => 'integer'])],
            ],
        ]);
        $class = $namespace . '\Crate';

        foreach ([
            '{"widths":{},"grid":{"row":{}},"rows":[{},{"a":1}],"box":{"widths":{}},"maybe":{},"shapes":{"c":{}}}',
            '{"widths":{"a":1},"grid":{},"rows":[],"box":{"widths":{"b":2}},"maybe":null,"shapes":{}}',
        ] as $json) {
            $payload = json_decode($json, true);
            $this->assertIsArray($payload);
            $input = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: $payload);
            $this->assertSame([], $this->messages($this->verdictOf($input)), $json);

            $this->assertSame(expected: $json, actual: (string)json_encode($input));
            $this->assertSame(expected: $json, actual: (string)json_encode($input->getData()));

            $written = json_decode((string)json_encode($input), true);
            $again = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: is_array($written) ? $written : []);
            $this->assertSame([], $this->messages($this->verdictOf($again)), $json . ' re-sent');
        }
    }

    /**
     * An optional property whose PHP type the hydrator cannot fill from the value — an enum given a
     * value it lacks, an object or an enum given null — is refused, not read as "not sent".
     */
    public function testAnOptionalValueTheHydratorCannotHoldIsRefused(): void
    {
        $class = $this->generate([
            'Color' => ['type' => 'string', 'enum' => ['red', 'blue']],
            'Widget' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
            'Probe' => [
                'type' => 'object',
                'properties' => [
                    'tag' => ['type' => 'string', 'enum' => ['alpha', 'beta']],
                    'color' => ['$ref' => '#/components/schemas/Color'],
                    'widget' => ['$ref' => '#/components/schemas/Widget'],
                    'maybeColor' => ['oneOf' => [['$ref' => '#/components/schemas/Color'], ['type' => 'null']]],
                    'maybeWidget' => ['oneOf' => [['$ref' => '#/components/schemas/Widget'], ['type' => 'null']]],
                ],
            ],
        ]) . '\Probe';

        foreach ([['tag' => 'zzz'], ['tag' => null], ['color' => 'zzz'], ['color' => null], ['widget' => null], ['widget' => 'x'], ['maybeColor' => 'zzz']] as $payload) {
            $this->assertNotSame([], $this->messagesFor($class, $payload), (string)json_encode($payload));
        }
        foreach ([[], ['tag' => 'beta'], ['color' => 'red'], ['widget' => ['id' => 1]], ['maybeColor' => null], ['maybeWidget' => null], ['maybeColor' => 'blue']] as $payload) {
            $input = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: $payload);
            $this->assertSame([], $this->messages($this->verdictOf($input)), (string)json_encode($payload));
            $this->assertSame($payload, json_decode((string)json_encode($input), true), (string)json_encode($payload));
        }
    }

    /**
     * A discriminated union beside a null branch, as a list item and as a map value: each member is
     * checked against the schema the discriminator names, and null is let through.
     */
    public function testANullableDiscriminatedUnionInAContainerChecksItsMembers(): void
    {
        $union = ['oneOf' => [['$ref' => '#/components/schemas/Animal'], ['type' => 'null']]];
        $class = $this->generate([
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Cat' => ['type' => 'object', 'required' => ['kind', 'lives'], 'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'required' => ['kind', 'size'], 'properties' => ['kind' => ['type' => 'string'], 'size' => ['type' => 'number']]],
            'Probe' => [
                'type' => 'object',
                'properties' => [
                    'list' => ['type' => 'array', 'items' => $union],
                    'map' => ['type' => 'object', 'additionalProperties' => $union],
                ],
            ],
        ]) . '\Probe';

        $valid = ['list' => [null, ['kind' => 'Cat', 'lives' => 9]], 'map' => ['a' => null, 'b' => ['kind' => 'Dog', 'size' => 2]]];
        $input = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: $valid);
        $this->assertSame([], $this->messages($this->verdictOf($input)));
        $this->assertSame($valid, json_decode((string)json_encode($input), true));

        foreach ([
            ['list' => [['kind' => 'Cat']]],
            ['list' => [['kind' => 'Cow', 'lives' => 1]]],
            ['map' => ['a' => ['kind' => 'Dog', 'lives' => 1]]],
        ] as $payload) {
            $this->assertNotSame([], $this->messagesFor($class, $payload), (string)json_encode($payload));
        }
    }

    /**
     * @param array<string, mixed> $property
     * @return class-string
     */
    private function probe(array $property, bool $required): string
    {
        /** @var class-string $class */
        $class = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => $required ? ['u'] : [],
                'properties' => ['u' => $property],
            ],
        ]) . '\Probe';

        return $class;
    }

    /**
     * Accepted, read back by the getter as the value sent (a string as that date when `$temporal`),
     * written back as itself, and accepted again from what was written.
     */
    private function assertRoundTrip(string $class, mixed $value, bool $temporal): void
    {
        $label = (string)json_encode($value);
        $input = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: ['u' => $value]);
        $this->assertSame([], $this->messages($this->verdictOf($input)), $label);

        $held = $input->{'getU'}();
        if ($temporal && is_string($value)) {
            $this->assertInstanceOf(DateTimeImmutable::class, $held, $label);
            $this->assertSame(
                expected: (new DateTimeImmutable($value))->format(DATE_ATOM),
                actual: $held->format(DATE_ATOM),
                message: $label,
            );
        } else {
            $this->assertSame($value, $held, $label);
        }

        $written = json_decode((string)json_encode($input), true);
        $this->assertSame(['u' => $value], $written, $label);

        $again = (new Yii3Container())->hydrateValidatedInput(class: $class, payload: is_array($written) ? $written : []);
        $this->assertSame([], $this->messages($this->verdictOf($again)), $label . ' re-sent');
    }

    private function assertRefused(string $class, mixed $value): void
    {
        $this->assertNotSame([], $this->messagesFor($class, ['u' => $value]), (string)json_encode($value));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function messagesFor(string $class, array $payload): array
    {
        return $this->messages($this->verdictOf((new Yii3Container())->hydrateValidatedInput(class: $class, payload: $payload)));
    }

    private function verdictOf(AbstractInput $input): Result
    {
        $result = $input->getValidationResult();
        $this->assertNotNull($result);

        return $result;
    }
}
