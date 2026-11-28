<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

use OpenapiPhpDtoGenerator\Schema\SchemaNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The schema-level metamorphic layer, no generation: for every {@see SpecRewriter} rewrite,
 * `normalize(rewrite(spec))` and `normalize(spec)` are as close as {@see NormalizedForm} can say, and
 * no further apart than the rewrite has to leave them:
 *
 * - `wrapAllOf`, `splitScalar`, `moveToOtherFile` (folded back) and `fileInput` reach `exact` — except
 *   `{allOf: [$ref]}` at the TOP of a component, which is inheritance, a class of its own (`structural`);
 * - `nullableSpelling` reaches `exact` in 3.1; in 3.0 it moves the non-null core into a new component
 *   (`structural`: a component is a class name, so it is never inlined back);
 * - `aliasChain` reaches `reachable`: every reference follows the chain to its end, the alias
 *   component stays declared;
 * - `annotateRef` reaches `annotationFree`: the description is kept, docblocks read it;
 * - `extractComponent` reaches `structural`: the new component is a new class name;
 * - `reorderMembers` reaches `unordered`: member order is kept, a hydrating mode tries members in it
 *   (a `{type: null}` member of a pair excepted — it is spelled last).
 */
#[Group('fuzz')]
final class SchemaNormalizerMetamorphicTest extends TestCase
{
    private const int SEEDS = 40;

    private const int PROPERTIES = 6;

    #[DataProvider('rewrites')]
    public function testTheRewriteNormalizesNoFurtherApartThanItMust(string $rewrite): void
    {
        $applied = 0;
        $tooFar = [];
        $exact = 0;
        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            $spec = FuzzSpec::generate(seed: $seed, propertyCount: self::PROPERTIES);
            $document = $spec->document();
            $random = new FuzzRandom($seed);
            foreach (array_keys($spec->cases) as $property) {
                $rewritten = SpecRewriter::apply(rewrite: $rewrite, document: $document, property: $property, random: $random)['rewritten'];
                if ($rewritten === null) {
                    continue;
                }
                $applied++;
                $pair = NormalizedForm::normalizedPair(original: $document, rewritten: $rewritten);
                $level = NormalizedForm::level($pair['original'], $pair['rewritten']);
                $exact += $level === NormalizedForm::EXACT ? 1 : 0;
                $allowed = self::allowedLevel(rewritten: $rewritten, original: $document, openApi31: $spec->openApi31);
                if (array_search($level, NormalizedForm::LADDER, true) > array_search($allowed, NormalizedForm::LADDER, true)) {
                    $tooFar[] = sprintf('seed %d %s (%s): %s, allowed %s', $seed, $rewritten->site(), $rewritten->note, $level, $allowed);
                }
            }
        }

        $this->assertGreaterThan(self::SEEDS, $applied, 'the rewrite must find sites on fuzzed specs');
        $this->assertSame([], array_slice($tooFar, 0, 10));
        if (in_array($rewrite, [SpecRewriter::WRAP_ALL_OF, SpecRewriter::SPLIT_SCALAR, SpecRewriter::MOVE_TO_OTHER_FILE, SpecRewriter::FILE_INPUT], true)) {
            $this->assertGreaterThan(0, $exact);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rewrites(): iterable
    {
        foreach (SpecRewriter::REWRITES as $rewrite) {
            yield $rewrite => [$rewrite];
        }
    }

    public function testNormalizingTwiceChangesNothing(): void
    {
        $normalizer = new SchemaNormalizer();
        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            $once = $normalizer->normalize(FuzzSpec::generate(seed: $seed, propertyCount: self::PROPERTIES)->document());

            $this->assertTrue(NormalizedForm::same($normalizer->normalize($once), $once), 'seed ' . $seed);
        }
    }

    public function testTheOracleJudgesTheNormalizedDocumentAsTheOriginal(): void
    {
        $normalizer = new SchemaNormalizer();
        $changed = 0;
        $disagreements = [];
        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            $spec = FuzzSpec::generate(seed: $seed, propertyCount: self::PROPERTIES);
            $document = $spec->document();
            $normalized = $normalizer->normalize($document);
            $changed += NormalizedForm::same($document, $normalized) ? 0 : 1;
            $before = new ReferenceOracle($document);
            $after = new ReferenceOracle($normalized);
            foreach (MetamorphicRunner::payloadsOf($spec) as $payload) {
                $left = $before->accepts($payload['json']);
                $right = $after->accepts($payload['json']);
                if ($left !== null && $right !== null && $left !== $right) {
                    $disagreements[] = sprintf('seed %d %s', $seed, $payload['json']);
                }
            }
        }

        $this->assertGreaterThan(0, $changed, 'fuzzed specs carry spellings the normalizer folds');
        $this->assertSame([], array_slice($disagreements, 0, 10));
    }

    /**
     * The farthest apart the rewrite may leave the two normalized documents, by the site and the
     * spelling it rewrote.
     *
     * @param array<string, mixed> $original
     */
    private static function allowedLevel(RewrittenSpec $rewritten, array $original, bool $openApi31): string
    {
        $site = $original;
        foreach ($rewritten->path as $key) {
            $site = is_array($site) ? ($site[$key] ?? null) : null;
        }
        $componentTop = count($rewritten->path) === 3 && $rewritten->path[0] === 'components';

        return match ($rewritten->rewrite) {
            SpecRewriter::WRAP_ALL_OF => $componentTop && is_array($site) && array_key_exists('$ref', $site)
                ? NormalizedForm::STRUCTURAL
                : NormalizedForm::EXACT,
            SpecRewriter::SPLIT_SCALAR, SpecRewriter::MOVE_TO_OTHER_FILE, SpecRewriter::FILE_INPUT => NormalizedForm::EXACT,
            SpecRewriter::NULLABLE_SPELLING => $openApi31 ? NormalizedForm::EXACT : NormalizedForm::STRUCTURAL,
            SpecRewriter::ALIAS_CHAIN => NormalizedForm::REACHABLE,
            SpecRewriter::ANNOTATE_REF => NormalizedForm::ANNOTATION_FREE,
            SpecRewriter::EXTRACT_COMPONENT => NormalizedForm::STRUCTURAL,
            default => NormalizedForm::UNORDERED,
        };
    }
}
