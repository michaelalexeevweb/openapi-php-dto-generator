# The generator command

Every option `openapi:generate-dto` takes, and the two ways to wire the runtime services.

Back to the [README](README.md).

## Add script in your project `composer.json`

```json
{
  "scripts": {
    "openapi:generate-dto": "php vendor/michaelalexeevweb/openapi-php-dto-generator/bin/console openapi:generate-dto"
  }
}
```

## Generate DTO classes from YAML OpenAPI spec

**Default — use the runtime services straight from the installed package.** Omit the
`--dto-generator-*` options: the generated DTOs reference the runtime classes from
`vendor/` (`OpenapiPhpDtoGenerator\Contract\…`), so nothing is copied and updates come
through `composer update`:

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test
```

**Optional — vendor a private copy of the runtime services** into your project (e.g. to
commit them or decouple from the package). Pass `--dto-generator-directory`; the generated
DTOs then reference that copied namespace instead of `vendor/`:

```bash
composer openapi:generate-dto -- \
  --file=OpenApiExamples/test.yaml \
  --directory=generated/test \
  --namespace=Generated\\Test \
  --dto-generator-directory=Common \
  --dto-generator-namespace=Generated\\Common
```

Parameters:

| Option | Alias | Required | Description |
|---|---|---|---|
| `--file` | `-f` | ✅ | Path to OpenAPI spec file (YAML or JSON), or a mask (`specs/*/api.yml`) that generates every matching document in one run, with `{1}`, `{2}`… in `--directory` and `--namespace` — see [Many documents in one run](#many-documents-in-one-run). |
| `--directory` | `-d` | ✅ | Output directory for generated DTOs |
| `--namespace` | | | Explicit DTO namespace (derived from `--directory` if omitted) |
| `--dto-generator-directory` | | | **Omit** to use the runtime services from `vendor/` (no copy — the default). Pass it to copy them into the given directory instead; the flag without a value defaults to `Common`. `Common` is created inside `--directory`; any other relative path is resolved from the current working directory, an absolute one is used as is. |
| `--dto-generator-namespace` | | | Namespace for the copied runtime services. Only has effect together with `--dto-generator-directory`. |
| `--attributes` | | | Generation mode: `runtime` (default — DTOs use this library's runtime), `symfony` (DTOs decorated with Symfony Validator/Serializer attributes), `laravel` (a plain DTO plus a `FormRequest` with `rules()`), `laravel-data` (one `spatie/laravel-data` class per schema) or `yii3` (a `yiisoft/input-http` class per schema). See [Five modes](README.md#five-modes). |
| `--with-psr7` | | | Also copy the PSR-7 deserializer (`DtoDeserializerPsr7`) when vendoring the runtime via `--dto-generator-directory`. Requires `symfony/psr-http-message-bridge` in the consuming project. |
| `--symfony-path-params` | | | With `--attributes=symfony`: also generate `<Operation>PathParams`, a DTO of the operation's path parameters, for a value resolver of your own. See [Path parameters as a DTO](README.symfony.md#path-parameters-as-a-dto). Ignored with a warning in other modes. |
| `--laravel-form-request` / `--no-laravel-form-request` | | | With `--attributes=laravel`: on by default. `--no-laravel-form-request` writes the DTOs without their `<Dto>FormRequest`, for a project with `illuminate/validation` but no `laravel/framework`. See [Without `laravel/framework`](README.laravel.md#without-laravelframework). Ignored with a warning in other modes. |
| `--ref` | | | Placement for an external `$ref` spec file, directory **or mask**: `<refFileOrDir>=<directory>=<namespace>`. A directory key maps every ref'd file inside it; a mask (`specs/*/common`) maps every directory it matches, with `{1}`, `{2}`… in the directory and namespace — see [Masks](#masks). Repeatable. The older two-part `<refFileOrDir>=<directory>` still works and takes its namespace from `--ref-namespace`. |
| `--ref-namespace` | | | Namespace for a two-part `--ref`: `<refFileOrDir>=<namespace>`, same key. Repeatable. Not used with the three-part `--ref`. |
| `--only` | | | Generate only the classes whose name matches this mask (`Order`, `Order*`), and every class they need. Repeatable. See [Part of a document](#part-of-a-document). |
| `--exclude` | | | Leave out the classes whose name matches this mask; a kept class that needs one stops generation. Repeatable. |

Without `--ref`, a class from another document goes under the output directory, at the path its namespace
gives: the document's directory relative to the root one, `.` and `..` dropped, each segment in class-name
case. `../shared/money.yml` from the root document's directory becomes `<namespace>\Shared` in
`<directory>/Shared/`. A `common` directory follows the `Common` convention instead when the output path
has a segment to rebase it on. To put a document's classes anywhere else — one place shared by several
root documents, say — map its directory with `--ref`:

```bash
php bin/console openapi:generate-dto \
  --file=specs/api/orders.yml \
  --directory=src/Generated/Orders \
  --namespace='App\Generated\Orders' \
  --ref='specs/shared=src/Generated/Shared=App\Generated\Shared'
