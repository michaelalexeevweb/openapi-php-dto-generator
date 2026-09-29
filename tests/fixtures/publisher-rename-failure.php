<?php

declare(strict_types=1);

// Run only in a child process. Simulate a filesystem failure after one output was published.

namespace OpenapiPhpDtoGenerator\Command {
    function rename(string $source, string $target): bool
    {
        if (basename($target) === 'Second.php' && file_get_contents($source) === 'new-second') {
            return false;
        }
        return \rename($source, $target);
    }
}

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
    $root = $argv[1];
    try {
        (new OpenapiPhpDtoGenerator\Command\GeneratedFilePublisher())->publish(files: [
            $root . '/out/new.php' => 'new',
            $root . '/external/First.php' => 'new-first',
            $root . '/external/Second.php' => 'new-second',
        ], ownedDirectories: [$root . '/out']);
        exit(2);
    } catch (RuntimeException $error) {
        echo $error->getMessage();
        exit(1);
    }
}
