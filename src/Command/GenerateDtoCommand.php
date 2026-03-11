<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use RuntimeException;
use OpenapiPhpDtoGenerator\Service\OpenApiDtoGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'openapi:generate-dto', description: 'Generate readonly DTO classes from OpenAPI components.schemas')]
final class GenerateDtoCommand extends Command
{
    public function __construct(
        private readonly OpenApiDtoGeneratorService $dtoGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Path to OpenAPI yaml file', 'OpenApiExamples/test.yaml');
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Path to OpenAPI yaml file (alternative to argument)');
        $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Output directory for generated DTO classes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get file from --file option or positional argument
        $fileOption = $input->getOption('file');
        $file = is_string($fileOption) && $fileOption !== '' ? $fileOption : (string) $input->getArgument('file');
        $directoryOption = $input->getOption('directory');
        $directory = is_string($directoryOption) ? trim($directoryOption) : '';

        if ($directory === '') {
            $io->error('Option --directory is required. Example: --directory=generated/test');
            return Command::FAILURE;
        }

        if (!is_file($file)) {
            $io->error(sprintf('File not found: %s', $file));
            return Command::FAILURE;
        }

        $outputDirectory = $this->resolveOutputDirectory($directory);
        $namespace = $this->directoryToNamespace($directory);

        try {
            $count = $this->dtoGenerator->generateFromFile($file, $outputDirectory, $namespace);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Generated %d DTO class(es) in %s with namespace %s.', $count, $outputDirectory, $namespace));

        return Command::SUCCESS;
    }

    private function resolveOutputDirectory(string $directory): string
    {
        $normalized = str_replace('\\', '/', $directory);

        if (str_starts_with($normalized, '/')) {
            return rtrim($normalized, '/');
        }

        $workingDirectory = getcwd() ?: '.';
        return rtrim($workingDirectory . '/' . ltrim($normalized, '/'), '/');
    }

    private function directoryToNamespace(string $directory): string
    {
        $normalized = trim(str_replace('\\', '/', $directory), '/');

        if ($normalized === '') {
            return 'Generated';
        }

        $segments = array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== '');
        $namespaceParts = [];

        foreach ($segments as $segment) {
            $namespaceParts[] = $this->normalizeNamespaceSegment($segment);
        }

        return implode('\\', $namespaceParts);
    }

    private function normalizeNamespaceSegment(string $segment): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $segment) ?: [];
        $normalized = implode('', array_map(static fn (string $part): string => ucfirst(strtolower($part)), array_filter($parts)));

        if ($normalized === '') {
            return 'Generated';
        }

        if (is_numeric($normalized[0])) {
            return '_' . $normalized;
        }

        return $normalized;
    }
}

