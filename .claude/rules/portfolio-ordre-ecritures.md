---
paths:
  - "backend/src/Portfolio/**"
  - "backend/tests/Portfolio/**"
  - "frontend/src/presentation/pages/**"
  - "frontend/src/presentation/ui/**"
---

# Backend — `Portfolio` ordering: application refusals and writes under the scope lock

> Split out of `.claude/rules/portfolio-contenus.md` (#605), whose index lists every `Portfolio` content rule. These rules live in `Portfolio/Shared/` and apply to the eight localized entities with a position. The first paragraph (« Nor a me-card group… ») continues « What the trigger does not see » of `.claude/rules/portfolio-ordre-migrations.md`. The index of every rule is at the top of `.claude/CLAUDE.md`.

**Nor a me-card group across two categories** (issue #501). Me-cards are ordered per category, and the
members of a group share theirs: the application guarantees it (no category setter on `AboutMeCard`,
`AboutMeCardAdministrator::membersInCategory()` reads a group within the category, the seed groups per
category); the database must too. The #390 trigger alone compares positions across categories and does
not listen to `category`, so an EN version inserted in SQL into another category at the same position, or
an `UPDATE` of `category` alone, goes through it. Such a group gets two ranks, one per category (FR and EN
ranked differently on the public page); « Détacher » limited to the category sees only the entry and does
nothing (`detach()` is a no-op on an entry alone in its category); and a `reorder()` of either category is
refused only when it moves the stray member, under the misleading name of the positions. Migration
`Version20261012160000` keeps that trigger's **one function and one trigger name**: the function reads its table's
scope column from `TG_ARGV[0]` (none for the seven single-scope tables, untouched), one query returns
`MIN`/`MAX` of the positions and the number of distinct scopes (the list of scopes, for the message, is
read only on a refusal), and the scope check runs **before**
the position check — a group both spread and on two positions is refused for the cause, not its
consequence — raising SQLSTATE **`23T91`**, which `TranslationGroupRefusal` names
`TranslationGroupAcrossSeveralScopesException` when the scope lock's COMMIT gets it (`Domain/Exception`, a
`LogicException`, `refusedAtCommit()`, unmapped: a `critical` 500, a pipeline defect, like the
positions' one; both SQLSTATEs map in one `match` of `TranslationGroupRefusal::nameOf()`, the lock's
`onCommitRefused`, #506). No common parent to the two exceptions (#506): nothing catches them
together, the positions' one also comes from `ContentPlacement::inGroup()` before any write, and the
shared one-line factory is not worth a type. On `about_me_card` the trigger is recreated `AFTER INSERT OR UPDATE OF
position, translation_group, category … EXECUTE FUNCTION portfolio_translation_group_single_position('category')`.
Moving a whole group to another category in one transaction stays accepted (deferred). Same accepted
limit as the positions: two concurrent SQL transactions that skip the scope lock can each add a member
in another category without seeing the other's, and both commit. Rejected: a
second function or trigger (two reads of the group per row at COMMIT, two names for `SET CONSTRAINTS`);
a check in `reorder()` alone (sees only what goes through it, late, SQL writes still unseen); doing
nothing (the misleading 500 and the two ranks would stay). **Groups already spread are not corrected** (no
category is surely the right one): the migration reports each again as a `warning` with its categories,
and any write that touches one (insertion, a member's position, group or category) is refused —
a `DELETE` does not wake the trigger. The fix is in the backoffice: **delete the stray entry, then
recreate its version in the right category**, or **« Détacher » it** (issue #561, below).
**The application refuses such a group by name, before any write** (issue #561). Otherwise « Créer la
version EN » in the category where EN is missing, and the « Version de » selector (which lists the
form's category only) on a `PUT`, would see the group filtered by category, let `inGroup()` accept a
locale the group already carries elsewhere, and end on the unique `(translation_group, locale)` index —
a raw 500 that closes the EntityManager; a `reorder()` would write, then be refused at the COMMIT
(`23T91`) — and only when it moves the stray member, the database letting through one that leaves it in
place. `AboutMeCardAdministrator` throws `TranslationGroupAcrossSeveralScopesException::forGroup()` (same
`critical` 500, the group, its categories and the fix in the message, nothing written, the manager left
open): on a creation or an attachment, `membersInCategory()` reads the group across categories (the
same query, widened: no extra cost) and refuses a group with members both in the category and outside
it — a group wholly in another category stays the 422 `unknown-translation-group`; on a `reorder()`,
`refuseGroupsSpreadOutsideOf()` refuses **any** reorder of a category holding such a group, whatever the
order asked (it depends on the state only), through `findInOtherCategoriesSharingAGroupWith()` — one
query under the lock, empty on a sound database — so a reorder that would leave the stray member in
place is refused too: the group must be repaired first. Two writes are **not**
refused, on purpose: a `PUT` sending the card's **own** group (what the frontend sends on a text edit of
a member, `hasSibling()` looking across categories) is a no-op the administrator tests before reading
anything, so the cards to be corrected stay editable; and « Détacher » (`translationGroup: null`) reads
the card's group across categories, so on a spread member it **really detaches** (a new group, the end of
its category, #169) — the trigger checks only the new group, alone, and the old one keeps its other
member: a second fix. Either fix repairs the **group**, not the
other category's numbering: the member left there keeps the position it was given in the first one,
which may already be another key's (a collision the fix does not introduce: leave `detach()` alone); a
reorder of that category, accepted
again once no spread group remains, renumbers it. **Order of the guards** on an ordered write,
each before any write: a group across categories (`AboutMeCardAdministrator`, me-cards only, the only
per-scope table — kept out of the Shared services, which only see their scope), then a group on
several positions (`ContentPlacement::inGroup()` on a placement; `OrderAssigner::assign()` on a
`reorder()`, #503), then the client's errors (the 409 `translation-already-exists`,
`OrderAssigner`'s 422s of the exact-set rule), then the trigger at COMMIT for what bypasses the application — the cause before its
consequence, as in the trigger, and a pipeline defect before a client error, as in #384.
**Two client errors come first, on purpose, both read from the body alone: a `PUT` carrying another
locale (issue #582, end of this paragraph), then one carrying another category** (issue #578). A card
never changes category (no setter, see #501 above); a `PUT` that validated `category` and ignored it
would answer 200 — and a form whose category change empties « Version de » would send
`translationGroup: null` with it, so the card would be silently detached and sent to the end of its
category. `AboutMeCardAdministrator::update()` receives the category and throws
`AboutMeCardCategoryIsImmutableException` (`About/Domain/Exception`, 409, `type`
`me-card-category-immutable`, `log_level: info`, modelled on the watch slug's
`WatchedProductSlugIsImmutableException`) right after re-reading the card under the lock, before any
other read and any change in memory (#549), hence before `detach()`/`reattach()` and before the
spread-group refusal. That does not contradict #384: #384 forbids a client error that **results from**
the corrupt state from hiding it, while this one depends on the request body alone; a spread group is
still refused under its name by the next valid request. The frontend disables the field in edit
(`.claude/rules/frontend-backoffice.md`). **The locale, on the eight contexts** (issue #582): an entry
never changes language — it is its group's version in that language, unique there —, and disabling
the field in the form (#170 R2) does not stop a `PUT` from sending another one. Every
`<X>Administrator::update()` receives the locale and throws
`TranslatableContentLocaleIsImmutableException` (`Portfolio/Shared/Domain/Exception`, **one** exception
for the eight, like `TranslationAlreadyExistsException`: one rule, one `type` a client keys on —
`locale-immutable`, 409, `log_level: info`, a literal message) right after re-reading the entry under the
lock, in the same place and for the same reasons as the category; on a me-card **before** the category
(the rule shared by the eight first, the card's own second — both depend on the body alone,
`testTheLocaleRefusalComesFirstAndBeforeAnyChange`). The admin frontend does not map it (the field is
disabled in edit, nothing in the UI can send it; a 409 of that `type` would read as
`translation-already-exists`, accepted). Rejected: a check in the processors through `previous_data`
(a business rule in Infrastructure, read before the lock), an edit DTO without `locale` (a 400 on every
body carrying it, the same locale included: a contract break for every client). Pinned by
`tests/Portfolio/Shared/Application/LocaleIsImmutableOnUpdateTest` over `ProvidesLocalizedAdministrators`
(whose `update` takes an optional locale, the entry's by default — a new context is covered there once:
refused under the name, the row unchanged, the manager open, and before the 409
`translation-already-exists`) and by each `Backoffice<X>ResourceTest` (the typed 409).
`AboutMeCardAdministratorTest` pins each path with mocks (no `save()`, nothing changed in memory before
the refusal, the text edit that reads nothing, the real detachment, the reorder refused before an
unknown key's 422, the category refusal before any read and before the spread-group one, #578);
`BackofficeAboutMeCardResourceTest` the 409 on a card with a translation (the body of a form changing
the category), on a lone card, and with a group of the requested category (the
guard order at the HTTP layer: a 422 `unknown-translation-group` before); `AboutMeCardGroupSpreadOverTwoCategoriesTest` on the real database (creation,
attachment and a reorder moving the stray member refused under the name with no `previous` and the
manager open, a reorder leaving it in place refused too, detachment accepted at the COMMIT) and `AboutMeCardRepositoryTest` the
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
the XX version" landing between a `reorder()`'s read and its save would keep the old position (a group on
two positions, invisible to the exact-set rule), and two simultaneous creations without a group would get
the same `atEndOf()`. `delete()` and the watch catalogue's `update()` place nothing, and are locked for
another reason (#499): outside the lock, a deletion committed between an operation's re-read (below) and
its `flush()` would leave a `PUT`'s `UPDATE` touching no row, answered with a 200 on a vanished entry —
and the watch `update()`, with neither lock nor re-read, would "update" a product deleted since its
loading.
The adapter, `Shared/Infrastructure/Doctrine/PostgresAdvisoryOrderScopeLock`, runs the body
in a transaction it opens and closes by hand (not `Connection::transactional()`, issue #390, see
`.claude/rules/portfolio-ordre.md`) whose first statement is `pg_advisory_xact_lock(ORDR, hashtext(scope))`,
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
calling `<Repository>::save()` re-inserts the deleted entry without the guard, no error, no trace (measured
on the eight localized contexts, the watch catalogue and the technologies). The adapter re-reads and detaches
through `Shared/Infrastructure/Doctrine/RereadUnderLock::everyManagedInstanceOf()` (issue #560, the one
re-read under a lock shared by every lock that re-reads, see `.claude/rules/backend-architecture.md`), which
calls `VanishedEntities::detachVanished()`; that one remembers the instance in a
`WeakMap` (nothing kept in memory); its `prePersist` listener — emitted only for an instance Doctrine takes
for new, exactly the re-insertion path — refuses it with `VanishedEntitySavedAgainException` (a
`LogicException`, unmapped, a `critical` 500: a caller's defect), before anything is scheduled. No false
positive: the map holds objects, not ids nor classes, and only an object passed to `detachVanished()` is in
it. No caller does it today (the `Administrator`s re-find their entry under the lock and only receive ids, processors
call no repository, seeds re-save nothing): the guard makes the rule hold by construction, like #526's,
instead of relying on the next caller. Every lock that detaches a vanished row goes through
`RereadUnderLock`, the only caller of `detachVanished()`, never `detach()` — the technology row lock too (#545,
`.claude/rules/portfolio-contenus.md`), the account administration lock (#551) and the account row lock (#560, `.claude/rules/security-user.md`);
only the container's
instance listens (a hand-built one refuses nothing, so tests take it from the container). Rejected: a check in
each repository's `save()` (the UnitOfWork cannot tell a detached entity from a new one with a natural id and
no row; it would need the same registry in every repository); removing instead of detaching (a phantom
`DELETE` at the flush, then the instance is new again); `markReadOnly` (`find()` would still return it,
reopening #402). **Not covered, accepted**: an instance detached otherwise — `close()` (#502, #547, #549) or
`clear()` — is not remembered; on the same closed manager its `persist()` already fails with
`EntityManagerClosed`, only a reset manager would take it, and no worker writes an entity a lock detaches.
**An instance this same manager removed and flushed is covered too** (#557): `UnitOfWork::executeDeletions()`
wipes its state after the `DELETE` but leaves the UUID the constructor assigned, so Doctrine takes it for new too
and a re-save re-inserts the row at the same id without the guard (measured on the eight localized contexts,
the watch catalogue and the technologies, through each `Administrator::delete()`). A second listener on
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
the day one does. **A DQL deletion by id is covered too** (#569): it emits no `postRemove` and leaves the
instance **managed**, so a change then a `save()` would send an `UPDATE` touching no row, lost in silence (Doctrine
counts affected rows only for a versioned entity). The two such deletions,
`PasswordSetupTokenRepository::remove()` and `CpgUserRepository::removePendingInvitation()`
(`.claude/rules/security-user.md`), run it through `VanishedEntities::deleteById(Query, entity)` (#560: the
rule written once, the manager read from the query) — the instance detached, and remembered under the
« removed by this manager » cause — **only when the `DELETE` touched the row**: a row their condition spared (a
consumed token, an account activated meanwhile) still exists and its instance stays managed. A new DQL deletion
by id goes through it. Still not covered, accepted: a **bulk** DQL deletion
(`deleteForUser()`, `deleteUnusedForUser()`), an SQL one and an `ON DELETE CASCADE` detach nothing (none of
those paths has an instance loaded); a deletion by id that touched no row because the row was already gone
detaches nothing either (nothing tells it apart from a spared one); and `deleteById()` inside a
transaction later rolled back remembers an instance whose row is back, with the manager left open, since the
skeleton's `WrittenFlushWatch` does not see a DQL write — both callers delete outside any transaction; a
still-managed entity **referencing** a detached account (a loaded token) makes the next `flush()` fail with
Doctrine's « A new entity was found through the relationship… » — loud, not lost, and no path loads a token
before `removePendingInvitation()`.
Without the re-read, `find()` hands back the identity-map instance and a query does not rehydrate a managed
one: a concurrent write committed in between would be silently undone (`reattach()` sees a no-op, `detach()`
looks in the group the entry has left, a version created in a group takes the position the group is leaving,
a deleted entry is "updated" with a 200). Not `refresh()` entry by entry: it does not see a deleted row (it hydrates
nothing and keeps the instance) and costs a query each. Not detaching the whole scope either: an instance
held from before and saved afterwards collides with the one the operation reloaded
(`EntityIdentityCollisionException`, measured on four resource tests). Consequence for a caller: an unwritten
change to such an instance is overwritten. An instance scheduled for insertion or removal is neither re-read
nor detached (detaching it would silently cancel the pending `INSERT`). As `delete()` takes the lock
(#499), an application deletion runs wholly before an operation of its scope — which then reports the
entry not found — or wholly after it, never in between: the loser of two simultaneous `DELETE`s of one
entry answers **404**, like a `DELETE` arriving later.
**Not covered, accepted**: a deletion in SQL, which skips the `Administrator`s, can still commit during an
operation, whose `PUT` then answers 200 on a row gone. (Covered, by contrast: the `--force` purge of the
`app:*:seed` commands runs under the scope locks of the command's `withLocks()` (#567), so a backoffice write of the scope waits
for the whole seed — a few hundred milliseconds at most — instead of landing in the middle of it.) Under
`withLocks()` the scopes are re-read once, at entry, and the re-entrant `withLock()`s re-read nothing (a
re-read per operation would cost O(N²) rows to a command chaining N operations on one EntityManager). It relies on READ COMMITTED (PostgreSQL's default, set nowhere):
under REPEATABLE READ the snapshot would be taken by the lock's own `SELECT`, before the wait. Last writer
wins, like every other field; no `#[ORM\Version]`. `ScopeLockReadsCommittedStateTest` pins both (the four
stale-state cases over the same `ProvidesLocalizedAdministrators` list as `OrderScopeLockCoverageTest`, so a
new localized context goes there once, the second `DELETE` of an entry deleted meanwhile, the watch
catalogue's end, its `PUT` and `DELETE` of a product deleted meanwhile, the pending insert, the re-save of an
entry or a product detached by the re-read, refused instead of re-inserted (#548), and the isolation level);
`VanishedEntitiesTest` pins the listener's wiring, its refusal before scheduling, no false positive and the
`WeakMap` (red with an `SplObjectStorage`, measured); also (#557) the refusal of an instance this manager
removed, under its own message, the removal cancelled before the flush, and no memory kept on the
`postRemove` path. The re-save after each `Administrator::delete()` is pinned by
`ScopeLockReadsCommittedStateTest` (localized contexts, watch catalogue) and
`PostgresExperienceTechnologyRowLockTest`.
In an FPM worker the wait is bounded by `PGOPTIONS` `lock_timeout=5s`; past it the adapter throws
`OrderScopeLockTimeoutException` (named, unmapped: a `critical` 500 — 5 s for a millisecond write is an
anomaly, not a conflict to retry), and only for its own lock statement: a timeout inside the operation
stays the DBAL exception. That covers a backoffice `DELETE` and a watch `PUT` too (#499): each costs the
lock, a transaction and the re-read of its entry, and comes out as that
500 behind a writer holding the scope past 5 s. **The console is bounded too** (#512, #525): the
`app:*:seed` commands take the same lock — once per command (#567), through `withLocks()` on every
scope the command writes, held for the whole purge and recreation —, which
`www.prod.conf` does not reach — its pod's own `PGOPTIONS` gives it `lock_timeout=5s`, and a session with
none (dev, CI) gets `PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT`. Every backoffice holder keeps
the lock for milliseconds; a seed holds its scopes for the whole command, measured on the
largest (`app:about:seed --force`, 30 cards and the settings, one `flush()` each): about 100 ms in the
test environment, the command taking 0.5 s with the kernel boot — two orders of magnitude under the 5 s
bound; revisit before holding it any longer. Guards:
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
link would survive every proofreading, an isolated row is one "Version of" click away — and, unlike
the UUID migrations, whose `down()` throws, it is **reversible**.
