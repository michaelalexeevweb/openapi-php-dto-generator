<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A regenerated directory loses everything in it, so the generator must only ever regenerate one
 * that holds nothing but its own output. `--dto-generator-directory=.` once deleted a whole project
 * and reported success.
 */
final class OutputDirectorySafetyTest extends TestCase
{
    private string $project;

    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->project = (string)realpath(sys_get_temp_dir()) . '/opg-safety-' . bin2hex(random_bytes(8));
        mkdir($this->project . '/src/Domain', 0o775, true);
        file_put_contents($this->project . '/composer.json', '{"name": "acme/zoo"}');
        file_put_contents($this->project . '/src/Domain/Zebra.php', '<?php final class Zebra {}');
        file_put_contents($this->project . '/spec.json', (string)json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Zoo', 'version' => '1.0.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Widget' => ['type' => 'object', 'properties' => ['color' => ['type' => 'string']]],
            ]],
        ]));
        $this->workingDirectory = (string)getcwd();
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $this->remove($this->project);
    }

    public function testServicesCopiedIntoTheProjectRootLeaveTheProjectAlone(): void
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => 'spec.json',
            '--directory' => 'gen',
            '--namespace' => 'Zoo\Gen',
            '--dto-generator-directory' => '.',
            '--dto-generator-namespace' => 'Zoo\Shared',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFileExists($this->project . '/composer.json');
        $this->assertFileExists($this->project . '/spec.json');
        $this->assertFileExists($this->project . '/src/Domain/Zebra.php');
        $this->assertFileExists($this->project . '/DtoDeserializer.php');
        $this->assertFileExists($this->project . '/gen/Widget.php');
    }

    public function testServicesDirectoryKeepsTheConsumersOwnClasses(): void
    {
        mkdir($this->project . '/shared');
        file_put_contents($this->project . '/shared/ColorHelper.php', '<?php final class ColorHelper {}');
        file_put_contents($this->project . '/shared/DtoNormalizer.php', '<?php // stale copy');

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => 'spec.json',
            '--directory' => 'gen',
            '--namespace' => 'Zoo\Gen',
            '--dto-generator-directory' => $this->project . '/shared',
            '--dto-generator-namespace' => 'Zoo\Shared',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertSame('<?php final class ColorHelper {}', file_get_contents($this->project . '/shared/ColorHelper.php'));
        $this->assertStringContainsString('namespace Zoo\Shared;', (string)file_get_contents($this->project . '/shared/DtoNormalizer.php'));
        $this->assertFileExists($this->project . '/shared/DtoValidator.php');
    }

    public function testWorkingDirectoryIsRefusedAsOutput(): void
    {
        $this->assertRefused(directory: '.', message: 'contains the working directory');
    }

    public function testDirectoryAboveTheWorkingDirectoryIsRefusedAsOutput(): void
    {
        chdir($this->project . '/src');

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => '../spec.json',
            '--directory' => '..',
            '--namespace' => 'Zoo\Gen',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('contains the working directory', $this->display($tester));
        $this->assertProjectIntact();
    }

    public function testProjectRootIsRefusedAsOutput(): void
    {
        chdir(sys_get_temp_dir());

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => $this->project . '/spec.json',
            '--directory' => $this->project,
            '--namespace' => 'Zoo\Gen',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('looks like a project root (it has composer.json)', $this->display($tester));
        $this->assertProjectIntact();
    }

    public function testDirectoryHoldingTheDocumentIsRefusedAsOutput(): void
    {
        mkdir($this->project . '/specs');
        rename($this->project . '/spec.json', $this->project . '/specs/spec.json');
        file_put_contents($this->project . '/specs/notes.md', 'colors');

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => 'specs/spec.json',
            '--directory' => 'specs',
            '--namespace' => 'Zoo\Gen',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('contains the input document specs/spec.json', $this->display($tester));
        $this->assertFileExists($this->project . '/specs/spec.json');
        $this->assertFileExists($this->project . '/specs/notes.md');
    }

    public function testOwnOutputDirectoryIsStillRegeneratedWhole(): void
    {
        mkdir($this->project . '/gen');
        file_put_contents($this->project . '/gen/Removed.php', '<?php final class Removed {}');

        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => 'spec.json',
            '--directory' => 'gen',
            '--namespace' => 'Zoo\Gen',
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFileDoesNotExist($this->project . '/gen/Removed.php');
        $this->assertFileExists($this->project . '/gen/Widget.php');
    }

    private function assertRefused(string $directory, string $message): void
    {
        $tester = new CommandTester(new GenerateDtoCommand());
        $exitCode = $tester->execute([
            '--file' => 'spec.json',
            '--directory' => $directory,
            '--namespace' => 'Zoo\Gen',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($message, $this->display($tester));
        $this->assertProjectIntact();
    }

    /** The console wraps long errors; the message is checked as one line. */
    private function display(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function assertProjectIntact(): void
    {
        $this->assertFileExists($this->project . '/composer.json');
        $this->assertFileExists($this->project . '/spec.json');
        $this->assertFileExists($this->project . '/src/Domain/Zebra.php');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach ((array)scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
