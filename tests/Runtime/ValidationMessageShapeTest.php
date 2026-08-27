<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Runtime;

use OpenapiPhpDtoGenerator\Service\DtoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One shape for every message: a full stop at the end, a capital at the start unless the sentence
 * opens with a name the document chose.
 *
 * The second half is the one worth a test of its own. Three modes share these messages and spell the
 * subject differently on purpose — `param "f"` in runtime mode, `field "f"` in Symfony mode, and in
 * Laravel mode the bare property path, because Laravel keys its error bag by that path. Capitalising
 * there would rewrite an identifier the document owns, so `children.leaves.title` must survive as
 * itself. A future "just ucfirst everything" would read as tidying and would quietly break that.
 */
final class ValidationMessageShapeTest extends TestCase
{
    #[DataProvider('messageProvider')]
    public function testAMessageIsFinalisedToTheOneShape(string $message, ?string $subject, string $expected): void
    {
        self::assertSame($expected, DtoValidator::finalizeMessage($message, $subject));
    }
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function messageProvider(): array
    {
        return [
            // A sentence with its own subject: capitalised, full stop added.
            'english opener gains both' => ['method getX() must return int', null, 'Method getX() must return int.'],
            'already capital keeps it' => ['Required parameter "id" not found in request.', null, 'Required parameter "id" not found in request.'],

            // A subject label: left exactly as its owner spelled it.
            'runtime subject' => ['param "f" expects int, got string', 'param "', 'param "f" expects int, got string.'],
            'symfony subject' => ['field "f" must be of type integer', 'field "', 'field "f" must be of type integer.'],
            'laravel bare path' => ['children.leaves.title is required', 'children.leaves.title', 'children.leaves.title is required.'],

            // Idempotent: finalising twice is finalising once, which is what lets the normalizer pass
            // the constraint validator's output straight through.
            'idempotent' => ['param "f" expects int.', 'param "', 'param "f" expects int.'],
            'empty stays empty' => ['', null, ''],
        ];
    }

    /**
     * The property that makes the single exit points safe: whatever went through already may go
     * through again.
     */
    public function testFinalisingTwiceChangesNothing(): void
    {
        foreach (self::messageProvider() as $case => [$message, $subject, $expected]) {
            $once = DtoValidator::finalizeMessage($message, $subject);
            self::assertSame($once, DtoValidator::finalizeMessage($once, $subject), $case);
            self::assertSame($expected, $once, $case);
        }
    }

    /**
     * A capital would rewrite the name, so the subject wins over the rule.
     */
    public function testASubjectIsNeverCapitalised(): void
    {
        self::assertSame(
            'children.leaves.title is required.',
            DtoValidator::finalizeMessage('children.leaves.title is required', 'children.leaves.title'),
        );
        // And without the subject the helper cannot know, which is why every exit point passes it.
        self::assertSame(
            'Children.leaves.title is required.',
            DtoValidator::finalizeMessage('children.leaves.title is required'),
        );
    }
}
