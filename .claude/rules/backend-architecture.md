---
paths:
  - "backend/src/**"
  - "backend/tests/**"
  - "backend/migrations/**"
  - "backend/config/**"
---

# Backend — architecture and UUID v7 keys

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture (introduction) ». The index of every rule is at the top of `.claude/CLAUDE.md`.

### Backend architecture (`../backend/src`)

DDD structure: each bounded context is a top-level folder under `src/`, itself split into `Domain/`,
`Application/`, `Infrastructure/`, `Presentation/` layers — only the layers a context actually needs, no empty
ceremonial folders. `config/packages/doctrine.yaml`'s mapping scans all of `src/` (not a single `Entity/`
folder), so entities live inside their bounded context instead of a shared top-level directory.

**Every entity has a UUID v7 primary key, assigned in its constructor** (spec 0003, `v0.11.0`):
`#[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;` with no `GeneratedValue`,
`$this->id = Uuid::v7();` as the first assignment, right after the constructor's guards (three entities
validate an invariant first: `CpgUser`, `WatchedProduct`, `WatchSnapshot`), `getId(): Uuid` non-nullable.
Never a `setId()`, never an id supplied from outside on creation. The column is PostgreSQL's native `uuid`
type (`symfony/doctrine-bridge`'s `UuidType`, `symfony/uid` `8.1.*` a direct dependency), never
`VARCHAR(36)`. Every item operation declares `requirements: ['id' => Requirement::UUID]`
(`Symfony\Component\Routing\Requirement`), so a malformed `{id}` is a 404 from the router before the
firewall or any Provider runs; Providers/Processors read it via `ResolvesUriVariables::uriVariableUuid()`.
`tests/Security/ItemRouteRequirementTest.php` pins this invariant by walking the compiled router and
asserting every `/api` route whose path contains `{id}` declares it, with an explicit, justified allow-list
for API Platform's internal `{id}` paths that aren't entities.
`Requirement::UUID` is case-sensitive (lowercase hex), so an upper-case UUID in a URL is a router 404 too —
`toRfc4122()` always emits lowercase, don't widen the regex. DTOs expose `id` as an RFC 4122 string
(`$entity->getId()->toRfc4122()`), never the `Uuid` object — a read/write DTO's `id` stays
`?string $id = null` (absent on creation), but an output-only DTO's is non-nullable
(`BackofficeUserResource`). **`===`/`!==` between two `Uuid` compares objects, not values — always use
`->equals()`** (`CpgUserAdministrator`, `CpgUserRoleAdministrator`, `ExperienceTechnologyAdministrator` are
the three sites that need it). The migration to UUID is irreversible and monotone: PostgreSQL 18's
`uuidv7(interval)` assigns each existing row a v7 id offset by its rank in the old integer order, so
`ORDER BY id` keeps producing today's order — one migration per task of phase A (five files,
`backend/migrations/Version202609141{2..6}0000.php`), each `down()` throws rather than pretend the
original integers are recoverable. `ApiRouteExposureTest` substitutes `{id}` with a fixed valid UUID (not
`'1'`): with `requirements` in place, an integer placeholder would 404 at the router and the test would
silently stop covering every item route. Two traps hit while migrating: the API Platform metadata pool
survives `cache:clear` after a DTO's `id` type changes — `rm -rf var/cache/<env>` instead; and the usual
bind-mount desync (`docker compose restart backend`) can make a container run a stale Provider/Processor
mid-migration.

