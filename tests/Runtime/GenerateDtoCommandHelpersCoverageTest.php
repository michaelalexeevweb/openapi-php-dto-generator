<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Reflection-driven coverage for GenerateDtoCommand's pure string/type helpers — the small
 * normalization and formatting branches that the end-to-end generation tests do not exercise
 * (empty/numeric-leading names, dedup, non-string inputs, already-nullable types, etc.).
 */
final class GenerateDtoCommandHelpersCoverageTest extends TestCase
{
    private GenerateDtoCommand $command;

    protected function setUp(): void
    {
        $this->command = new GenerateDtoCommand();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this->command, $method))->invoke($this->command, ...$args);
    }

    public function testNormalizeClassNameEdgeCases(): void
    {
        // No usable characters → the fallback name.
        $this->assertSame('GeneratedDto', $this->call('normalizeClassName', '!!!'));
        // A leading digit is prefixed so the result is a valid class name.
        $this->assertSame('Value123Abc', $this->call('normalizeClassName', '123 abc'));
    }

    public function testNormalizePropertyNameEdgeCases(): void
    {
        $this->assertSame('value', $this->call('normalizePropertyName', '   '));
        $this->assertSame('value', $this->call('normalizePropertyName', '!!!'));
    }

    public function testToBooleanBranches(): void
    {
        $this->assertTrue($this->call('toBoolean', 1));
        $this->assertFalse($this->call('toBoolean', 0));
        $this->assertTrue($this->call('toBoolean', 'yes'));
        // Neither bool/int/string → false.
        $this->assertFalse($this->call('toBoolean', ['x']));
    }

    public function testSanitizeEnumCaseNameEdgeCases(): void
    {
        $sanitize = new ReflectionMethod($this->command, 'sanitizeEnumCaseName');
        $used = [];
        $args = ['!!!', &$used];
        $this->assertSame('VALUE', $sanitize->invokeArgs($this->command, $args));
        // Leading digit → prefixed.
        $args2 = ['9lives', &$used];
        $this->assertSame('VALUE_9lives', $sanitize->invokeArgs($this->command, $args2));
        // Collision → suffixed.
        $args3 = ['!!!', &$used];
        $this->assertSame('VALUE_2', $sanitize->invokeArgs($this->command, $args3));
    }

    public function testBuildEnumCaseNameEdgeCases(): void
    {
        $build = new ReflectionMethod($this->command, 'buildEnumCaseName');
        $used = [];
        $args = ['!!!', &$used];
        $this->assertSame('VALUE', $build->invokeArgs($this->command, $args));
        $used2 = [];
        $args2 = [123, &$used2];
        $this->assertSame('VALUE_123', $build->invokeArgs($this->command, $args2));
    }

    public function testNormalizeNamespaceSegmentEdgeCases(): void
    {
        $this->assertSame('Generated', $this->call('normalizeNamespaceSegment', '!!!'));
        $this->assertSame('Value9x', $this->call('normalizeNamespaceSegment', '9x'));
    }

    public function testIsScalarSchemaDefinition(): void
    {
        $this->assertTrue($this->call('isScalarSchemaDefinition', ['type' => 'string']));
        $this->assertFalse($this->call('isScalarSchemaDefinition', ['$ref' => '#/x']));
        $this->assertFalse($this->call('isScalarSchemaDefinition', ['type' => 'string', 'properties' => []]));
    }

    public function testIsInlineObjectVariant(): void
    {
        $this->assertTrue($this->call('isInlineObjectVariant', ['type' => 'object']));
        // No explicit type but has properties → still an inline object.
        $this->assertTrue($this->call('isInlineObjectVariant', ['properties' => ['a' => []]]));
        $this->assertFalse($this->call('isInlineObjectVariant', ['type' => 'string']));
    }

    public function testEnsureTypeAllowsNullBranches(): void
    {
        // Already nullable → unchanged.
        $this->assertSame('?Foo', $this->call('ensureTypeAllowsNull', '?Foo'));
        $this->assertSame('A|null', $this->call('ensureTypeAllowsNull', 'A|null'));
        // Union without null → append |null.
        $this->assertSame('A|B|null', $this->call('ensureTypeAllowsNull', 'A|B'));
        // Single type → nullable shorthand.
        $this->assertSame('?Foo2', $this->call('ensureTypeAllowsNull', 'Foo2'));
    }

    public function testOpenApiTypeToSymfonyTypeBranches(): void
    {
        $this->assertNull($this->call('openApiTypeToSymfonyType', 123));
        $this->assertSame('string', $this->call('openApiTypeToSymfonyType', 'string'));
        $this->assertSame('array', $this->call('openApiTypeToSymfonyType', 'object'));
        $this->assertNull($this->call('openApiTypeToSymfonyType', 'unknown'));
    }

    public function testResolveEnumCaseNameForValueBranches(): void
    {
        // Unknown enum → null.
        $this->assertNull($this->call('resolveEnumCaseNameForValue', 'NoSuchEnum', 'x'));

        // Known enum but value absent → null; present → the case name.
        $this->command->enumSchemas['Color'] = [
            'type' => 'string',
            'values' => ['red', 'green'],
            'caseNames' => ['RED', 'GREEN'],
        ];
        $this->assertNull($this->call('resolveEnumCaseNameForValue', 'Color', 'blue'));
        $this->assertSame('GREEN', $this->call('resolveEnumCaseNameForValue', 'Color', 'green'));
    }

    public function testExtractDescriptionNormalizesWhitespace(): void
    {
        $this->assertNull($this->call('extractDescription', []));
        $this->assertNull($this->call('extractDescription', ['description' => '   ']));
        $this->assertSame('multi line', $this->call('extractDescription', ['description' => "multi\n   line"]));
    }

    public function testExtractExampleStringAndScalar(): void
    {
        $this->assertNull($this->call('extractExample', []));
        $this->assertNull($this->call('extractExample', ['example' => '  ']));
        $this->assertSame('a b', $this->call('extractExample', ['example' => "a\n b"]));
        // Non-string scalar/array example is stringified.
        $this->assertNotNull($this->call('extractExample', ['example' => 42]));
    }

    public function testComposePhpTypeHintBranches(): void
    {
        $this->assertSame('Foo', $this->call('composePhpTypeHint', 'Foo', false));
        $this->assertSame('mixed', $this->call('composePhpTypeHint', 'mixed', true));
        $this->assertSame('A|B|null', $this->call('composePhpTypeHint', 'A|B', true));
        $this->assertSame('A|null', $this->call('composePhpTypeHint', 'A|null', true));
        $this->assertSame('?Foo', $this->call('composePhpTypeHint', 'Foo', true));
    }

    public function testIsPropertyOverrideCompatible(): void
    {
        $mk = static fn(string $t, bool $n): array => ['type' => $t, 'nullable' => $n];
        // Different types → incompatible.
        $this->assertFalse($this->call('isPropertyOverrideCompatible', $mk('int', false), $mk('string', false)));
        // Parent non-nullable, child nullable → incompatible.
        $this->assertFalse($this->call('isPropertyOverrideCompatible', $mk('int', false), $mk('int', true)));
        // Same type, nullability not widened → compatible.
        $this->assertTrue($this->call('isPropertyOverrideCompatible', $mk('int', true), $mk('int', false)));
    }

    public function testRenderPhpLiteralBranches(): void
    {
        $this->assertSame('null', $this->call('renderPhpLiteral', null));
        $this->assertSame('true', $this->call('renderPhpLiteral', true));
        $this->assertSame('7', $this->call('renderPhpLiteral', 7));
        $this->assertSame("'hi'", $this->call('renderPhpLiteral', 'hi'));
        // Array literal renders too.
        $this->assertStringContainsString('1', $this->call('renderPhpLiteral', [1, 2]));
    }

    public function testNormalizePathForSchemaNameSkipsPlaceholders(): void
    {
        // Path params like {id} are dropped; segments are PascalCased and concatenated.
        $this->assertSame('UsersPosts', $this->call('normalizePathForSchemaName', '/users/{id}/posts'));
    }

    public function testDirectoryToNamespace(): void
    {
        $this->assertSame('Generated', $this->call('directoryToNamespace', ''));
        $this->assertSame('Foo\Bar', $this->call('directoryToNamespace', 'foo/bar'));
    }

    public function testNormalizeTrackingFlagName(): void
    {
        $this->assertSame('userNameInRequest', $this->call('normalizeTrackingFlagName', 'userName', 'InRequest'));
        // No usable characters → 'value' base.
        $this->assertSame('valueInPath', $this->call('normalizeTrackingFlagName', '!!!', 'InPath'));
    }

    public function testParseRefPairsRejectsMalformedInput(): void
    {
        $parse = new ReflectionMethod($this->command, 'parseRefPairs');

        // No '=' separator.
        try {
            $parse->invoke($this->command, ['no-separator'], 'ref-namespace');
            $this->fail('expected exception for missing separator');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid ref-namespace', $e->getMessage());
        }

        // Separator at position 0 (empty file).
        try {
            $parse->invoke($this->command, ['=value'], 'ref-namespace');
            $this->fail('expected exception for position-0 separator');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid ref-namespace', $e->getMessage());
        }

        // Empty value after '='.
        try {
            $parse->invoke($this->command, ['file='], 'ref-namespace');
            $this->fail('expected exception for empty value');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty file or value', $e->getMessage());
        }
    }

    public function testFormatFlatConstraintsForDocBlock(): void
    {
        $this->assertNull($this->call('formatFlatConstraintsForDocBlock', []));
        $text = $this->call('formatFlatConstraintsForDocBlock', ['type' => 'integer', 'minimum' => 5, 'maximum' => 10]);
        $this->assertIsString($text);
        $this->assertStringContainsString('minimum', $text);
    }

    public function testFormatUnionConstraintsForDocBlock(): void
    {
        // Non-array variant skipped; empty → null.
        $this->assertNull($this->call('formatUnionConstraintsForDocBlock', 'oneOf', ['not-array']));
        $text = $this->call('formatUnionConstraintsForDocBlock', 'oneOf', [['minLength' => 2], ['maximum' => 9]]);
        $this->assertIsString($text);
        $this->assertStringStartsWith('oneOf=', $text);
    }

    public function testScalarConstraintSpecsBuildsLengthAndRange(): void
    {
        $specs = $this->call('scalarConstraintSpecs', ['minLength' => 2, 'maxLength' => 5, 'exclusiveMinimum' => 1]);
        $names = array_column($specs, 'name');
        $this->assertContains('Length', $names);
        $this->assertContains('GreaterThan', $names);
    }

    public function testResolvePropertyTypeArrayItemBranches(): void
    {
        // items: object → a nested "<Owner><Prop>Item" DTO type.
        [$type] = $this->call(
            'resolvePropertyType',
            ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]],
            'Owner',
            'things',
        );
        $this->assertSame('array<OwnerThingsItem>', $type);

        // items: number → array<float>.
        [$numType] = $this->call(
            'resolvePropertyType',
            ['type' => 'array', 'items' => ['type' => 'number']],
            'Owner',
            'nums',
        );
        $this->assertSame('array<float>', $numType);

        // items with no type → bare array.
        [$bareType] = $this->call(
            'resolvePropertyType',
            ['type' => 'array', 'items' => []],
            'Owner',
            'anything',
        );
        $this->assertSame('array', $bareType);
    }
}
