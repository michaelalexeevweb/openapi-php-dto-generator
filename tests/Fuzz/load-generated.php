<?php

declare(strict_types=1);

/*
 * Loads every file the generator wrote, in a process of its own: a class that cannot be linked (an
 * incompatible signature, a redeclared name) is a FATAL error, which would end the fuzz run itself.
 *
 * Then asks every declared class whether the types it names exist — a property typed by a class that
 * was never written autoloads only when a payload reaches it, so `php -l` and a plain `require` both
 * miss it.
 *
 * Usage: php load-generated.php — then one request per line on stdin, `<directory>\t<namespace>`; each
 * answer is one JSON line, the list of problems. A fatal error ends the process mid-request; the caller
 * reads that as "cannot load" and starts a new one. One long-lived process, because starting PHP and the
 * autoloader costs twenty generations.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

while (($line = fgets(STDIN)) !== false) {
    [$directory, $namespace] = explode("\t", rtrim($line, "\n"));
    echo json_encode(checkGenerated(directory: $directory, namespace: $namespace)), "\n";
}

/**
 * @return array<int, string>
 */
function checkGenerated(string $directory, string $namespace): array
{
    spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
        if (str_starts_with($class, $namespace . '\\')) {
            $file = $directory . '/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            require_once $file->getPathname();
        }
    }

    $problems = [];
    $typeExists = static fn(string $name): bool => class_exists($name) || interface_exists($name) || enum_exists($name);
    $namedTypes = static function (?ReflectionType $type): array {
        if ($type === null) {
            return [];
        }
        $types = $type instanceof ReflectionNamedType ? [$type] : ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : []);
        $names = [];
        foreach ($types as $inner) {
            if ($inner instanceof ReflectionNamedType && !$inner->isBuiltin() && !in_array(strtolower($inner->getName()), ['self', 'static', 'parent'], true)) {
                $names[] = $inner->getName();
            }
        }

        return $names;
    };

    foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
        if (!str_starts_with($class, $namespace . '\\')) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties() as $property) {
            foreach ($namedTypes($property->getType()) as $name) {
                if (!$typeExists($name)) {
                    $problems[] = sprintf('%s::$%s is typed by %s, which does not exist', $class, $property->getName(), $name);
                }
            }
        }
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            foreach ($namedTypes($method->getReturnType()) as $name) {
                if (!$typeExists($name)) {
                    $problems[] = sprintf('%s::%s() returns %s, which does not exist', $class, $method->getName(), $name);
                }
            }
            foreach ($method->getParameters() as $parameter) {
                foreach ($namedTypes($parameter->getType()) as $name) {
                    if (!$typeExists($name)) {
                        $problems[] = sprintf('%s::%s($%s) takes %s, which does not exist', $class, $method->getName(), $parameter->getName(), $name);
                    }
                }
            }
        }
        if ($reflection->isEnum()) {
            $class::cases();
        }
    }

    return array_values(array_unique($problems));
}
