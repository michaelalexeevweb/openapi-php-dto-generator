<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A response built in code and checked the way an application checks one, while a request is bound:
 * `toArray()`, through JSON and back to arrays, then `::validate($array)`.
 *
 * The raw-body checks read the bound request's body whenever every key of that body was in the data —
 * so an empty body `{}`, or one whose keys the response echoed, passed for the RESPONSE's data. Every
 * nested object was then judged as a JSON array: a discriminated list item failed `expects object, got
 * array`, and `oneOf: [$ref Person, {nullable: true}]` failed "does not match any oneOf branch (expected
 * object or null, got object)" for a valid Person.
 */
final class ResponseValidationTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/ld-response-' . bin2hex(random_bytes(6));
        mkdir($this->outputDirectory, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->outputDirectory);
    }

    #[DataProvider('boundRequestProvider')]
    public function testAResponseBuiltInCodeValidatesWhateverRequestIsBound(string $requestBody): void
    {
        $namespace = $this->generate();
        $person = static fn(int $id): object => new ($namespace . '\Person')(id: $id, name: 'n' . $id);
        $kind = $namespace . '\LogKind';
        $at = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $editClass = $namespace . '\Edit';
        $swapClass = $namespace . '\Swap';
        $rollbackClass = $namespace . '\Rollback';
        $rollback = new $rollbackClass(
            logs: [
                new $editClass(kind: $kind::from('edit'), createdAt: $at, author: $person(1), old: $person(2), new: null),
                new $swapClass(kind: $kind::from('swap'), createdAt: $at, author: null, old: [$person(3)], new: [$person(4), $person(5)]),
            ],
            company: $person(9),
        );
        $json = json_encode($rollback->toArray(), JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        $errors = static function (array $payload) use ($requestBody, $rollbackClass): array {
            try {
                LaravelDataContainer::withRequest($requestBody, static fn(): mixed => $rollbackClass::validate($payload));
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        };

        $this->assertSame([], $errors($data));
        // And the check still bites: a wrong id inside a list item, and a Person without its required id.
        $wrongItem = $data;
        $wrongItem['logs'][1]['old'] = [['id' => 'x']];
        $this->assertSame(['logs.1.old.0.id'], array_keys($errors($wrongItem)));
        $wrongCompany = $data;
        $wrongCompany['company'] = ['name' => 'no id'];
        $this->assertSame(['company.id'], array_keys($errors($wrongCompany)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function boundRequestProvider(): iterable
    {
        yield 'no body' => [''];
        yield 'an empty body' => ['{}'];
        yield 'a body the response echoes' => ['{"company":{"id":9,"name":"n9"}}'];
    }

    private function generate(): string
    {
        LaravelDataContainer::boot();
        $namespace = 'Rollback' . bin2hex(random_bytes(6));
        $person = ['$ref' => '#/components/schemas/Person'];
        (new GenerateDtoCommand())->generateFromArray(
            openApi: [
                'openapi' => '3.0.3',
                'info' => ['title' => 'T', 'version' => '1.0.0'],
                'paths' => [],
                'components' => ['schemas' => [
                    'Person' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
                    'Log' => [
                        'type' => 'object',
                        'required' => ['kind', 'createdAt'],
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['edit', 'swap']],
                            'author' => ['allOf' => [$person], 'nullable' => true],
                            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                        'discriminator' => ['propertyName' => 'kind', 'mapping' => ['edit' => '#/components/schemas/Edit', 'swap' => '#/components/schemas/Swap']],
                        'oneOf' => [['$ref' => '#/components/schemas/Edit'], ['$ref' => '#/components/schemas/Swap']],
                    ],
                    'Edit' => ['allOf' => [['$ref' => '#/components/schemas/Log'], ['type' => 'object', 'properties' => [
                        'old' => ['allOf' => [$person], 'nullable' => true],
                        'new' => ['allOf' => [$person], 'nullable' => true],
                    ]]]],
                    'Swap' => ['allOf' => [['$ref' => '#/components/schemas/Log'], ['type' => 'object', 'properties' => [
                        'old' => ['type' => 'array', 'items' => $person],
                        'new' => ['type' => 'array', 'items' => $person],
                    ]]]],
                    'Rollback' => ['type' => 'object', 'required' => ['logs'], 'properties' => [
                        'logs' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Log']],
                        'company' => ['oneOf' => [$person, ['nullable' => true]]],
                    ]],
                ]],
            ],
            outputDirectory: $this->outputDirectory,
            namespace: $namespace,
            mode: GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
        );
        $target = $this->outputDirectory;
        spl_autoload_register(static function (string $class) use ($target, $namespace): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $target . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
    }
}
