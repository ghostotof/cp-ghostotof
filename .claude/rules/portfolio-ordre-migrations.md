---
paths:
  - "backend/src/Portfolio/Shared/**"
  - "backend/tests/Portfolio/Shared/**"
  - "backend/migrations/**"
  - "backend/tests/Migrations/**"
  - "backend/src/Portfolio/*/Domain/Entity/**"
  - "backend/config/packages/doctrine_migrations.yaml"
---

# Backend — `Portfolio` ordering: migrations and what the trigger does not see

> Split out of `.claude/rules/portfolio-contenus.md` (#605), whose index lists every `Portfolio` content rule. These rules live in `Portfolio/Shared/` and apply to the eight localized entities with a position. The index of every rule is at the top of `.claude/CLAUDE.md`.

**Trap for future migrations** (issue #505, measured on PostgreSQL 18, pinned by
`tests/Migrations/DeferredTriggerPendingEventsTest`). A deferred trigger leaves a pending event per
row a watched write touches, until the COMMIT, and PostgreSQL then refuses some DDL on that table in
SQLSTATE `55006` ("cannot ALTER TABLE …" / "cannot CREATE INDEX … because it has pending trigger
events"). The refusal depends on the subcommand, not on the statement: **measured refused**, the
`ALTER TABLE` subcommands `ADD`/`DROP COLUMN`, `ALTER COLUMN … TYPE`, `SET NOT NULL`, `SET DEFAULT`,
`ADD CONSTRAINT`, `DISABLE TRIGGER`, **and `CREATE INDEX`**; **measured accepted**, `ALTER TABLE …
RENAME COLUMN` (an `ALTER TABLE` too), a `COMMENT`, a `DROP INDEX`, a DDL on another table. **Any other
DDL on the table: measure it before relying on it passing** — a case in the test's providers —, and
until then wrap it in the fix below, `IMMEDIATE` right before it **and `DEFERRED` right after it**:
that changes nothing when nothing is pending, while `IMMEDIATE` left in force would refuse a group
rewritten row by row later in the migration (`23T90`, pinned). Each
migration runs in its own transaction (`doctrine_migrations.yaml`, no `all_or_nothing`), so the
trap is a write then a DDL on the same table **within one migration**. **Watched writes**: an
`INSERT` that inserts rows, and an `UPDATE` touching rows and naming a column of the trigger's
`UPDATE OF` clause — `position`, `translation_group`, plus `category` on `about_me_card` since
#501; an `UPDATE` of any other column (`SET title = …`), a `DELETE`, a statement touching no row
leave nothing pending. **The fix**: `SET CONSTRAINTS trg_translation_group_single_position
IMMEDIATE` **after** the writes, right before the DDL — it runs the pending checks there (a
divergent group is refused at that line, `23T90`, or `23T91` for a me-card group across
categories) and empties the queue —, then `SET CONSTRAINTS … DEFERRED` if more writes follow the
DDL (immediate mode stays in force for the rest of the transaction); or the DDL first, the writes
after. **Never at the top** of a migration that rewrites a group in several statements: in
immediate mode each statement is checked at its end, and the legitimate gap between two members'
`UPDATE`s is refused (`23T90`) — the old advice here said exactly that, and was wrong. **Where it
shows**: a statement only queues events for the rows it touches, and the CI migrates an empty
database (no seeds). A plain `INSERT … VALUES` always inserts, so the CI catches it followed by a
DDL; an `UPDATE`, or an `INSERT … SELECT` from a table that is empty there, touches no row, so the
CI is green and the refusal appears only on a populated database — a `make db-migrate` on a dev
database with content, or the preprod deploy (fail-closed there: the migration Job fails before
`apply -k`, see `.claude/rules/deploiement-pipeline.md`); agents' worktrees, on empty test databases, miss it
too. Hence the model of `.claude/rules/seeding.md` (a data migration tested by `tests/Migrations/`
against rows), required here: **a migration that writes into one of the eight tables has a
`tests/Migrations/` test that first puts rows in the tables it writes and alters** (rows its
statements actually touch), replaying it inside a rolled-back transaction (`ReplaysMigration`);
such a test clears its own `INSERT`s' events before replaying (`SET CONSTRAINTS … IMMEDIATE` then
`DEFERRED`), or it gets a false `55006`. **And a migration that drops and recreates the trigger
writes no data**: dropping it while writes of the same transaction await their deferred check
cancels those checks silently — a later `IMMEDIATE` checks nothing (pinned), nor does the COMMIT
(measured for #505). **No automatic guard, by decision** (#505): replaying every migration on a
populated database in CI was weighed and rejected — the hole is narrow (a watched write touching
no row in CI, then a refused DDL on the same table, in one migration), a plain `INSERT` is already
caught by the CI, the preprod refusal fails closed with the previous release still serving, and
the test on rows above catches it before the release branch. The test pins the facts, not
migrations: a PostgreSQL upgrade or a trigger change that contradicts them turns it red, and this
paragraph with it. Migrations are frozen: `Version20261012120000`'s docblock still gives the old
« `IMMEDIATE` at the top » advice — never copy it, this paragraph is the reference. Migration `Version20261012120000` reported every divergent group as
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
group divergent: two concurrent SQL transactions that skip the scope lock (the accepted limit of `.claude/rules/portfolio-ordre.md`);
a write with triggers disabled — `SET session_replication_role = replica` (superuser only),
`pg_restore --disable-triggers`, `ALTER TABLE … DISABLE TRIGGER`; a migration that drops and recreates
the trigger while writes of its own transaction await their check (#505, above). (The category hole is
closed by #501 and #561, in `.claude/rules/portfolio-ordre-ecritures.md`.) Before #503 the next `reorder()` of the scope met such a group and
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
rows unchanged, the manager open; red before: the reorder repaired the group). **The production
application role cannot disable the triggers itself** (issue #575, decided 2026-10-13): it was the
image's bootstrap superuser, owner of the tables; since #575 the application runs under `cpg_app`
(DML only, no ownership, no superuser) and the migrations under `cpg_migrator` (the owner), so
`ALTER TABLE … DISABLE TRIGGER`, `DROP TRIGGER` and `SET session_replication_role` are refused to it
(`42501`) — `ApplicationRolePrivilegesTest` proves it on the real schema, and that the trigger still
fires under that role (`SECURITY INVOKER`). The test role of dev and CI stays the superuser, on purpose
(this probe needs `session_replication_role`). See `.claude/rules/deploiement-postgres.md`, « The application
and the migrations run under two PostgreSQL roles ».
