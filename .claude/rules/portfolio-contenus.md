---
paths:
  - "backend/src/Portfolio/**"
  - "backend/tests/Portfolio/**"
  - "frontend/src/presentation/pages/**"
  - "frontend/src/presentation/ui/**"
---

# Backend — `Portfolio` content contexts

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Portfolio/Shared/` to `Portfolio/Incident/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **`Portfolio/Shared/`** — `Domain/ValueObject/Locale.php`, the `enum Locale: string { FR = 'fr'; EN = 'en' }`
  shared by every `Portfolio/*` context. Two entry points, and the distinction matters (audit I3):
  - **`Locale::fromString()` for anything coming from outside** (a `{locale}` URL segment, a command
    argument). It throws `InvalidLocaleException` (`Portfolio/Shared/Domain/Exception/`), the only thing mapped
    to 404 in `exception_to_status`. Providers/Processors get it in one step via `uriVariableLocale()` on the
    `ResolvesUriVariables` trait — use that rather than re-deriving it.
  - **`Locale::from()` stays for already-validated values** (backoffice DTO fields, bounded upstream by
    `#[Assert\Choice]`, or values read back from the DB). A `\ValueError` there is a genuine bug and must
    surface as a 500.
  - There used to be a blanket `ValueError: 404` mapping. It has been **removed and must not come back**: it
    disguised *every* `ValueError` in the HTTP stack as a plausible "404 Not Found", which is exactly how a
    real defect goes unnoticed.
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
    `UPDATE` at a time, and the gap between two statements is legitimate. Since no group can stay
    divergent, a reorder no longer meets one to repair silently. `inGroup()`'s check stays the **first**
    net — it answers before any write, under a domain name —; the trigger only catches at COMMIT what
    bypasses it. **On the application path the refusal comes out as `TranslationGroupHasSeveralPositionsException`**
    (`refusedAtCommit()`, the database's message in its own, previous = the `DriverException`), a
    `critical` 500: `PostgresAdvisoryOrderScopeLock` opens and closes its transaction by hand — through
    the skeleton it shares with the two account locks since #512, `Shared/Infrastructure/Lock/PostgresLockedTransaction`,
    which also bounds the wait of a session without `lock_timeout` (the console, `app:*:seed`, waited
    forever before) — and translates `23T90` at `commit()` (its `onCommitRefused`).
    **It refuses to run inside a transaction already open** (issue #526), another `withLock()` included: there
    its `commit()` would only be a `RELEASE SAVEPOINT`, and the trigger would refuse at the caller's COMMIT as
    a bare `23T90`. The refusal, `OrderScopeLockInsideTransactionException` (a `LogicException`, unmapped, a
    `critical` 500), comes before any write; no caller nests today (the `Administrator`s, the seeds, the
    migrations, which write in SQL, the whole suite), so the rule holds by construction instead of relying on
    the next caller's memory. Since #532 the check lives in the shared skeleton (`run()`'s required
    `onNested`), common to the two account locks, each naming its own refusal. A future operation spanning
    two scopes takes one lock, or an adapter that owns the outer COMMIT — never a nested `withLock()`. DBAL's count is the judge: a transaction opened in raw
    SQL (`executeStatement('BEGIN')`) is not seen, and none may be opened that way around an `Administrator`. **Never go back to `Connection::transactional()`** there: when
    `commit()` fails, DBAL has already brought its nesting level to 0, and `transactional()`'s fallback
    `rollBack()` throws "There is no active transaction" over the real error, for any SQLSTATE outside
    its short list (unique, foreign key, deadlock…) — measured, `PostgresAdvisoryOrderScopeLockTest`
    pins it. No `rollBack()` after a failed `commit()`: PostgreSQL already rolled the transaction back,
    and the lock with it. The check runs before the COMMIT, hence still under the scope lock for the
    application's writes. **Accepted limits**: two concurrent SQL transactions that skip that lock can
    each re-read the group without the other's uncommitted write and both commit; after a refused
    COMMIT, the EntityManager stays open and its managed entities keep the refused positions and
    groups (the request ends in a 500; a command chaining operations stops on it); the check costs one
    indexed query per row written at COMMIT, 2N for a reorder of N FR/EN pairs, under the lock.
    **Trap for future migrations**: a deferred trigger leaves pending events until the COMMIT, and
    PostgreSQL refuses an `ALTER TABLE` on a table that has some (55006, "pending trigger events") — a
    migration that writes into one of the eight tables and then alters it starts with `SET CONSTRAINTS
    trg_translation_group_single_position IMMEDIATE`. That migration reported every divergent group as
    a `warning` with its original positions per locale in the migration Job's output, then **renumbered
    its perimeter** `0…n-1` by lowest position, the group breaking ties — aligning on the lowest
    position alone left two groups tied, which the repositories break by each row's own id, so FR and
    EN in two orders; a table with no divergent group was left untouched. Me-cards are renumbered per
    category; a group spread across two categories (which the application forbids) is left out of the
    renumbering — each category would give it its own rank, creating the divergence being repaired — and
    reported: reordering either category is refused until it is corrected in the backoffice. Rejected: an `EXCLUDE USING gist
    (translation_group WITH =, position WITH <>)` (`btree_gist`), safe under concurrency, but DBAL
    introspects its index, which no mapping declares, so every `doctrine:migrations:diff` would propose
    a `DROP INDEX` — DBAL reads neither triggers nor functions; a `CHECK`, which cannot read other rows;
    logging in `OrderAssigner` alone (late, a logger in a pure Domain service, SQL writes still unseen);
    a periodic check (`watch-refresh`, a preprod smoke test), as late. A new localized context adds its
    table to a **new** migration creating the same trigger: `TranslationGroupSinglePositionTest` fails on
    any `TranslatableContent` entity whose table lacks it, and probes each context of
    `ProvidesLocalizedAdministrators` (`UPDATE` of a position or a group, `INSERT`, a transient gap, a real
    `reorder()`); `tests/Migrations/Version20261012120000Test` pins the alignment and its warnings. A test
    that must write a divergent group does it in a transaction it rolls back: a deferred trigger never
    fires there.
    **Every write that computes a position runs under its scope's lock** (issue #389): `create()`, `update()`
    and `reorder()` of the eight localized `Administrator`s, `create()` and `reorder()` of
    `WatchedProductAdministrator` (its `update()` never touches the position), wrap their **whole body** in
    `Shared/Application/OrderScopeLockInterface::withLock(ORDER_SCOPE, …)`, `ORDER_SCOPE` being the entity
    class (`<Entity>::class`, never a table name, which the Application layer has no business knowing; the
    whole table for `AboutMeCard` too, wider than its per-category ordering scope, on purpose). Without it a "Create
    the XX version" landing between a `reorder()`'s read and its save kept the old position (a group on two
    positions, invisible to the exact-set rule), and two simultaneous creations without a group got the same
    `atEndOf()`. The adapter, `Shared/Infrastructure/Doctrine/PostgresAdvisoryOrderScopeLock`, runs the body
    in a transaction it opens and closes by hand (not `Connection::transactional()`, since issue #390, see
    above) whose first statement is `pg_advisory_xact_lock(ORDR, hashtext(scope))`,
    released by the COMMIT/ROLLBACK itself. Three choices not to undo: a **scope** lock, never `SELECT … FOR
    UPDATE` (a blocked one does not see rows the other transaction inserted, and locks nothing on an empty
    table, so the creation race survives it); **not** `lock.factory` (a session lock on a second connection,
    which would have to be released after the ORM's COMMIT); **not** `wrapInTransaction()`, which closes the
    EntityManager on any exception, while a 409 inside the lock is an ordinary outcome. A read made *before*
    `withLock()` escapes it, hence the whole body. **Once the lock is taken, the adapter re-reads every
    managed instance of the scope's class** (issue #402), in one `HINT_REFRESH` query on their ids: what the
    EntityManager loaded before the lock — the `PUT` entry, loaded by the provider; the whole scope, in a command
    chaining operations such as a seed — is refreshed in place (same object, which a caller may keep and save),
    and an instance the query no longer returns (deleted meanwhile) is detached, so `find()` reports it not found.
    Before, `find()` handed back the identity-map instance and a query did not rehydrate a managed one: a
    concurrent write committed in between was silently undone (`reattach()` saw a no-op, `detach()` looked in the
    group the entry had left, a version created in a group took the position the group was leaving, a deleted
    entry was "updated" with a 200). Not `refresh()` entry by entry: it does not see a deleted row (it hydrates
    nothing and keeps the instance) and costs a query each. Not detaching the whole scope either: an instance
    held from before and saved afterwards collides with the one the operation reloaded
    (`EntityIdentityCollisionException`, measured on four resource tests). Consequence for a caller: an unwritten
    change to such an instance is overwritten. An instance scheduled for insertion or removal is neither re-read
    nor detached (detaching it silently cancelled the pending `INSERT`). Not covered: a deletion committed
    *during* the operation — `delete()` takes no lock, so if it commits between the re-read and a `PUT`'s
    flush, the `UPDATE` touches no row and the `PUT` still answers 200; only a deletion committed before the
    lock is reported not found. A command chaining N operations on one EntityManager re-reads O(N²) rows,
    negligible for ten-entry seeds. It relies on READ COMMITTED (PostgreSQL's default, set nowhere):
    under REPEATABLE READ the snapshot would be taken by the lock's own `SELECT`, before the wait. Last writer
    wins, like every other field; no `#[ORM\Version]`. `ScopeLockReadsCommittedStateTest` pins both (the four
    stale-state cases over the same `ProvidesLocalizedAdministrators` list as `OrderScopeLockCoverageTest`, so a
    new localized context goes there once, the watch catalogue's end, the pending insert, and the isolation level).
    In an FPM worker the wait is bounded by `PGOPTIONS` `lock_timeout=5s`; past it the adapter throws
    `OrderScopeLockTimeoutException` (named, unmapped: a `critical` 500 — 5 s for a millisecond write is an
    anomaly, not a conflict to retry), and only for its own lock statement: a timeout inside the operation
    stays the DBAL exception. **The console waits without a bound**: the `app:*:seed` commands go through the
    `Administrator`s, so the deploy's seed Job takes the same lock, which `www.prod.conf` does not reach —
    harmless while every holder keeps it for milliseconds, revisit before holding it any longer. Guards:
    `OrderScopeLockCoverageTest` (a probe session holds each scope, every placing operation of every
    `Administrator` must wait — a new one written without `withLock()` turns it red) and
    `ContributionOrderConcurrencyTest` (two real processes, interleaved at the `preFlush`, both races).
    `delete()` takes no lock: it leaves a gap, never a collision. The **only client-driven
    writer of `position` is `PUT /api/backoffice/<x>/order`** (`ContentPlacement` derives it server-side,
    no request body ever chooses it) (`Backoffice<X>OrderResource`, `read: false`,
    `output: false`, 204, one per context, `{groups: [uuid…]}` — `{ids: […]}` for Watch, plus
    `category` for me-cards): `Domain/Service/OrderAssigner` enforces the **exact-set rule** (D4) —
    an unknown key is 422 `unknown-order-entry`, a missing one 422 `incomplete-order`, both
    `ProblemExceptionInterface` slugs the frontend keys on — and renumbers `0…n-1` on every entity
    sharing a key. That 422 is not validation, it is optimistic concurrency without a version column:
    a row created or deleted between the page load and the save changes the set, the server refuses,
    the frontend reloads and says so. Never relax it to "ignore unknown keys". The
    `Version20260914170000` migration paired the existing FR/EN rows by `position` (and `category`)
    **only when the pair was unique on both sides**, giving every other row its own group — a wrong
    link would survive every proofreading, an isolated row is one "Version of" click away — and it is
    the first migration of the UUID era that is **reversible**.
- **`Portfolio/Experience/`**, **`Portfolio/Quality/`**, **`Portfolio/About/`**, **`Portfolio/Contribution/`**,
  **`Portfolio/Incident/`** — DB-backed
  content that used to be (or, for `Experience`, always was) hardcoded in the frontend. Each follows the same
  shape: a Doctrine entity per concept (`ExperienceTechnology`; `QualityPrinciple`/`QualityTrait`;
  `AboutSettings`/`AboutSiteCard`/`AboutMeCard`, the latter with an `AboutMeCardCategory` enum), a public
  read-only API Platform resource (`GetCollection('/experience/technologies')`, or an aggregating `Provider` for
  `/quality/{locale}` and `/about/{locale}` that returns `{principles, traits}` / `{settings, siteCards, meCards}`
  in one call), and a backoffice CRUD resource (see `.claude/rules/backoffice-api.md`). Seeded via idempotent `app:{about,quality,contributions,incidents}:seed`
  console commands (purge-by-locale then recreate — safe to rerun).

  **`ExperienceTechnology.years` is a finite number in `[0, 100]`, checked at three layers** (issue #372).
  A single non-encodable row (`Infinity`, `NaN`) is enough to put the public `GET /api/experience/technologies`
  in 500 for every visitor, and `PositiveOrZero` alone let `{"years":1e999}` (`json_decode` → `INF`) through.
  The rule is written once, in the Value Object `Domain/ValueObject/ExperienceYears` (`MIN`, `MAX`, `fromFloat`,
  `fromString`; `-0.0` is normalised to `0`). The entity, the registrar and the administrator only receive an
  `ExperienceYears`. The backoffice DTO validates through an `Assert\Callback` that delegates to it, so the
  422 names `years` with the domain's message. The CLI command parses `--years` before reaching the
  registrar. **And the database refuses the same bounds** (`ExperienceTechnologyRepository::YEARS_CHECK_CONSTRAINT`,
  migration `Version20261006120000`), because Doctrine never calls the constructor when hydrating, so no PHP
  guard sees a row written in SQL. One gap is accepted: the `CHECK` lets `-0` through (`-0 >= 0`), a value
  only SQL can write and the public list would publish as `"years":-0`. Enforcing it would take an unreadable
  sign test, for a cosmetic defect no write path produces. `ExperienceTechnologyYearsConstraintTest` reads both bounds from the Value Object and
  probes a billionth past each, so a constraint loosened or tightened by a hair turns it red. A test that must
  write a row the database now refuses lifts the constraint in a rolled-back transaction through the
  `LiftsExperienceYearsConstraint` trait. Never drop it for good, and never write that `ALTER TABLE` by hand.
  That migration clamped any existing faulty row **and set it `secondary`**, reporting each one as a
  `warning` with its original value in the migration Job's output. The invented duration is therefore never
  **displayed on the site**, since `ExperiencePage` shows no duration for a secondary technology, and the row
  stays one backoffice edit away from being fixed. The public API still carries its `years`: the duration is
  not protected data, and hiding it there would change the public contract. `InvalidExperienceYearsException` is deliberately **not** mapped to an HTTP status: from
  the API it is unreachable unless a write path bypasses the DTO, a server fault that must stay a
  `critical` 500.

  **`ExperienceTechnology.name` is a `Domain/ValueObject/TechnologyName`** (issue #386): valid UTF-8, trimmed
  of **Unicode** blanks (`\s`, `\p{Z}`, zero-width characters, BOM — `trim()` only knows ASCII, and a pasted
  non-breaking space rebuilt the duplicate), not blank, no control character (PostgreSQL refuses NUL in a
  `varchar`: the DBAL exception was a `critical` 500), at most `MAX_LENGTH` (the column's 180) characters. The
  entity, the registrar and the administrator only receive one, so the uniqueness check (`findOneByName()`)
  runs on the trimmed value — trimming in the entity alone would have let `" PHP "` through to the unique
  index, whose exception closes the EntityManager. The backoffice DTO validates `name` through an
  `Assert\Callback` that delegates to the Value Object, exactly like `years` — never a copied `NotBlank` or
  `Length`, which is how the NUL and the non-breaking space got through; `InvalidTechnologyNameException` is
  unmapped on purpose, like `InvalidExperienceYearsException`. No `CHECK` in the database, unlike `years`: an untrimmed name is a
  duplicate on the public page, not a 500, and no write path reaches SQL without the Value Object. `relatedTechnologyName` is a free label, not trimmed.

  `Contribution` is the odd one out and deliberately so: it carries a long `body` (the argument, not
  just a link to it) alongside `title`/`project`/`reference`/`url`/`summary`. That text is **plain
  text**, paragraphs separated by a blank line, rendered by splitting on those blanks —
  `ContributionsPage.vue` never uses `v-html`. The only markup honoured is `` `backticks` ``, turned
  into `<code>` by splitting on a capturing regex, so every segment stays an interpolated text node.
  The content is authored through the backoffice, so treating it as HTML would trade formatting for
  a stored-XSS hole on a public page — there is a regression test pinning that
  (`tests/presentation/pages/ContributionsPage.spec.ts`).

  `Incident` follows the same shape with a post-mortem's fields — `impact`, `rootCause`,
  `resolution` and, crucially, **`invariant`, which is `NOT NULL`**. That constraint is the page's
  editorial line made structural: an entry you cannot save without stating the rule you drew from
  the outage cannot drift into a confession. Don't relax it to "make an entry easier to add".

  Both pages render their prose through **`presentation/ui/RichText.vue`** — paragraphs split on
  blank lines, `` `backticks` `` turned into `<code>`, never `v-html`. It is shared rather than
  duplicated precisely because it carries a security guarantee: a fix applied to one copy would
  silently leave the other exposed. Reach for it for any backoffice-authored prose.
