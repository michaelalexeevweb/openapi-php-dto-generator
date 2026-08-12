<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\LaravelData;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Every attribute the laravel-data emitter writes must RESOLVE — which is not something the file's
 * syntax can tell you.
 *
 * The bug this exists for: `#[WithCast(...)]` was emitted without importing `WithCast`. The file parsed,
 * `php -l` was happy, the golden snapshot was happy, and laravel-data skipped the attribute in silence
 * (`if (! class_exists($reflectionAttribute->getName())) continue;`). The property quietly fell back to
 * the global ATOM date cast and a `format: date` payload died in the cast — only driving a generated
 * class through the real package showed it.
 *
 * So the assertion is made through reflection over the whole demo corpus: an attribute whose class does
 * not exist is a missing `use` statement, and one that cannot be instantiated is a wrong argument list.
 */
final class GeneratedAttributesResolveTest extends TestCase
{
    private const string SPEC = __DIR__ . '/../../OpenApiExamples/test.yaml';
    private const string NAMESPACE = 'LaravelDataAttributeCorpus';

    private string $outputDirectory = '';

    protected function tearDown(): void
    {
        if ($this->outputDirectory === '') {
            return;
        }

        $this->deleteRecursively($this->outputDirectory);
        $this->outputDirectory = '';
    }

    public function testEveryEmittedAttributeResolvesAndInstantiates(): void
    {
        $classes = $this->generateCorpus();
        $this->assertNotSame([], $classes, 'the corpus generated no laravel-data classes');

        $checked = 0;
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isSubclassOf(Data::class)) {
                // A union base is an interface here, as in every other mode.
                continue;
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                foreach ($property->getAttributes() as $attribute) {
                    $this->assertTrue(
                        class_exists($attribute->getName()),
                        sprintf(
                            '%s::$%s carries #[%s], which does not resolve — the emitter wrote the '
                                . 'attribute without importing it, and laravel-data would skip it in silence',
                            $class,
                            $property->getName(),
                            $attribute->getName(),
                        ),
                    );

                    try {
                        $attribute->newInstance();
                    } catch (Throwable $exception) {
                        $this->fail(sprintf(
                            '%s::$%s carries #[%s] that cannot be instantiated: %s',
                            $class,
                            $property->getName(),
                            $attribute->getName(),
                            $exception->getMessage(),
                        ));
                    }

                    $checked++;
                }
            }
        }

        // A corpus that emitted no attributes at all would pass every assertion above for the wrong
        // reason, so the count is part of the claim.
        $this->assertGreaterThan(0, $checked, 'no attributes were emitted, so nothing was proven');
    }

    /**
     * @return array<int, class-string>
     */
    private function generateCorpus(): array
    {
        $this->outputDirectory = sys_get_temp_dir() . '/ld-attrs-' . bin2hex(random_bytes(6));
        mkdir($this->outputDirectory, 0o755, true);

        (new GenerateDtoCommand())->generateFromFile(
            self::SPEC,
            $this->outputDirectory,
            self::NAMESPACE,
            GenerateDtoCommand::ATTRIBUTE_MODE_LARAVEL_DATA,
        );

        $target = $this->outputDirectory;
        spl_autoload_register(static function (string $class) use ($target): void {
            if (!str_starts_with($class, self::NAMESPACE . '\\')) {
                return;
            }
            $file = $target . '/' . str_replace('\\', '/', substr($class, strlen(self::NAMESPACE) + 1)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        $classes = [];
        foreach ($this->phpFilesIn($this->outputDirectory) as $relative) {
            /** @var class-string $class */
            $class = self::NAMESPACE . '\\' . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesIn(string $directory, string $prefix = ''): array
    {
        $found = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                foreach ($this->phpFilesIn($path, $prefix . $entry . '/') as $nested) {
                    $found[] = $nested;
                }

                continue;
            }
            if (str_ends_with($entry, '.php')) {
                $found[] = $prefix . $entry;
            }
        }

        return $found;
    }

    private function deleteRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteRecursively($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
