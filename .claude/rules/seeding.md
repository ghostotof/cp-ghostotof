---
paths:
  - "backend/src/**/Presentation/Command/**"
  - "backend/migrations/**"
  - "k8s/base/seed-job.yaml"
---

# Backend — seeding (`app:*:seed`)

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Seeding ». The index of every rule is at the top of `.claude/CLAUDE.md`.

### Seeding (`app:*:seed`)

Seven commands carry the reference content: `app:{about,quality,contributions,incidents,watch,case-studies,anonymous-cv}:seed`.
They **purge and recreate** — that is how an entry removed from the reference content actually
disappears — which used to make them silently destructive on any environment whose content had been
edited through the backoffice.

Since 2026-09-08 the rule is inverted by `App\Shared\Presentation\Command\GuardsExistingContent`:
**a populated database is left alone**, and `--force` is required to replace it. Three consequences,
all deliberate:

- a fresh environment seeds itself, which is what makes automatic seeding safe;
- **prod becomes untouchable by accident** — it has content, so the command declines, even on a
  wrong-namespace mistake, which is the error that costs the most;
- a deliberate reset is still possible, but it has to be written out.

**The refusal exits 0.** This detail carries the rest: a non-zero exit would fail the seed Job — and
therefore the deployment — on every run after the first. "There is already content" is the expected
answer in nearly every execution, not an error. `SeedWatchedProductsCommandTest` pins it.

**A seed is atomic, per command** (issue #567). The count, the purge and the recreations of one command
run in **one** transaction, under the order scope lock of every entity class the command writes
(`OrderScopeLockInterface::withLocks()`, the `Administrator`s' own `withLock()` re-entering it — see
`.claude/rules/portfolio-ordre.md`): a command that fails writes nothing, and the next run — the next
deploy's Job (`backoffLimit: 0`, no retry inside a Job), or the operator's relaunch — repopulates.
Before, the purge committed row by row outside any lock and each `Administrator::create()` in its own
transaction, so a failure in the middle left a partial base — with `--force`, the previous content gone and
a few new rows; on a first seed, a few rows the guard then took for "content in place", so the preprod Job
(`backoffLimit: 0`, `set -e`, never `--force`) turned red once, and the redeploy **declined with exit 0 and
went green on a half-empty page** (in prod, where the Job is applied by hand for a new context, the same
misleading green); for About and Quality, which write two tables and count both, the second table stayed
empty for good. Three details that hold the rest: the count is re-read **under the lock** (read before,
two simultaneous seeds of an empty base both filled it); the purge is `flush()`ed row by row **before** the
first creation (in one `flush()` the UnitOfWork runs the `INSERT`s before the `DELETE`s, and the unique
`slug` index of the watch catalogue refuses the recreation); the success lines are collected in a
`SeedOutcome` and printed **after** the transaction returns, never inside it. The command passes the entity
classes its `Administrator`s lock (`ORDER_SCOPES`); a mismatch is refused at the first `withLock()` as an
`OrderScopeLockInsideTransactionException`, a `critical` 500, nothing written. The `AboutSettings` rows
(no lock of their own, #553) are written in that same transaction. Cost: the scopes are held for the whole
command — measured on `app:about:seed --force`, the largest: about 100 ms, under the 5 s `lock_timeout` by
two orders of magnitude; a backoffice write of the same scope waits that long instead of landing in the
middle of a purge (the former "accepted limit" of `.claude/rules/portfolio-contenus.md`, gone). The locks are taken one
statement each, so `lock_timeout` bounds each wait, not their sum: a two-table seed (About, Quality) behind
two busy scopes can wait up to 2 × 5 s before failing. After a failure past the
first write, the EntityManager is closed (#549): the command stops on the exception, as before.
`tests/Portfolio/Shared/Presentation/Command/SeedCommandsAreAtomicTest` pins the seven commands: a
`--force` interrupted at the second `persist()` (one creation already flushed) leaves the previous rows
with their ids; a first seed interrupted the same way leaves every table empty, and the relaunch without
`--force` fills them all — red before on every command, with one row left.

`k8s/base/seed-job.yaml` runs the seven on **every preprod deploy** (`case-studies` was missing from it
until 2026-09-13 — adding a seed command means adding its line there, the Job's comment says so). Like `migrate-job.yaml` it sits
outside `kustomization.yaml`, hence `${BACKEND_IMAGE}` + `envsubst` (`tools/run-job.sh`, issue #488). It never passes `--force`, so it
cannot repair a divergence: if the reference content changes in code, preprod keeps the old one until
someone forces it by hand. That is the price of harmlessness, and it is the right trade — a Job that
can destroy nothing beats a Job that syncs and one day picks the wrong namespace.

**A change to the *meaning* of reference data ships with a data migration** (issue #287). The
guarded seed never rewrites an existing row — by design — so changing how a seeded row must look
(a new enum value, a field that becomes derived) leaves every live environment on the old shape
forever. #19 moved five watched products from `manual` to `deployed` in the seed only: preprod and
prod kept their hand-typed versions, and `/stack` published PostgreSQL 18.4 for three weeks while
the cluster ran 18.6. `Version20260930180000` is the model: it converts only the rows still in the
old shape, is safe to replay, and `tests/Migrations/` tests it against a pre-change table. The same
change must also reach every `Assert\Choice` and form that lists the values — bind them to the
enum (`VersionSource::values()`, like `Locale::values()`), never to a hand-copied list.

**Production is never seeded automatically** — settled 2026-09-09, issue #17. Not "not yet": the seed
Job is wired to `deploy-preprod` and must stay there. Prod's content is authored through the
backoffice, and an automatic writer against it is a standing risk for no standing benefit. Seeding prod
is a deliberate, case-by-case act: apply the same Job by hand to the `prod` namespace when a genuinely
empty table needs a starting point (a new bounded context, typically). The guard makes that safe — the
already-populated contexts decline, only the empty one is filled — but *safe* is not *automatic*, and
the distinction is the decision. Do not "complete" the pipeline by adding this step to `deploy-prod`.

The cost is accepted and worth naming: a new context ships with an empty page in production until
someone seeds it, and nothing fails to announce it. If that ever needs catching, the answer is a
post-deploy check that fails on an empty public payload — never an automatic writer.

**Preprod never receives a copy of production data.** The content comes from the code, not from a
dump: a dump would carry `cpg_user` — e-mail addresses and password hashes — into a second
environment, multiplying the places they can leak, and it would buy nothing here since prod's content
*is* what these seeds produce.

To add a new bounded context (e.g. a second `Security` aggregate, or a new `Portfolio` sub-context): mirror
the same `Domain/Application/Infrastructure/Presentation` split under a new `src/<Context>/` folder, creating
only the layers actually needed (no persistence → no `Infrastructure/Doctrine/`; no HTTP entry point → no
`Presentation/Controller/`). Tests mirror the same tree under `tests/<Context>/`. To add a new *backoffice* CRUD
resource for existing content: follow the API Platform pattern of `.claude/rules/backoffice-api.md` rather than reinventing a controller.

