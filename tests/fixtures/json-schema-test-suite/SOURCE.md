# JSON-Schema-Test-Suite (draft 2020-12, required tests)

- Repository: https://github.com/json-schema-org/JSON-Schema-Test-Suite
- Tag: `Test-JSON-Schema-Acceptance-1.041`
- Commit: `245a45abc90190c0bff04d97e6d2b541b4bb9f12`
- Copied: 2026-11-22
- Copied files: `tests/draft2020-12/*.json` (the required tests only — `optional/` is not included)
  and the suite's `LICENSE` (MIT), unchanged.

`remotes/draft2020-12/` is copied too (2026-11-23, same tag, unchanged): the schemas the suite serves
from `http://localhost:1234/draft2020-12/…`. The generator fetches nothing, so the harness preloads
them, as a validator registers the remotes before a run: each one a referenced document needs is
embedded in the wrapped OpenAPI document as a component whose `$id` is its URL. The other drafts'
remotes are not copied. A reference to a json-schema.org metaschema stays `skipped`.

`expectations.php` is ours, not the suite's: the per-mode list of tests that do not yet agree
with the suite, read by `tests/Parity/JsonSchemaTestSuiteTest.php`. Re-record it with
`JSTS_RECORD=1 vendor/bin/phpunit tests/Parity/JsonSchemaTestSuiteTest.php` — reasons of entries
whose status did not change are kept.
