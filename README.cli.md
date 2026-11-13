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
| `--file` | `-f` | ✅ | Path to OpenAPI spec file (YAML or JSON) |
| `--directory` | `-d` | ✅ | Output directory for generated DTOs |
| `--namespace` | | | Explicit DTO namespace (derived from `--directory` if omitted) |
| `--dto-generator-directory` | | | **Omit** to use the runtime services from `vendor/` (no copy — the default). Pass it to copy them into the given directory instead; the flag without a value defaults to `Common`. `Common` is created inside `--directory`; any other relative path is resolved from the current working directory, an absolute one is used as is. |
| `--dto-generator-namespace` | | | Namespace for the copied runtime services. Only has effect together with `--dto-generator-directory`. |
| `--attributes` | | | Generation mode: `runtime` (default — DTOs use this library's runtime), `symfony` (DTOs decorated with Symfony Validator/Serializer attributes), `laravel` (a plain DTO plus a `FormRequest` with `rules()`), `laravel-data` (one `spatie/laravel-data` class per schema) or `yii3` (a `yiisoft/input-http` class per schema). See [Five modes](README.md#five-modes). |
| `--with-psr7` | | | Also copy the PSR-7 deserializer (`DtoDeserializerPsr7`) when vendoring the runtime via `--dto-generator-directory`. Requires `symfony/psr-http-message-bridge` in the consuming project. |
| `--symfony-path-params` | | | With `--attributes=symfony`: also generate `<Operation>PathParams`, a DTO of the operation's path parameters, for a value resolver of your own. See [Path parameters as a DTO](README.symfony.md#path-parameters-as-a-dto). Ignored with a warning in other modes. |
| `--laravel-form-request` / `--no-laravel-form-request` | | | With `--attributes=laravel`: on by default. `--no-laravel-form-request` writes the DTOs without their `<Dto>FormRequest`, for a project with `illuminate/validation` but no `laravel/framework`. See [Without `laravel/framework`](README.laravel.md#without-laravelframework). Ignored with a warning in other modes. |
| `--ref` | | | Placement for an external `$ref` spec file **or directory**: `<refFileOrDir>=<directory>=<namespace>`. A directory key maps every ref'd file inside it. Repeatable. The older two-part `<refFileOrDir>=<directory>` still works and takes its namespace from `--ref-namespace`. |
| `--ref-namespace` | | | Namespace for a two-part `--ref`: `<refFileOrDir>=<namespace>`, same key. Repeatable. Not used with the three-part `--ref`. |

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
