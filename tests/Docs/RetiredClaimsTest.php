<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sentences the docs once said and the code has since made false.
 *
 * Each one outlived the change that retired it, because a sentence does not fail when the behaviour
 * under it moves. The behaviour is pinned by the test named beside it; this keeps the old claim from
 * coming back in the docs or in a comment the generator writes into every DTO.
 */
final class RetiredClaimsTest extends TestCase
{
    #[DataProvider('retiredClaimProvider')]
    public function testARetiredClaimIsNotRepeated(string $file, string $claim, string $provenBy): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../' . $file);
        $normalized = (string)preg_replace('/\s+/', ' ', $text);

        $this->assertStringNotContainsString(
            needle: $claim,
            haystack: $normalized,
            message: sprintf('%s repeats a claim %s disproves', $file, $provenBy),
        );

        [$testFile, $method] = explode('::', $provenBy);
        $this->assertStringContainsString(
            needle: 'function ' . $method . '(',
            haystack: (string)file_get_contents(__DIR__ . '/../' . $testFile),
            message: sprintf('%s still proves the opposite', $provenBy),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function retiredClaimProvider(): iterable
    {
        $emptyArray = 'Runtime/EmptyJsonContainerTest.php::testAnEmptyContainerIsJudgedByWhatArrived';
        $closedObject = 'Parity/ValidationParityTest.php::testAClosedObjectRefusesAnUndeclaredKeyWhereverTheRawBodyIsReachable';

        yield 'laravel: runtime accepts [] for an object' => [
            'README.laravel.md',
            'and runtime and Symfony mode accept it too',
            $emptyArray,
        ];
        yield 'laravel template: runtime accepts [] for an object' => [
            'templates/dto.laravel.php.twig',
            'and runtime and Symfony mode accept it',
            $emptyArray,
        ];
        yield 'laravel: runtime refuses [] only at the root' => [
            'README.laravel.md',
            'the one place the two differ',
            $emptyArray,
        ];
        yield 'laravel-data: runtime refuses [] only at the root' => [
            'README.laravel-data.md',
            'Runtime mode refuses `[]` at the root as well.',
            $emptyArray,
        ];
        yield 'laravel: runtime cannot close an object' => [
            'README.laravel.md',
            'runtime and Symfony mode both bind the payload into an object first',
            $closedObject,
        ];
        yield 'validation: subschema-local $defs is not folded' => [
            'README.validation.md',
            'is not folded',
            'Parity/ValidationParityTest.php::testAReferenceIntoAComponentNamesTheSchemaThere',
        ];
    }
}
