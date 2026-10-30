<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Yii3\Recipe;

use BackedEnum;
use Yiisoft\Hydrator\Attribute\Parameter\Collection;
use Yiisoft\Hydrator\Attribute\Parameter\CollectionResolver;
use Yiisoft\Hydrator\Attribute\Parameter\ParameterAttributeInterface;
use Yiisoft\Hydrator\Attribute\Parameter\ParameterAttributeResolverInterface;
use Yiisoft\Hydrator\AttributeHandling\ParameterAttributeResolveContext;
use Yiisoft\Hydrator\DataInterface;
use Yiisoft\Hydrator\Exception\NonInstantiableException;
use Yiisoft\Hydrator\Result;

/**
 * README.yii3 "Raw checks in a nested object": `CollectionResolver` building each item of a list under
 * the list's key and the item's index, so `tags.1.id` is reported, not `id`.
 */
final class PathAwareCollectionResolver implements ParameterAttributeResolverInterface
{
    public function __construct(
        private readonly NestedPathTypeCaster $paths,
        private readonly CollectionResolver $collections = new CollectionResolver(),
    ) {
    }

    public function getParameterValue(
        ParameterAttributeInterface $attribute,
        ParameterAttributeResolveContext $context,
    ): Result {
        if (
            !$attribute instanceof Collection
            || is_a($attribute->className, BackedEnum::class, true)
            || !$context->isResolved()
            || !is_iterable($context->getResolvedValue())
        ) {
            return $this->collections->getParameterValue($attribute, $context);
        }

        $key = NestedPathTypeCaster::wireName($context->getParameter());
        $collection = [];
        foreach ($context->getResolvedValue() as $index => $item) {
            if (!is_array($item) && !$item instanceof DataInterface) {
                continue;
            }
            try {
                $collection[] = $this->paths->collectUnder(
                    path: [$key, $index],
                    build: static fn(): object => $context->getHydrator()->create($attribute->className, $item),
                );
            } catch (NonInstantiableException) {
                continue;
            }
        }

        return Result::success($collection);
    }
}
