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
| `--dto-generator-directory` | | | **Omit** to use the runtime services from `vendor/` (no copy — the default). Pass it to copy them into the given directory instead; the flag without a value defaults to `Common`. |
| `--dto-generator-namespace` | | | Namespace for the copied runtime services. Only has effect together with `--dto-generator-directory`. |
| `--attributes` | | | Generation mode: `runtime` (default — DTOs use this library's runtime), `symfony` (DTOs decorated with Symfony Validator/Serializer attributes), `laravel` (a plain DTO plus a `FormRequest` with `rules()`), `laravel-data` (one `spatie/laravel-data` class per schema) or `yii3` (a `yiisoft/input-http` class per schema). See [Five modes](README.md#five-modes). |
| `--with-psr7` | | | Also copy the PSR-7 deserializer (`DtoDeserializerPsr7`) when vendoring the runtime via `--dto-generator-directory`. Requires `symfony/psr-http-message-bridge` in the consuming project. |
| `--ref` | | | Explicit output directory for an external `$ref` spec file **or directory**: `<refFileOrDir>=<directory>`. A directory key maps every ref'd file inside it. Repeatable. Requires a matching `--ref-namespace`. Unmatched ref files are ignored. |
| `--ref-namespace` | | | Explicit namespace for an external `$ref` spec file **or directory**: `<refFileOrDir>=<namespace>`. Repeatable. Requires a matching `--ref`. |

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
silently reusing the first. `--ref` and `--ref-namespace` change output locations, but do not make the
internal class-name registry support duplicate names. Repeated references to the same canonical
file and schema remain supported, including recursive references.

DTOs, enums and Laravel FormRequests must also have distinct generated classes and output files.
Collisions stop generation before publication, preserving the previous output.

## Requirements

- PHP 8.3+
- Symfony 7.4 or 8.x components (`console`, `http-foundation`, `mime`, `yaml`; 8.x needs PHP 8.4.1+)

A mode's own dependencies are listed in its guide — see the [README](README.md#five-modes).