**`src/` never instantiates a bare `\Exception`, `\LogicException`, `\RuntimeException` or
`\InvalidArgumentException`** (issues #338, #383).
Every failure gets its own class: a client error implements `ProblemExceptionInterface` (see
`.claude/rules/backoffice-api.md`, "Exceptions"), and a server fault (wiring defect, broken invariant, build
step) extends the generic class it replaces, without a mapping, so it stays a `critical` 500. The point is a
name that can be targeted in `framework.exceptions` and recognised in the logs. Create it next to the layer
that throws it, with a named factory (`forOperation()`, `forGroup()`, `forDirectory()`…), and never put a
client-supplied value in its message without bounding it (`InputContradictsValidationException::nonTextualField()`).
`tests/Shared/NoBareGenericExceptionTest.php` enforces it. It counts tokens through
`tests/Support/BareExceptionInstantiations`, which shares its file walk with `DeclaredClasses` via `PhpSources`, so
an import, an alias, a comma list or an anonymous subclass is seen too. It has **no exemption list**, and
that is deliberate: a site that seems to need one gets a class, shared if several sites throw the same
failure. **A console question's validator follows the same rule** (#383): the `QuestionHelper` catches
any `\Exception` a validator throws, shows its message and asks again, so the domain exception is the
right one when the rule has one (`InvalidUsernameException`, `InvalidExperienceYearsException`), and
`Shared/Presentation/Command/InvalidConsoleAnswerException` covers the rest. Its message never quotes
the answer, which can be anything (a password pasted into the wrong prompt): `InvalidUsernameException::invalidFormat()`,
not `forUsername()`. Three traps of the same commands. In non-interactive mode, `ask()` returns the default
(`null`) **without calling the validator**, so the command refuses `null` explicitly and names the option,
instead of relying on an `assert()` that production compiles out. On end of input after a refused answer,
`ask()` **rethrows the validator's last exception**, so `ask()` sits inside the `try` that turns it into
`$io->error()` — otherwise it leaves the command and the console's `ErrorListener` logs it `critical`. And a
`CommandTester` is **interactive by default**, so a `-n` test passes `['interactive' => false]`. A hidden
question that reads a password is `setTrimmable(false)` (a question is trimmed by default, `--password` and
`json_login` are not), with a normalizer that strips the one line ending the read keeps when untrimmed. The other SPL classes (`\DomainException`…) are out of the guard's scope:
none is thrown bare in `src/`, and adding one to `BareExceptionInstantiations::FORBIDDEN` is the step to
take the day one is.

**Every class, interface, enum and trait is imported with `use` and written by its short name — native
ones included** (issue #391). `use LogicException;` then `new LogicException(…)`, `use Throwable;` then
`catch (Throwable $e)`, `use DateTimeImmutable;` then `DateTimeImmutable $at`, likewise `Stringable`,
`JsonSerializable`, `Closure`, `Generator`, `ReflectionClass`…: never `\LogicException`, never
`\App\…\Foo` inline, in code, attributes and phpdoc types alike (settled on 2026-10-10). A class only a comment refers to is cited as `{@see Foo}` with its `use`: Rector's
`removeUnusedImports` keeps an import referenced by `{@see}` but **deletes** one cited in plain prose
(verified on 2026-10-10), so a bare short name in a sentence loses its import on the next `rector:fix`.
**`{@see}` only holds an import inside a `/** … */` docblock** — in a `//` or `/* … */` comment Rector
deletes it all the same (verified on 2026-10-10). There, write the short name when the class is already
imported for the code, and keep the qualified name otherwise: it is the only form that still resolves
(`tests/Support/ReadsSecurityAuditLog.php`, `Monolog\Logger`). Code quoted in a comment
(`` `new DateTimeImmutable('-'.$x)` ``, `` `#[SensitiveParameter]` ``) takes the short name without `{@see}`:
it shows code, it does not link a class. **A reference that points outward takes no `use`**
(settled on 2026-10-10, review of #391): from `Domain` to `Application`, `Infrastructure` or `Presentation`,
from `Application` to `Infrastructure` or `Presentation`. An inner layer does not depend on an outer one,
not even through an import only a docblock needs, so the class keeps its fully qualified name in prose,
without `{@see}` (`App\Shared\Infrastructure\Http\RetryAfterListener` in `RetryAfterAware`), or the name
relative to its context that the repository interfaces already write (`Infrastructure\Doctrine\CpgUserRepository`). A quoted configuration key keeps its fully qualified name, the only
one the config knows (`Symfony\Component\Serializer\Exception\ExceptionInterface: 400`, `MalformedRequestBodyTest`). A
name clash gets an alias (`use UnexpectedValueException as NativeUnexpectedValueException;` in
`MalformedRequestBodyException`, which already imports the Serializer's), never a qualified name.
**`use` statements are sorted alphabetically**, case-insensitive, `\` as a segment separator — the order
of PhpStorm's "Optimize imports" and php-cs-fixer's `ordered_imports` (`alpha`). No tool enforces it, and
`rector:fix` inserts a new import at the top of the block: re-sort after it. php-cs-fixer is deliberately
not a dependency (#391); a one-off run of its phar under `var/` with that single rule is how #391 sorted
the existing code. Out of scope, **by decision** (2026-10-10): native **functions and constants** are
never imported — no `use function` / `use const`, `importShortClasses` does not touch them; namespaces
cited in prose (`App\Shared`). **A native function is qualified exactly when the compiler optimizes it**
(settled on 2026-10-10, review of #391): a function of php-cs-fixer's `@compiler_optimized` set (`\count`,
`\in_array`, `\is_string`, `\strlen`, `\sprintf`, `\dirname`…) is written with its leading backslash, every
other one without (`array_map`, `trim`, `json_decode`: no dedicated opcode, nothing to gain). Measured for
`sprintf`: `\sprintf('x %s', $a)` compiles to a bare `FAST_CONCAT`, the unqualified call to a runtime
lookup and a function call. A first-class callable (`is_string(...)`) is not a call and stays as written. No
tool enforces the split; #391 applied it once (73 `sprintf` in `src/`, 147 in `tests/`, a dozen others)
with the same phar, rule `native_function_invocation` (`include: ['@compiler_optimized']`, `strict: false`).
Constants were not migrated: a leading backslash already there stays.
**The line is performance, and it was measured** (2026-10-10, PHP 8.5, OPcache dump after the optimizer): the
rule only covers natives whose import costs nothing. `\DateTimeImmutable` and an imported `DateTimeImmutable`
compile to **identical opcodes** — `use` is resolved at compile time — whereas an unqualified `count($a)`
loses the dedicated `COUNT` opcode for `INIT_NS_FCALL_BY_NAME` + `DO_FCALL_BY_NAME`, a runtime lookup that
falls back to the global function (constants share that fallback). A class-like native that ever proved
costly to import would keep its qualified name, with the measurement as its justification. Also out of scope:
`config/bundles.php` and `config/reference.php` (Flex-generated); class names held in strings as test data;
`migrations/` (frozen, outside Rector's paths); the `Assert\…` constraint shorthand, which mirrors the
`use …\Constraints as Assert;` alias the code itself writes. The guard is Rector's
`withImportNames(importShortClasses: true, removeUnusedImports: true)` in `backend/rector.php`, checked by
the blocking `rector-backend` job: it rejects a qualified class, native or namespaced, in code and phpdoc
types (issue #391, ≈ 200 files migrated). It does **not** see a `@template T of \Foo` bound nor any prose:
those are reviewed by hand.