```

One value is one mapping: the referenced file or directory, the output directory, the namespace. The two-part
form spreads the same mapping over `--ref` and `--ref-namespace`, matched by key; it still works, and the two
forms can be mixed. Mapping one key two different ways — two `--ref`s, or a three-part `--ref` plus a `--ref-namespace` —
stops generation instead of letting one of them win. A path or namespace that itself contains `=` cannot be
given in the three-part form.

### Masks

A key with `*`, `?` or `[…]` in it is a mask. It is matched one path segment at a time — `*` never crosses a
`/` — and, like a directory key, covers everything below the segments it matched. `{1}`, `{2}`… in the
directory and the namespace stand for the segments the 1st, 2nd… wildcard segment matched, in class-name
case in both (`pipeline` → `Pipeline`, `user_reports` → `UserReports`), so the directory and the namespace
segment are the same word and PSR-4 finds the class:

```bash
--ref='specs/*/common=src/Generated/{1}/Common=App\Generated\{1}\Common'
```

`specs/billing/common/money.yml` → `src/Generated/Billing/Common/Money.php`, namespace
`App\Generated\Billing\Common`; `specs/orders/common/address.yml` → `src/Generated/Orders/Common/`.

Which key wins for a file: an exact file key, then the deepest directory key, then a mask. A file that two
masks match stops generation — map it with an exact key or narrow the masks. A `{n}` past the key's last
wildcard segment, or on a key without one, is refused before anything is generated. Quote the value, so
the shell leaves the `*` alone.

## Many documents in one run

A `--file` with `*`, `?` or `[…]` in it is a mask: one run generates every file it matches. `{1}`, `{2}`… in
`--directory` and `--namespace` stand for what the 1st, 2nd… wildcard segment matched, in class-name case in
both — a file name without its extension — so each document gets its own directory and namespace and
PSR-4 finds the class:

```bash
php bin/console openapi:generate-dto \
  --file='specs/*/api.yml' \
  --directory='src/Generated/{1}' \
  --namespace='App\Generated\{1}' \
  --ref='specs/shared=src/Generated/Shared=App\Generated\Shared'
```

`specs/billing/api.yml` → `src/Generated/Billing`, `App\Generated\Billing`; `specs/user_reports/api.yml` →
`src/Generated/UserReports`. Without `--namespace` each namespace follows its filled directory.

The result is what one run per document would give — every document is generated on its own, with the
same options — in one process, published together:

- a document that fails stops the run before anything is written, and the error names the document;
- two documents may emit the same file — a `--ref`'d document both use, a shared `--dto-generator-directory`
  — as long as the bytes are the same; different contents at one path stop the run;
- a mask that matches no file, or a `{n}` past the mask's last wildcard segment (or with a plain `--file`),
  is refused before anything is generated;
- each document's `--directory` belongs to the run as described below; the directory of a document that
  no longer matches the mask is left alone.

Quote the value, so the shell leaves the `*` alone.

## Part of a document

`--only` and `--exclude` take a class name or a mask (`*`, `?`, `[…]`, case ignored), matched against the
generated class's short name. Both repeat.

```bash
php bin/console openapi:generate-dto --file=specs/api.yml --directory=src/Generated \
  --only='Order*' --exclude='OrderAudit*'
```

- `--only` picks the classes the run starts from and keeps every class they need: a property's class, a
  list's item class, an enum, a parent, a union's interface and the members that implement it. Nothing
  is ever left pointing at a class that was not generated.
- `--exclude` leaves out what it matches. A kept class that still needs an excluded one stops generation,
  naming both — exclude that class as well, or narrow the mask.
- a Laravel `<Dto>FormRequest` goes with its DTO: `--only=Order` keeps `OrderFormRequest`.
- a mask that matches no class anywhere in the run is refused, so a typo does not pass as a filter.
- the output directory still belongs to the run: a class the filter leaves out is removed from it.

## The output directory belongs to the command

`--directory` is regenerated, not merged into: anything the run did not emit is removed, so the
directory should hold generated code only.

**Outputs are staged before publication.** Rendering and all file writes finish in temporary locations
before the previous output is replaced. This includes explicit reference outputs and vendored common
services. A preparation failure leaves existing files intact; a publication failure restores the saved
outputs. Write failures are reported as errors, never as a successful generation.

The output root must be a real directory, not a symlink. Links inside an old output are removed as
links; the generator never recursively deletes their targets. Reference outputs outside the main
output directory preserve unrelated files.

The output directory itself is kept — its permissions, ownership and inode — so it may be a mount
point, and its parent does not need to be writable: staging and backup are hidden `.openapi-*`
directories inside it, and only its contents are swapped.

Publication uses filesystem renames and retains backups until every output is installed. Multiple
output roots do not switch atomically as a group, and process termination or a filesystem failure
that also prevents rollback can require recovery from the reported backup paths. Generate in CI or
an inactive release directory, then deploy the finished tree. Do not run concurrent generators against
the same output paths.

### Schema and output collisions

Referenced schemas from different documents must have distinct generated class names. Even identical
schema definitions in two files are separate identities: the generator reports a collision instead of
silently reusing the first. `--ref` (and `--ref-namespace`) change output locations, but do not make the
internal class-name registry support duplicate names. Repeated references to the same canonical
file and schema remain supported, including recursive references.

DTOs, enums and Laravel FormRequests must also have distinct generated classes and output files.
Collisions stop generation before publication, preserving the previous output.

## Requirements

- PHP 8.3+
- Symfony 7.4 or 8.x components (`console`, `http-foundation`, `mime`, `yaml`; 8.x needs PHP 8.4.1+)

A mode's own dependencies are listed in its guide — see the [README](README.md#five-modes).
