<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Fuzz;

/**
 * A document after one {@see SpecRewriter} rewrite: the root document, the other documents it now
 * refers to (file name => document), where the rewrite applied, and how.
 */
final readonly class RewrittenSpec
{
    private const string LOCAL_PREFIX = '#/components/schemas/';

    /**
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $externals file name => document, beside the root document
     * @param array<int, int|string> $path where the rewrite applied, as keys into the ORIGINAL document
     * @param bool $asFile whether the documents are generated from files rather than from an array
     */
    public function __construct(
        public string $rewrite,
        public array $document,
        public array $externals,
        public array $path,
        public int $choiceSeed,
        public string $note,
        public bool $asFile = false,
    ) {
    }

    /**
     * The site as a JSON pointer.
     */
    public function site(): string
    {
        return '/' . implode('/', array_map(
            callback: static fn(int|string $key): string => str_replace(['~', '/'], ['~0', '~1'], (string)$key),
            array: $this->path,
        ));
    }

    /**
     * The documents folded back into one, external references made local again — what the reference
     * oracle, which reads one document, judges. Sound because a moved component takes everything it
     * reaches with it, so no name of the other document clashes with one left behind.
     *
     * @return array<string, mixed>
     */
    public function bundled(): array
    {
        $document = $this->document;
        foreach ($this->externals as $file => $external) {
            $schemas = $external['components']['schemas'] ?? [];
            foreach (is_array($schemas) ? $schemas : [] as $name => $schema) {
                $document['components']['schemas'][$name] = $schema;
            }
            $document = SpecRewriter::retargetReferences(
                node: $document,
                from: $file . self::LOCAL_PREFIX,
                to: self::LOCAL_PREFIX,
                names: null,
            );
        }

        return $document;
    }
}
