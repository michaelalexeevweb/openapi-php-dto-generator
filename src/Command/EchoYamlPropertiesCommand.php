<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use OpenapiPhpDtoGenerator\Service\YamlPhpArrayFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;

#[AsCommand(name: 'yaml:echo-properties', description: 'Read a YAML file and echo all properties as nested PHP array syntax')]
final class EchoYamlPropertiesCommand extends Command
{
    public function __construct(
        private readonly YamlPhpArrayFormatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to YAML file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string)$input->getArgument('file');

        if (!is_file($file)) {
            $io->error(sprintf('File not found: %s', $file));
            return Command::FAILURE;
        }

        try {
            echo $this->formatter->formatFile($file) . "\n";
        } catch (ParseException $exception) {
            $io->error(sprintf('YAML parse error: %s', $exception->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
