<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Command;

use RuntimeException;
use Throwable;

/** Stages every output before replacing any existing generation. */
final class GeneratedFilePublisher
{
    /**
     * Owned directories get entirely new CONTENTS; the directory itself stays — its inode, its
     * permissions, a mount point — and its parent need not be writable, because the staging and the
     * backup both live inside it. Files outside them (explicit ref mappings) are replaced
     * individually, preserving unrelated files. Backups survive until every rename succeeds.
     *
     * @param array<string, string> $files
     * @param list<string> $ownedDirectories
     */
    public function publish(array $files, array $ownedDirectories): void
    {
        $normalizedFiles = [];
        foreach ($files as $path => $content) {
            $normalizedFiles[$this->absolutePath($path)] = $content;
        }
        $roots = array_values(array_unique(array_map($this->absolutePath(...), $ownedDirectories)));
        usort($roots, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));
        $owned = [];
        foreach ($roots as $root) {
            if (dirname($root) === $root || is_link($root) || (file_exists($root) && !is_dir($root))) {
                throw new RuntimeException(sprintf('Output must be a real directory, not a file or symlink: %s', $root));
            }
            if ($this->containingRoot($root, $owned) === null) {
                $owned[] = $root;
            }
        }

        /** @var array<string, array{stage: string, backup: string|null, installed: bool}> $operations */
        $operations = [];
        /** @var array<string, array{stage: string, backup: string, retired: list<string>, installed: list<string>}> $rootOperations */
        $rootOperations = [];
        /** @var list<string> $createdDirectories */
        $createdDirectories = [];
        try {
            foreach ($owned as $root) {
                $this->ensureDirectory($root, $createdDirectories);
                $stage = $this->temporaryPath($root . DIRECTORY_SEPARATOR . 'x');
                if (!@mkdir($stage, 0o775)) {
                    throw new RuntimeException(sprintf('Cannot create staging directory: %s', $stage));
                }
                $rootOperations[$root] = [
                    'stage' => $stage,
                    'backup' => $this->temporaryPath($root . DIRECTORY_SEPARATOR . 'x'),
                    'retired' => [],
                    'installed' => [],
                ];
            }
            foreach ($normalizedFiles as $path => $content) {
                $root = $this->containingRoot($path, $owned);
                if ($root !== null) {
                    if ($path === $root) {
                        throw new RuntimeException(sprintf('Output file collides with an output directory: %s', $path));
                    }
                    $stage = $rootOperations[$root]['stage'] . substr($path, strlen($root));
                    $this->ensureDirectory(dirname($stage), $createdDirectories);
                } else {
                    // Never follow a mapped output directory symlink or overwrite a directory as a file.
                    if (is_link(dirname($path)) || (is_dir($path) && !is_link($path))) {
                        throw new RuntimeException(sprintf('Invalid output file destination: %s', $path));
                    }
                    $this->ensureDirectory(dirname($path), $createdDirectories);
                    $stage = $this->temporaryPath($path);
                    $operations[$path] = ['stage' => $stage, 'backup' => null, 'installed' => false];
                }
                if (@file_put_contents($stage, $content) !== strlen($content)) {
                    throw new RuntimeException(sprintf('Cannot write generated file: %s', $path));
                }
            }

            foreach ($rootOperations as $root => $operation) {
                if (!@mkdir($operation['backup'], 0o775)) {
                    throw new RuntimeException(sprintf('Cannot create backup directory: %s', $operation['backup']));
                }
                foreach ($this->entries($root, except: [$operation['stage'], $operation['backup']]) as $entry) {
                    $this->move($root . DIRECTORY_SEPARATOR . $entry, $operation['backup'] . DIRECTORY_SEPARATOR . $entry);
                    $rootOperations[$root]['retired'][] = $entry;
                }
                foreach ($this->entries($operation['stage']) as $entry) {
                    $this->move($operation['stage'] . DIRECTORY_SEPARATOR . $entry, $root . DIRECTORY_SEPARATOR . $entry);
                    $rootOperations[$root]['installed'][] = $entry;
                }
            }
            foreach ($operations as $target => $operation) {
                if (file_exists($target) || is_link($target)) {
                    $backup = $this->temporaryPath($target);
                    $this->move($target, $backup);
                    $operations[$target]['backup'] = $backup;
                }
                $this->move($operation['stage'], $target);
                $operations[$target]['installed'] = true;
            }
        } catch (Throwable $error) {
            $rollbackErrors = [];
            foreach (array_reverse($rootOperations, true) as $root => $operation) {
                try {
                    foreach ($operation['installed'] as $entry) {
                        $this->remove($root . DIRECTORY_SEPARATOR . $entry);
                    }
                    foreach ($operation['retired'] as $entry) {
                        $this->move($operation['backup'] . DIRECTORY_SEPARATOR . $entry, $root . DIRECTORY_SEPARATOR . $entry);
                    }
                    $this->remove($operation['backup']);
                    $this->remove($operation['stage']);
                } catch (RuntimeException $rollbackError) {
                    $rollbackErrors[] = $rollbackError->getMessage();
                }
            }
            foreach (array_reverse($operations, true) as $target => $operation) {
                try {
                    if ($operation['installed']) {
                        $this->remove($target);
                    }
                    if ($operation['backup'] !== null) {
                        $this->move($operation['backup'], $target);
                    }
                    $this->remove($operation['stage']);
                } catch (RuntimeException $rollbackError) {
                    $rollbackErrors[] = $rollbackError->getMessage();
                }
            }
            foreach (array_reverse($createdDirectories) as $directory) {
                @rmdir($directory); // Remove only empty directories created during preparation.
            }
            if ($rollbackErrors !== []) {
                throw new RuntimeException(
                    message: $error->getMessage() . ' Rollback requires attention: ' . implode('; ', $rollbackErrors),
                    previous: $error,
                );
            }
            throw $error;
        }

