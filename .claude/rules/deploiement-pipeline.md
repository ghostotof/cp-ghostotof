---
paths:
  - "k8s/**"
  - "docker/**"
  - ".github/workflows/**"
  - "tools/**"
  - "backend/migrations/**"
  - "backend/config/packages/cache.yaml"
  - "backend/config/packages/lock.yaml"
  - "backend/config/packages/rate_limiter.yaml"
  - "backend/config/packages/monolog.yaml"
  - "backend/src/Shared/Infrastructure/Lock/**"
  - "frontend/public/.well-known/**"
---

# Deployment invariants — Jobs and the deploy pipeline

> Split out of `.claude/rules/deploiement.md` (#605), whose index lists every deployment rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

- **Doctrine migrations run as a Job, not `kubectl exec`** (audit C8). `k8s/base/migrate-job.yaml` is
  deliberately **outside** `kustomization.yaml`'s `resources:` — so kustomize's image transformer never sees
  it, hence the `${BACKEND_IMAGE}` placeholder that `envsubst` fills at apply time (`image: backend` would
  resolve to `docker.io/library/backend`). **Every Job outside kustomize runs through
  `tools/run-job.sh <ns> <manifest> <image> <timeout> [NAME=value…]`** (#488) — migration and seed
  in `pipeline.yml`, the messenger probe, the pg_hba probe (#596, its image written in the
  manifest, the image argument `sans-objet`), the manual `create-user` procedure, the three `failed`
  maintenance Jobs (#521). **Before the cluster is
  touched** (exit 2): bash ≥ 4, jq and envsubst present (otherwise `wait-rollout.sh` would fail *after* the
  apply, on a Job still running); one object, `kind: Job`, its `metadata.name` (end-of-line comment
  stripped) a DNS-1123 name — never an option, `delete job/NAME`; each `NAME=value` a `${NAME}` of the
  manifest other than `BACKEND_IMAGE`, its value non-empty within `[A-Za-z0-9._@:/+-]` (it is pasted into
  the YAML as is) and never quoted in a refusal; `envsubst` with that explicit list, then any `${…}` left
  refused — so a shell variable in a manifest is written `$VAR`, comments included. Then delete + apply
  (refused by the cluster: exit 3), `tools/wait-rollout.sh` (Job failed: exit 1), and every container's
  last 200 log lines (`--all-containers --prefix`, init containers included) — on stdout after a success,
  on stderr after an `::error::` otherwise; on that failure path, a Job neither `Complete` nor `Failed`
  is then **stopped** (`delete --cascade=foreground --wait=true --timeout=90s`, #535, see the
  deadline bullet below). The probe blames the worker on exit 1 only. Never re-inline
  that sequence — `run-job.test.sh` greps `pipeline.yml` for it: a fix made in one copy is a fix missing
  in the others. Offline test: `tools/tests/run-job.test.sh`. The deployer `Role` has no `pods/exec: create`, so the
  CI identity cannot open an interactive shell in a pod — but **that is not a secret boundary** (3rd audit,
  A3/D4): `jobs create` + `pods/log` + `externalsecrets create/update` read every Secret of the namespace
  by construction (a Job that prints its environment is enough). The Role is a full deployer of its
  namespace; what bounds the exposure is the **token**, not the verb list: a bound, 90-day token
  (`kubectl create token`) issued by `tools/rotate-deployer-token.sh <preprod|prod>`, published as an
  environment secret, rotated quarterly — never a durable `kubernetes.io/service-account-token` Secret. If a console command must run at deploy time, declare another Job — never bring `pods/exec`
  back. The RBAC is a **manual bootstrap the pipeline never replays**: after changing it, re-run the loop in
  `k8s/README.md` §4 *before* the next deploy, or the job fails on `cannot create resource "jobs"`.
- **What « never `kubectl exec` » covers, exactly** (#520). Three cases, three answers:
  - **the CI identity never gets `pods/exec`** (nor `pods/attach`, `pods/portforward`) — audit C8 above;
  - **a command with a side effect, or one that writes an audit line, runs as a Job** (`tools/run-job.sh`),
    never through `exec`: its stderr would be the operator's terminal and `kubectl logs` would never see
    the line (#386, `app:user:create`, see `.claude/rules/security-user.md`). **One written
    exception**, #593: the PostgreSQL roles procedure (the #575 bullet of `.claude/rules/deploiement-postgres.md`), an `exec` on the
    `postgres` pod's local socket because no Job can reach the bootstrap superuser. It writes no
    audit line, its output is `verify.sql`'s counters; `OperatorExecBoundaryTest` exempts the `bash`
    block of its runbook section **by its place** (the phrase opening it is unique), refuses a second
    exec there, judges a copy of the line anywhere else like any other `psql`, and refuses any
    `kubectl debug`, `attach` or `port-forward` to `postgres` in the runbook — a new writing `exec`
    needs the same reasoning written down, never a widened exemption;
  - **a human holding an admin kubeconfig may `exec` for read-only diagnosis whose output stays on their
    terminal**, from a separate terminal, never behind a `!` in an assistant session: `nginx -T` on the
    sidecar (`.claude/rules/deploiement.md`, the two ConfigMaps), the Xdebug checks and `kubectl cp` of the profiles, the `SHOW` of the PostgreSQL
    keepalives over TCP (`k8s/README.md`, « Sessions fantômes PostgreSQL », #279), the count of password hashes per format before a change of `password_hashers` (`k8s/README.md`, « Avant de changer `password_hashers` », #590 — a format header and a count, never a salt, a hash or an identifier), and naming an account from its `userId` (`k8s/README.md`, « Journal de sécurité »). That last one is an exec *on purpose*: a Job
    would write the name and address into its pod's logs, readable until the pod is deleted, by the CI
    token too (`pods/log`) — what #363 keeps out of the logs. The query goes on stdin
    (nothing in the exec's argv, so nothing in the API server's request URL — the UUID does stay in the
    operator's shell history, a pseudonymous identifier), the UUID as a quoted psql variable cast to
    `uuid` (no injection from a pasted log value), inside a `default_transaction_read_only` session — a
    guard rather than a boundary, a `SET` lifts it — and `NS` has no default.
  Two guards, two scopes: `OperatorExecBoundaryTest` reads the repo (the deployer's Role, ClusterRole and
  both bindings, wildcards such as `*/exec` included, plus the shape of the account lookup and of every
  runbook `psql`: read-only `PGOPTIONS`, `-X`, `ON_ERROR_STOP`, `${NS:?…}`, query on stdin — #590);
  `tools/rotate-deployer-token.sh` checks the token actually issued against the cluster
  (`auth can-i create pods/exec` → `no`) — the only one that sees a binding added by hand. Keep both. A
  new manual `exec` in the runbook must fit the third case; if its output carries a secret or personal
  data, it stays on the terminal, and if it writes anything, it is a Job. **Messenger's `failed`
  transport follows that split** (#521, `k8s/README.md` « File d'échec Messenger »):
  `messenger:failed:show` is the third case — read-only, and at `-vv` it dumps the message itself (a
  visitor's name, address and text, an invitation's `userId`, the raw body of an undecodable
  envelope; measured: none of it without `-vv`) — so an `exec` on `deploy/backend -c php-fpm` under
  `env PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s'` (libpq reads it, so the whole session is
  read-only: a `failed:remove` there fails with SQLSTATE 25006, measured); `failed:retry <id>`,
  `failed:remove <id>` and `stop-workers` have side effects, so `messenger-failed-{retry,remove}-job.yaml`
  and `messenger-stop-workers-job.yaml` (running `app:messenger:stop-workers`, which reads the signal back,
  #528 — never Symfony's, which is green on a refused write), never with `-v` (and `SHELL_VERBOSITY=0` in their `env`, which
  beats a `backend-config` value), one at a time (`run-job.sh` deletes a running namesake), the id through `FAILED_MESSAGE_ID` and refused
  in 64 unless all digits (`run-job.sh` admits `-`, and `--all` read as an option empties the table).
  `MessengerMaintenanceJobsManifestTest` pins the Jobs, `OperatorExecBoundaryTest` the read block and
  that a runbook `exec` runs no console command but `messenger:failed:show` (an allow-list, so an
  abbreviation such as `m:f:rem` fails it). The read-only session is measured in dev only, to confirm in
  preprod (#521).
- **The standard deploy runs the migration *before* the rollout, with the old pods still serving**
  (#175, expand/contract). Doctrine selects every mapped column on each hydration, so rolling out
  before migrating would make the new pods of a release that adds a field `SELECT` a column the table
  doesn't have yet: every route touching it would answer 500 for the one to two minutes the Job takes
  (observed in preprod). In
  `deploy-preprod`/`deploy-prod` the `Deploy` step goes:
  `kustomize build -o` + apply of **`backend-config` alone** (the migrate Job reads it by literal name,
  outside kustomize, so without this it would run on the *previous* release's configuration and a
  variable added by this release would be missing; running pods don't re-read their env, so this
  changes nothing for them — the filename `v1_configmap_backend-config.yaml` is stable because the
  ConfigMap has `disableNameSuffixHash`), then `migrate-job.yaml` with the release image (300 s
  timeout, `tools/wait-rollout.sh`), then `kubectl apply -k .` + the rollout waits
  (`tools/wait-rollout.sh`, see below). **Fail-closed**: a failed Job exits before the
  `apply`, so the previous release keeps serving on the old schema, which is exactly the safe state
  (PostgreSQL DDL is transactional and Doctrine wraps each migration in its own transaction) — fix the
  migration by a new commit on the `release/*` branch (a `fix/*` PR targeting it; every push redeploys
  preprod) — a new migration if an earlier one of the release already ran —, or by a hotfix release once
  `main` has moved; never a tag by hand, the pipeline tags.
  **A migration's lock waits are bounded at 5 s, and there the bound stops the deploy, it refuses
  no request** (#525). A DDL waiting for an exclusive lock queues every
  API request on its table behind it (each one then failing on FPM's own 5 s); bounded, the
  migration fails with `55P03`, the Job retries once (`backoffLimit: 1`, the remaining migrations
  only — each runs in its own transaction), then fails, `apply -k` never runs and the previous
  release keeps serving. A transient blocker therefore means **re-running the deploy job** — say
  so in the release notes. A migration that must wait longer says so itself, `SET LOCAL
  lock_timeout = '…'` at the top of its `up()` (`Version20261011120000` sets `5s` that way, redundant
  with the bound and left as is: migrations are frozen); a non-transactional one
  (`isTransactional(): false`, `CREATE INDEX CONCURRENTLY`) has no transaction for `SET LOCAL` to
  apply to — a no-op with a warning — and sets `SET lock_timeout = '…'` on the session instead.
  **One rule for every Job's deadline: `wait + 35 < activeDeadlineSeconds ≤ wait + 120`**
  (#525, #535), whoever waits — the pipeline, the probe, or an operator following the runbook.
  `run-job.sh` deletes a Job only before applying it, so without a deadline a stuck Job (a long DDL
  holding its exclusive lock in front of the API, a hung connection) would run on indefinitely after
  the `::error::`: hence the upper bound, the Job dies shortly after the wait. And **after**
  it, never before: at the deadline the Job controller deletes the pods, their logs with them,
  while past the wait (plus `wait-rollout.sh`'s ~35 s overrun) the pod still runs and `run-job.sh`
  prints its logs — the diagnosis of exactly the hang the deadline is for. A deadline under the
  wait gives an `::error::` with no logs, only `DeadlineExceeded` — and on Kubernetes ≥ 1.31
  `Failed` is set only once the pods are gone, 30 s of grace after the deadline (the probe and the four manual
  Jobs had 240 under 300 before #535: 240 + 30 s under a 300 s wait, a narrow margin before a bare
  timeout). Current values:
  waited 300 s, deadline 360 —
  `migrate-job.yaml`, `messenger-probe-job.yaml`, `messenger-stop-workers-job.yaml`,
  `messenger-failed-{retry,remove}-job.yaml`, `create-user-job.yaml`; `seed-job.yaml` waited 180,
  deadline 240; `postgres-hba-probe-job.yaml` (#596) waited 120, deadline 180. Counted from the Job's
  creation, `Always` pulls and retry included; `sh`/`php` as
  PID 1 ignore SIGTERM, so the pod dies 30 s after the deadline (grace period). **An `::error::`
  means « stopped »** (#535): with the deadline above the wait, a Job whose wait
  expired — or that `wait-rollout.sh` failed early, a pod that never started — is still active and
  could complete after the `::error::` (a mail sent, an account created, a migration applied, a
  probe started during `rollback-preprod`), and a chained relaunch would delete it mid-flight. So
  after printing its logs, `run-job.sh` reads its conditions and, unless `Complete` or `Failed`
  (a refused read counts as active), deletes it in the foreground, waiting for its pods (≤ 90 s:
  the 30 s grace period); a `Failed` Job is kept, its logs readable until `ttlSecondsAfterFinished`.
  A refused stop is a second `::error::`, still exit 1: only then does the runbook's « wait for
  `Complete`/`Failed` before relaunching » apply. `delete jobs` is already in the deployer Role.
  That stop does not lengthen the waits (#285); it adds up to 90 s to the failure path, counted in the
  `timeout-minutes` of `deploy-preprod`, `smoke-test-preprod`, `postgres-hba-preprod` and `deploy-prod`. The guard is `tools/tests/run-job.test.sh`, the only one of that
  relation (the PHPUnit manifest tests pin values, not the relation): it reads every `run-job.sh`
  call of `pipeline.yml`, `tools/smoke-messenger-probe.sh` and `k8s/README.md` (continuation lines
  joined, a minimum count per file so a reworded call cannot empty the check), fails on a deadline
  missing or outside the interval, and on a `kind: Job` manifest of `k8s/base` that no call
  launches — a new Job needs its call written, with its wait, in one of those files; a commented
  line is not a call. Out of its reach, on purpose: overlays (these Jobs are outside kustomize, no
  overlay can patch them — a Job added under `k8s/overlays/` would need the guard extended) and the
  CronJobs (`messenger-purge`, `watch-refresh`: no `run-job.sh`, no wait to outlive, their deadline
  only bounds a run).
  The discipline that makes this order safe, to respect in every migration:
  a column added `NOT NULL` has a default or is filled by the migration itself
  (`Version20260914170000` is the model); **never drop or rename a column in the release that stops
  reading it**, only in the next one; a Messenger message in flight at deploy time must stay readable
  by both versions (v0.11.0's `SendAccountInvitationMessage.userId` note); and the migration adds the
  fingerprint of the schema it leaves to `verify.sql`'s `#611` block (#611, see the roles bullet
  of `.claude/rules/deploiement-postgres.md` — the test says which line). Only migrations the old code
  cannot survive fall outside this order — see the maintenance window below.
  **A migration that writes into one of the eight ordered content tables, then alters or indexes that
  table, can pass the CI and fail only on a populated database — preprod, or a dev database with
  content** (#505): their deferred trigger `trg_translation_group_single_position` leaves pending
  events that make PostgreSQL refuse the DDL (`55006`), and a write touching no row on the CI's empty
  database queues none. Which writes count, the fix (`SET CONSTRAINTS … IMMEDIATE` after the writes,
  never at the top), the test on rows it requires and the drop-and-recreate trap are in
  `.claude/rules/portfolio-ordre-migrations.md`, « Trap for future migrations ».
- **Every ExternalSecret of the release is synced before anything is migrated or rolled out**
  (#325). Right after `backend-config`, the `Deploy` step applies the ESO objects alone
  (`external-secrets.io_*.yaml` from the `kustomize build -o` output) and runs
  `tools/wait-external-secrets.sh <ns> <names…> --timeout 120`, before the maintenance window.
  Otherwise a key missing from Secret Manager would surface only as `CreateContainerConfigError` after
  a rollout timed out (observed in phase 1, Anthropic key); instead, the job fails naming the ExternalSecret and its remote key names, fail-closed. **"Synced" is `Ready=True` *and* `status.syncedResourceVersion` starting with
  `<metadata.generation>-`** — ESO keeps the previous generation's `Ready=True` until it reconciles a
  changed spec, so `kubectl wait --for=condition=Ready` would pass exactly when a release adds a key
  (observed on ESO v2.9.0). Never replace the script with a bare `kubectl wait`. It never reads a
  Secret; it annotates `force-sync` on the blocking ones once, to break ESO's backoff when a job is
  re-run after publishing the key. `cp-ghostotof.com/deploy-gate: optional` turns a failure into a
  warning — only `backend-xdebug-trigger` (preprod) carries it, never put it on an ExternalSecret a
  pod or Job needs to start. Offline test: `tools/tests/wait-external-secrets.test.sh`.
  Corollary of the order: a release's ExternalSecrets stay applied even when its migration then fails,
  and the previous release's pods restart on them — so **never remove or rename a Secret key in the
  release that stops reading it**, only in the next one (the column rule, applied to secrets); adding
  a key is always safe. And `rollback-preprod` runs only when `deploy-preprod` *succeeded*
  (`needs.deploy-preprod.result == 'success'`): `failure()` is true as soon as any *ancestor* job
  fails, so without that condition a deploy stopped before its rollout would trigger `rollout undo`
  anyway and roll the still-serving release back to the one before it. It undoes **`backend`, `worker` and `frontend`**,
  each attempted even when another fails (`|| status=1`, the job stays red — under `bash -e` the first
  failure would skip the rest): the worker reads what the API queues under the same envelope MAC key, so
  undoing one without the other sends every message to `failed` (#450). A manual rollback in
  prod follows the same rule, see `k8s/README.md`, « Retour arrière applicatif ». It is triggered only
  by the jobs it `needs`, and a check accusing a workload it does not undo is never one of them: the
  pg_hba probe (`postgres-hba-preprod`, #600, see the #593 bullet of `.claude/rules/deploiement-postgres.md`).
- **`smoke-test-preprod` proves a message travels from the API to the worker** (#470), not only that
  the worker is `Ready`: a MAC key mismatch between `backend` and `worker` (a `KEY_CONTEXT` badly deployed,
  a worker left on the previous release) would otherwise leave preprod green with every message in `failed`.
  `tools/smoke-messenger-probe.sh preprod <image>` applies `k8s/base/messenger-probe-job.yaml` (outside
  kustomize, `${BACKEND_IMAGE}` only, through `tools/run-job.sh`), which runs `app:messenger:probe
  --timeout=90` with the release image and **the backend's Secrets**, so it signs under the API's key: it
  queues a `MessengerProbeMessage` (a random id, no mail, no business data) and waits for the mark the
  worker's `MessengerProbeHandler` leaves in `cache.app`, read back **by value** (`getItem()->isHit()`, never
  `hasItem()`, a bare `SELECT 1` that skips the #468 MAC). No mark in 90 s → exit 1 → the Job fails
  (`backoffLimit: 0`, waited 300 s by `tools/wait-rollout.sh` — the probe's budget, counted from the apply,
  two `Always` pulls included, ~210 s left to them; `activeDeadlineSeconds: 360`, above it like every Job,
  see the migration bullet, #535) → the smoke fails → `rollback-preprod`. A probe pod still pulling when the
  wait expires is stopped by `run-job.sh` before the smoke fails, so it cannot start during the rollback. A mark the worker could not
  save raises `ProbeMarkNotSavedException` (Messenger retries) instead of acknowledging silently. **The Job
  proves the path from the release image, not from the running pods**: the script first requires
  `deployment/backend` and `deployment/worker` to run that very image, or a fresh worker behind a stale API
  would pass. The pipeline reads no Secret (the variables stay in the pod). A refused probe stays in `failed`
  (no purge covers it: read it with the Job's logs, then `messenger:failed:remove`); a probe left unconsumed
  is handled later, harmlessly — except by a worker rolled back past #470, which does not know the class
  (one more `failed` row). Offline test: `tools/tests/smoke-messenger-probe.test.sh`. Preprod only — prod has
  no rollback to trigger.
- **Every deploy wait fails early on a container that will not start** (#353). The `Deploy`
  steps never call `kubectl rollout status` or `kubectl wait --for=condition=complete` directly:
  `tools/wait-rollout.sh <ns> deployment/NAME|job/NAME --timeout N` cuts kubectl's own wait into
  5 s slices — so "done" keeps kubectl's meaning — and between slices reads the pods of the
  *current* revision only: for a Deployment, the ReplicaSet whose `deployment.kubernetes.io/revision`
  matches, once `status.observedGeneration` has caught up (pods of an earlier stuck rollout would be
  a false positive); for a Job, the pods owned by its uid (the previous `backend-migrate`, just
  deleted, may still be around). Containers **and** initContainers. It fails naming pod, container,
  reason and the kubelet's message — which names the missing key or Secret, never a value — on
  `InvalidImageName` at once, `CreateContainerConfigError` persisting 15 s, `ErrImagePull` and
  `ImagePullBackOff` persisting 150 s **together** (the kubelet alternates them; long on purpose:
  images are checked on GHCR first, so a pull error is mostly a registry hiccup, and after `apply -k`
  in prod there is no rollback — a deploy that would have converged must not go red); a Job with
  `Failed=True` fails at once instead of waiting out its timeout. `CrashLoopBackOff` is deliberately
  not fatal. Slicing must not swallow kubectl's own errors: a slice that exits non-zero *without*
  having expired ("timed out waiting for the condition", or "context deadline exceeded" when a slow
  API outlasts the slice before the cache syncs) — Forbidden, NotFound, ProgressDeadlineExceeded —
  is tolerated twice, the third in a row fails quoting it. A missing Secret or ConfigMap mounted as
  a non-optional *volume* (`jwt-keys`, `backend-nginx-conf`) gives no telling reason — the pod sits
  in `ContainerCreating` (`PodInitializing` with initContainers) and the cause is only in a
  `FailedMount` event: while a current-revision pod still waits for its volumes
  (`PodReadyToStartContainers` present **and** `False`, no container started or blocked otherwise)
  the script reads the namespace's `FailedMount` events — once per round, all pods together — and
  fails after 15 s, one count per volume, on `secret|configmap "…" not found` / `references
  non-existent … key` only — **never on any `FailedMount`**: a `Recreate` (postgres) reattaching its
  PVC emits transient ones. Condition absent (pod not scheduled yet, node without the feature): no
  judgement, since an event kept an hour cannot be told from a live failure. A transient read error
  keeps the running counts. That needs
  `get`/`list` on `events` in the deployer Role (#353 — **re-run `k8s/README.md` §4 after
  changing the Role**); refused, the script warns once and waits on — a forgotten bootstrap must
  never break every deploy. The reasons live in one table, `REASON_CLASS`, with their delays in
  `GRACE`. It never reads a Secret.
  Each wait may overrun its timeout by ~35 s, counted in `timeout-minutes`. Fail-closed
  holds: a failure before `apply -k` leaves the previous release serving; after it, no automatic
  rollback in prod, and `rollback-preprod` still runs only on a successful deploy. The 60 s
  `rollout status` checks of `smoke-test-preprod` are not deploy waits and stay: they catch a
  datastore that went down after the deploy, first in the job (#603, see the #593 bullet of `.claude/rules/deploiement-postgres.md`). Offline test:
  `tools/tests/wait-rollout.test.sh`.
- **`DEPLOY_MAINTENANCE_WINDOW` (repository variable) opts a deploy into a maintenance window** — for an
  irreversible migration where the new code cannot read the old schema **and vice versa** (v0.11.0's
  integer→UUID primary keys), so no pod may serve a request while the migration runs. It is needed in
  two cases only (#175): such a migration, and a change of the Messenger envelope `KEY_CONTEXT`
  (#450, `AuthenticatedEnvelopeSerializer`), where an old API pod still signing under the old key
  meets a worker already demanding the new one — a refused MAC is final, the message is lost. An
  additive migration doesn't. When it equals `true`,
  `deploy-preprod`/`deploy-prod` in `pipeline.yml` patch `backend` and `worker` to `replicas: 0` (a
  `kubectl patch` on `spec.replicas` — the deployer `Role` has no `deployments/scale` subresource, so
  never `kubectl scale`), wait for their pods to disappear, run `migrate-job.yaml` against the quiet
  database, then let `kubectl apply -k .` restore the manifests' replica counts and the existing
  rollout waits (`tools/wait-rollout.sh`) wait for the new pods. The frontend keeps serving; only the API returns 503 through
  the ingress for the window's duration. It is opt-in specifically so an ordinary release stays
  zero-downtime — **set it before the first push of the `release/*` branch (every push deploys
  preprod) and unset it right after the production deploy**: a forgotten `true` turns every subsequent deploy into a downtime deploy for no reason.
  **Fail-closed on a migration failure**: the script exits before reaching `kubectl apply -k .`, so
  `backend`/`worker` stay at 0 replicas until someone intervenes — deliberate, never serve traffic
  against a half-migrated schema. To recover: if the migration wrote nothing, `kubectl apply -k .` on
  that overlay redeploys the previous image against the still-old schema; otherwise fix the migration
  through the release branch (or a hotfix release), as above — the pipeline tags, never by hand.
