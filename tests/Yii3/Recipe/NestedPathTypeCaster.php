<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3\Recipe;

use ReflectionParameter;
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
        if (!is_array($value)) {
            return $this->caster->cast($value, $context);
        }

        return $this->collectUnder(
            path: [self::wireName($context->getReflection())],
            build: fn(): Result => $this->caster->cast($value, $context),
        );
    }

    /**
     * Runs `$build` with the raw errors it causes collected apart, then reports them under `$path`.
     *
     * @template T
     * @param list<string|int> $path
     * @param callable(): T $build
     * @return T
     */
    public function collectUnder(array $path, callable $build): mixed
    {
        $outer = $this->result;
        if ($outer === null) {
            return $build();
        }

        $inner = new ValidationResult();
        $this->collectInto($inner);
        try {
            return $build();
        } finally {
            $this->collectInto($outer);
            foreach ($inner->getErrors() as $error) {
                $outer->addError($error->getMessage(), $error->getParameters(), [...$path, ...$error->getValuePath()]);
            }
        }
    }

    /** The key the payload carries the property under. */
    public static function wireName(ReflectionParameter|ReflectionProperty $reflection): string
    {
        $data = $reflection instanceof ReflectionProperty ? $reflection->getAttributes(Data::class) : [];
        $name = $data === [] ? null : ($data[0]->getArguments()[0] ?? null);

        return is_string($name) ? str_replace('\.', '.', $name) : $reflection->getName();
    }
}
