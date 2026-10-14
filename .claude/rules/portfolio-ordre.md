---
paths:
  - "backend/src/Portfolio/**"
  - "backend/tests/Portfolio/**"
  - "frontend/src/presentation/pages/**"
  - "frontend/src/presentation/ui/**"
---

# Backend — `Portfolio` ordering and translation groups: the model, the trigger, the scope lock

> Split out of `.claude/rules/portfolio-contenus.md` (#605), whose index lists every `Portfolio` content rule. These rules live in `Portfolio/Shared/` (the « here » of the first bullet) and apply to the eight localized entities with a position. The index of every rule is at the top of `.claude/CLAUDE.md`.

- **Content ordering and translation groups** (spec 0004, `v0.12.0`) also live here, because nine
  contexts share one rule. The eight localized entities with a position (`QualityPrinciple`,
  `QualityTrait`, `AboutSiteCard`, `AboutMeCard`, `Contribution`, `Incident`, `AnonymousCvSection`,
  `CaseStudy`) carry `translation_group uuid NOT NULL` with a unique `(translation_group, locale)`
  index (D1): two rows of the same group are the same content in two languages, and the group is an
  **identifier, not an entity** — the day something needs to hang *on the group*, that UUID becomes
  the primary key of a new table and the rows already reference it. **Nothing hard-wires the FR/EN
  pair** (D2): every locale `#[Assert\Choice]` points at `Locale::values()`, `AboutMeCardCategory`'s
  at its own `values()`, the frontend renders its language badges from `SUPPORTED_LOCALES` — a third
  language is a third row per group. **`position` is never typed in** (D3): it is out of every write
  DTO (`#[ApiProperty(writable: false)]`, a body still carrying it is accepted and ignored) and every
  `create`/`update` signature; `Domain/Service/ContentPlacement` derives it (a new entry without a
  group takes `max + 1` of its scope, one attached to a group **inherits the group's position**;
  `detach()` on a `PUT` with `translationGroup: null` detaches **and moves the entry to the end of
  its scope** (issue #169 — keeping the position let the old group receive that locale again via
  "Create the XX version" and inherit the same position: two keys on one position), and is a no-op
  on an entry already alone; `reattach()` with a group inherits its position; `inGroup()` throws
  `TranslationGroupHasSeveralPositionsException` (a `LogicException`, issue #338) on a group whose members disagree on the position, a pipeline bug, never a 4xx — checked **before** the
  "locale already there" 409, issue #384: with two locales the only corrupt group the unique index allows
  is an FR/EN pair, which always carries the requested locale, so the reverse order hid the bug as an `info`;
  when it fires, look at migrations and SQL writes, which bypass the scope lock below). `WatchedProduct` is not localized, so it is `Orderable` on its
  own id, and its `Administrator` computes the end of the catalogue itself.
  **The database refuses a group on several positions** (issue #390). `inGroup()` only saw one when a
  locale was attached to that very group, which the backoffice almost never offers: the public pages
  ranked the content differently in FR and EN, and the next `reorder()` repaired it without a trace,
  erasing the evidence of the upstream defect. Migration `Version20261012120000` puts a **deferred
  constraint trigger**, `trg_translation_group_single_position` (one name on all eight tables), calling
  one plpgsql function, `portfolio_translation_group_single_position()`: at COMMIT, for each row
  inserted or whose `position`/`translation_group` changed, it re-reads the group's **current** `MIN`/`MAX`
  position (not `NEW.position`, which would refuse a row written twice in the transaction) and raises
  SQLSTATE **`23T90`** (class 23, integrity; a subclass PostgreSQL does not define, so it is recognised
  without reading the message) naming the table and the group, whatever the path (application,
  migration, SQL). **Deferred, never immediate**: `OrderAssigner` and Doctrine write a group's members one
  `UPDATE` at a time, and the gap between two statements is legitimate. A few writes still escape it
  and can leave a group divergent; the next `reorder()` refuses such a group instead of repairing it
  silently (issue #503, see « What the trigger does not see » in `portfolio-ordre-migrations.md`). `inGroup()`'s check stays the **first**
  net — it answers before any write, under a domain name —; the trigger only catches at COMMIT what
  bypasses it. **On the application path the refusal comes out as `TranslationGroupHasSeveralPositionsException`**
  (`refusedAtCommit()`, the database's message in its own, previous = the `DriverException`), a
  `critical` 500: `PostgresAdvisoryOrderScopeLock` opens and closes its transaction by hand — through
  the skeleton it shares with the three account locks since #512 (the administration one since #551) and the technology row lock since #545, `Shared/Infrastructure/Lock/PostgresLockedTransaction`,
  which also bounds the wait of a session without `lock_timeout` (the console, `app:*:seed`, waited
  forever before) — and names the trigger's refusals at `commit()` (its `onCommitRefused`, see below).
  **Who names the refusal, and who knows the codes** (issue #506): the lock **triggers** the translation
  because it owns the COMMIT where a deferred refusal falls — nothing else on the application path sees it.
  The **knowledge** of the SQLSTATEs (`23T90`, `23T91`) and of the exception each one names lives apart, in
  `Portfolio/Shared/Infrastructure/Doctrine/TranslationGroupRefusal` (`nameOf(DriverException)`, `null` for
  any other SQLSTATE), passed as the hook itself: a new invariant of the trigger adds a constant and an arm
  there, never touches the lock. Tests that need a code read it there (`TranslationGroupRefusalTest` pins
  the mapping, `previous` and message kept). Every application write able to wake the trigger is a write
  that places, hence under `withLock()` (#389, `OrderScopeLockCoverageTest`). A write that bypasses the lock
  gets the **raw** refusal, which still names the table and the group despite the driver-error redaction —
  accepted limit: in SQL, a migration, or a `flush()` inside a transaction the caller opened
  (`wrapInTransaction()`, which also closes the manager), a `DriverException` `23T90`/`23T91` at that
  COMMIT; through an ORM `flush()` outside any transaction, Doctrine wraps it in `OptimisticLockException`
  « Commit failed » (its `previous`, `UnitOfWork::commit()`, measured) and closes the manager (unmapped, a
  `critical` 500;
  `PostgresAdvisoryOrderScopeLockTest::testAWriteOutsideTheLockSurfacesAsDoctrinesCommitFailureWrappingTheRawRefusal`
  pins it). Rejected: a DBAL middleware naming the refusal everywhere — `ExceptionConverter::convert()` must
  return a `DriverException`, which the invariant's name is not, so it would either break that contract or
  give one refusal two names, and it would make the COMMIT hook useless.
  **It refuses to run inside a transaction already open** (issue #526), another `withLock()` included: there
  its `commit()` would only be a `RELEASE SAVEPOINT`, and the trigger would refuse at the caller's COMMIT as
  a bare `23T90`. The refusal, `OrderScopeLockInsideTransactionException` (a `LogicException`, unmapped, a
  `critical` 500), comes before any write; no caller nests today (the `Administrator`s, the
  migrations, which write in SQL, the whole suite), so the rule holds by construction instead of relying on
  the next caller's memory. Since #532 the check lives in the shared skeleton (`run()`'s required
  `onNested`), common to every lock of the skeleton, each naming its own refusal. **One exception, bounded,
  outside the skeleton** (issue #567, reversing #532's "no adapter has a use for it"): the order scope lock
  gains `withLocks(scopes, operation)` — one transaction through the unchanged skeleton, the advisory locks
  of every scope taken **sorted** (no deadlock between two callers asking for the same ones), one statement
  each (a `SELECT` list has no guaranteed evaluation order), so `lock_timeout` bounds each wait and not their
  sum — N scopes, up to N × 5 s —, each scope re-read (#402), the held scopes kept in a small registry
  emptied in a `finally` whatever the outcome —, and under that transaction a `withLock()` of the **same
  adapter** on a scope **that transaction holds** runs its operation directly: no `BEGIN`, no new lock, no
  re-read (nothing can have been committed into a scope it holds). A plain `withLock()` fills no registry:
  under it a `withLock()` of the same scope stays refused like any other — the re-entrance exists only for
  the operation that asked for it by taking `withLocks()`, never on the backoffice path. That
  is what lets an `app:*:seed` chain the `Administrator`s, which each take `withLock()`, under one
  transaction (see `.claude/rules/seeding.md`). It does not contradict #526's reason: the COMMIT stays the
  outer lock's, so `onCommitRefused` still names a deferred refusal, and the closing of the EntityManager
  (#547, #549) covers the whole transaction. Every other nested case still goes through the skeleton and
  is refused: a scope not held (a seed locking X while its `Administrator` writes Y — a scope mistake that
  must show, as a `critical` 500 at the first write), a `withLocks()` under a lock, a transaction opened
  by other code, an empty scope list (`OrderScopeLockWithoutScopeException`). The registry is the
  container instance's — one adapter serves the `Administrator`s and the commands; a hand-built one holds
  nothing for them. `PostgresAdvisoryOrderScopeLockTest` pins it (both scopes held and released,
  re-entrance at nesting level 1 with no second transaction, scope not held refused, the same scope under
  a plain `withLock()` refused, a timeout on the second scope named after that scope, `withLocks()` never
  re-entrant, refusal named at the outer COMMIT with the manager closed, registry emptied after a success,
  an exception and a refused COMMIT, both scopes re-read). A future operation spanning
  two scopes takes `withLocks()` — never a nested `withLock()` on a scope it does not hold. DBAL's count is the judge: a transaction opened in raw
  SQL (`executeStatement('BEGIN')`) is not seen, and none may be opened that way around an `Administrator`. **Never go back to `Connection::transactional()`** there: when
  `commit()` fails, DBAL has already brought its nesting level to 0, and `transactional()`'s fallback
  `rollBack()` throws "There is no active transaction" over the real error, for any SQLSTATE outside
  its short list (unique, foreign key, deadlock…) — measured, `PostgresAdvisoryOrderScopeLockTest`
  pins it. No `rollBack()` after a failed `commit()`: PostgreSQL already rolled the transaction back,
  and the lock with it. The check runs before the COMMIT, hence still under the scope lock for the
  application's writes. **A COMMIT the database refuses closes the EntityManager** (issue #502): any
  `DriverException` the COMMIT raises, whatever its SQLSTATE (a `CommitFailedRollbackOnly`, which nothing
  here can cause, leaves it open). Since #547 the **skeleton** closes it, for every lock built on it
  (it receives the EntityManager, not the bare connection), **before** calling `onCommitRefused`, which only
  names the refusal it recognises (`TranslationGroupRefusal::nameOf()`, the `23T90` and `23T91`, #506) —
  the closing stays in the skeleton, and a new lock cannot forget it. The operation's
  `flush()`es had succeeded at the savepoint, so the UnitOfWork held as written what the refusal had just
  rolled back — a read on the same EntityManager returned the refused position, `find()` an entry never
  committed, and a write outside the lock started from there (a following locked operation of the same
  scope was already repaired by the #402 re-read of `portfolio-ordre-ecritures.md`, measured). It is closed, as Doctrine's own failed
  COMMIT closes it (`UnitOfWork::commit()`): identity map emptied, a **new** read re-reads the database, an
  ORM write fails with `EntityManagerClosed` — the refusal is terminal for the unit of work that got it.
  What the caller already holds keeps the refused values (detached), and a raw DBAL statement or a DQL
  `UPDATE`/`DELETE` still goes through the closed manager (no repository of `Portfolio` writes that way).
  A session lost at the COMMIT (`ConnectionLost`, a `DriverException` too) leaves its outcome unknown:
  closing is the safe answer there as well. **An exception the operation throws after a write closes it
  too** (issue #549, in the skeleton, for every lock): a `flush()` that had something to write succeeded
  at the savepoint, the operation then threw — a business refusal placed after a `save()`, a database
  error on a later statement, a lost session — and the ROLLBACK undid what the UnitOfWork held as
  written, the same stale state by the other path. `Shared/Infrastructure/Lock/WrittenFlushWatch`,
  hooked on `onFlush` for that transaction only and unhooked whatever happens, notes a flush with
  scheduled changes; the skeleton closes the manager **after** the ROLLBACK (in the `ConnectionLost`
  branch too) and rethrows the exception unchanged. A business refusal (409, 404) thrown **before** any
  write — every `Administrator` throws it there, keep it so: no wasted write, and the manager stays open
  for a caller that carries on — leaves it open; so do a flush with nothing to write and a DQL or raw
  DBAL write, which bypass the UnitOfWork (accepted limit, no caller under a lock of the skeleton: what
  the code itself carries into memory after such a write — an entity loaded or refreshed afterwards, or
  original data synced by hand as `CpgUserRepository::advanceInvitationGeneration()` does, outside any
  lock today — keeps the rolled-back value; such a pattern placed under a lock goes through `flush()` or
  closes the manager itself). Only a `flush()` counts: an entity changed in memory then a refusal before
  the `flush()` leaves those pending changes in the open manager, as anywhere with Doctrine — so a
  refusal also comes before any change (`ContentPlacement`, `OrderAssigner` do). Before #549 « throw before
  writing » was only written down. Rejected: doing nothing (the rule alone does not stop the next refusal
  placed after a `save()`); closing on any exception (`wrapInTransaction()`'s default, which breaks callers
  that set a refusal aside and continue — the invitation handler, the purge); a test guard checking that
  each expected refusal precedes the write (it sees only the scenarios written, neither database errors
  nor the account locks); `postFlush`, emitted by an empty flush too. `PostgresLockedTransactionTest`
  pins it (a written flush then an exception, a deletion alone, an empty flush, a lost session, no
  listener left behind — commit, refusal, failed acquisition, lost session —, no write held against the
  next transaction), with `PostgresAdvisoryOrderScopeLockTest` (a read after
  the refusal sees the database, the next write fails with `EntityManagerClosed`) and
  `PostgresUsernameAllocationLockTest`. A Messenger worker gets a fresh one at the next message (DoctrineBundle's `Registry::reset()`
  resets a closed manager); a command stops on the exception. Rejected: re-reading the scope on the
  refusal (one more query on the failure path, to guard so it never masks the refusal, the scope's class
  only, and writes that carry on after a broken invariant); `clear()` alone (it leaves the manager writing
  — `close()` empties the identity map just as wide, every class included, then refuses ORM writes). `PostgresAdvisoryOrderScopeLockTest` pins a read, a
  following write and a refusal under another SQLSTATE (a deferred unique constraint on a temporary
  table). **Accepted limits**: two concurrent SQL transactions that skip that lock can
  each re-read the group without the other's uncommitted write and both commit; the check costs one
  indexed query per row written at COMMIT, 2N for a reorder of N FR/EN pairs, under the lock.
