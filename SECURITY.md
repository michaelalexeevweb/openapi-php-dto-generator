# Security policy

## Supported versions

Fixes are released on the latest `2.x` minor line only. Upgrade to the newest release before reporting —
the issue may already be fixed.

| Version         | Supported |
|-----------------|-----------|
| latest `2.15.x` | ✅        |
| anything older  | ❌        |

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub:
[Report a vulnerability](https://github.com/michaelalexeevweb/openapi-php-dto-generator/security/advisories/new)
(Security tab → "Report a vulnerability").

Include what you can of:

- the affected version and generation mode (runtime, symfony, laravel, laravel-data, yii3);
- a minimal OpenAPI document and payload that reproduce it — with invented names, not your
  production schema;
- what happens and what you expected.

You will get an acknowledgement within a few days. A confirmed issue is fixed in a new release, published
with a GitHub security advisory; reporters are credited unless they ask not to be.

## Scope

In scope: the generator itself (what it reads and the files it writes), the code it generates, and the
runtime services (`DtoDeserializer`, `DtoNormalizer`, `DtoValidator`) — for example generated code that
accepts data the schema forbids, or a document that makes the generator write outside its output
directory.

Out of scope: vulnerabilities in your application's own logic, and in dependencies — report those to
their maintainers.
