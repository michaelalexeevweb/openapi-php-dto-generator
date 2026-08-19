<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use Yiisoft\Validator\Result;

/**
 * Generation harness for the yii3 suites: one spec in, loaded classes out.
 *
 * Every generation gets its OWN directory. `require_once` keys on the file path, so re-generating a
 * second spec over the same files would silently leave the first namespace's classes loaded and the
 * new ones would never exist — a failure that reads like a generator bug and is not one.
 */
trait GeneratesYii3Input
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/opg-yii3-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->outputDirectory)) {
            return;
        }

        foreach ((array)glob($this->outputDirectory . '/*/*.php') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        foreach ((array)glob($this->outputDirectory . '/*', GLOB_ONLYDIR) as $directory) {
            if (is_string($directory)) {
                rmdir($directory);
            }
        }
        rmdir($this->outputDirectory);
    }

    /**
     * @param array<string, mixed> $schemas
     */
    private function generate(array $schemas): string
    {
        return $this->generateSpec([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1.0.0'],
            'components' => ['schemas' => $schemas],
        ]);
    }

    /**
     * A whole document, for the cases where the shape comes from an OPERATION — query and path
     * parameters exist only there, and they are what decide the emitted source attributes.
     *
     * @param array<string, mixed> $spec
     */
    private function generateSpec(array $spec): string
    {
        $namespace = 'Yii3Gen' . bin2hex(random_bytes(5));
        $directory = $this->outputDirectory . '/' . $namespace;

        (new GenerateDtoCommand())->generateFromArray(
            $spec,
            $directory,
            $namespace,
            GenerateDtoCommand::ATTRIBUTE_MODE_YII3,
        );

        // An AUTOLOADER, not a require loop: a member class references its union interface, and glob
        // order put `Cat.php` before `Pet.php`, so the interface did not exist yet.
        spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            $file = $directory . '/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        return $namespace;
    }

    /**
     * @return array<string, string> error message keyed by its value path
     */
    private function messages(Result $result): array
    {
        $messages = [];
        foreach ($result->getErrors() as $error) {
            $messages[implode('.', $error->getValuePath())] = $error->getMessage();
        }

        return $messages;
    }

    /**
     * The verdict for one payload against one property schema — the shape most rule cases need.
     *
     * @param array<string, mixed> $propertySchema
     */
    private function verdictFor(array $propertySchema, mixed $value, bool $required = true): Result
    {
        $namespace = $this->generate([
            'Probe' => [
                'type' => 'object',
                'required' => $required ? ['f'] : [],
                'properties' => ['f' => $propertySchema],
            ],
        ]);

        $container = new Yii3Container();

        return $container->validate($container->hydrate($namespace . '\Probe', ['f' => $value]));
    }
}
