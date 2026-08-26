<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Every link between the markdown files, resolved.
 *
 * Documentation breaks SILENTLY: a heading is renamed, the links into it keep rendering as links, and
 * the reader lands at the top of a page instead of the section they were promised. Three of them were
 * live at once — two `README.md#validation-notes` and one `README.md#generation-modes`, both left over
 * from a restructuring of `README.md` — and nothing noticed, because nothing was looking.
 *
 * Only INTERNAL links are checked. An `https://` link needs the network to verify and would make the
 * suite flaky; a file-relative one is answerable from the repository alone, which is the whole point.
 */
final class DocumentationLinksTest extends TestCase
{
    /**
     * GitHub's own rule for turning a heading into a fragment: lowercase, drop everything that is not
     * a word character, a hyphen or a space, then spaces become hyphens. Implemented here because the
     * anchors this test resolves are the ones GitHub will resolve.
     */
    private static function slug(string $heading): string
    {
        $text = strtolower(trim(preg_replace('/^#+\s*/', '', $heading) ?? ''));
        $text = preg_replace('/[^\p{L}\p{N}_\- ]+/u', '', $text) ?? '';

        return str_replace(' ', '-', $text);
    }

    public function testEveryInternalDocumentationLinkResolves(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/*.md') ?: [];
        self::assertNotSame([], $files, 'no markdown files found — the glob is wrong, not the docs');

        $anchorsByFile = [];
        foreach ($files as $path) {
            $anchors = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^#{1,6}\s/', $line) === 1) {
                    $anchors[self::slug($line)] = true;
                }
            }
            $anchorsByFile[basename($path)] = $anchors;
        }

        $problems = [];
        foreach ($files as $path) {
            $name = basename($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                // `](target)` where the target is not absolute: either `file.md`, `file.md#anchor`, or
                // a bare `#anchor` meaning this same file.
                preg_match_all('/\]\(([^)\s]*)\)/', $line, $matches);

                foreach ($matches[1] as $target) {
                    if ($target === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                        continue;
                    }

                    [$file, $anchor] = array_pad(explode('#', $target, 2), 2, null);
                    $file = $file === '' ? $name : $file;

                    if (!array_key_exists($file, $anchorsByFile)) {
                        // A link to something other than a sibling markdown file — a source path, a
                        // directory. Only its existence is answerable here.
                        if (!file_exists($root . '/' . $file)) {
                            $problems[] = sprintf('%s:%d links to "%s", which does not exist', $name, $index + 1, $file);
                        }

                        continue;
                    }

                    if ($anchor !== null && !array_key_exists($anchor, $anchorsByFile[$file])) {
                        $problems[] = sprintf(
                            '%s:%d links to "%s#%s" — that file has no such heading',
                            $name,
                            $index + 1,
                            $file,
                            $anchor,
                        );
                    }
                }
            }
        }

        self::assertSame([], $problems, "Broken documentation links:\n" . implode("\n", $problems));
    }
}
