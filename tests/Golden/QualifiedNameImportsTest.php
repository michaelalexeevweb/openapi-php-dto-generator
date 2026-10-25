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
            <<<'PHP'
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
            QualifiedNameImports::apply($source),
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
}
