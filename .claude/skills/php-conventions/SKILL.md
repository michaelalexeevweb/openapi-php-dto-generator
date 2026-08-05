---
name: php-conventions
description: Coding and verification conventions for the openapi-php-dto-generator repository. Load before writing or editing any PHP in src/, tests/ or templates/ — it covers the banned language constructs the static analysis config implies (isset, unset), the generated-code rules, the golden-corpus workflow and the gates a change must pass before it is committed.
---

# Conventions in this repository

## Banned language constructs

**Never `isset()`.** PHPStan runs `ergebnis/phpstan-rules` with `noIsset` enabled, so `isset()` fails
the build. Use `array_key_exists('k', $array)` when the question is "is the key there", and `?? null`
when the question is "give me the value or nothing":

```php
// no
if (isset($schema['format'])) { … }

// yes
if (array_key_exists('format', $schema) && is_string($schema['format'])) { … }
$format = $schema['format'] ?? null;
```

**Never `unset()`.** Same spirit: the config bans the sibling construct, and a `unset()` sequence
describes what a structure is NOT. Build the result positively instead — a rebuilt array also states
the intent in one place and cannot leave a stale key behind:

```php
// no
foreach (self::COVERED as $keyword) {
    unset($schema[$keyword]);
}

// yes
$pruned = [];
foreach ($schema as $keyword => $value) {
    if (in_array($keyword, self::COVERED, true)) {
        continue;
    }
    $pruned[$keyword] = $value;
}
```

There is no sniff that catches `unset()` today, and ~24 pre-existing uses remain in `src/` — a global
ban needs a cleanup pass first. Until then: **do not add new ones.** In new code, rebuild.

## Style the tools enforce

- typed class constants: `private const array OBJECT_SHAPING_KEYWORDS = [...]`, `private const string
  ATTRIBUTE_MODE_LARAVEL = 'laravel'`;
- `declare(strict_types=1);` in every file;
- PHPStan level 8 over `src/` only, with `phpstan-strict-rules` and `privateInFinalClass`,
  `declareStrictTypes`, `noCompact`, `noEval`, `noIsset` from `ergebnis`;
- php-cs-fixer covers `src/` AND `tests/`; phpcs covers `src/`. Run `vendor/bin/php-cs-fixer fix`
  rather than hand-formatting.

## Generated code

- **Never hand-edit generated output.** Change the emitter (`src/Command/Rendering/Renders*Dto.php`) or
  the Twig template (`templates/*.twig`), then regenerate.
- Generated files are excluded from the style tools; do not run cs-fixer or phpcs over them.
- The generated code must be dependency-free per mode: runtime DTOs use this package's services,
  Symfony-mode DTOs use only `symfony/*`, Laravel-mode DTOs use only what ships with the framework.

## The golden corpus is the drift alarm

`tests/Golden/GoldenCorpusTest` snapshots the whole demo corpus per mode, byte for byte.

- an emitter change that does not intend to change output must leave the snapshots untouched — that is
  how a refactor proves it is behaviour-neutral;
- an intended change: `make golden` (or `UPDATE_GOLDEN_CORPUS=1 vendor/bin/phpunit --filter
  GoldenCorpus`), then READ the diff before committing it.

## Gates before every commit

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=1G
vendor/bin/phpcs
vendor/bin/php-cs-fixer fix --dry-run
```

All four, every time. `make check` runs the first three.

## Verify against the real thing, not against the emitted source

Source assertions prove only that the emitter wrote what was intended. Both times a mode was measured
against the framework instead, it found a bug the same day:

- `distinct` was emitted on the property path, where Laravel accepts it and enforces nothing;
- an enum inside an `anyOf` branch was never checked, because it had moved into the PHP type.

So: drive generated code through the real `Validator` / `Serializer` / `DtoDeserializer` in tests, and
prefer a case that asserts BOTH that a valid payload passes and that an invalid one fails — a rule that
accepts everything is invisible otherwise.

## Commits and releases

- no `Co-Authored-By` trailers, ever;
- release notes and version bumps stay in the short-bullet style of `CHANGELOG.md` and the GitHub
  releases (2-6 words per line);
- a published tag is immutable on Packagist: never amend or force-push over one.
