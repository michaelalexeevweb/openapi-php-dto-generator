<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three runtime services do not depend on one another. What they share lives in
 * `DtoServiceSupport`, and what the deserializer and the normalizer ask of a validator goes through
 * `Contract\JsonShapeAwareValidatorInterface`. The one name allowed across is the default validator a
 * constructor builds when none is given, `new DtoValidator()`, so `new DtoDeserializer()` keeps working.
 *
 * Read from the code tokens, so a comment that mentions a neighbour is not a dependency.
 */
final class ServiceIndependenceTest extends TestCase
{
    /**
     * @param array<string, int> $allowedMentions how often each neighbour may be named in code
     */
    #[DataProvider('serviceProvider')]
    public function testAServiceNamesNoNeighbourBeyondItsDefault(string $service, array $allowedMentions): void
    {
        $tokens = token_get_all((string)file_get_contents(__DIR__ . '/../../src/Service/' . $service . '.php'));
        $mentions = array_fill_keys(array_keys($allowedMentions), 0);
        $defaults = 0;
        $previous = [];
        foreach ($tokens as $token) {
            if (!is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $name = $token[1];
            if (array_key_exists($name, $mentions) && $token[0] === T_STRING) {
                $mentions[$name]++;
                if (($previous[0] ?? null) === T_NEW) {
                    $defaults++;
                }
            }
            $previous = $token;
        }

        $this->assertSame($allowedMentions, $mentions, $service . ' names a neighbour');
        $this->assertSame(array_sum($allowedMentions), $defaults, $service . ' names a neighbour other than as `new DtoValidator()`');
    }
    /**
     * @return iterable<string, array{0: string, 1: array<string, int>}>
     */
    public static function serviceProvider(): iterable
    {
        yield 'the validator names neither neighbour' => ['DtoValidator', ['DtoDeserializer' => 0, 'DtoNormalizer' => 0]];
        yield 'the deserializer builds the default validator only' => ['DtoDeserializer', ['DtoNormalizer' => 0, 'DtoValidator' => 1]];
        yield 'the normalizer builds the default validator only' => ['DtoNormalizer', ['DtoDeserializer' => 0, 'DtoValidator' => 1]];
        yield 'the shared support names no service' => ['DtoServiceSupport', ['DtoDeserializer' => 0, 'DtoNormalizer' => 0, 'DtoValidator' => 0]];
    }
}
