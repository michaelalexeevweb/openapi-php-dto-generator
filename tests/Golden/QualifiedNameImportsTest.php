<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Golden;

use OpenapiPhpDtoGenerator\Command\Rendering\QualifiedNameImports;
use PHPUnit\Framework\TestCase;

/**
 * The import pass on hand-written input, for the cases the demo corpus does not reach: a short name the
 * file already uses for something else, and a second run over its own output.
 */
final class QualifiedNameImportsTest extends TestCase
{
    public function testAQualifiedClassAndConstantAreImported(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            use Illuminate\Validation\Validator;

            use function trim;

            final class Probe
            {
                public function check(\Illuminate\Validation\Validator $validator): int
                {
                    try {
                        return \PREG_SPLIT_NO_EMPTY + (int)trim('1');
                    } catch (\OverflowException $error) {
                        throw new \LogicException('x');
                    }
                }
            }

            PHP;

        $this->assertSame(
            expected: <<<'PHP'
                <?php

                namespace App;

                use Illuminate\Validation\Validator;
                use LogicException;
                use OverflowException;

                use function trim;

                use const PREG_SPLIT_NO_EMPTY;

                final class Probe
                {
                    public function check(Validator $validator): int
                    {
                        try {
                            return PREG_SPLIT_NO_EMPTY + (int)trim('1');
                        } catch (OverflowException $error) {
                            throw new LogicException('x');
                        }
                    }
                }

                PHP,
            actual: QualifiedNameImports::apply($source),
        );
    }

    /**
     * A schema may be called `Validator`: the sibling class is then what an unqualified `Validator`
     * means in that namespace, and an import under the same name would silently retarget it.
     */
    public function testAShortNameTheFileAlreadyUsesGetsAnAlias(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            final class Probe
            {
                /** @param array<Validator> $siblings */
                public function check(\Illuminate\Validation\Validator $validator, array $siblings): ?Rule
                {
                    return $this->validator === null ? null : new \Illuminate\Validation\Rule();
                }
            }

            PHP;

        $result = QualifiedNameImports::apply($source);

        $this->assertStringContainsString("use Illuminate\\Validation\\Rule as ValidationRule;\nuse Illuminate\\Validation\\Validator as ValidationValidator;\n", $result);
        $this->assertStringContainsString('public function check(ValidationValidator $validator, array $siblings): ?Rule', $result);
        $this->assertStringContainsString('new ValidationRule()', $result);
        $this->assertSame($result, QualifiedNameImports::apply($result), 'a second pass changes nothing');
    }

    public function testAMemberSharingTheShortNameIsNotACollision(): void
    {
        $source = "<?php\n\nnamespace App;\n\nfinal class Probe\n{\n    private ?\\LogicException \$logicException = null;\n\n"
            . "    public function get(): mixed\n    {\n        return \$this->logicException ?? \$this->validator(rules: 1);\n    }\n}\n";

        $this->assertStringContainsString("namespace App;\n\nuse LogicException;\n\nfinal class Probe", QualifiedNameImports::apply($source));
    }

    /**
     * A class of the file's own namespace is written by its short name and never imported; one of
     * another namespace is imported, even when the reference sits deep inside an expression.
     */
    public function testAnOwnNamespaceClassIsShortenedWithoutAnImport(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App\Paint;

            use Illuminate\Validation\Rule;

            final class Probe
            {
                public static function rules(): array
                {
                    return [
                        'tone' => [Rule::enum(\App\Paint\Tone::class)],
                        'swatch.colour' => [Rule::enum(\App\Shared\Colour::class)],
                    ];
                }
            }

            PHP;

        $this->assertSame(
            expected: <<<'PHP'
                <?php

                namespace App\Paint;

                use App\Shared\Colour;
                use Illuminate\Validation\Rule;

                final class Probe
                {
                    public static function rules(): array
                    {
                        return [
                            'tone' => [Rule::enum(Tone::class)],
                            'swatch.colour' => [Rule::enum(Colour::class)],
                        ];
                    }
                }

                PHP,
            actual: QualifiedNameImports::apply($source),
        );
    }

    /**
     * A class generated into the namespace owns its short name in every file there, even one that does
     * not mention it: the library class goes under an alias instead, and an attribute written in full
     * is a class, not a call.
     */
    public function testAReservedShortNameIsNeverImported(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            final class Probe
            {
                #[\Symfony\Component\Serializer\Attribute\Context(normalizationContext: [])]
                private ?\DateTimeInterface $at = null;
            }

            PHP;

        $this->assertSame(
            expected: <<<'PHP'
                <?php

                namespace App;

                use DateTimeInterface;
                use Symfony\Component\Serializer\Attribute\Context as AttributeContext;

                final class Probe
                {
                    #[AttributeContext(normalizationContext: [])]
                    private ?DateTimeInterface $at = null;
                }

                PHP,
            actual: QualifiedNameImports::apply(php: $source, reservedShortNames: ['Context', 'Widget']),
        );
    }

    /**
     * A namespace alias the document owns the name of is dropped, and every name written through it is
     * spelled out and imported on its own. A bare mention of the name stays the document's class.
     */
    public function testAReservedNamespaceAliasIsSpelledOut(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            use Symfony\Component\Validator\Constraints as Assert;

            final class Probe
            {
                #[Assert\Length(max: 3)]
                private string $name = '';

                #[Assert\Valid]
                private ?Assert $assert = null;
            }

            PHP;

        $this->assertSame(
            expected: <<<'PHP'
                <?php

                namespace App;

                use Symfony\Component\Validator\Constraints\Length;
                use Symfony\Component\Validator\Constraints\Valid;

                final class Probe
                {
                    #[Length(max: 3)]
                    private string $name = '';

                    #[Valid]
                    private ?Assert $assert = null;
                }

                PHP,
            actual: QualifiedNameImports::apply(php: $source, reservedShortNames: ['Assert']),
        );
    }
}
