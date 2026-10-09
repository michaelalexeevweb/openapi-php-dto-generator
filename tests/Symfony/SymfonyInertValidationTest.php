<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Symfony;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A schema the PHP types already describe completely gets no interpreter: no constant, no callback.
 * The classes around it still validate what they own, and still see it when they need its payload.
 */
final class SymfonyInertValidationTest extends TestCase
{
    private const string NAMESPACE = 'InertCheck';

    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-symfony-inert';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
        (new GenerateDtoCommand())->generateFromArray(self::spec(), $this->outputDirectory, self::NAMESPACE, 'symfony');
        foreach (['Envelope', 'Holder'] as $class) {
            require_once $this->outputDirectory . '/' . $class . '.php';
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->outputDirectory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->outputDirectory . '/' . $entry);
            }
        }
        @rmdir($this->outputDirectory);
    }

    public function testAnInertSchemaGetsNoInterpreter(): void
    {
        $envelope = (string)file_get_contents($this->outputDirectory . '/Envelope.php');
        $this->assertStringNotContainsString('OPENAPI_VALIDATION_CONSTRAINTS', $envelope);
        $this->assertStringNotContainsString('validateOpenApiConstraints', $envelope);

        $holder = (string)file_get_contents($this->outputDirectory . '/Holder.php');
        $this->assertStringContainsString('validateOpenApiConstraints', $holder);
    }

    public function testTheEnclosingClassStillValidatesWhatItOwns(): void
    {
        $envelope = self::NAMESPACE . '\Envelope';
        $holder = self::NAMESPACE . '\Holder';

        $first = new $envelope(ok: true, data: [1, 'x', null], when: null);
        $second = new $envelope(ok: false, data: [], when: new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $valid = new $holder(tags: ['ab', 'cd'], envelopes: [$first, $second]);
        $this->assertCount(0, $this->validator()->validate($valid));

        $shortTag = new $holder(tags: ['a'], envelopes: [$first]);
        $this->assertCount(1, $this->validator()->validate($shortTag));

        // uniqueItems compares the envelopes' payloads, which the holder reads from outside.
        $duplicated = new $holder(tags: ['ab'], envelopes: [$first, new $envelope(ok: true, data: [1, 'x', null], when: null)]);
        $this->assertCount(1, $this->validator()->validate($duplicated));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Envelope' => [
                        'type' => 'object',
                        'required' => ['ok', 'data', 'when'],
                        'properties' => [
                            'ok' => ['type' => 'boolean'],
                            'note' => ['type' => 'string'],
                            'data' => ['type' => 'array', 'items' => new stdClass()],
                            'when' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        ],
                    ],
                    'Holder' => [
                        'type' => 'object',
                        'required' => ['tags', 'envelopes'],
                        'properties' => [
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]],
                            'envelopes' => [
                                'type' => 'array',
                                'uniqueItems' => true,
                                'items' => ['$ref' => '#/components/schemas/Envelope'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
