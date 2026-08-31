# Changelog

This file starts at 2.9.0. Notes for every earlier tag are the
[GitHub releases](https://github.com/michaelalexeevweb/openapi-php-dto-generator/releases).

## 2.15.15 — 2026-08-31

- `then` and `else` carrying `properties` checked nothing

A generated DTO validates its own fields against its own constraints, so the enclosing schema strips the
`properties` RULES before re-checking the value — without that, every message arrives twice. The strip
was applied to a CONDITIONAL branch as well, and there it is wrong: `then` and `else` are rules the
parent applies on top of the class, and the class has never heard of them. Measured:
`then: {properties: {code: {minLength: 7}}}` accepted `"short"`, while `then: {required: [code]}` —
which the strip leaves alone — had been enforced all along, which is why the gap looked like support for
the keyword rather than the absence of it.

Only runtime mode was affected; the other four already enforced it, and `allOf`, `anyOf`, `oneOf` and
`dependentSchemas` were right before this change. All six branch kinds are now asserted side by side, so
a future fix that quietly accepts less shows up as a failure rather than as silence.

**A limit is now documented rather than implied.** A conditional written on a PROPERTY is enforced in
every mode; written at the TOP LEVEL of a component, `if` / `then` / `else` is carried into the
constraint map by Symfony and yii3 only — runtime, laravel and laravel-data accept the object unchecked.
Measured in all five modes. The neighbouring object-wide keywords (`minProperties`, `dependentRequired`,
`not`) are emitted just as unevenly at that level, only yii3 carrying all of them, and their enforcement
per mode is not yet measured — so the row says that rather than implying a clean answer. Workaround:
state the rule on the property, or nest the object one level.

## 2.15.14 — 2026-08-31

- a `$ref` under `contains` / `prefixItems` / `unevaluatedItems` was checked by nothing
- `unevaluatedItems` as a schema is enforced, not only as `false`

Three applicators describe a value nothing materializes for: no property to type, no class to write. A
`$ref` under them was therefore left to the scrubber, and dropping it dropped the whole KEYWORD — a
subschema that extracts to `[]` matches everything, so `contains` stopped requiring, `prefixItems`
stopped constraining the position and `unevaluatedItems` stopped guarding the tail. Measured against the
identical document with the subschema written INLINE, which refused what the `$ref` spelling accepted.
This is the 2.15.6 union-branch bug and the 2.15.11 `allOf` bug in a third spelling, and unlike `allOf`
it is not confined to containers: nothing materializes for these keywords at any level.

`not` is deliberately left alone. An empty subschema matches everything, so a dropped `not` accepts
where it should reject — but an inlined one would reject where it should accept, and being wrong there
costs valid requests rather than silent passes. It gets its own measurement.

**Symfony, Laravel, laravel-data and yii3 needed a second fix.** The interpreter they share read
`unevaluatedItems` only in its `false` spelling — "no extra items allowed" — and ignored the SCHEMA
spelling entirely, which is the one a document uses to type the tail after `prefixItems`. It was carried
in the emitted constants and read by nothing, so every item past the prefix was accepted whatever it
held. Runtime had always read both; now all five agree, per keyword, pinned by `ValidationParityTest`.

## 2.15.13 — 2026-08-31

- a list with gaps in its PHP keys was written as a JSON object

`type: array` promises an array on the wire and says nothing about PHP keys — but `json_encode()` does:
hand the constructor the result of an `array_filter()` and `[0 => "a", 2 => "c"]` goes out as
`{"0":"a","2":"c"}`, an object where the document said list. `validate()` has always reported this
(`field "tags" must be a JSON array (list with sequential keys)…`), so a consumer who validates was
never in the dark — but one who only serializes had nothing to notice.

The emitted `toArray()` now reindexes a property declared as a LIST. It is unconditional rather than
opt-in, because for `type: array` the keys are never part of the contract, and an opt-in keyword would
have left every document that does not know about it emitting the broken form. A MAP is untouched:
`additionalProperties` makes the keys the data, and both halves are asserted side by side.

Nothing is silenced. `validate()` reads the property, not this view, so a payload assembled with holes
is still reported — the wire form is corrected, the mistake is still named.

Two modes emit their own `toArray()` and both got it: runtime and laravel. Symfony, laravel-data and
yii3 serialize through their framework or library, which this generator does not emit, so the same
document in those modes still depends on what that serializer does with a keyed array.

## 2.15.12 — 2026-08-31

- yii3: a class written as `allOf` asserted nothing at all

`Cat: {allOf: [$ref Pet, {required: [meow], …}]}` is how a document spells a class with a parent, and
the generator has always read it that way for SHAPE: `analyzeSchema()` merges the inline branches, so
`Cat` is emitted carrying `$meow` and extending nothing it should not. The CONSTRAINT path read the raw
schema instead, where the only top-level keyword is `allOf` — which the yii3 interpreter does not read.
The class came out with no constraints at all, so a payload missing `meow` was accepted, and so was one
missing the parent's `name`. The other four modes reported each once; this was measured against them.

The constraint path now sees the same object the class is: inline branches merged, and a `$ref` branch
resolved and merged too, so a parent's `required` is asserted for the child that inherits it. It does
not double-report, and the reason is the pruning that was already there — `minLength` on an inherited
property is covered by the emitted native rule and subtracted, so what reaches the interpreter is the
`required` list no rule expresses. One violation, one message, measured per case.

Only yii3 changed: `runtime`, `symfony`, `laravel` and `laravel-data` snapshots are byte-identical, and
in yii3 exactly the six `allOf`-shaped classes of the corpus gained their checks.

A WRONG TYPE inside such a class is still accepted in yii3, and that is the framework, not this: the
hydrator's `PhpNativeTypeCaster` turns `5` into `"5"` before any rule runs. It is declared for every
scalar case in `ValidationParityTest` and pinned again here.

## 2.15.11 — 2026-08-28

- `items: {allOf: [...]}` was checked by nothing, in every mode
- Symfony's interpreter learnt `allOf`; Laravel stopped repeating what its rules already say

`allOf` was the one composition keyword the inlining skipped, on a rule that reads reasonably: a
single-`$ref` `allOf` is how this generator spells inheritance, and that ref DOES become a class which
checks itself. Measured, the rule holds on a PROPERTY and inside a MAP, and fails under `items`: there
the value collapses to a bare `array`, no class is written for the item, and the emitted constraints
were `['type' => 'array']` and nothing else. `required`, every bound, and every level below them were
accepted in silence, while the identical chain spelled with `oneOf` was refused. Branches are walked
now under the same guard as a union branch — only where nothing materializes — so inheritance on a
property is untouched.

Symfony needed a second half: its emitted interpreter had no `allOf` at all, so the keyword reached the
generated constants and was read by nothing. It has the section now, and a `$ref` chain under `items`
is refused in all five modes — pinned per level and per spelling by `ValidationParityTest`.

**Laravel says less, not more.** Teaching the interpreter a keyword the rule map also covers is how one
violation becomes two messages, and `bag.test` did report both `validation.string` and
`bag.test must be of type string`. The rule-coverage pruning now descends into `allOf` branches, so a
branch keeps only what no rule expresses — a `required` list, typically — and each violation is
reported once. `InterpreterMessageParityTest` holds that per mode, yii3 included, where a property-level
`allOf` still reports nothing: its constraints never carried the keyword, and that gap is pinned as it
is rather than left to be rediscovered.

## 2.15.10 — 2026-08-28

- the inline ceiling now says so instead of truncating in silence
- the union chain is pinned in all five modes, not just runtime
- both orders of the 3.1 nullable type array are pinned

2.15.9 documented a limit: a `$ref` chain below materialization is inlined five hops deep, and anything
past that is accepted unchecked. Documented is not the same as visible — the generated code does not
mention those levels at all, so a consumer whose document is that deep had nothing to notice. The
generator now warns, once per cut point, naming the `$ref` where checking stops and what it costs.

A recursive schema does NOT warn. It is stopped by the other guard, has no finite inline form to ask
for, and there is nothing for the reader to act on. Measured before the warning was written: across the
demo corpus and the whole test suite the ceiling is reached ZERO times and the cycle guard 107 — which
is the difference between a warning that means something and noise on every recursive document.

The chain fixed in 2.15.8 and 2.15.9 had only ever been measured in runtime mode. Measured now in all
five at the old ceiling, the fourth hop was accepted in silence in every one of them, so neither the gap
nor the fix was ever runtime-specific. `ValidationParityTest` carries a case per level of the chain.

No behaviour changed for `type: ["null", "string"]` — it was already read exactly like
`["string", "null"]`, at the property level and inside a container. It had simply never been pinned:
every test in the repository wrote `null` last. A permission a document may spell two ways is two
chances to diverge later, so both orders now carry a case, in all five modes.

## 2.15.9 — 2026-08-28

- a `$ref` chain under a union branch is checked at every hop, not the first two

2.15.8 moved the border and left it in place: the inlining that carries a `$ref`'s rules into the
emitted constraints counted HOPS, so `A -> B -> C` was checked and `A -> B -> C -> D` was not. Under a
`oneOf` below a container nothing materializes — the property type collapses to `array` — so there is
no class on the path to catch what the constraints miss, and the level past the count passed in
silence. Raising the count would have moved the border again.

What sets it is asking the right question. A hop count was standing in for a CYCLE guard, and the two
are not the same: a chain that names something new at every hop is not a cycle and is now inlined for
as long as it keeps doing so. The guard proper is a per-path count of what has already been inlined,
which unrolls a self-referential component twice and stops — the depth the hop budget gave it, so the
recursive forms in the corpus are byte-identical in four modes and gain a level in the fifth.

That count was tried in 2.15.5 and exhausted memory. The reason is gone: back then the inlining ran on
every re-entry of `extractValidationConstraints()` — once per `oneOf` branch, once for `not`, once more
from the scrubber — and each re-entry started a fresh set, so a recursive component peeled off one more
level per pass. Since 2.15.6 it runs once, in the outermost extraction, and walks the whole tree itself.
Measured on the recursive corpus forms: 12 MB and 0.04s, the same numbers a hop budget gave.

One test had to be rewritten rather than re-expected. `testAUnionBranchUnderAContainerCarriesItsConstraints()`
asserted a SUBSTRING and called it "exactly one level deep" — but the innermost level satisfies that
substring at any depth, so it passed whether the component was unrolled once or ten times. It counts the
unrolls now, and fails at one and at three.

**A hop ceiling remains, at five, and it is now documented.** A count of repeats cannot see the other
runaway shape — an ACYCLIC document that branches, where nothing ever repeats. Measured on three
distinct components per level, each referring to all three of the next, the emitted constraints grow
about 2.9x per level: 70 KB at depth five, 194 KB at six, 568 KB and 68 MB at seven. Five hops is where
that curve is still cheap and deeper than any chain measured in a real document. Past it, values are
accepted unchecked — see "Not generated in any mode" in the support matrix.

## 2.15.8 — 2026-08-28

- a `$ref` chain under a union branch went unchecked past its first hop

2.15.6 taught the branch of a `oneOf`/`anyOf` below a container to carry its `$ref`'s constraints, one
level and no further. One level is enough wherever the chain materializes: `items: {$ref: B}` becomes a
DTO, that DTO runs the same rule for itself, and C is reached the next level down. Under a union branch
below a container nothing materializes — the property type collapses to `array` — so the chain stopped
at B. `A -> B -> C` with `minLength: 3` on `C.code` reported nothing at all for
`{"chain":[{"links":[{"code":"x"}]}]}`, while the same payload through the plain `$ref` chain was
refused. Validation passing where it should fail, in silence.

The one-level stop was not an oversight: a per-path "already seen" set had exhausted memory on a
recursive component in 2.15.5, because the extraction re-enters itself per union branch and each entry
started a fresh set. What replaces it is a BUDGET of two — it counts down along the path and cannot be
refilled by descending, so a self-referential schema still terminates. Raising it further multiplies
work per nesting level, so it moves only against a measured miss.

## 2.15.7 — 2026-08-28

- a container value the schema lets be null was refused
- an object two containers deep is hydrated, not a `stdClass`
- `nullable` alone no longer emits a Symfony interpreter
- a nested item's error path is quoted whole

**A message changed shape.** An error inside a `$ref`ed object two containers deep now reads
`param "tagRows.0.0.id" must be greater than or equal to 5.` where it read `tagRows".0.0.id must be
greater than or equal to 5.` The report moved to the cast that already handles a DTO one level up, and
that one has always quoted the whole path. Anything matching on the old spelling needs updating.

`nullable` on a container value is a permission, and each of the four ways to write it was wrong in its
own way. The 3.0 map value died in the cast with `expects string, got null`, because only `items` was
consulted for the permission and never `additionalProperties`. The 3.0 list declared `array<?string>`
honestly, but the docblock parser split on `|` alone and read `?string` as one unknown name, so the null
came back as `returned null but type is non-nullable string`. The 3.1 list lost its item type outright
and declared a bare `array`, whose items are never checked at all. The 3.1 map declared
`array<string, string>`, forbidding the null the document had just allowed. All four agree now, and a
non-null value of the wrong type is still refused, once.

Hydration reaches two containers deep in all four spellings — list of lists, list of maps, map of lists,
map of maps. `array<array<Tag>>` holds real `Tag` instances; until now it held the `stdClass`
`json_decode()` produced, which is why 2.15.4 had replaced the declaration with the true-but-poorer
`mixed`. Checking already reached that depth in 2.15.5, so what was missing was the typed object, not
the checks. Three containers deep nothing is hydrated and the declaration still says `mixed`. A nested
SCALAR is deliberately left where it was: it is already the value it claims to be, and casting it would
only reword a message consumers have had for three releases.

## 2.15.6 — 2026-08-27

- a `$ref` in a `oneOf`/`anyOf` branch under a container was checked by nothing
- a `$ref` to a CONTAINER component crashed the deserializer, or lost the keys in silence
- one shape for every message: a full stop, and a capital only where a sentence starts
- a map and a list of the same schema now answer alike, `42.0` included
- the docs stopped showing messages the code no longer writes

Two of these came from a real document that a consumer had been running for a year with its response
validation switched off by hand, because the generated checks were unusable on that schema.

**A union branch under a container.** `additionalProperties: {oneOf: [{$ref: Leaf}, …]}` emitted
`['type' => 'object']` and nothing else: every value under it was accepted in silence. The inlining
that turns a `$ref` into constraints walked containers and properties but not union BRANCHES, so the
branch stayed a bare `$ref`, extracted to nothing, and — because one empty branch makes a union
unenforceable — took the whole subschema with it. Branches are walked now, and only where it is true
that nothing materializes: on a PROPERTY an inline union becomes a PHP union type (`Circle|Square`),
the value is a generated DTO and validates itself, so that case is left alone. `allOf` is not walked
either, because a single-`$ref` `allOf` is how this generator spells inheritance and that ref does get
a class.

The first attempt at this exhausted memory on a self-referential component. `extractValidationConstraints()`
re-enters itself once per union branch, once for `not`, and four more times from the scrubber, and an
inlining that ran on every entry peeled one more level off the recursion per pass. It runs in the
OUTERMOST extraction now and walks the whole tree itself, one level per path.

**A `$ref` to a container component.** A component whose top level is `type: array` or a map is a type
ALIAS, not an object — which the property level has known for releases, comment and all. Under `items`
it was named as a class instead, and the two shapes failed differently: `array<StringList>` for a
`type: array` component, where no `StringList.php` is ever written, so a VALID payload died with
`unknown type` and the endpoint could not be called; `array<CountMap>` for a map component, where the
class IS written and is empty, so `{"f":[{"a":7}]}` came back as `{"f":[{}]}` with the keys gone and
nothing reported. Both branches now ask the same resolver the property level asks. A map VALUE that is
such a `$ref` gains the same precision — `array<string, array<string>>` where it used to say `mixed`.

**One shape for every message.** Half of them ended in a full stop and half did not, which reads as a
defect in a list joined behind `DTO validation failed:`. All of them do now. Capitalisation follows the
grammar rather than a blanket rule: `Required parameter "id" not found in request.` is a sentence with
its own subject and takes a capital, while `param "f" expects int, got string.` opens with a subject
LABEL and does not. That distinction is load-bearing — the same messages are spelled `field "f"` in
Symfony mode and, in Laravel mode, as the bare property path, because Laravel keys its error bag by
that path. Capitalising there would rewrite an identifier the document owns: `children.leaves.title`
is not `Children.leaves.title`. The rule lives in one place (`DtoValidator::finalizeMessage()`) and is
applied at each class's single exit, plus once per emitted interpreter packaging.

**A map and a list of the same schema.** `additionalProperties: {type: integer}` emitted a native
`#[Assert\Type('int')]` for its values while `items: {type: integer}` did not, so a map refused `42.0`
and a list accepted it — and JSON Schema 2020-12 §6.1.1 says a zero-fraction float IS an integer, so
the list was right. The attribute is no longer emitted for a SCALAR map value and `type` goes back to
the callback, exactly as in the list spelling; a map of a CLASS keeps its `Assert\Type`, because
nothing else can say "this is that enum". The support matrix said the `42.0` divergence reached map
values; it no longer does, and now says so.

**Docs.** Eleven examples across six files showed messages in the old shape the moment the shape
changed. A `$ref` to a container component is now a row in both depth tables.

Not changed, deliberately: a wrong TYPE inside a container still produces two messages in Symfony mode,
and the second one is Symfony's own — `Assert\Range` applied to a non-number adds "This value should be
a valid number." A hand-written `#[Assert\All([new Assert\Range(min: 5)])]` produces it too, the user's
translations apply to it, and `InterpreterMessageParityTest` has always held that a framework's own
sentences are not ours to overwrite.


## 2.15.5 — 2026-08-27

- a property name with a quote produced PHP that did not parse
- a `$ref`ed object two containers deep was validated by nothing
- one mistake, one message: Symfony reported container items two and three times
- a bound below ten containers deep was enforced by neither half in Laravel mode
- `bin/benchmark` could not find its autoloader in a dist install
- declarations two deep now say what they hold, not `mixed`

Six defects, five of them found by MEASURING the thing the last release had just documented rather
than by reading it. Three had been shipping for releases.

**A quote in a property name.** `it's` is a name a document is entitled to, and both characters that
end a single-quoted PHP literal — the quote and the backslash — were emitted raw in five places. The
generated file did not parse at all, in runtime and yii3 modes; the other three survived by accident.
One value was being escaped four different ways: the full form in a `|replace(…)` repeated
thirty-seven times across the templates, the quote alone in the yii3 ones, `str_replace` in the
renderers, and nothing whatsoever in five literals. There is now ONE spelling — a `php_string` Twig
filter over the `escapeSingleQuoted()` the renderers already used — and `laravelStringLiteral()`, a
second implementation of the same idea, is gone. The corpus gained the names `it's`, `back\slash` and
`both\'s`, so `testEveryGeneratedCorpusFileParses` answers for all five modes at once; the corpus had
held nothing more awkward than camelCase, which is why this was invisible.

**An object two containers deep.** `array<array<Tag>>` was checked by nothing: a value below its
`minimum`, a missing `required` property, even a bare string where the object belonged — all accepted
in silence. Three separate halves were missing, and each only became visible once the one before it
was fixed. The generator now inlines an object `$ref`'s own `properties`/`required` into the emitted
constraints below the level where materialization stops (with a per-path guard, so a self-referential
component inlines once instead of forever). `DtoValidator` gives a plain `stdClass` the array view the
emitted interpreter already took, without which every object keyword was skipped for a value that was
not a generated DTO — that half was runtime-only, and the parity suite said so by failing on exactly
one column. And Laravel's dotted rules now descend into an item's PROPERTIES (`tagRows.*.*.id`), which
the prune had already been assuming they did.

Hydration still stops there: the value remains the `stdClass` `json_decode()` produced, which is why
the declaration says `mixed`. The support matrix said "shape-checking" was missing; it was more than
the shape, and it now says what is actually absent.

**One mistake, one message, in Symfony mode.** `filterSymfonyValidationConstraints()` states that it
removes what the attributes already enforce "so the callback does not duplicate attribute-based
violations" — and then handed the callback every scalar keyword of every container item, while
`#[Assert\All]` was enforcing them too. Measured: three messages for one wrong map value, two for
every bound. The specs and the keywords they consume are now reported from the same branches
(`scalarConstraintSpecList()`, the shape `laravelSchemaRuleSpec()` has always had), and exactly those
are subtracted from the FIRST container hop. `Assert\All` does not nest, so from the second hop down
the callback keeps everything — which is what makes the paragraph above possible. `type` is never
subtracted for a list: no attribute asserts it, and the callback accepts the integral `42.0` that
JSON Schema calls an integer and `Assert\Type` would refuse. A map whose values are containers now
emits no `#[Assert\All]` at all, because the only thing it had to say was already the callback's.

**A bound ten containers deep, in Laravel mode.** The dotted rules and the coverage test counted depth
differently, so between them they left a hole at one end and an overlap at the other: at twelve
containers the rules had stopped while the coverage test still reported "a rule covers this", and
nothing enforced the bound — runtime rejected the payload, Laravel accepted it; at eight and nine both
claimed it and one mistake was reported twice. Both halves now count `.*` HOPS from the property
against one constant, and a sweep from one to twenty containers has them agreeing everywhere.

**`bin/benchmark` in a dist install.** It resolved its autoloader as `__DIR__ . '/../vendor/autoload.php'`,
a path that does not exist once the package sits in a consumer's `vendor/` — while
`README.performance.md`, which ships in the archive, tells the reader to run exactly that command. It
now walks up for the autoloader the way `bin/console` always has. There were no tests for `bin/` at
all; there are now, driving both binaries from a faked Composer layout.

**Declarations below the first level of items.** They said `mixed` where the value was perfectly
ordinary: a `format: date` two deep is the plain string the payload carried, so is an enum member, and
a `type: number` holds an int and a float in the same array. Each now says so — `array<array<string>>`,
`array<array<float|int>>` — and a `$ref` to a scalar or enum component resolves to its backing type.
`mixed` is left for exactly one shape, the object, where it is the only true answer.

**Also.** Three cross-document links pointed at headings a restructuring had removed, and six
docblocks had been stranded above the declaration that displaced them, leaving their owners with none
— which is how a `missingType.iterableValue` reached PHPStan, and how one comment survived describing
a constant the file no longer had. Both rules are mechanical and are now tests; PHPStan analyses `src`
only, so nothing had been looking at the second one in `tests/`.

No behaviour changed for a document that uses none of the shapes above: what a mode ACCEPTS is
unchanged everywhere except the four cases named here, all of which were payloads the schema forbade.


## 2.15.4 — 2026-08-26

- six bugs in one family: "container plus type"
- laravel casts temporal ITEMS; laravel-data and yii3 declare the strings they hold
- a container inside a container declares what it holds, and its values are checked
- yii3 renders from a template like the other four modes
- one mistake, one message: a map's value schema no longer reports twice
- the Packagist archive drops from 4.3 MB to 1.2 MB

One class of bug, found six times, each faster than the last because the parity matrix and the golden
corpus kept growing: the SCALAR path of a keyword gets its treatment and the ITEMS path does not.

**Temporal container items.** `items: {type: string, format: date}` types the item as
`DateTimeImmutable`, and three modes now really hold one there: runtime and symfony already did, laravel
casts each element in `fromValidated()` and gained the same getter pair the scalar has
(`getDates(): array<string>`, `getDatesAsDateTime()`). Before this its property held the strings
`validated()` handed over while the docblock promised objects — a lie a consumer's PHPStan was believing.
laravel-data and yii3 cannot convert without emitting a class of ours into generated code that depends on
nothing from this package (`#[WithCast]` casts the property and never its items — measured against the
package; the yii3 `#[ToDateTime]` resolver fails on an array outright), so they declare `array<string>`,
which is what they hold. The WIRE form was already identical in all five and still is. A yii3 schema whose
only temporal values are container items no longer pulls in `ext-intl`.

**Containers inside containers.** `array<array<X>>` collapsed to `array<mixed>` — the one item type
`DtoNormalizer::validate()` skips — so a matrix with a scalar where a row belonged passed in silence.
Worse, the map spelling did the opposite: `array<array<string, Tag>>` NAMED a class while holding the
`stdClass` `json_decode()` produced. Both are declarations now: a scalar keeps its type at any depth
(`array<array<int>>`, `array<string, array<string, int>>`) and anything that would need converting down
there says `mixed`. The values are checked in every mode — the emitted interpreter carries the depth-2
`enum` and `$ref`ed enum members (they were dropped by the constraint extractor, which four modes out of
five entered through the class schema rather than the property one), and laravel's dotted rules go as deep
as the schema does (`matrix.*.*`). A `$ref`ed OBJECT two containers deep is still not hydrated in any
mode, and the support matrix says so.

**One mistake, one message.** The Laravel interpreter prune tested `items` and not
`additionalProperties`, so a map's value schema travelled to the interpreter whole and every violation in
it was reported twice — `validation.string` plus `plainMap.k must be of type string`. Symmetric now.

**yii3 renders from a template.** `RendersYii3Dto` was the only mode assembling its class from strings —
59 `$lines[]` sites and five `implode()`s, no `.twig` at all — and that is where the container bug in this mode hid: four dead
`#[ToDateTime]` attributes on array properties, invisible in a diff that a template would have shown. The
markup moved to `dto.yii3.php.twig`; the renderer keeps only the decisions, the same border the other four
draw. Proven neutral block by block: all 40 classes of the yii3 snapshot are byte-identical.
`enum.symfony.php.twig` is now `enum.standalone.php.twig` and `$isSymfony` is
`$rendersStandaloneEnum` — there is one axis, "does the enum carry this package's runtime interface", and
the old names were false for laravel, laravel-data and yii3 alike. A test now holds that axis: the four
non-runtime modes must emit the same enum, byte for byte.

**Also fixed this release.** `DtoNormalizer::extractArrayItemTypeNames()` parses nesting instead of
matching a regex, which had reported the INNER type of a list of maps for any member type. The temporal
mapper tolerates a hand-built DTO holding a string where a date belongs, instead of a `TypeError` from
inside the getter that took `validate()` — the call whose job is to report it — down with it. Laravel
hydrates an array of a discriminated union through the discriminator (it called `fromValidated()` on the
base interface and died with `Call to undefined method`) and a MAP of DTOs at all (a list-only regex fed
nine places); laravel-data gained `#[DataCollectionOf]` on a map from the same fix. An empty nested object
writes `{}` rather than `[]` in every position, in runtime, laravel and yii3. A temporal `default`
emits `new DateTimeImmutable('…')` parsed at generation time, not the unusable
`DateTimeImmutable::VALUE_2020_01_01`. The interpreter shared by symfony and yii3 refuses an overflowing
`date-time` (`2026-13-45T99:99:99+00:00` used to pass `createFromFormat()` silently). The yii3 `ext-intl`
skip is now proof-based — a trial hydration rather than a regex over the spec — which takes the parity
suites from 5 and 9 skips down to 1 and 5, so the yii3 half of 2.15.3 is verified locally and not only on
CI.

**Distribution.** A `.gitattributes` marks `tests/`, `.github/`, `OpenApiExamples/` and the tooling
config `export-ignore`. The Packagist archive goes from 4.3 MB to 1.2 MB (275 KB gzipped); `.claude/` also
stops travelling to consumers. Tags 2.10.0…2.15.3 keep what they shipped — history is not rewritten.

## 2.15.3 — 2026-08-24

- an array of `format: date` items is written back as dates
- the item getter formats, an `AsDateTime` twin keeps the objects
- symfony and yii3 write the same shape
- pinned as normalization parity, across every mode

`items: {type: string, format: date}` types the item as `DateTimeImmutable` — exactly what the scalar
case does — and there the treatment stopped. The scalar has always been READ as the string the schema
asks for (`getSingle(): string` formatting `Y-m-d`); the array handed its objects straight back, and
whatever normalized them printed RFC 3339. So a document declaring an array of dates produced
`["2026-03-10T00:00:00+00:00"]` on the wire: a response contradicting the schema it was generated from,
silently, since nothing validates an item's `format` on the way out.

Runtime and symfony modes now format the items the same way the scalar is formatted. `getDates()` returns
`array<string>`, `getDatesAsDateTime()` returns the `array<DateTimeImmutable>` — the same pairing the
scalar getter has, with the same `#[Ignore]` on the symfony twin so the serializer does not emit both.
`toArray()`/`jsonSerialize()` read the formatting getter, and the `arrayItemType` in the normalization map
says `array<string>` because that is what the getter now hands over. A MAP of temporal values
(`additionalProperties: {format: date}`) is covered by the same path and still encodes as a JSON object;
nullable items and a nullable array keep their nulls.

yii3 already walked arrays in `openApiWireValue()` passing the temporal format down — its
`OPENAPI_TEMPORAL_FORMATS` map simply never listed an array property, because the format sits one level
down in `items`. It is read from there now.

laravel and laravel-data are unchanged: neither converts temporal ITEMS on the way in, so their arrays
were already strings on the way out.

Two cases in `NormalizationParityTest` pin it for all five modes at once — a date array and a date-time
array — so no mode can drift back. Regenerate to pick this up.

## 2.15.2 — 2026-08-21

- yii3 resolves a schema named like a framework class
- one predicate behind the spelling and the import list
- DateTimeImmutable stays reserved, and why
- pin it by hydrating, not by reading the source

A document may call a schema `Result`, `Data`, `Query` or `Nested` — ordinary words for an API — and every
emitted yii3 input carried imports with exactly those short names. PHP then failed two ways, neither
visible from the document: the file DECLARING it did not load at all (`Cannot redeclare X\Result`), and in
a SIBLING file the `use` silently won over the same-namespace class, so a property was typed the
framework's and the payload filling it a `TypeError`. Until now yii3 only warned about this; runtime and
laravel-data have resolved it for a release.

Every framework short name the yii3 renderer writes now goes through `yii3Lib()`, which answers with the
short name or with the fully qualified one, and `yii3SortedImports()` drops the import that would have
collided. Both ask `namespaceDeclaresClass()`, so the body and the import list cannot disagree about which
names belong to the document. Eighteen names are covered, including every `Yiisoft\Validator\Rule\*`
through the one place that emits a rule attribute.

`DateTimeImmutable` is NOT covered and stays in the reserved list. The type a `format: date-time` resolves
to comes from the mode-neutral type mapper, which does not know the namespace, so a schema of that name
silently TYPES the property as its own class — a file that loads and then fails at hydration, which is
worse than one that does not load. Runtime mode has the same gap for the same reason, and both are warned
about at generation time.

Pinned by hydrating rather than by reading the emitted source: both failures are invisible to a source
assertion — one is a parse error, the other a type that reads perfectly fine. Reverting the import filter
makes the new case fail with the original `Cannot redeclare`, which is how it was checked.

Generated output is unchanged for any document that does not take one of those names — all five golden
snapshots are byte-identical.

## 2.15.1 — 2026-08-21

- laravel says why an undiscriminated union cannot hydrate
- declare toArray() on the union interface
- pin what the request sees, not only the warning

`UnhydratableUnionParityTest` has pinned since it was written that a `oneOf`/`anyOf` over objects with no
`discriminator` cannot be hydrated in any mode, and that the generator says so at build time. What it did
not pin is what the REQUEST sees, and laravel mode — the one that writes its own hydrator — wrote
`Shape::fromValidated($data['shape'])` against an interface declaring no such method. A payload the
document allows died on `Error: Call to undefined method UnionWarnLv\Shape::fromValidated()`: a PHP-level
accident naming an internal artefact, for a document-level limitation already reported at generation.

It now throws the sentence the warning carries — *Property "shape" is typed as Shape, a union with no
discriminator: the document does not say which member a payload is, so it cannot be hydrated. Add a
discriminator to Shape, or type the property as one member.* — and a new case in that suite pins it,
verified to fail with the old `Error` when the emitter is reverted.

The generated union interface also declares `toArray(): array` in laravel mode, where an owning DTO's
`toArray()` calls `$this->prop?->toArray()` on it. Every member is a generated DTO and has the method, so
this only writes down what was already true; PHPStan level 8 over the laravel corpus reported the call as
undefined until now. Only laravel mode: nothing calls it through the interface in the other four, and
their union members do not all carry `toArray()`.

Regenerate to pick either up. Runtime, symfony, laravel-data and yii3 output is byte-identical.

## 2.15.0 — 2026-08-21

- a generated fast hydrator for a plain-body schema
- inline the source-resolution decision tree
- one short-circuit chain, not five varargs calls
- hoist the type tests out of the element loops
- decide composition once per element list
- import every global name the emitted code uses
- runtime enforces `additionalProperties: false`
- `unevaluatedProperties: false` closes an object the same way
- emitted only for a schema that says so
- name the Content-Type when the body was not read
- runtime resolves a schema named like a class it imports
- yii3 reports the clash it cannot resolve
- named arguments in the generated constructor call
- state the type the throw already guaranteed
- split the README into comparison / cli / validation
- add README.comparison.md — five tools, run, not read
- maxbeckers runs after all, and invents required values
- re-measure every published figure, mean of two runs

### Faster, with the same verdicts

Three before/after pairs were run against the 2.14.0 tag on the same corpus — two on the host, one in
the project container. The runtime path came out faster in every one, and never slower:

| runtime step | 2.14.0 → 2.15.0 |
|---|---|
| `bind` | 26–39% faster |
| `validate` | 14–21% faster |
| round trip | 19–30% faster |

A range rather than a figure, deliberately: two runs of the SAME build differ by up to 16% on this
benchmark, so any single number inside that band would be a coincidence quoted as a result. Current
absolutes for all five modes are in [README.performance.md](README.performance.md). The other four modes
move much less, and where they move it is their own generated code getting the same treatment — symfony
`normalize` and yii3 `normalize` are the two that show it.

Nothing about what is accepted or refused moved, and that is asserted rather than asserted-to:
`testTheFastHydratorAndTheGeneralLoopAgree` drives thirteen payloads through BOTH routes and compares the
resulting array, every `isXInRequest()` flag and the error text, so the two cannot answer differently.
`testAFormEncodedRequestDoesNotTakeTheFastHydrator` pins which route a request takes, and
`testPlainBodyDtoKeepsTheDeclaredSourcePrecedence` pins the order of the five source checks the inline
copy makes — router attribute, body, query, uploaded file, form.

The generated constructor call now passes NAMED arguments. This is not cosmetic: the plan is built from
the constructor's own parameter list precisely because reading it off the property list once passed a
slug where a status was expected, and a named argument cannot make that mistake at all. Each required
local also carries the type the throw above already guaranteed — the `$cast` closure returns `mixed`, so
without it an analyser reads `$id` as `int|null` against a non-nullable `int` parameter and reports a
`null` no execution can reach.

Four changes carry it. A DTO whose every property is a plain body field — no bound parameter source, no
serialization style, no delimiter, no default, no `readOnly`, no inheritance, no discriminator — now
carries a generated `hydrateFast()`, and the deserializer hands it a closure that owns every decision
ABOUT a value. That division is the whole design: the generated method decides only which keys are
present and in which order the constructor takes them, so it cannot disagree with the general loop about
a cast, a field constraint or the wording of an error. The first attempt had the generated code
re-implement those semantics and broke nineteen tests.

The resolver's decision tree is inlined for that same class of DTO rather than called. `DtoValidator`
lost a varargs helper that was called five times per value in favour of short-circuiting
`array_key_exists` chains, tests the value's type once instead of once per keyword group, and computes
`hasComposition` once per element list instead of once per element. And every global name the emitted
code uses — classes AND functions — is now imported rather than called unqualified: an unqualified call
inside a namespace makes PHP look for a namespaced twin that never exists, which measured ~29 ns a call
and, isolated on the generated hydrator, about 10%.

### A closed object is closed in runtime mode too

Runtime mode is the one mode still holding the RAW body when generated code runs, so it is the one mode
that can see a key the schema never declared — a hydrating mode drops it into no property and never
learns it was there. Generated DTOs of a closed schema now carry `getObjectConstraints()` and the
deserializer refuses the undeclared key; `unevaluatedProperties: false` closes an object the same way.
The method is emitted ONLY for a schema that closes itself, so three modes now agree where two did, and
output for a document that closes nothing is unchanged by this.

### The body that was never read

A request with a JSON body but a `Content-Type` of `text/plain` — or none — was read as an empty payload,
so the client got `param "id" not found in request` for a field it had plainly sent. Only
`application/json` is decoded as a JSON body, deliberately: form and multipart values arrive through
their own sources. The verdict is unchanged, but when the body is the only thing that could have carried
the missing values and it was not read, the exception now says so:

```
Required parameter "id" not found in request.
The request body was not read: Content-Type is "text/plain", and only application/json is decoded
as a JSON body (form and multipart values are read from their own sources).
```

### A schema named like a class the emitted code imports

A document may call a schema `Stringable`, `RuntimeException` or `Result`, and the emitted file carries
imports with exactly those short names. PHP then fails two ways, neither visible from the document: the
file DECLARING it does not load at all (`Cannot redeclare X\Stringable`), and in any SIBLING file the
`use` silently wins over the same-namespace class, so a property is typed the library's class and the
payload that should fill it is a `TypeError`.

laravel-data has resolved this since it was written. That mechanism is now a shared trait,
`NamesLibraryClasses`, and runtime mode uses it: such a schema gets `\Stringable` written out and no
import. `UnsetValue` stays reserved, and not because of the emitter — `DtoDeserializer` recognises the
sentinel by SHORT name on purpose, so that a sentinel copied into your own namespace by
`--dto-generator-namespace` is still recognised, and a schema of that name falls under the same test.
yii3 names roughly forty library short names across its renderer and does not resolve them yet; it is
now in the reserved-name table, so generation reports the clash instead of emitting a file that cannot
load.

### The comparison is in the repository now

The front page makes measured claims about other generators, so the method that produced them belongs
beside it: [README.comparison.md](README.comparison.md) names the versions, the payloads and the
verdicts, and carries a *where we are behind* section — no HTTP client generation, no TypeScript, and
several generation modes is not a distinction (OpenAPI Generator ships nine PHP generators).

One earlier finding was wrong and is corrected there. `maxbeckers/php-openapi-generator` was recorded as
unable to start; it starts fine once `symfony/console` is pinned to `^7`, which composer resolves without
complaint. Having run it: its `fromArray()` reads `$data['id'] ?? 0`, `$data['name'] ?? ''`, so an empty
payload produces a well-formed object with `id = 0` and `name = ''`. That is worse than the missing
validation it shares with the others — nothing downstream can tell invented values from sent ones.

### Upgrading

`composer update`, then **regenerate**: emitted output changed in all five modes. Runtime-mode DTOs gain
`hydrateFast()` and, for a closed schema, `getObjectConstraints()`; every mode's files gain the import
group. No contract, constructor signature or public method changed.

DTOs generated by 2.14.0 keep working against 2.15.0 services unchanged — both new methods are optional
and the services fall back when they are absent. Measured, not assumed: the 2.14.0 corpus was generated
from that tag and driven through 2.15.0's deserializer, validator and normalizer, with the same accept /
refuse verdicts and the same normalized output. You lose the speed-up until you regenerate, nothing else.

## 2.14.0 — 2026-08-19

- add yii3 generation mode
- one `AbstractInput` per schema
- presence from uninitialised typed properties
- no sentinel, no helper class of ours
- `DataSetInterface` + `RulesProviderInterface` together
- `getRules()` or nothing is validated
- yiisoft rules for what they express
- class-level `#[Callback]` for the rest
- keep scalar keywords for the interpreter
- prune what a rule already covers
- `skipOnEmpty: WhenNull` on every rule
- one message for an absent required property
- one message for an explicit null
- `#[Data]` binds an aliased wire name
- `#[Collection]` hydrates lists of DTOs
- `#[ToDateTime]` hydrates temporal properties
- `#[UploadedFiles]` with PSR-7, not Symfony
- `#[FromBody]` only on a request payload
- query and path bind to their own attributes
- `nullable` is the document's, not the type's
- free-form property as a written-out union
- `readOnly` and `writeOnly` out of `getData()`
- interpreter honours an explicit `nullable`
- yii3 keeps sub-second precision, like runtime
- fifth column in every parity suite
- fifth golden corpus snapshot
- fifth column in the benchmark
- add README.yii3.md
- yii3 rows in the support matrix
- fix an exclusive bound in laravel mode
- emit only the numeric checks a schema uses
- drop two constants nothing read
- constants before properties in yii3 output
- no deprecation on a schema without a type
- stack the wire formats a temporal hydrates from
- write a temporal back in its schema's shape
- leave `format: time` to the interpreter

`--attributes=yii3` emits one `yiisoft/input-http` input per schema: `yiisoft/hydrator` fills it from the
request and `yiisoft/validator` reads the emitted attributes. It is the only mode that does NOT turn a
failed validation into a 422 — Yii3 hands the action a `Result` and the action decides, which is how the
framework works and is left alone on purpose. Presence needs no invention either: the class has no
constructor, so an unsent key leaves a typed property uninitialised and `hasProperty()` answers from
`ReflectionProperty::isInitialized()`. The interpreter behind the class-level `#[Callback]` is the same
code Symfony and Laravel mode run, so the messages are identical:
[README.yii3.md](README.yii3.md).

Three changes reach the FOUR existing modes. `minimum: 3` next to `exclusiveMinimum: true` — the
OpenAPI 3.0 spelling of an exclusive bound — was translated to Laravel's inclusive `min:3`, and that
also took the keyword away from the interpreter, so laravel mode ACCEPTED the boundary value every
other mode refused; laravel-data inherited it. The emitted interpreter now steps aside for a `null`
the document explicitly allows instead of adding a second message about it, and it emits only the
numeric checks a schema actually uses rather than all five whenever one is present. runtime-mode
output is byte-identical.

Three temporal bugs in yii3 mode were found by CI, which has **ext-intl** where the development machine
did not, so the cases that catch them were skipped locally. `#[ToDateTime]` takes ONE format and was
emitted with one pattern: `2026-03-10T12:00:00.123456+03:00` parsed as none of it, the hydrator skipped
the property, and the request came back as `field "at" is required` for a value the client had sent.
Several are now STACKED — measured, the hydrator tries each in turn — covering exactly
`GeneratedDtoInterface::DATE_TIME_FORMATS`. `getData()` also wrote a full timestamp for a `format: date`,
where every other mode writes `2026-03-10`. And `format: time` carried a `#[Time]` rule that rejects any
plain string, including the legal `13:45:00Z`; that format goes to the interpreter instead.

## 2.13.0 — 2026-08-18

- carry the items schema into per-element casts
- `format: date` and nullable elements now deserialize
- fix `deserializeCollection()` the same way
- put the two flags on the contract
- return type follows the nullable flag
- pin object-vs-array under assoc input
- say what "in request" means without a request

**`format: date` elements and nullable elements were undeserializable.** `castArrayItemValue()` takes
`itemsNullable` and `arrayItemTemporalFormat`; neither `deserializeValue()` nor `deserializeCollection()`
passed them, so a `type: string, format: date` collection was rejected element by element
(`expects a valid date-time …, got "2026-03-10"`) and a nullable element was rejected as a type error.
A bare type name has no owning DTO property, so the two facts cannot be inferred at these call sites —
they are now parameters:

```php
$deserializer->deserializeValue('2026-03-10', DateTimeImmutable::class, '3', false, 'Y-m-d');
$deserializer->deserializeValue(null, 'int', '3', true);
$deserializer->deserializeCollection($request, DateTimeImmutable::class, false, 'Y-m-d');
$deserializer->deserializeCollection($request, 'int', true);
```

**The declared return follows the nullable flag, so static analysis sees the null.** With
`$nullable`/`$itemsNullable` on, these methods really can hand back `null` (or a list containing
`null`). The types now say it, and this stops passing PHPStan:

```php
$dt = $deserializer->deserializeValue(null, DateTimeImmutable::class, '3', true);
$dt->format('c');   // DateTimeImmutable|null — reported, was silently accepted
```

Leave the flag off and the type stays non-null, resolving to the exact class as before.

Both flags default to the strict behaviour, so existing calls are unchanged. `'Y-m-d'` narrows the cast
rather than disabling it — an invalid date still fails, naming the narrowed format. Not a 2.12.0
regression: `deserializeCollection()` had the same two holes since it was written, which is why both
entry points are fixed together. `deserializeCollectionPsr7()` forwards the two arguments.

**Breaking for implementors of `DtoDeserializerInterface`.** The new parameters are on the CONTRACT,
not on the service alone, so a class of your own still declaring the 2.12.0 arity fatals at autoload:
*"Declaration … must be compatible with …"*. Copy the signatures from the interface. Consumers, type
hints and DI are unaffected, and no existing CALL site changes — every added parameter is optional.

Putting them on the service alone was built first and abandoned, because it cannot be made honest.
A conditional return needs the flag in scope, and an implementation may not widen a return type its
interface narrows: with the flag only on the service, either the service lies about null
(`method.childReturnType` if it does not) or the contract admits a null that its own signature makes
unreachable, taxing every interface-typed call site with checks it can never need. Both were measured;
the contract change is the only shape where each declaration says exactly what its caller can get.
`tests/Runtime/GeneratedConstraintsIntegrationTest` pins interface and service to the same signatures
so they cannot drift apart again.

Generated DTOs are unchanged — output is byte-identical to 2.12.0 in all four modes.

Two behaviours are now pinned rather than changed. A missing required field on the Request-free path
still reports `Required parameter "3.id" not found in request.`; the text comes from the shared
deserialization core, where it is accurate and where changing it would change the Request paths too, so
it stays and the docblocks say to read "in request" as "in the payload". And `deserializeValue()` still
accepts a plain assoc array beside the promised `json_decode($json, false)` output: under assoc input
the object-vs-array check errs toward refusing, so nothing array-shaped is silently accepted — the cost
is one exotic document, an object with sequential integer keys (`{"0":1,"1":2}`), which survives as an
object only in the stdClass decoding. A test now names that asymmetry.

## 2.12.0 — 2026-08-17

- add `deserializeValue()`
- deserialize one decoded JSON value
- per-element errors for batch endpoints
- new method on `DtoDeserializerInterface`

`deserializeCollection()` is all-or-nothing: it aggregates every element's error into one exception, so
a single malformed element fails the whole body. `deserializeValue($data, $type, $path)` exposes the
per-element cast on its own — no `Request`, one already-decoded JSON value in, one DTO (or scalar, enum,
date) out, discriminator resolution included. A batch endpoint loops over the decoded body itself,
catches each `RuntimeException` and answers "element 3 was rejected, the rest were accepted". `$path`
names the value in the error message, so pass the element's position; it defaults to `value`. Example:
[README.runtime.md](README.runtime.md#batch-endpoints-accepting-the-good-elements-reporting-the-bad-ones).

**Breaking for implementors of `DtoDeserializerInterface`.** The method was added to the interface, not
only to `DtoDeserializer`, so a class of your own implementing that interface must add it. Consumers of
the interface (type hints, DI) are unaffected, and generated DTOs are unchanged — output is byte-identical
to 2.11.0 in all four modes.

## 2.11.0 — 2026-08-13

- add laravel-data generation mode
- one Data class per schema
- Optional for presence, no emitted flags
- null and Optional as independent types
- reuse the laravel rule translator and interpreter
- no MergeValidationRules: rules() is the truth
- morph base for discriminated unions
- #[Hidden] for writeOnly
- schema formats on the date cast
- suppress inferred nested rules
- one message per mistake in laravel-data mode
- 39% off the laravel-data validate step
- mode list is data, not three columns
- fourth column in every parity suite
- fourth golden corpus snapshot
- guard every emitted attribute resolves
- laravel-data container without testbench
- add README.laravel-data.md
- fourth column in the benchmark
- list every mode in the --attributes error
- warn on an undiscriminated object union
- accept null beside a union
- fix `mixed` inside a union type
- map a discriminator's wire name
- enforce a self-recursive root schema
- drop an unused generated import
- survive a schema named like an import
- warn on a schema named like a used class
- pin what a schema `default` normalizes to

`--attributes=laravel-data` emits ONE `spatie/laravel-data` class per schema instead of the FormRequest +
DTO pair `--attributes=laravel` emits. It is the only mode whose generated code needs a third-party
package, which is why it is opt-in and why first-party Laravel stays the default. What it buys is presence
as a language-level fact: an optional property is `string|Optional`, an unprovided one IS an `Optional`,
and laravel-data omits it from `toArray()` — no flag array, no `fromValidated()` factory, no hydration code
of ours. `null` and `Optional` are separate union members, so `nullable` follows the document alone. The
rule translator and the interpreter are the SAME code laravel mode uses, so the messages are identical:
[README.laravel-data.md](README.laravel-data.md).

Discriminated unions are the one place the emitted class SHAPE differs between modes. laravel-data cannot
hydrate an interface, so the base is an abstract `Data` implementing `PropertyMorphableData`, its `morph()`
maps the discriminator value to a member, and members `extends` it and forward the discriminator. An
unmapped value comes back as a 422 rather than an exception to translate.

Two decisions are measurements, not preferences. The class carries no `#[MergeValidationRules]`: with it,
laravel-data's inferred rules are merged into ours, duplicating them and prepending a `nullable` the schema
never asked for. And a nested-`Data` property carries `#[WithoutValidation]`: overriding `rules()` does not
stop laravel-data injecting its own nested rule resolution, which reported one missing nested key twice —
once as `validation.present`, once as the interpreter's `tags[0].id is required`. Removing it also took 39%
off the validate step and 45% off `from($request)`.

A property whose schema is a union of OBJECTS with no `discriminator` is now named at GENERATION time.
Nothing can hydrate one — the document does not say which member a payload is — and every mode used to
find that out at request time, late and differently: `Unsupported type: Shape` in runtime mode, a
`NotNormalizableValueException` in symfony, and in the two Laravel modes a `Call to undefined method
Shape::fromValidated()` and a `TypeError`, both of which read as bugs in the generated code rather than as
a gap in the document. Generation still succeeds — the interface is a useful type, and a union referenced
only in a response is never hydrated — and the warning names the property and the remedy. The demo corpus
has one such property, which is how ordinary the shape turns out to be.

`nullable: true` NEXT TO a `oneOf`/`anyOf` now means what it says. It is the same statement as spelling a
`{type: null}` variant INSIDE the union, but only the spelled form reached the emitted interpreter — so a
document written the first way had its own `null` refused with "does not match any oneOf branch (expected
integer or string, got null)" in symfony, laravel and laravel-data mode. Runtime mode reads the schema
directly and always accepted it, so three modes were wrong about a value the document explicitly allows.
Both spellings are now held to one verdict by `ValidationParityTest`.

A property with NO type in its schema — an empty schema, or one carrying only a description or an
extension keyword — no longer breaks RUNTIME mode. It resolves to `mixed`, `mixed` cannot take part in a
union or be marked nullable, and the emitted class said `mixed|UnsetValue|null` and `?mixed`: two
compile-time fatals, so the file could not be loaded at all. `mixed` already admits the sentinel and null,
so it now stands alone and presence tracking is unaffected. laravel-data mode has the same constraint from
the other side — it is the one property shape with no `|Optional` to mark absence with, so an unprovided
one is echoed as `null`, a divergence now declared in the parity suite.

A `discriminator` whose `propertyName` is not already a PHP identifier — `pet_type`, the name OpenAPI's own
Pet example uses — works in laravel-data mode now. The morph base reads the discriminator BEFORE there is
an object to read it from, and `DataMorphClassResolver` looks the value up by the property name and by its
INPUT-MAPPED name; the base carried `#[PropertyForMorph]` but no `#[MapName]`, so neither name matched the
payload and a document the other three modes hydrate came back as `validation.required` on a key nobody
sent. `NormalizationParityTest` now runs the union under both spellings, and `MorphDiscriminatorTest`
every case twice.

A schema that refers to ITSELF from the root class is enforced at every depth in the two Laravel modes.
Both halves of the pair had a hole exactly where they met: the flat rules cannot expand a cycle, so no
`children.*` path was emitted, while the interpreter treated the first `$ref` back to the root as a fresh
class — expanded one level and PRUNED of everything the rules were assumed to cover. Measured: a child
violating `minimum: 1` was ACCEPTED, and a child sending a string for an integer surfaced as a `TypeError`
from the constructor rather than a 422. The cycle guard is now seeded with the owner class, so the root
folds like any other recursive class. The recursion suite only ever reached such a schema through a
non-recursive root, which is why it saw none of this; it now covers both entries.

A document may call a schema `Data`, `Optional`, `Request`, `Validator` or `Container`, and laravel-data
mode imports classes with all five of those short names. PHP then resolved the clash two ways, neither of
them the document's: the file DECLARING that class did not load at all (`Cannot redeclare X\Data,
previously declared as local import`), and in a SIBLING file the `use` quietly won over the
same-namespace class, so a property typed `Request` was Illuminate's and the payload meant for it a
`TypeError`. Every class this mode needs is now spelled fully qualified when the document has taken its
name, and imported only when it has not — `EmissionEdgeCasesTest` drives all five through the package.

An eleventh divergence was there all along and nothing measured it: an unprovided optional property is
left out of `toArray()` by runtime, laravel and laravel-data, and comes back as `null` from Symfony —
as the schema's `default` where the document declared one, because the constructor default IS that
property's value there. `isXxxProvided()` still answers the question on the object. Pinned by
`NormalizationParityTest` and listed in the support matrix, which had claimed "everything else about the
response shape is identical in all four".

The same clash in the OTHER modes is now named at generation time instead of being discovered at
request time. Only laravel-data can resolve it from the import list; a generator that carries a PHP type
as a SHORT name cannot tell `format: binary` from a schema the document called `UploadedFile`, and no
import list fixes that. So each mode declares the names its emitted code already uses — `UnsetValue`,
`GeneratedDtoInterface`, `JsonException`, `Stringable` in runtime; `Assert`, `Ignore`, `SerializedName`,
`ExecutionContextInterface` in symfony; `Validator`, `Rule`, `FormRequest`, `stdClass` in laravel;
`DateTimeImmutable` and `UploadedFile` in all four — and a schema of that name gets a warning naming the
clash and the remedy. Generation still succeeds, as with the undiscriminated-union warning above.

The mode list stopped being three hardcoded columns. `tests/GenerationMode` is data, the parity suites
iterate it, and a `match` over modes has no `default` arm anywhere — so a mode is either measured in every
case or it fails loudly. Adding the fourth column surfaced one thing the old shape had hidden:
`additionalProperties: false` on a DTO-shaped schema is enforced by the two rule-based modes and invisible
to the two that hydrate first.

### Upgrading from 2.10.0

For the new mode, `composer require spatie/laravel-data` — the generated classes need `^4.0`.

One change reaches LARAVEL mode as well, because both modes read the same predicate for "is this property
nullable per the document". `$property['nullable']` conflates schema-nullable with optional (the walker sets
it for every optional property so the other modes have somewhere to put "absent"), and the keyword check
that replaced it cannot see a nullable `$ref` — its constraint map carries no `type`. So for a REQUIRED
nullable property written as `$ref` or `oneOf: [$ref, {type: null}]`, laravel mode now emits `nullable`
where it emitted nothing:

    'child' => ['present']              // 2.10.0
    'child' => ['present', 'nullable']  // 2.11.0

`nullable` only tells the validator to skip the remaining rules when the value IS null, which the schema
says is allowed — so this widens nothing that was refused before. The demo corpus contains no property of
that shape, which is why every golden snapshot but the new mode's is unchanged byte for byte. Regenerate
anyway if your document has one.

The recursion fix reaches LARAVEL mode the same way, and it NARROWS what is accepted: a document whose
ROOT schema refers to itself gets an interpreter fold it did not have, so payloads that used to slip
through below the first level are now 422s. That is the point — they violate the schema — but a client
relying on the old leniency will notice. The demo corpus has no self-recursive root either, so the laravel
snapshot is unchanged byte for byte here too.

## 2.10.0 — 2026-08-06

- add laravel generation mode
- emit FormRequest per request payload
- rules() for illuminate/validation
- enforce composition via withValidator()
- presence from validated() keys
- third column in parity suites
- third golden corpus snapshot
- add README.laravel.md
- add README.support-matrix.md and README.performance.md
- add bin/benchmark
- accept `42.0` for `type: integer`
- name accepted types on a union mismatch
- pin message wording across modes
- name the path in nested deserialization errors
- rename the parity suites for three modes
- state the Laravel 11 floor
- refuse a JSON array for `type: object`
- keep the payload of an object that only constrains its keys
- absolute `format: uri` / `iri`, real `uri-template` grammar
- enforce a recursive schema at every depth in laravel mode
- optional is not nullable in laravel mode
- one message per mistake in laravel mode
- FormRequest for a `$ref`-ed request body

`--attributes=laravel` emits a plain DTO carrying `rules()` plus a `FormRequest` that Laravel resolves
and validates before the controller body runs. Nothing to install: `FormRequest` and
`illuminate/validation` ship with the framework, and the generated code has no runtime dependency on
this package. What Laravel's rule vocabulary cannot express — composition, conditionals, `contains`,
`unevaluated*`, `content*`, `propertyNames`, `discriminator` — is enforced by the same interpreter
Symfony mode uses, entered from `withValidator()`; a schema that needs nothing beyond rules gets no
interpreter at all. Details and the full rule mapping: [README.laravel.md](README.laravel.md).

Two conformance fixes shared by all modes. `type: integer` now matches a number with a zero fractional
part, as JSON Schema 2020-12 §6.1.1 says it must: `42.0` is accepted (and hydrated into an `int`),
`42.5` still rejected. And a union that gates every branch out by type no longer answers with a bare
"does not match any oneOf branch" — it names what it would take: `(expected integer or string, got
boolean)`. `tests/Parity/InterpreterMessageParityTest` now pins interpreter-owned messages to the same
sentence in all three modes.

Runtime mode also names the path of a nested failure. A nested DTO is deserialized by its own pass, so
its errors carried the bare key: a payload missing `discriminator.id` reported `Required parameter "id"
not found in request.`, which reads as the ROOT object missing an `id` it may not even declare. Messages
now come out as `Required parameter "discriminator.id" not found in request.` and
`param "tags.0.name" expects string, got int`, at any depth.

A JSON array is no longer accepted for a `type: object` property. It used to be read as a map keyed
`0..n-1` — in every mode. The distinction exists only in the raw body (`{"0":1,"1":2}` and `[1,2]` decode
to the same PHP list), so the check sits where the raw body is reachable: the runtime deserializer, and
`withValidator(Validator $validator, ?string $rawJson = null)` in Laravel mode, which the generated
FormRequest now feeds `$this->getContent()`. Symfony mode still accepts it — the serializer denormalizes
before any constraint runs — and that boundary is pinned by a test.

An object that constrains only its KEYS keeps its payload. `{type: object, dependentRequired: {…}}` with
no `properties` — and the same for a lone `required`, `propertyNames` or `unevaluatedProperties` — used to
be materialized into a DTO class with no properties, which accepted the whole payload and returned `[]`.
Those four keywords name keys without declaring a schema for any of them, so nothing a DTO could hold:
the schema now stays a map (`array<string, mixed>`) and the keyword is enforced over it. **All three
modes.**

Two format checks were wrong in the emitted interpreter, so Symfony and Laravel mode accepted values the
runtime validator had always refused. `format: uri` and `format: iri` are ABSOLUTE — only the
`*-reference` forms take a relative value — and were both mapped to the reference check, accepting
`/rel/path`. And `uri-template` compared `preg_match(...) !== false`, which is true for zero matches as
well as one, so the brace check asserted nothing and `/a{unclosed` passed. Both now match runtime.

Laravel mode got three correctness fixes of its own. A recursive schema is enforced at every depth: the
fold is emitted once into `OPENAPI_RECURSIVE_SCHEMAS` and re-entered through a marker, where before the
walk stopped at the first turn of the cycle and a child violating `minimum` was accepted. An OPTIONAL
property is no longer treated as nullable — `sometimes` covers the absent key, and a key carrying `null`
that the schema never marked `nullable` is refused, as runtime mode does. And one mistake now produces one
message: the interpreter schema is pruned down to what the rules did not already take.

A request body written as `$ref: '#/components/schemas/X'` now gets a FormRequest. Only INLINE bodies were
recorded before, so the most idiomatic way to write a spec produced the one shape the mode exists for and
then withheld it.

### Upgrading from 2.9.0

**Regenerate.** Unlike earlier releases this one can change emitted output in ANY mode, not just the new
one, and two of the changes are visible in your own code:

- **types change** where a schema constrains only its keys: a property that was a generated nested class
  (`?FooBag`) becomes `?array`, and the class is no longer emitted. If you type-hinted it or called
  methods on it, that code needs updating — it was returning an empty object before;
- **payloads that used to pass may now be rejected**: a relative value for `format: uri`/`iri`, a
  malformed `uri-template`, and in Laravel mode `null` for an optional non-nullable property or a
  violation nested inside a recursive schema. All four were conformance gaps, so a payload that starts
  failing was always invalid against the document;
- **error messages changed** in three places (the union mismatch, nested deserialization paths, and one
  message per mistake in Laravel mode). Only code that STRING-MATCHES a message is affected;
- `42.0` is now accepted for `type: integer` in runtime and Laravel mode. Symfony mode still rejects it —
  the serializer decides before any constraint runs.

In Laravel mode the generated constructor no longer takes a `$providedOpenApiKeys` parameter: it takes the
schema's properties and nothing else, and `fromValidated()` — the only hydrator — records presence itself.
Hand-built instances keep working and report nothing as provided.

What each mode enforces, and the six places they differ, is now written down and generated from the test
suites: [README.support-matrix.md](README.support-matrix.md). Timings, with the benchmark to re-run them:
[README.performance.md](README.performance.md).

## 2.9.0 — 2026-08-05

- support matrix, label
- support allowReserved, allowEmptyValue
- support in: querystring
- support additionalOperations, QUERY method
- generate webhooks, callbacks
- dereference component requestBodies, responses
- fold Encoding Object into body
- refuse Swagger 2.0 clearly
- symfony mode: presence tracking
- symfony mode: serialization groups
- symfony mode: temporal string getters
- symfony mode: discriminated union interfaces
- keep free-form object payloads
- named scalar schemas become types
- suffix reserved class names
- distinguish case-only sibling keys
- refuse contradictory enum members
- fix nullable map item crash
- fix list-of-maps deserialization
- validate unevaluatedProperties on DTO values
- surface defects inside DTO values
- lazy raw query parsing
- raw query matches parse_str
- support uint32, uint64 formats
- golden corpus snapshot tests
- runtime vs symfony parity suites
- split README per mode
- add upgrade notes

### Upgrading from 2.8.x

The library is a drop-in replacement: **DTOs generated by 2.8.x keep working unchanged against 2.9.0
services** — measured on 55 specs, same accept/reject verdicts and the same normalized output. The two
metadata methods added here are simply absent on older DTOs and the services fall back.

What changes is the code the generator EMITS, so read this before regenerating.

**Runtime mode**

- `matrix` / `label` path parameters now bind. 2.8.x rejected them outright (`param "ids" expects
  array, got string`). This one comes from the services, so it applies before you regenerate.
- A bare `type: object` property is `array<string, mixed>` instead of a synthesized class that
  dropped the payload. `$dto->getMeta()->toArray()` becomes
  `Call to a member function toArray() on array` — use `$dto->getMeta()`.
- A named scalar schema (`Uuid: {type: string, format: uuid}`) is a type alias: no class is generated
  and the property is `string` with the alias's own `format` / `minLength` / `enum` as constraints.
  Referencing the class gives `Class "…\Uuid" not found`. Such a property used to reject every
  request (`Cannot deserialize nested DTO … from non-array value`), so no working code depended on
  it.
- **Response bodies change** for those fields: they used to serialize as `[]` with the data dropped,
  now they carry the object — `{}` when empty, as `type: object` says.
- A schema named after a PHP keyword (`Parent`, `Self`, `Int`, `List`, `Match`, `Readonly`, `Static`)
  gets a `Schema` suffix. Those files could not be loaded at all before.
- Two keys differing only in case (`name` + `NAme`) get distinct accessors (`getNAme2()`), because
  PHP method names are case-insensitive. The wire names are unchanged.
- `type: integer, enum: [1, "two"]` is refused at generation, naming the schema and the value. 2.8.18
  refused it too, with a vaguer message.

**Symfony mode — needs code changes**

The shape changed so that `PATCH` can tell "field absent" from "field sent as null"
(see [README.symfony.md](README.symfony.md)):

- properties are private with accessors: `$dto->name` becomes `$dto->getName()`;
- a temporal property reads as the string the schema declares (`getAt(): string`), with the
  `DateTimeImmutable` available from `getAtAsDateTime()`;
- optional properties gain `isXxxProvided()`;
- the class is no longer `final readonly` — `readonly` cannot be written twice, which is what the
  optional-half setters need.

**Recommended order**

1. `composer update` alone, keeping the old generated code: behaviour is unchanged and
   `matrix`/`label` start working.
2. Regenerate into a scratch directory and diff it against the committed output.
3. Fix the call sites, then commit the regenerated code.

