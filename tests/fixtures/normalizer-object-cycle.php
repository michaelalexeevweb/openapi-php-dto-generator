<?php

declare(strict_types=1);

use OpenapiPhpDtoGenerator\Service\DtoNormalizer;

require __DIR__ . '/../../vendor/autoload.php';
require $argv[1];
$class = $argv[2];
$first = new stdClass();
$second = new stdClass();
switch ($argv[3]) {
    case 'self':
        $first->child = $first;
        break;
    case 'mutual':
        $first->child = $second;
        $second->child = $first;
        break;
    case 'array':
        $first->children = [$first];
        break;
}
try {
    (new DtoNormalizer())->toJson(new $class([$first]));
    exit(1);
} catch (RuntimeException $error) {
    echo $error->getMessage();
}
