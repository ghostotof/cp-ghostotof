---
paths:
  - "backend/src/**"
  - "backend/tests/**"
  - "backend/migrations/**"
  - "backend/config/**"
---

# Backend — architecture and UUID v7 keys

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture (introduction) ». The index of every rule is at the top of `.claude/CLAUDE.md`.

**Where the rest lives** (split by #605, so that each file reads in one `Read`). A reference elsewhere to
`.claude/rules/backend-architecture.md`, « <paragraph> » points at one of these:

| Rule | Paragraphs |
|---|---|
| this file | the DDD layout, the schema fingerprint of every migration, UUID v7 keys, the re-read under a lock, timestamps with their offset, elapsed hours |
| `backend-integrite.md` | every queued envelope authenticated before unserializing (#444), its size bound, every database cache value authenticated (#468, #528), `APP_SECRET` never keying an HMAC directly (#455), `KeyedMac` (#493) |
| `backend-conventions.md` | no bare SPL exception in `src/` (#338, #383), a full message queue as a named 503 (#485), `#[SensitiveParameter]` (#411, #414), no anonymous class nor free function (#432), regexes ending on `\z`, imports (`use`, sorting, comments), `make back-style-fix`, the `sprintf` split and its measured cost |

### Backend architecture (`../backend/src`)

DDD structure: each bounded context is a top-level folder under `src/`, itself split into `Domain/`,
`Application/`, `Infrastructure/`, `Presentation/` layers — only the layers a context actually needs, no empty
ceremonial folders. `config/packages/doctrine.yaml`'s mapping scans all of `src/` (not a single `Entity/`
folder), so entities live inside their bounded context instead of a shared top-level directory.

**Every migration adds the fingerprint of the schema it leaves to `k8s/base/postgres-roles/verify.sql`**
(issue #611): its `#611` block admits, per migrations applied (the latest and their count), the
schema's fingerprints, and the roles procedure fails on any other. `DatabaseOwnerSettingsVerificationTest`
turns red on a migration without its line and prints it — add it, never remove an older one. Why, and what the fingerprint covers:
`.claude/rules/deploiement.md`, the PostgreSQL roles bullet.

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

**A lock re-reads what the EntityManager loaded before it through `Shared/Infrastructure/Doctrine/RereadUnderLock`,
and a row gone is detached through `VanishedEntities::detachVanished()`, never `EntityManager::detach()`** (issues #548,
#560). Doctrine takes an instance it does not manage for new (`persist()` assumes `STATE_NEW` and reads nothing): a caller re-saving
the detached instance re-inserted the deleted row, silently. The service remembers the instance in a
`WeakMap` and its `prePersist` listener refuses it (`VanishedEntitySavedAgainException`, unmapped, a
`critical` 500). `RereadUnderLock` (#560) is the one place that re-reads under a lock — `row()` (one row, one
DQL query with `HINT_REFRESH`, `FOR UPDATE` on demand, `null` when the row is gone) and
`everyManagedInstanceOf()` (the order scope's #402 re-read) —, the one place that skips an instance awaiting
an insertion or a deletion (detaching it would cancel that write), and the one caller of `detachVanished()`.
Used by the order scope lock, the technology row lock, the account administration lock and, since #560, the
account row lock (`.claude/rules/security-user.md`); a new lock that re-reads goes through it, never a copy
(there were four, and the account row lock's did not detach). Since #557 `VanishedEntities`' `postRemove` listener also
remembers every instance the same manager removed and flushed — Doctrine took it for new as well, its UUID
kept — so re-saving a deleted entity is refused too (a restoration creates a new entity). **A DQL `DELETE` by
id** emits no `postRemove`: since #569 the repository runs it through `VanishedEntities::deleteById(Query, entity)`
(#560), which detaches the instance under the same « removed by this manager » cause **when the statement
touched the row, and only then** — left managed, a later `save()` sent an `UPDATE` touching no row, lost in
silence; a row the condition spared, or already gone, still leaves the instance managed. A new DQL deletion by
id goes through it. The registry is not per EntityManager: `detachVanished()` takes the manager only for the
`detach()` (a Doctrine listener cannot receive it by injection), `deleteById()` reads it from the query. The details, the
rejected options and the accepted limits live in `.claude/rules/portfolio-contenus.md` (ordering section).

**Every entity timestamp is stored with its offset** (issue #434): `#[ORM\Column(type:
Types::DATETIMETZ_IMMUTABLE)]` (`datetimetz` for a mutable object), PostgreSQL `TIMESTAMP(0) WITH TIME
ZONE` — never `datetime` nor `datetime_immutable`, the type Doctrine infers from a bare
`DateTimeImmutable` property. Those store the wall time of PHP's zone (`date.timezone = Europe/Paris`)
with no offset: the hour repeated when the clocks go back is read back one hour late (measured: an
invitation of 2026-10-25 02:30 CEST came back at 02:30 CET, +3600 s), and a value compared with a
column that has a zone is read in the PostgreSQL session's zone, **UTC**. Likewise a query parameter
compared with such a column is typed `Types::DATETIMETZ_IMMUTABLE` (`findAwaitingPasswordSetupInvitedBefore`'s
threshold, `claim()`'s `usedAt`): typed `DATETIME_IMMUTABLE`, Paris wall time would be read as UTC, two
hours off all year. A calendar day without a time (`Incident::$occurredAt`, `date_immutable`) has no
zone and stays a `DATE`. A value read back is in the session's zone (UTC): compare instants
(`getTimestamp()`), never `format('Y-m-d H:i:s')`, and convert before displaying a wall time
(`WatchProvider::utc()`). `tests/Shared/Infrastructure/Doctrine/TimestampColumnsCarryTheirOffsetTest.php`
walks every entity's metadata and fails on any `datetime`, `datetime_immutable`, `time` or
`time_immutable` field. Migration `Version20261011120000` converted the five existing timestamps with
`AT TIME ZONE 'Europe/Paris'`. Messenger's own tables are not entities and stay as the vendor made them:
`messenger_messages.created_at`/`available_at`/`delivered_at` are `TIMESTAMP WITHOUT TIME ZONE` holding
**UTC wall time** (the Doctrine transport writes `new DateTimeImmutable('UTC')`), so a value compared with
them in SQL is converted to UTC first — `PurgeFailedContactMessagesCommand` formatted its threshold in
Paris time and purged one to two hours early (issue #446).
**The database's sessions read wall times in PHP's zone** (`ALTER DATABASE … SET timezone TO
'Europe/Paris'`, same migration, settled in the review of #434): the second net, for a time without
offset that still reaches the database — an untyped query parameter, which the ORM infers as
`datetime_immutable`, or the old code during a rollout and **indefinitely after a rollback**, since
`rollout undo` never replays a migration's `down()` (two hours off: a 50-hour link, an invitation purge two
hours early, irreversible). The trade-off: it hides a typing mistake instead of revealing it — the
mapping guard above is what reveals it. The same test checks `SHOW timezone` equals
`date_default_timezone_get()`: changing `date.timezone` in `php.*.ini` means a migration that moves the
database with it. A value read back therefore carries Paris's offset; still compare instants.
**A duration in hours is added as elapsed hours** (issue #445): `add(new DateInterval('PT48H'))`, never
`modify('+48 hours')`, which counts wall-clock hours in PHP's zone — measured under PHP 8.5, 49 h on the eve
of the autumn change and 47 h on the eve of the spring one (the invitation link announced as "valid 48 hours",
the tech watch's 36-hour freshness threshold). `modify()` stays exact for minutes, and a number of days is
meant as calendar days. `tests/Shared/ElapsedDurationsTest.php` walks the tokens of `src/` and fails on
any string literal adding or subtracting hours (`'+48 hours'` in a constant, `'+%d hours'` for `sprintf()`),
comments excluded.

