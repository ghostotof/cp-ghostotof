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
that is deliberate: when the same failure needs an exemption a second time, give it a shared class
instead. **A console question's validator follows the same rule** (#383): the `QuestionHelper` catches
any `\Exception` a validator throws, shows its message and asks again, so the domain exception is the
right one when the rule has one (`InvalidUsernameException`, `InvalidExperienceYearsException`), and
`Shared/Presentation/Command/InvalidConsoleAnswerException` covers the rest. Two traps of the same commands:
in non-interactive mode, `ask()` returns the default (`null`) **without calling the validator**, so the
command refuses `null` explicitly and names the option, instead of relying on an `assert()` that production
compiles out. And a `CommandTester` is **interactive by default**, so a `-n` test passes
`['interactive' => false]`. The other SPL classes (`\DomainException`…) are out of the guard's scope:
none is thrown bare in `src/`, and adding one to `BareExceptionInstantiations::FORBIDDEN` is the step to
take the day one is.

