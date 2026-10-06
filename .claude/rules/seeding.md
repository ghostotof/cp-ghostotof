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

`k8s/base/seed-job.yaml` runs the seven on **every preprod deploy** (`case-studies` was missing from it
until 2026-09-13 — adding a seed command means adding its line there, the Job's comment says so). Like `migrate-job.yaml` it sits
outside `kustomization.yaml`, hence `${BACKEND_IMAGE}` + `envsubst`. It never passes `--force`, so it
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

