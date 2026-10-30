<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3\Recipe;

use ReflectionProperty;
use Yiisoft\Hydrator\Attribute\Parameter\Data;
use Yiisoft\Hydrator\Result;
use Yiisoft\Hydrator\TypeCaster\HydratorTypeCaster;
use Yiisoft\Hydrator\TypeCaster\TypeCastContext;
use Yiisoft\Hydrator\TypeCaster\TypeCasterInterface;
use Yiisoft\Hydrator\Validator\Attribute\ValidateResolver;
use Yiisoft\Validator\Result as ValidationResult;

/**
 * README.yii3 "Raw checks in a nested object": `HydratorTypeCaster`, plus the key of the property it
 * builds an object for, put in front of every raw-check error the object reports.
 */
final class NestedPathTypeCaster implements TypeCasterInterface
{
    private ?ValidationResult $result = null;

    public function __construct(
        private readonly ValidateResolver $validateResolver,
        private readonly TypeCasterInterface $caster = new HydratorTypeCaster(),
    ) {
    }

    /** Where `ValidateResolver` writes; `null` when nothing collects raw errors. */
    public function collectInto(?ValidationResult $result): void
    {
        $this->result = $result;
        $this->validateResolver->setResult($result);
    }

    public function cast(mixed $value, TypeCastContext $context): Result
    {
        $outer = $this->result;
        if ($outer === null || !is_array($value)) {
            return $this->caster->cast($value, $context);
        }

        $inner = new ValidationResult();
        $this->collectInto($inner);
        try {
            return $this->caster->cast($value, $context);
        } finally {
            $this->collectInto($outer);
            $key = $this->wireName($context);
            foreach ($inner->getErrors() as $error) {
                $outer->addError($error->getMessage(), $error->getParameters(), [$key, ...$error->getValuePath()]);
            }
        }
    }

    private function wireName(TypeCastContext $context): string
    {
        $reflection = $context->getReflection();
        $data = $reflection instanceof ReflectionProperty ? $reflection->getAttributes(Data::class) : [];
        $name = $data === [] ? null : ($data[0]->getArguments()[0] ?? null);

        return is_string($name) ? str_replace('\.', '.', $name) : $reflection->getName();
    }
}
