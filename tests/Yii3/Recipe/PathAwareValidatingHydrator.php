<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3\Recipe;

use Yiisoft\Hydrator\DataInterface;
use Yiisoft\Hydrator\HydratorInterface;
use Yiisoft\Hydrator\Validator\ValidatedInputInterface;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\ValidatorInterface;

/**
 * README.yii3 "Raw checks in a nested object": `ValidatingHydrator`, collecting the raw checks through
 * {@see NestedPathTypeCaster} so an error in a nested object keeps the path to it.
 */
final class PathAwareValidatingHydrator implements HydratorInterface
{
    public function __construct(
        private readonly HydratorInterface $hydrator,
        private readonly ValidatorInterface $validator,
        private readonly NestedPathTypeCaster $paths,
    ) {
    }

    public function hydrate(object $object, array|DataInterface $data = []): void
    {
        $result = new Result();
        $this->paths->collectInto($result);
        try {
            $this->hydrator->hydrate($object, $data);
        } finally {
            $this->paths->collectInto(null);
        }
        $this->validated($object, $result);
    }

    public function create(string $class, array|DataInterface $data = []): object
    {
        $result = new Result();
        $this->paths->collectInto($result);
        try {
            $object = $this->hydrator->create($class, $data);
        } finally {
            $this->paths->collectInto(null);
        }
        $this->validated($object, $result);

        return $object;
    }

    private function validated(object $object, Result $result): void
    {
        if (!$object instanceof ValidatedInputInterface) {
            return;
        }

        $result->isValid()
            ? $this->validator->validate($object)
            : $object->processValidationResult($result);
    }
}
