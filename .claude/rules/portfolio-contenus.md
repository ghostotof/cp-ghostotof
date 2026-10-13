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
    `UPDATE` at a time, and the gap between two statements is legitimate. A few writes still escape it
    and can leave a group divergent; the next `reorder()` refuses such a group instead of repairing it
    silently (issue #503, see « What the trigger does not see » below). `inGroup()`'s check stays the **first**
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
    `critical` 500), comes before any write; no caller nests today (the `Administrator`s, the seeds, the
    migrations, which write in SQL, the whole suite), so the rule holds by construction instead of relying on
    the next caller's memory. Since #532 the check lives in the shared skeleton (`run()`'s required
    `onNested`), common to every lock of the skeleton, each naming its own refusal. A future operation spanning
    two scopes takes one lock, or an adapter that owns the outer COMMIT — never a nested `withLock()`. DBAL's count is the judge: a transaction opened in raw
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
    scope was already repaired by the #402 re-read below, measured). It is closed, as Doctrine's own failed
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
    **Trap for future migrations**: a deferred trigger leaves pending events until the COMMIT, and
    PostgreSQL refuses an `ALTER TABLE` on a table that has some (55006, "pending trigger events") — a
    migration that writes into one of the eight tables and then alters it starts with `SET CONSTRAINTS
    trg_translation_group_single_position IMMEDIATE`. Since #501 that includes a write of `category`
    alone in `about_me_card`, which the trigger now listens to. And a migration that drops and recreates
    the trigger writes no data: dropping it while writes of the same transaction await their deferred
    check cancels those checks silently (measured for #505). That migration reported every divergent group as
    a `warning` with its original positions per locale in the migration Job's output, then **renumbered
    its perimeter** `0…n-1` by lowest position, the group breaking ties — aligning on the lowest
    position alone left two groups tied, which the repositories break by each row's own id, so FR and
    EN in two orders; a table with no divergent group was left untouched. Me-cards are renumbered per
    category; a group spread across two categories (which the application forbids) is left out of the
    renumbering — each category would give it its own rank, creating the divergence being repaired — and
    reported (for its fate since #501, see the next paragraph). Rejected: an `EXCLUDE USING gist
    (translation_group WITH =, position WITH <>)` (`btree_gist`), safe under concurrency, but DBAL
    introspects its index, which no mapping declares, so every `doctrine:migrations:diff` would propose
    a `DROP INDEX` — DBAL reads neither triggers nor functions; a `CHECK`, which cannot read other rows;
    logging in `OrderAssigner` alone as the main guard (late, a logger in a pure Domain service, SQL writes
    still unseen — its refusal as a net behind the trigger is #503's, below);
    a periodic check (`watch-refresh`, a preprod smoke test), as late. A new localized context adds its
    table to a **new** migration creating the same trigger: `TranslationGroupSinglePositionTest` fails on
    any `TranslatableContent` entity whose table lacks it, and probes each context of
    `ProvidesLocalizedAdministrators` (`UPDATE` of a position or a group, `INSERT`, a transient gap, a real
    `reorder()`); `tests/Migrations/Version20261012120000Test` pins the alignment and its warnings. A test
    that must write a divergent group does it in a transaction it rolls back: a deferred trigger never
    fires there — or, to run a real `reorder()` on it (the `Administrator` refuses an open transaction,
    #526), commits it from a probe session under `SET session_replication_role = replica` (#503).
    **What the trigger does not see, and the net behind it** (issue #503). These writes can still leave a
    group divergent: two concurrent SQL transactions that skip the scope lock (the accepted limit above);
    a write with triggers disabled — `SET session_replication_role = replica` (superuser only),
    `pg_restore --disable-triggers`, `ALTER TABLE … DISABLE TRIGGER`; a migration that drops and recreates
    the trigger while writes of its own transaction await their check (#505, above). (The category hole is
    closed by #501 and #561, below.) Before #503 the next `reorder()` of the scope met such a group and
    **repaired it silently**: `OrderAssigner` groups the scope by key and writes one position on every
    member without reading theirs, so the evidence of the upstream defect vanished — exactly what #390 set
    out to prevent. Since #503 `Orderable` exposes `getPosition()` (lifted from `TranslatableContent`;
    `WatchedProduct`, keyed by its own id, has one entity per key and can never diverge) and
    `OrderAssigner::assign()` refuses a scope in which one key's entities disagree, with
    `TranslationGroupHasSeveralPositionsException::forOrderingKey()` — the group, the positions found and
    the fix in the message, a `critical` 500, nothing written, the manager left open —, **before** the
    exact-set 422s: a pipeline defect before a client error, as in #384 (a 422 first, an `info` stale list,
    would hide it until the next save after the reload). It reads the scope the caller
    already loaded under the lock: in memory, O(n), no query, no extra wait under the lock. It refuses
    **any** reorder of that scope, whatever the order asked, like #561's spread groups: the group is repaired
    first. It is a **net, not a detector**: nothing reports the group until someone reorders its scope (the
    public pages rank FR and EN differently meanwhile). **Repair**: in the backoffice, detach the entry whose
    position is wrong (« Version de » set to « — aucune — »: a new group, the end of its scope, #169), then
    attach it back to its group through « Version de » (`reattach()` inherits the group's position, the
    remaining member's — which entry is detached decides which position the group keeps); or in SQL, the
    members aligned in one transaction. Then reorder. The message names the content type (hence the
    table), the group and the positions found; the backoffice user only sees a generic 500, the fix is in
    the `critical` log. Rejected: a periodic check, in a CronJob (no alert reads the CronJobs'
    logs: a `warning` there reaches nobody); a query in the preprod smoke test (preprod is only written by
    the `Administrator`s, which the trigger already guards, and it would be a new Job); a warning in the
    migration Job (the deployment path, for a content defect, and it sees only the state at deploy time).
    #390's rejection of « logging in `OrderAssigner` » (above) aimed at a **main** guard, late and logged; a
    refusal serving as a net behind the trigger is not that — it logs nothing (a pure Domain service), it
    throws. `OrderAssignerTest` pins the refusal, nothing moved, and its precedence over the three 422
    cases (unknown, duplicated, missing key); `TranslationGroupSinglePositionTest` the eight contexts of
    `ProvidesLocalizedAdministrators` (the probe commits the divergent group triggers disabled — the test
    role is a superuser in dev and CI —, the real `reorder()` is refused under the name, no `previous`, the
    rows unchanged, the manager open; red before: the reorder repaired the group). Whether the production
    application role could itself disable the triggers: #575.
    **Nor a me-card group across two categories** (issue #501). Me-cards are ordered per category, and the
    members of a group share theirs: the application guarantees it (no category setter on `AboutMeCard`,
    `AboutMeCardAdministrator::membersInCategory()` reads a group within the category, the seed groups per
    category), the database did not — the #390 trigger compared positions across categories and did not
    listen to `category`, so an EN version inserted in SQL into another category at the same position, or
    an `UPDATE` of `category` alone, went through. Such a group got two ranks, one per category (FR and EN
    ranked differently on the public page); « Détacher » saw only the entry and did nothing (`detach()` is
    a no-op on an entry alone in its category); and a `reorder()` of either category was refused only
    when it moved the stray member, under the misleading name of the positions. Migration
    `Version20261012160000` keeps **one function and one trigger name**: the function reads its table's
    scope column from `TG_ARGV[0]` (none for the seven single-scope tables, untouched), one query returns
    `MIN`/`MAX` of the positions and the number of distinct scopes (the list of scopes, for the message, is
    read only on a refusal), and the scope check runs **before**
    the position check — a group both spread and on two positions is refused for the cause, not its
    consequence — raising SQLSTATE **`23T91`**, which `TranslationGroupRefusal` names
    `TranslationGroupAcrossSeveralScopesException` when the scope lock's COMMIT gets it (`Domain/Exception`, a
    `LogicException`, `refusedAtCommit()`, unmapped: a `critical` 500, a pipeline defect, like the
    positions' one; both SQLSTATEs map in one `match` of `TranslationGroupRefusal::nameOf()`, the lock's
    `onCommitRefused` since #506). No common parent to the two exceptions (#506): nothing catches them
    together, the positions' one also comes from `ContentPlacement::inGroup()` before any write, and the
    shared one-line factory is not worth a type. On `about_me_card` the trigger is recreated `AFTER INSERT OR UPDATE OF
    position, translation_group, category … EXECUTE FUNCTION portfolio_translation_group_single_position('category')`.
    Moving a whole group to another category in one transaction stays accepted (deferred). Same accepted
    limit as the positions: two concurrent SQL transactions that skip the scope lock can each add a member
    in another category without seeing the other's, and both commit. Rejected: a
    second function or trigger (two reads of the group per row at COMMIT, two names for `SET CONSTRAINTS`);
    a check in `reorder()` alone (sees only what goes through it, late, SQL writes still unseen); doing
    nothing (the misleading 500 and the two ranks stayed). **Groups already spread are not corrected** (no
    category is surely the right one): the migration reports each again as a `warning` with its categories,
    and any write that touches one (insertion, a member's position, group or category) is now refused —
    a `DELETE` does not wake the trigger. The fix is in the backoffice: **delete the stray entry, then
    recreate its version in the right category**, or **« Détacher » it** (issue #561, below).
    **The application refuses such a group by name, before any write** (issue #561). The backoffice
    still offered to write into it: « Créer la version EN » in the category where EN was missing, and the
    « Version de » selector (which lists the form's category only) on a `PUT`, both saw the group filtered by
    category, let `inGroup()` accept a locale the group already carried elsewhere, and ended on the unique
    `(translation_group, locale)` index — a raw 500 that closed the EntityManager; a `reorder()` wrote, then
    was refused at the COMMIT (`23T91`), and only when it moved the stray member. Now
    `AboutMeCardAdministrator` throws `TranslationGroupAcrossSeveralScopesException::forGroup()` (same
    `critical` 500, the group, its categories and the fix in the message, nothing written, the manager left
    open): on a creation or an attachment, `membersInCategory()` reads the group across categories (the
    same query as before, no extra cost) and refuses a group with members both in the category and outside
    it — a group wholly in another category stays the 422 `unknown-translation-group`; on a `reorder()`,
    `refuseGroupsSpreadOutsideOf()` refuses **any** reorder of a category holding such a group, whatever the
    order asked (it depends on the state only), through `findInOtherCategoriesSharingAGroupWith()` — one
    query under the lock, empty on a sound database — so a reorder that would have left the stray member in
    place, accepted before, is refused too: the group must be repaired first. Two writes are **not**
    refused, on purpose: a `PUT` sending the card's **own** group (what the frontend sends on a text edit of
    a member, `hasSibling()` looking across categories) is a no-op the administrator tests before reading
    anything, so the cards to be corrected stay editable; and « Détacher » (`translationGroup: null`) reads
    the card's group across categories, so on a spread member it **really detaches** (a new group, the end of
    its category, #169) — the trigger checks only the new group, alone, and the old one keeps its other
    member: a second fix, where it used to be a silent no-op. Either fix repairs the **group**, not the
    other category's numbering: the member left there keeps the position it was given in the first one,
    which may already be another key's (it was before the fix too); a reorder of that category, accepted
    again once no spread group remains, renumbers it. **Order of the guards** on an ordered write,
    each before any write: a group across categories (`AboutMeCardAdministrator`, me-cards only, the only
    per-scope table — kept out of the Shared services, which only see their scope), then a group on
    several positions (`ContentPlacement::inGroup()` on a placement; `OrderAssigner::assign()` on a
    `reorder()`, since #503), then the client's errors (the 409 `translation-already-exists`,
    `OrderAssigner`'s 422s of the exact-set rule), then the trigger at COMMIT for what bypasses the application — the cause before its
    consequence, as in the trigger, and a pipeline defect before a client error, as in #384.
    `AboutMeCardAdministratorTest` pins each path with mocks (no `save()`, nothing changed in memory before
    the refusal, the text edit that reads nothing, the real detachment, the reorder refused before an
    unknown key's 422); `AboutMeCardGroupSpreadOverTwoCategoriesTest` on the real database (creation,
    attachment and a reorder moving the stray member refused under the name with no `previous` and the
    manager open — red before with the unique violation and the COMMIT refusal —, a reorder leaving it in
    place refused too — accepted before —, detachment accepted at the COMMIT) and `AboutMeCardRepositoryTest` the
    strays query. Both commit the spread group through `tests/Portfolio/About/Support/CommitsSpreadMeCardGroup`:
    the administrator refuses an open transaction (#526), so a rolled-back one is not an option; it disables
    the trigger, inserts and re-enables it **in one committed transaction** (PostgreSQL's DDL is
    transactional, no other session sees the table without its trigger, and the inserts leave no pending
    event, hence no 55006), and the test's `tearDown` deletes the rows. `TranslationGroupSinglePositionTest`
    pins the insertion and the move of one member (refused in `23T91`), a group spread and on two
    positions (`23T91`, not `23T90`), the whole group moved (accepted), and, for every table, the scope
    argument and the watched columns (`SCOPE_COLUMNS`: a new per-scope table goes there and in its
    migration); `PostgresAdvisoryOrderScopeLockTest` the name on the application path;
    `tests/Migrations/Version20261012160000Test` the warning, the untouched group and `down()`.
    **Every write of an ordered content's `Administrator` runs under its scope's lock** (issues #389, #499):
    `create()`, `update()`, `delete()` and `reorder()` of the eight localized `Administrator`s and of
    `WatchedProductAdministrator` wrap their **whole body** in
    `Shared/Application/OrderScopeLockInterface::withLock(ORDER_SCOPE, …)`, `ORDER_SCOPE` being the entity
    class (`<Entity>::class`, never a table name, which the Application layer has no business knowing; the
    whole table for `AboutMeCard` too, wider than its per-category ordering scope, on purpose). Without it a "Create
    the XX version" landing between a `reorder()`'s read and its save kept the old position (a group on two
    positions, invisible to the exact-set rule), and two simultaneous creations without a group got the same
    `atEndOf()`. `delete()` and the watch catalogue's `update()` place nothing, and are locked since #499 for
    another reason: outside the lock, a deletion committed between an operation's re-read (below) and its
    `flush()` left a `PUT`'s `UPDATE` touching no row, answered with a 200 on a vanished entry — and the
    watch `update()`, with neither lock nor re-read, "updated" a product deleted since its loading.
    The adapter, `Shared/Infrastructure/Doctrine/PostgresAdvisoryOrderScopeLock`, runs the body
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
    **That detached instance is never saved again** (issue #548): `UnitOfWork::doPersist()` takes an instance it
    does not manage for **new** — it assumes `STATE_NEW` and reads nothing — so a caller still holding it and
    calling `<Repository>::save()` re-inserted the deleted entry, no error, no trace (measured before the fix on
    the eight localized contexts, the watch catalogue and the technologies). The adapter detaches through
    `Shared/Infrastructure/Doctrine/VanishedEntities::detachVanished()`, which remembers the instance in a
    `WeakMap` (nothing kept in memory); its `prePersist` listener — emitted only for an instance Doctrine takes
    for new, exactly the re-insertion path — refuses it with `VanishedEntitySavedAgainException` (a
    `LogicException`, unmapped, a `critical` 500: a caller's defect), before anything is scheduled. No false
    positive: the map holds objects, not ids nor classes, and only an object passed to `detachVanished()` is in
    it. No caller did it (the `Administrator`s re-find their entry under the lock and only receive ids, processors
    call no repository, seeds re-save nothing): the guard makes the rule hold by construction, as #526 did,
    instead of relying on the next caller. Every lock that detaches a vanished row goes through
    `detachVanished()`, never `detach()` — the technology row lock too (#545, below), and the account
    administration lock (#551, `.claude/rules/security-user.md`); only the container's
    instance listens (a hand-built one refuses nothing, so tests take it from the container). Rejected: a check in
    each repository's `save()` (the UnitOfWork cannot tell a detached entity from a new one with a natural id and
    no row; it would need the same registry in every repository); removing instead of detaching (a phantom
    `DELETE` at the flush, then the instance is new again); `markReadOnly` (`find()` would still return it,
    reopening #402). **Not covered, accepted**: an instance detached otherwise — `close()` (#502, #547, #549) or
    `clear()` — is not remembered; on the same closed manager its `persist()` already fails with
    `EntityManagerClosed`, only a reset manager would take it, and no worker writes an entity a lock detaches.
    **An instance this same manager removed and flushed is covered since #557**: `UnitOfWork::executeDeletions()`
    wipes its state after the `DELETE` but leaves the UUID the constructor assigned, so Doctrine took it for new too
    and a re-save re-inserted the row at the same id (measured before the fix on the eight localized contexts, the
    watch catalogue and the technologies, through each `Administrator::delete()`). A second listener on
    `postRemove` — emitted at the end of the flush's deletions, before the COMMIT — remembers the instance in the
    same `WeakMap`, which keeps the **cause** (`VanishedEntityCause`: vanished under a lock / removed by this
    manager) so the refusal names it (`VanishedEntitySavedAgainException::vanishedUnderLock()` /
    `removedByThisManager()`); every class, like `prePersist`. `remove()` then `persist()` **before** the flush is
    untouched: the removal is cancelled (`STATE_REMOVED` becomes managed again, no `prePersist`, no `postRemove`).
    Rejected: an `onFlush` check (it would need the same registry, and refuses only once the `INSERT` is
    scheduled), a guard in each repository's `save()`, a list of classes, documenting only. **Accepted limits**:
    restoring an entity by re-persisting the removed instance is refused with no escape hatch — a restoration
    creates a new entity; a ROLLBACK after the deleting flush leaves the instance remembered while the row is
    back — the lock skeleton closes the manager when it sees that ROLLBACK (the operation threw after writing, or
    the database refused the COMMIT, #547/#549), but not on a COMMIT DBAL itself refuses (`CommitFailedRollbackOnly`)
    nor in a transaction opened and rolled back by hand: there, without the guard, the `INSERT` would hit the
    primary key at the flush; the guard refuses it first, under a message that still thinks the row deleted; an entity with a **generated** id would
    be refused wrongly (`executeDeletions()` nulls its id, its re-persist is a new `INSERT`) — none has one, revisit
    the day one does; a DQL or SQL deletion emits no `postRemove`, its in-memory instance is not remembered (still
    managed, it never reaches `prePersist`; no such path re-saves it).
    Before, `find()` handed back the identity-map instance and a query did not rehydrate a managed one: a
    concurrent write committed in between was silently undone (`reattach()` saw a no-op, `detach()` looked in the
    group the entry had left, a version created in a group took the position the group was leaving, a deleted
    entry was "updated" with a 200). Not `refresh()` entry by entry: it does not see a deleted row (it hydrates
    nothing and keeps the instance) and costs a query each. Not detaching the whole scope either: an instance
    held from before and saved afterwards collides with the one the operation reloaded
    (`EntityIdentityCollisionException`, measured on four resource tests). Consequence for a caller: an unwritten
    change to such an instance is overwritten. An instance scheduled for insertion or removal is neither re-read
    nor detached (detaching it silently cancelled the pending `INSERT`). Since `delete()` takes the lock
    (#499), an application deletion runs wholly before an operation of its scope — which then reports the
    entry not found — or wholly after it, never in between: the loser of two simultaneous `DELETE`s of one
    entry answers **404** (it answered 204, its `DELETE` touching no row), like a `DELETE` arriving later.
    **Not covered, accepted**: a deletion that skips the `Administrator`s — the `--force` purge of the
    `app:*:seed` commands (`<Repository>::remove()`, no lock: an operator's deliberate overwrite, refused
    without `--force`) and SQL — can still commit during an operation, whose `PUT` then answers 200 on a row
    gone. A command chaining N operations on one EntityManager re-reads O(N²) rows,
    negligible for ten-entry seeds. It relies on READ COMMITTED (PostgreSQL's default, set nowhere):
    under REPEATABLE READ the snapshot would be taken by the lock's own `SELECT`, before the wait. Last writer
    wins, like every other field; no `#[ORM\Version]`. `ScopeLockReadsCommittedStateTest` pins both (the four
    stale-state cases over the same `ProvidesLocalizedAdministrators` list as `OrderScopeLockCoverageTest`, so a
    new localized context goes there once, the second `DELETE` of an entry deleted meanwhile, the watch
    catalogue's end, its `PUT` and `DELETE` of a product deleted meanwhile, the pending insert, the re-save of an
    entry or a product detached by the re-read, refused instead of re-inserted (#548), and the isolation level);
    `VanishedEntitiesTest` pins the listener's wiring, its refusal before scheduling, no false positive and the
    `WeakMap` (red with an `SplObjectStorage`, measured); since #557 also the refusal of an instance this manager
    removed, under its own message, the removal cancelled before the flush, and no memory kept on the
    `postRemove` path. The re-save after each `Administrator::delete()` is pinned by
    `ScopeLockReadsCommittedStateTest` (localized contexts, watch catalogue) and
    `PostgresExperienceTechnologyRowLockTest`.
    In an FPM worker the wait is bounded by `PGOPTIONS` `lock_timeout=5s`; past it the adapter throws
    `OrderScopeLockTimeoutException` (named, unmapped: a `critical` 500 — 5 s for a millisecond write is an
    anomaly, not a conflict to retry), and only for its own lock statement: a timeout inside the operation
    stays the DBAL exception. Since #499 that covers a backoffice `DELETE` and a watch `PUT` too, which never
    waited before: each now costs the lock, a transaction and the re-read of its entry, and comes out as that
    500 behind a writer holding the scope past 5 s. **The console is bounded too** (#512, #525): the
    `app:*:seed` commands go through the `Administrator`s, so the deploy's seed Job takes the same lock, which
    `www.prod.conf` does not reach — its pod's own `PGOPTIONS` gives it `lock_timeout=5s`, and a session with
    none (dev, CI) gets `PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT`. Every holder keeps the lock for
    milliseconds; revisit before holding it any longer. Guards:
    `OrderScopeLockCoverageTest` (a probe session holds each scope, every write of every
    `Administrator` must wait — a new one written without `withLock()` turns it red) and
    `ContributionOrderConcurrencyTest` (two real processes, interleaved at the `preFlush`: both races of #389,
    and a `DELETE` during a `PUT`, #499 — the child reports the title it deleted, the one the `PUT` wrote
    once it waits). A deletion leaves a gap, never a collision. The **only client-driven
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
  console commands (content already there: they decline, unless `--force`, which purges the whole table — every
  locale, spec 0004 D3 — then recreates; **`AboutSettings` is created when missing, rewritten with `--force`
  only, never purged**, see below).

  **`AboutSettings` has no lock, by decision** (issue #553). It is the singleton per locale of the About page's
  section texts, and **nothing in the application deletes it**: `AboutSettingsRepositoryInterface` has no
  `remove()`, the backoffice resource only `Get`/`Put`, and `app:about:seed` never purges it (`ensureSettings()`:
  a direct `save()` when the locale has no row; `update()` through the `Administrator` with `--force` only;
  otherwise the row is kept as edited). Its `countExisting()` leaves it out, so with no card left the
  command runs without `--force` — it then recreates the cards and creates missing settings, but **never
  rewrites existing ones** (issue #568: before, that run reset the five section texts edited in the
  backoffice, the one rewrite of an existing row by a guarded seed, contrary to `.claude/rules/seeding.md`).
  `SeedAboutContentCommandTest` pins kept, created and `--force`-reset settings. The defect of #499/#545 — a
  `PUT` answering 200 on a row deleted since the provider loaded it — does not arise, because `AboutSettingsAdministrator::update()` re-reads the database:
  `findByLocale()` is a `findOneBy()`, a search by criteria whose `SELECT` Doctrine **always** runs, so a row
  gone is `null` (**404**, `AboutSettingsNotFoundException`) and a row recreated under another id is the one
  written — unlike `find($id)`, which hands back the identity-map instance without a query (#545). Its fields
  are not refreshed, but `AboutSettings::update()` overwrites all five editable ones, and `id`/`locale` never
  change. **Keep that search by criteria**: a `find($id)`, a second-level or result cache, or a memoizing decorator
  without a lock reopens the 200 on a vanished row. **Accepted**: a deletion in SQL — or a deletion and re-creation under
  another id — committed between that `SELECT` and the `flush()`'s `UPDATE` still answers 200, the `UPDATE` on the
  old id touching no row (the write is lost; milliseconds, SQL only, very low severity); two simultaneous `PUT`s of one locale, last
  writer wins, like every field (no `#[ORM\Version]`); two concurrent creations (two seeds) meet the unique index
  `uniq_about_settings_locale`; the seed's settings writes run outside any lock, on purpose (no position, no
  group). **Trigger**: an application deletion of the settings (a seed purge, a `DELETE`) first requires the
  row lock of #545 (`ExperienceTechnologyLockInterface`'s pattern on `PostgresLockedTransaction`).
  `AboutSettingsUpdateReadsCommittedRowTest` pins it: settings loaded as the provider does, deleted in SQL → not
  found; deleted then re-inserted under a new id → the new row is written (both red with a read from the identity
  map, measured); and the trigger itself — the repository interface exposes exactly `findByLocale()`/`save()`, the
  backoffice resource exactly `Get`/`Put` (red with a `remove()` or a `Delete` added, measured). Turning it red
  means adding the lock first, then updating the test. That guard is partial: an `EntityManager::remove()`, a DQL
  or DBAL `DELETE` written elsewhere (a command, another processor) does not trip it — review is the net there.

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

  **A technology's `PUT` and `DELETE` run under its row lock** (issue #545). The defect was the one #402 and
  #499 fixed for ordered content: the provider loads the technology before the processor, `find()` hands back
  that identity-map instance, and a deletion committed in between left the `UPDATE` or `DELETE` touching no
  row — Doctrine counts affected rows only for a versioned entity —, so the `PUT` answered **200** on a
  vanished technology and the loser of two simultaneous `DELETE`s **204**. `ExperienceTechnologyAdministrator`'s
  `update()` and `delete()` now wrap their **whole body** in `Application/ExperienceTechnologyLockInterface::whileLocked($id, …)`;
  the adapter, `Infrastructure/Doctrine/PostgresExperienceTechnologyRowLock`, runs it on the shared skeleton
  (`Shared/Infrastructure/Lock/PostgresLockedTransaction`, like the order scope lock and the two account
  locks): **one** DQL `SELECT … FOR UPDATE` (`LockMode::PESSIMISTIC_WRITE`) with `Query::HINT_REFRESH` locks the
  row, says whether it still exists (`null`) and refreshes the managed instance in place, handed to the
  operation. Row gone: the stale instance is detached — unless it is scheduled for insertion or removal,
  whose pending write detaching would cancel, the #402 filter — through `VanishedEntities::detachVanished()`,
  so a caller still holding it cannot save it again (issue #548, see the ordering section), and the
  technology is not found (**404**, mapping
  unchanged; the admin frontend already reads it as `not-found`). So a deletion runs wholly before the write or
  wholly after it. Not `find($id, LockMode::PESSIMISTIC_WRITE)`: on an instance already managed it is a
  `refresh()` with `FOR UPDATE`, which hydrates nothing when the row has gone and returns the stale instance —
  existence must be read explicitly. A **row** lock, not the order scope lock: nothing is placed (no position,
  no translation group), only the written row is held — two technologies never wait for each other — and,
  unlike an advisory lock, PostgreSQL enforces it on every `UPDATE`/`DELETE` of the row, so a deletion in SQL
  waits too. `create()` (`ExperienceTechnologyRegistrar`) stays outside: nothing can vanish under it, and its
  race is the unique index on `name`. The name check of `update()` runs under the lock but only holds that row:
  the unique index stays the last net, its violation still converted to 409 (the failed `flush()` closes the
  EntityManager, as before). Its wait is bounded like the other locks of the skeleton (`lock_timeout=5s` under
  FPM, the skeleton's fallback elsewhere); past it, `ExperienceTechnologyLockTimeoutException` (unmapped, a
  `critical` 500); inside a transaction already open, `ExperienceTechnologyLockInsideTransactionException`
  before anything is locked. **A refused COMMIT**: no `onCommitRefused` is passed, there is nothing to name —
  the table has no deferred constraint (the unique name and the `years` `CHECK` are immediate and refuse at
  the `flush()`), so only a session lost at that moment can refuse it; the `DriverException` surfaces as is
  and the skeleton **closes the EntityManager** (issue #547, as for every lock of the skeleton, see the
  ordering section above): its UnitOfWork held as written what the refusal had just rolled back; an exception
  the operation throws after a write closes it too (issue #549, same section); the 409 of a name already
  taken leaves it open when it comes from the check before the `save()`, while the unique index's 409 (two
  racing `PUT`s) comes after a failed `flush()`, which has already closed it. Cost per
  `PUT`/`DELETE`, all under the lock: `BEGIN`, the lock-bound `set_config`, the locked read, the `flush()`'s
  `SAVEPOINT`, write and `RELEASE SAVEPOINT`, `COMMIT` — sub-millisecond round trips each. Guards: `ExperienceTechnologyWritesUnderRowLockTest` (a technology loaded as the
  provider does, then deleted in SQL: `update()` and `delete()` say not found; a probe session holds the row,
  and each write must fail on the lock's named timeout — without the lock, the `flush()` waited on the row too
  but failed as a bare `DriverException`, having decided on the provider's instance) and
  `PostgresExperienceTechnologyRowLockTest` (re-read, not found and detached, its re-save refused rather than
  re-inserted (#548), a pending insert not found but
  kept, a `flush()` refused inside the operation rolled back and surfacing as is — the path of the 409 —, wait
  bound, named timeout, nesting refused, a COMMIT refused after a successful `flush()` closing the EntityManager
  and a new read seeing the database, #547). No two-process test (decided for #545): the probe already proves the write waits for the
  row before reading it.

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