        // Publication is complete. A cleanup failure must not turn it into a failed generation after
        // some backups have already been removed; retain the remaining backup and report its location.
        foreach ($rootOperations as $operation) {
            foreach ([$operation['backup'], $operation['stage']] as $leftover) {
                try {
                    $this->remove($leftover);
                } catch (RuntimeException $error) {
                    error_log($error->getMessage());
                }
            }
        }
        foreach ($operations as $operation) {
            if ($operation['backup'] === null) {
                continue;
            }
            try {
                $this->remove($operation['backup']);
            } catch (RuntimeException $error) {
                error_log($error->getMessage());
            }
        }
    }

    /**
     * @param list<string> $except
     * @return list<string>
     */
    private function entries(string $directory, array $except = []): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException(sprintf('Cannot read output directory: %s', $directory));
        }
        $names = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($directory . DIRECTORY_SEPARATOR . $entry, $except, true)) {
                continue;
            }
            $names[] = $entry;
        }

        return $names;
    }

    private function move(string $source, string $target): void
    {
        if (!@rename($source, $target)) {
            throw new RuntimeException(sprintf('Cannot move generated output from %s to %s.', $source, $target));
        }
    }

    private function temporaryPath(string $target): string
    {
        return dirname($target) . '/.openapi-' . bin2hex(random_bytes(12));
    }

    /** @param list<string> $roots */
    private function containingRoot(string $path, array $roots): ?string
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return $root;
            }
        }
        return null;
    }

    /** @param list<string> $created */
    private function ensureDirectory(string $directory, array &$created): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $directory));
        }
        $this->ensureDirectory(dirname($directory), $created);
        if (!@mkdir($directory, 0o775)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $directory));
        }
        $created[] = $directory;
    }

    private function remove(string $path): void
    {
        // is_dir() follows links. Test the link first, including dangling directory links.
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException(sprintf('Cannot remove output backup: %s', $path));
            }
            return;
        }
        if (!file_exists($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException(sprintf('Cannot read output backup: %s', $path));
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        if (!@rmdir($path)) {
            throw new RuntimeException(sprintf('Cannot remove output backup directory: %s', $path));
        }
    }

    private function absolutePath(string $path): string
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        if ($windows) {
            $path = str_replace('\\', '/', $path);
        }
        if (!str_starts_with($path, '/') && (!$windows || preg_match('~^[a-zA-Z]:/~', $path) !== 1)) {
            if ($windows && preg_match('~^[a-zA-Z]:~', $path) === 1) {
                throw new RuntimeException('Drive-relative output paths are not supported; use an absolute path.');
            }
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Cannot resolve the working directory.');
            }
            $path = ($windows ? str_replace('\\', '/', $cwd) : $cwd) . '/' . $path;
        }
        $prefix = '/';
        if ($windows) {
            if (preg_match('~^([a-zA-Z]:/|//[^/]+/[^/]+(?:/|$))~', $path, $match) === 1) {
                $prefix = rtrim($match[1], '/') . '/';
                $path = substr($path, strlen($match[1]));
            } else {
                $cwd = getcwd();
                if ($cwd === false || preg_match('~^[a-zA-Z]:~', $cwd) !== 1) {
                    throw new RuntimeException('Cannot resolve the output drive; use an absolute path.');
                }
                $prefix = substr($cwd, 0, 2) . '/';
            }
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $normalized = $prefix . implode('/', $parts);

        return $windows ? str_replace('/', DIRECTORY_SEPARATOR, $normalized) : $normalized;
    }
}
