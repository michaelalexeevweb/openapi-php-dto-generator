<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use OpenapiPhpDtoGenerator\Service\DtoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runtime mode reads a `format: date-time` string in two places: the deserializer builds a typed
 * `DateTimeImmutable` property from it, and the validator judges one held as decoded data (an item of a
 * union's list member, a response). Until 2.15.163 they disagreed — the deserializer took a space
 * separator and a missing offset and refused a lowercase `t` / `z`, the validator the other way round — so
 * one value was accepted or refused depending on where it sat. Both now answer from one reader.
 *
 * The reading is the lenient one clients already rely on: a space separator and a missing offset are
 * accepted (a strict RFC 3339 reading is a 3.0 candidate); a lowercase `t` / `z` is accepted (RFC 3339
 * §5.6); an offset out of range, a date with no time and anything PHP would merely parse are refused.
 */
final class DateTimeAgreementTest extends TestCase
{
    private static ?string $probe = null;

    #[DataProvider('values')]
    public function testTheDeserializerAndTheValidatorGiveOneAnswer(string $value, bool $accepted): void
    {
        $validated = (new DtoValidator())->validate('field', $value, ['type' => 'string', 'format' => 'date-time']) === [];

        try {
            (new DtoDeserializer())->deserialize(Request::create(
                uri: '/',
                method: 'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string)json_encode(['stamp' => $value]),
            ), self::probeClass());
            $deserialized = true;
        } catch (RuntimeException) {
            $deserialized = false;
        }

        $this->assertSame(
            expected: ['validator' => $accepted, 'deserializer' => $accepted],
            actual: ['validator' => $validated, 'deserializer' => $deserialized],
            message: $value,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function values(): iterable
    {
        foreach ([
            '2024-01-02T10:00:00+00:00',
            '2024-01-02T10:00:00Z',
            '2024-01-02t10:00:00z',
            '2024-01-02T10:00:00.123+02:00',
            '2024-01-02 10:00:00',
            '2024-01-02T10:00:00',
        ] as $accepted) {
            yield 'accepts ' . $accepted => [$accepted, true];
        }
        foreach ([
            '2024-01-02',
            'x',
            'now',
            '2024-01-02T10:00:00+25:00',
            '2024-01-02T10:00:00+02:60',
            '2024-02-30T10:00:00Z',
            '2024-01-02T10:00:00+0200',
            '2024-01-02T10:00:00.5',
        ] as $refused) {
            yield 'refuses ' . $refused => [$refused, false];
        }
    }

    private static function probeClass(): string
    {
        if (self::$probe !== null) {
            return self::$probe;
        }
        $directory = sys_get_temp_dir() . '/opg-date-time-agreement-' . getmypid();
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.1.0',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'components' => ['schemas' => [
                    'Probe' => ['type' => 'object', 'properties' => ['stamp' => ['type' => 'string', 'format' => 'date-time']]],
                ]],
            ],
            outputDirectory: $directory,
            namespace: 'DateTimeAgreement',
        );
        require_once $directory . '/Probe.php';

        return self::$probe = 'DateTimeAgreement\Probe';
    }
}
