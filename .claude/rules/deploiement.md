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

# Deployment invariants

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Deployment invariants ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **A PHPUnit test reads a manifest through `tests/Support/KubernetesManifests`, never by hand**
  (issue #541). `KubernetesManifests::base()` (`k8s/base`) or `::overlay('prod')` (the overlay's
  own files, never the `kustomize build` render — an overlay patch on the base stays invisible to
  every test below). It splits a file into documents (`---` alone or followed by a comment, CRLF
  included), descends to the pod spec (`Deployment`, `StatefulSet`, `Job`, `CronJob`, a bare
  `Pod`) and finds a container or initContainer by name; the business assertions stay in each
  test. It also holds the **one census of the manifests running the backend image** (issue #542,
  `manifestsRunningTheBackendImage()`, `*.yaml` and `*.yml` of the directory, not its
  subdirectories), read from the parsed `image` of every container and initContainer — never a
  regex on the text, two copies of which disagreed on quotes and trailing comments — and
  `isBackendImage()` / `backendContainers()` / `backendContainersIn($manifest)`: `backend` or the
  pipeline's `ghcr.io/ghostotof/cp-ghostotof-backend`, with or without a tag or digest (kustomize
  rewrites it by name), or `${BACKEND_IMAGE}` / `$BACKEND_IMAGE` (envsubst fills both). The census
  parses every manifest of the directory, so an unrelated one it refuses turns both its consumers
  red. YAML lists are read through it too (`entries()`, `firstWith($node, $list, $key, $value)`,
  `text()`), never a private copy. It **refuses** rather than skips: an unparsable document or one
  that is not a mapping (named by its first line), a resource of another kind carrying a pod
  template or a `containers` list at any depth (a `DaemonSet`, a `PodTemplate`, a `List` of Pods
  must be taught to it first), a list that is a mapping, a list entry that is not a mapping, a
  name found zero or twice. Its own test,
  `tests/Shared/KubernetesManifestsTest.php`, proves each case on manifests it writes. **Proving
  a manifest guard still fails** takes a mutation the dev container actually sees: `k8s/` is a
  bind mount that Docker Desktop loses track of when a file is replaced by a new inode (`sed -i`,
  `perl -i`, an editor's atomic save) — write in place (`cat new > file`), compare `sha1sum` on
  the host and in `backend`, and restore the same way; `docker compose restart backend` resyncs.
- **No application state on the pod's filesystem** (ADR 0005, audit of 2026-09-16, constat A1,
  hotfix v0.14.1). Pods run `readOnlyRootFilesystem: true` with only `var/log` mounted, and
  Symfony's default `cache.app` was a `FilesystemAdapter` under `var/cache/prod/pools`: its
  `save()` returned `false` **silently**, so `cache.rate_limiter` (every rate limiter, including
  `login_throttling`) and the prod Doctrine result cache never persisted anything — 14 wrong
  logins in a row on production were never throttled, while `LoginThrottlingTest` was green
  (CI's disk is writable). `cache.app` is now `cache.adapter.doctrine_dbal` in **every** env
  (`cache.yaml`), table `cache_items` created by migration `Version20260916180000`, pruned
  daily by `cache:pool:prune` in the housekeeping CronJob (`messenger-purge-cronjob.yaml`, name
  kept: `apply -k` never deletes a renamed object). `tests/Security/RateLimiterStorageTest`
  pins it (every limiter + `cache.app` DBAL-backed, never Filesystem — add a new limiter to its
  list); `tools/smoke-login-throttling.sh`, run by `smoke-test-preprod`, is the only check that
  exercises a real pod (6 wrong logins, the 6th must be a 429 `/errors/rate-limited` **with** a
  `Retry-After` and the application's `detail` — otherwise it is the nginx zone answering, which
  proves nothing, issue #399)
  and the nginx `login` zone (10 r/m, burst 10, both confs) is the backstop if the storage ever
  fails again. Anything that "just writes a file" at runtime (a lock, a session, a render cache)
  falls under the same rule: DB, a dedicated service, or nowhere.
  **One bounded exception, `cache.system`** (issue #288, ADR 0005 amended D11): it is *not*
  read-only at runtime — property-info, serializer, API Platform property metadata and, in the
  CLI Jobs, Doctrine's DQL `ParserResult` write keys the build warm-up never produces, and each
  write failed on every request with a `cache` `WARNING`. Every pod running the backend image
  mounts a bounded `emptyDir` (`cache-system`, `sizeLimit: 64Mi`) on
  `var/cache/prod/pools/system`, seeded by a `seed-cache-system` initContainer that copies the
  image's pre-warmed cache (the volume would hide it otherwise). That is allowed because the
  cache is derived, disposable and identical in every pod — not application state. A new pod
  spec running the image needs the three parts; `SystemCachePodVolumeTest` turns red otherwise
  and also checks, on `KubernetesManifests`' census, that no other manifest runs the image.
  **Shared storage is not atomicity: every limiter also takes a lock shared across pods**
  (issue #272, ADR 0005 amended 2026-09-30). Without `symfony/lock`, `consume()` was a
  non-atomic read-modify-write — 20 simultaneous calls on one key counted **one** unit.
  `lock.yaml` sets `framework.lock: '%env(pg_advisory:DATABASE_URL)%'`: the
  `Shared/Infrastructure/Lock/PostgresAdvisoryLockDsnEnvVarProcessor` suffixes the scheme with
  `+advisory`, which is what makes `StoreFactory` pick `DoctrineDbalPostgreSqlStore` (advisory
  lock, no table — a bare `postgresql://` would give the table-based `DoctrineDbalStore`); any
  other scheme is refused, the URL never echoed. With the component configured, every
  `rate_limiter.yaml` limiter (`lock_factory: 'auto'`) and both `login_throttling` ones get
  `lock.factory`, and `RateLimiterStorageTest` asserts that store for each;
  `RateLimiterConcurrencyTest` (issue #277) checks the behaviour — ten processes released by a
  file barrier consume one key at once, every unit must be counted (red with `lock_factory: null`).
  It runs the consumers as separate processes on purpose: a test that held the lock itself would
  wait forever on its own children. **Never `flock` or
  `semaphore`** (pod-local — they fix dev and CI and leave prod open from two replicas on); the
  Flex recipe writes `LOCK_DSN=flock` into `.env` and `phpunit.dist.xml`, remove it again if a
  recipe update brings it back. No separate `LOCK_DSN` either: it would be a second secret
  carrying the DB password. Cost measured: ~1.5 ms per `consume()` plus a second PostgreSQL
  connection on requests that reach a limiter. `RateLimiterStorageTest` also compares its list
  with the container's `limiter.*` services, so a new limiter that isn't listed turns it red.
  **The advisory lock is session-level and waits forever** — hence, in `www.prod.conf`,
  `env[PGOPTIONS] = "-c lock_timeout=5s"` (the DSN cannot carry it: DBAL doesn't forward `options`
  to pdo_pgsql, so it bounds every PostgreSQL lock wait of an FPM worker, ORM included, never the
  console) and `request_terminate_timeout = 65s` (total wall time — keep it above any legitimate
  request), pinned by `FpmLockWaitBoundTest`. That bounds the *wait*, not the lock's life: **a
  session whose client vanished without a FIN** (node lost, network partition) kept the lock until
  Linux's default keepalive, 2 h, every request on the key failing in 5 s meanwhile. Hence, in
  `k8s/base/postgres.yaml` (issue #279), `args` `-c tcp_keepalives_idle=60
  -c tcp_keepalives_interval=10 -c tcp_keepalives_count=3`: such a session dies in 90 s (measured
  96 s, still held after 202 s without) — **plus `-c tcp_user_timeout=90000`** (ms), because Linux sends no keepalive probe while data waits for its ACK: a client gone while the
  server was answering it was left to retransmissions, ~15 min (measured: held after 303 s with
  keepalives alone, released in 106 s with it). `PostgresGhostSessionReleaseTest` caps both at 120 s,
  values written without units. `args`, never `command` (the image's entrypoint initialises the
  database). On a Unix socket PostgreSQL ignores them and `SHOW` reads `0`: check them over TCP
  (`k8s/README.md`, « Sessions fantômes PostgreSQL », which also says why `idle_session_timeout` and
  `client_connection_check_interval` are not set). A pod deleted, even with `--force`, is no ghost: the
  kubelet kills the container and the server gets the FIN — nor is anything in dev, hence no `command`
  in `docker-compose.yml`. **The console gets the same bound from its pods**
  (issue #525): every container of `k8s/base` running the backend image carries
  `PGOPTIONS: '-c lock_timeout=5s'` in its own `env:` — worker, CronJobs, every Job, the migration
  included —, exactly www.prod.conf's value. `env:` per manifest rather than a `backend-config` line
  (written twice, one per overlay) or a DBAL middleware (it would not reach the connection
  `symfony/lock` opens itself through `DriverManager`, and could not tell a migration apart).
  `ConsoleLockWaitBoundTest` finds the manifests **by the image** (the same census), so a new one without the bound
  turns it red; its only exemption is `backend-deployment.yaml`'s `php-fpm` (the pool sets it).
  Without it a console session has `lock_timeout = 0` (measured). An operator's read-only `exec`
  (below) repeats `-c lock_timeout=5s` in its own `PGOPTIONS`, which replaces the variable whole.
  For the worker, a handler waiting past 5 s raises `55P03` and Messenger retries it. **The
  application's own PostgreSQL locks keep a fallback bound, now a safety net** (issue #512, kept on
  2026-10-12, #525) for sessions with none — the dev container's console, CI: the order-scope lock, the account lock and the username
  allocation lock share one skeleton, `Shared/Infrastructure/Lock/PostgresLockedTransaction`
  (an *Execute Around*: hand-made transaction, wait bounded, `55P03` — defined there only, as
  `LOCK_NOT_AVAILABLE` — renamed per lock, a refused COMMIT never masked, nor the cause by a
  ROLLBACK the database refuses after it, issue #526: that failure is logged `error` with classes and
  SQLSTATE only, the session is closed — never reused, PostgreSQL rolls it back, DBAL reopens one —, and
  the cause is rethrown as is; a programming error of the ROLLBACK, `NoActiveTransaction`, still
  surfaces; **never inside a transaction already open**, issues #526, #532: `run()` refuses before
  opening anything, under the name each adapter gives its required `onNested` —
  `OrderScopeLockInsideTransactionException`, `AccountLockInsideTransactionException`,
  `UsernameAllocationLockInsideTransactionException`, `LogicException`s, unmapped `critical` 500s —, since
  nested its COMMIT would only be a `RELEASE SAVEPOINT` and what each lock promises « after the commit »
  would not hold; the check lives in the skeleton, required, so a new lock cannot forget it, and DBAL's
  count is the judge — a raw `executeStatement('BEGIN')` is not seen), which gives the
  transaction `FALLBACK_LOCK_TIMEOUT` (`5s`, the FPM value) through `set_config('lock_timeout', …, true)`
  **only when the session has none** (`0`): a bound already chosen (`PGOPTIONS`, a test) is kept,
  neither lengthened nor shortened. A new PostgreSQL lock goes through it. `docker/php` is mounted read-only in the dev
  container for that test. **Never consume a limiter inside `wrapInTransaction`**: the lock and
  the `cache_items` row live on two connections PostgreSQL does not relate, which both deadlocks
  (until `lock_timeout`) and republishes the window after the lock is released. The Monolog
  `lock` channel has its own handler at `warning` and no other prod handler (`main`, `console`)
  receives it (`LockLogChannelTest`, issue #315): the component logs every acquire/release in
  `debug` and every failure in `notice`, always with the resource — an IP or a username — and
  never above `notice`, so the channel is silent in prod; a lock failure stays visible through
  `RateLimiterLockFailureListener`'s `error` line, which never names the resource. A future lock
  taken outside an `/api` request would fail silently: revisit this before adding one.
- **Doctrine migrations run as a Job, not `kubectl exec`** (audit C8). `k8s/base/migrate-job.yaml` is
  deliberately **outside** `kustomization.yaml`'s `resources:` — so kustomize's image transformer never sees
  it, hence the `${BACKEND_IMAGE}` placeholder that `envsubst` fills at apply time (`image: backend` would
  resolve to `docker.io/library/backend`). **Every Job outside kustomize runs through
  `tools/run-job.sh <ns> <manifest> <image> <timeout> [NAME=value…]`** (issue #488) — migration and seed
  in `pipeline.yml`, the messenger probe, the manual `create-user` procedure, the three `failed`
  maintenance Jobs (issue #521). **Before the cluster is
  touched** (exit 2): bash ≥ 4, jq and envsubst present (otherwise `wait-rollout.sh` failed *after* the
  apply, on a Job still running); one object, `kind: Job`, its `metadata.name` (end-of-line comment
  stripped) a DNS-1123 name — never an option, `delete job/NAME`; each `NAME=value` a `${NAME}` of the
  manifest other than `BACKEND_IMAGE`, its value non-empty within `[A-Za-z0-9._@:/+-]` (it is pasted into
  the YAML as is) and never quoted in a refusal; `envsubst` with that explicit list, then any `${…}` left
  refused — so a shell variable in a manifest is written `$VAR`, comments included. Then delete + apply
  (refused by the cluster: exit 3), `tools/wait-rollout.sh` (Job failed: exit 1), and every container's
  last 200 log lines (`--all-containers --prefix`, init containers included) — on stdout after a success,
  on stderr after an `::error::` otherwise; on that failure path, a Job neither `Complete` nor `Failed`
  is then **stopped** (`delete --cascade=foreground --wait=true --timeout=90s`, issue #535, see the
  deadline bullet below). The probe blames the worker on exit 1 only. Never re-inline
  that sequence — `run-job.test.sh` greps `pipeline.yml` for it: a fix made in one copy is a fix missing
  in the others. Offline test: `tools/tests/run-job.test.sh`. The deployer `Role` no longer has `pods/exec: create`, so the
  CI identity cannot open an interactive shell in a pod — but **that is not a secret boundary** (3rd audit,
  A3/D4): `jobs create` + `pods/log` + `externalsecrets create/update` read every Secret of the namespace
  by construction (a Job that prints its environment is enough). The Role is a full deployer of its
  namespace; what bounds the exposure is the **token**, not the verb list: a bound, 90-day token
  (`kubectl create token`) issued by `tools/rotate-deployer-token.sh <preprod|prod>`, published as an
  environment secret, rotated quarterly — never the durable `kubernetes.io/service-account-token` Secret
  it replaced. If a console command must run at deploy time, declare another Job — never bring `pods/exec`
  back. The RBAC is a **manual bootstrap the pipeline never replays**: after changing it, re-run the loop in
  `k8s/README.md` §4 *before* the next deploy, or the job fails on `cannot create resource "jobs"`.
- **What « never `kubectl exec` » covers, exactly** (issue #520). Three cases, three answers:
  - **the CI identity never gets `pods/exec`** (nor `pods/attach`, `pods/portforward`) — audit C8 above;
  - **a command with a side effect, or one that writes an audit line, runs as a Job** (`tools/run-job.sh`),
    never through `exec`: its stderr would be the operator's terminal and `kubectl logs` would never see
    the line (issue #386, `app:user:create`, see `.claude/rules/security-user.md`);
  - **a human holding an admin kubeconfig may `exec` for read-only diagnosis whose output stays on their
    terminal**, from a separate terminal, never behind a `!` in an assistant session: `nginx -T` on the
    sidecar (below), the Xdebug checks and `kubectl cp` of the profiles, the `SHOW` of the PostgreSQL
    keepalives over TCP (`k8s/README.md`, « Sessions fantômes PostgreSQL », issue #279), and naming an account from its `userId` (`k8s/README.md`, « Journal de sécurité »). That last one is an exec *on purpose*: a Job
    would write the name and address into its pod's logs, readable until the pod is deleted, by the CI
    token too (`pods/log`) — the very thing issue #363 removed from the logs. The query goes on stdin
    (nothing in the exec's argv, so nothing in the API server's request URL — the UUID does stay in the
    operator's shell history, a pseudonymous identifier), the UUID as a quoted psql variable cast to
    `uuid` (no injection from a pasted log value), inside a `default_transaction_read_only` session — a
    guard rather than a boundary, a `SET` lifts it — and `NS` has no default.
  Two guards, two scopes: `OperatorExecBoundaryTest` reads the repo (the deployer's Role, ClusterRole and
  both bindings, wildcards such as `*/exec` included, plus the shape of the account lookup);
  `tools/rotate-deployer-token.sh` checks the token actually issued against the cluster
  (`auth can-i create pods/exec` → `no`) — the only one that sees a binding added by hand. Keep both. A
  new manual `exec` in the runbook must fit the third case; if its output carries a secret or personal
  data, it stays on the terminal, and if it writes anything, it is a Job. **Messenger's `failed`
  transport follows that split** (issue #521, `k8s/README.md` « File d'échec Messenger »):
  `messenger:failed:show` is the third case — read-only, and at `-vv` it dumps the message itself (a
  visitor's name, address and text, an invitation's `userId`, the raw body of an undecodable
  envelope; measured: none of it without `-vv`) — so an `exec` on `deploy/backend -c php-fpm` under
  `env PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s'` (libpq reads it, so the whole session is
  read-only: a `failed:remove` there fails with SQLSTATE 25006, measured); `failed:retry <id>`,
  `failed:remove <id>` and `stop-workers` have side effects, so `messenger-failed-{retry,remove}-job.yaml`
  and `messenger-stop-workers-job.yaml` (running `app:messenger:stop-workers`, which reads the signal back,
  issue #528 — never Symfony's, which is green on a refused write), never with `-v` (and `SHELL_VERBOSITY=0` in their `env`, which
  beats a `backend-config` value), one at a time (`run-job.sh` deletes a running namesake), the id through `FAILED_MESSAGE_ID` and refused
  in 64 unless all digits (`run-job.sh` admits `-`, and `--all` read as an option empties the table).
  `MessengerMaintenanceJobsManifestTest` pins the Jobs, `OperatorExecBoundaryTest` the read block and
  that a runbook `exec` runs no console command but `messenger:failed:show` (an allow-list, so an
  abbreviation such as `m:f:rem` fails it). The read-only session is measured in dev only, to confirm in
  preprod (#521).
- **The standard deploy runs the migration *before* the rollout, with the old pods still serving**
  (issue #175, expand/contract). Doctrine selects every mapped column on each hydration, so with the
  old order (rollout, then Job) any release that adds a field made the new pods `SELECT` a column the
  table didn't have yet: every route touching it answered 500 for the one to two minutes the Job took
  (observed in preprod on v0.12.0). In `deploy-preprod`/`deploy-prod` the `Deploy` step now goes:
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
  no request** (issue #525, decided 2026-10-12). A DDL waiting for an exclusive lock queues every
  API request on its table behind it (each one then failing on FPM's own 5 s); bounded, the
  migration fails with `55P03`, the Job retries once (`backoffLimit: 1`, the remaining migrations
  only — each runs in its own transaction), then fails, `apply -k` never runs and the previous
  release keeps serving. A transient blocker therefore means **re-running the deploy job** — say
  so in the release notes. A migration that must wait longer says so itself, `SET LOCAL
  lock_timeout = '…'` at the top of its `up()` (`Version20261011120000` sets `5s` that way, now
  redundant and left as is: migrations are frozen); a non-transactional one
  (`isTransactional(): false`, `CREATE INDEX CONCURRENTLY`) has no transaction for `SET LOCAL` to
  apply to — a no-op with a warning — and sets `SET lock_timeout = '…'` on the session instead.
  **One rule for every Job's deadline: `wait + 35 < activeDeadlineSeconds ≤ wait + 120`**
  (issues #525, #535), whoever waits — the pipeline, the probe, or an operator following the
  runbook. `run-job.sh` deletes a Job only before applying it, so without a deadline a stuck Job (a
  long DDL holding its exclusive lock in front of the API, a hung connection) ran on indefinitely
  after the `::error::`: hence the upper bound, the Job dies shortly after the wait. And **after**
  it, never before: at the deadline the Job controller deletes the pods, their logs with them,
  while past the wait (plus `wait-rollout.sh`'s ~35 s overrun) the pod still runs and `run-job.sh`
  prints its logs — the diagnosis of exactly the hang the deadline is for. A deadline under the
  wait (the probe and the four manual Jobs had 240 under 300 until #535) gave an `::error::` with
  no logs, only `DeadlineExceeded` — and on Kubernetes ≥ 1.31 `Failed` is set once the pods are
  gone, 240 + 30 s of grace, a narrow margin under 300. Today: waited 300 s, deadline 360 —
  `migrate-job.yaml`, `messenger-probe-job.yaml`, `messenger-stop-workers-job.yaml`,
  `messenger-failed-{retry,remove}-job.yaml`, `create-user-job.yaml`; `seed-job.yaml` waited 180,
  deadline 240. Counted from the Job's creation, `Always` pulls and retry included; `sh`/`php` as
  PID 1 ignore SIGTERM, so the pod dies 30 s after the deadline (grace period). **An `::error::`
  means « stopped »** (decided 2026-10-12, #535): with the deadline above the wait, a Job whose wait
  expired — or that `wait-rollout.sh` failed early, a pod that never started — is still active and
  could complete after the `::error::` (a mail sent, an account created, a migration applied, a
  probe started during `rollback-preprod`), and a chained relaunch would delete it mid-flight. So
  after printing its logs, `run-job.sh` reads its conditions and, unless `Complete` or `Failed`
  (a refused read counts as active), deletes it in the foreground, waiting for its pods (≤ 90 s:
  the 30 s grace period); a `Failed` Job is kept, its logs readable until `ttlSecondsAfterFinished`.
  A refused stop is a second `::error::`, still exit 1: only then does the runbook's « wait for
  `Complete`/`Failed` before relaunching » apply. `delete jobs` is already in the deployer Role.
  The waits are unchanged (#285); the failure path gains up to 90 s, counted in the
  `timeout-minutes` of `deploy-preprod`, `smoke-test-preprod` and `deploy-prod`. The guard is `tools/tests/run-job.test.sh`, the only one of that
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
  by both versions (v0.11.0's `SendAccountInvitationMessage.userId` note). Only migrations the old code
  cannot survive fall outside this order — see the maintenance window below.
- **Every ExternalSecret of the release is synced before anything is migrated or rolled out**
  (issue #325). Right after `backend-config`, the `Deploy` step applies the ESO objects alone
  (`external-secrets.io_*.yaml` from the `kustomize build -o` output) and runs
  `tools/wait-external-secrets.sh <ns> <names…> --timeout 120`, before the maintenance window. A key
  missing from Secret Manager used to surface only as `CreateContainerConfigError` after a rollout
  timed out (phase 1, Anthropic key); now the job fails naming the ExternalSecret and its remote key
  names, fail-closed. **"Synced" is `Ready=True` *and* `status.syncedResourceVersion` starting with
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
  fails, so a deploy stopped before its rollout used to trigger `rollout undo` anyway and roll the
  still-serving release back to the one before it. It undoes **`backend`, `worker` and `frontend`**,
  each attempted even when another fails (`|| status=1`, the job stays red — under `bash -e` the first
  failure skipped the rest): the worker reads what the API queues under the same envelope MAC key, so
  undoing one without the other sends every message to `failed` (issue #450). A manual rollback in
  prod follows the same rule, see `k8s/README.md`, « Retour arrière applicatif ».
- **`smoke-test-preprod` proves a message travels from the API to the worker** (issue #470), not only that
  the worker is `Ready`: a MAC key mismatch between `backend` and `worker` (a `KEY_CONTEXT` badly deployed,
  a worker left on the previous release) used to leave preprod green with every message in `failed`.
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
- **Every deploy wait fails early on a container that will not start** (issue #353). The `Deploy`
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
  `get`/`list` on `events` in the deployer Role (added for #353 — **re-run `k8s/README.md` §4 after
  changing the Role**); refused, the script warns once and waits on — a forgotten bootstrap must
  never break every deploy. The reasons live in one table, `REASON_CLASS`, with their delays in
  `GRACE`. It never reads a Secret.
  Each wait may overrun its timeout by ~35 s, counted in `timeout-minutes`. Fail-closed is
  unchanged: a failure before `apply -k` leaves the previous release serving; after it, no automatic
  rollback in prod, and `rollback-preprod` still runs only on a successful deploy. The 60 s
  `rollout status` checks of `smoke-test-preprod` are not deploy waits and stay. Offline test:
  `tools/tests/wait-rollout.test.sh`.
- **`DEPLOY_MAINTENANCE_WINDOW` (repository variable) opts a deploy into a maintenance window** — added
  for v0.11.0's irreversible integer→UUID primary-key migrations, where the new code cannot read the old
  schema **and vice versa**, so no pod may serve a request while the migration runs. Since #175 it is
  needed in two cases only: such a migration, and a change of the Messenger envelope `KEY_CONTEXT`
  (issue #450, `AuthenticatedEnvelopeSerializer`), where an old API pod still signing under the old key
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
- **`watch-refresh-cronjob.yaml` *is* in `kustomization.yaml`'s `resources:`** — the opposite of
  `migrate-job.yaml` above, and deliberately: it wants kustomize's image transformer, since it must run the
  same image as the Deployment. It used to be **the only object in the cluster that makes outbound calls
  to third parties**; since ADR 0004 the `backend` Deployment does too (`api.anthropic.com`, from the
  backoffice only). The namespace's NetworkPolicies restrict ingress only, so nothing extra is needed today —
  but adding an egress policy would break this Job and the translation assistant first.
- **The two ConfigMaps hash differently, and each on purpose** (issue #18). `backend-nginx-conf` is a
  **`configMapGenerator`**: its content hash is part of its name, so editing `k8s/base/backend-nginx.conf`
  changes the name, hence the pod template, hence triggers a rollout — which is the only way the
  sidecar ever picks the change up, since the file is mounted with `subPath` and Kubernetes never
  refreshes those in a running container. Before that, `kubectl apply` printed
  `configmap … configured` while nginx kept its old rules **indefinitely** — the deploy reporting
  success while running something else, same family as the stale-image incident in the release invariant of `.claude/CLAUDE.md`.
  `backend-config` is the **opposite** and must stay `disableNameSuffixHash: true`: it is referenced
  by literal name from `migrate-job`, `seed-job` and both CronJobs, all deliberately outside
  kustomize, which therefore cannot rewrite their references — a hashed name breaks them with
  `CreateContainerConfigError` (incident v0.6.0). The rule that decides: **hash it if kustomize owns
  every reference to it, don't if anything outside kustomize names it.** Verify a config change
  actually landed with `kubectl exec … -c nginx -- nginx -T | grep <the new directive>`.
- **Six nginx rate-limit zones, two different jobs.** `contact` (10 r/m), `pwsetup` (20 r/m),
  `baseaccess` (20 r/m, issue #77 — each call signs an RS256 JWT), `login` (10 r/m, burst 10,
  ADR 0005 — the backstop under Symfony's `login_throttling`, which is the real ceiling) and
  `assistant` (10 r/m, burst 5, plus `limit_conn assistantconn 1` — each call is billed and each
  stream holds one of a pod's 8 php-fpm workers; the zones live in each sidecar, so an IP gets N times
  these ceilings with N backend pods) protect a *side effect* — sending mail, guessing a token,
  minting a token, guessing a password, spending money.
  `publicapi` (600 r/m, burst 200, on
  `location /`) protects the *resource*: without it every public read reaches PHP and Postgres as
  often as asked. Its ceiling is deliberately far above real use — behind a mobile carrier's CGNAT
  thousands of visitors share one address, and a tight cap would cut them all off at once, which is
  the very DoS audit C7 was about. `/healthz` uses an exact-match `location =`, so kubelet probes are
  never capped.
- **`location ^~ /api/assistant/` does its own `fastcgi_pass`** (spec 0005 D9, both confs). The career
  assistant streams `text/event-stream`, so the location sets `fastcgi_buffering off`; through
  `try_files … /index.php` the internal redirect to `location ~ ^/index\.php` would leave that directive
  behind. Measured on 2026-09-26: `X-Accel-Buffering: no` (set by `EventStreamResponse`) already suffices
  for the sidecar, which consumes it — the ingress never sees it and relies on its own `proxy-buffering`,
  `off` by default. The location is kept as defence in depth, and carries the `assistant` zones above,
  `fastcgi_ignore_client_abort on` (PHP holds its worker after a client abort, so the `limit_conn`
  slot must too). Its 429s come out in problem+json like every other zone's, see the next bullet.
- **Every nginx 429 answers problem+json `/errors/rate-limited`** (issue #347, both confs). One
  `error_page 429 = @rate_limited` at `server` level covers every zone (`limit_req` and
  `limit_conn`); nginx's default HTML page used to leak out of all of them but `assistant`.
  `limit_req_status 429` / `limit_conn_status 429` sit at `server` level too: a refusal defaults to
  503, which that `error_page` would not catch. Two traps: a `location` that declares an
  `error_page` of its own loses the inherited one, and an `add_header` in `@rate_limited` would drop
  the seven security headers (A16). Symfony's own 429s pass through
  untouched (no `fastcgi_intercept_errors`), `Retry-After` included. **No CORS headers on these
  429s, on purpose** (issue #368): only `nelmio_cors` sets them, and none is needed — in preprod and
  prod the frontend and the API share **one origin** (single-host ingress, `/api` → `backend`,
  `API_URL` = the site's own origin), so the browser applies no CORS check and the frontend reads
  the 429 and its problem+json body (checked in a real browser on preprod, 2026-10-10: response
  `type` `basic`, nginx's own `/errors/rate-limited` body read by `fetch`). Only dev is cross-origin (Vite on
  `:5173`, nginx on `:8080`): there `fetch` throws a `TypeError` on these 429s. Don't add CORS to
  `@rate_limited` (a second source of truth for the origin, plus the A16 header trap) and don't map
  a `TypeError` to "rate-limited" — revisit only if the API ever moves to its own host. Guard:
  `tools/check-backend-nginx-rate-limits.sh` (`make back-nginx-rate-limits`, CI job
  `backend-nginx-rate-limits`) runs the pinned sidecar image on each conf, saturates every
  `limit_req` zone and checks the body, the type and the headers; it fails naming any `limit_req`
  zone of the confs missing from its `ZONES` list. **`limit_conn` is not exercised** (it would need
  an upstream that holds the connection, and there is no php-fpm in the job).
- **nginx rate limits need `real_ip`** (audit C7). `limit_req_zone` keys on `$binary_remote_addr`, and behind
  the ingress the sidecar's TCP peer is the ingress-nginx pod — without the `set_real_ip_from` block, the whole
  internet shares one counter, which is a self-inflicted DoS. The trusted ranges mirror Symfony's
  `trusted_proxies: private_ranges` **including `100.64.0.0/10`** (RFC 6598, the Kapsule pod range —
  the ingress pod's actual IP; it was missing until v0.14.1, so `real_ip` never applied and every zone
  really was one global counter, audit 2026-09-16 A25), with `real_ip_recursive on`.
  `docker/nginx/default.conf` and `k8s/base/backend-nginx.conf` are mirrors of each other: change both.
  **And the client IP must survive the Scaleway Load Balancer**, which is a full proxy: without
  PROXY protocol, ingress-nginx sees one of the LB's two addresses as the client and forwards *that*
  in `X-Forwarded-For`, so Symfony's `login_throttling` and quotas keyed the whole internet on two
  addresses. `k8s/ingress-nginx-values.yaml` (`use-proxy-protocol` + the
  `scw-loadbalancer-proxy-protocol-v2` annotation, applied together by one `helm upgrade`) is the
  cluster prerequisite that fixes it — see ADR 0005 D6/D7. `tools/smoke-login-throttling.sh` is what
  proves the whole chain end to end: six attempts from one machine must land on one key.
- **An `add_header` inside a `location` cancels the inheritance of *every* `add_header` of the parent
  block**, not just the one it redefines (audit A16). That is how `/assets/`, `/config.js` and `/healthz`
  of the frontend came to be served with no CSP and no HSTS, and `/index.html` with no HSTS, while the
  `server` block declared all seven headers. The frontend's seven headers now live in **one file**,
  `docker/node/security-headers.conf` (copied to `/etc/nginx/security-headers.conf`, deliberately *not*
  under `conf.d/`, which the image already includes at `http` level), `include`d at `server` level **and**
  in every `location` that sets a header of its own. Adding such a `location` means adding the `include`,
  or it ships bare. The backend sidecar cannot share that file — `backend-nginx.conf` is a ConfigMap
  mounted by `subPath`, a second file would need a second mount — so there the headers are repeated
  explicitly on `location = /healthz`; keep the two in step. `tools/audit-prod.sh` checks `/`,
  `/config.js`, `/healthz` and an asset discovered from the home page, judging only the **final**
  response. A pre-merge guard now catches this before deploy: `tools/check-frontend-image-headers.sh`
  runs the built frontend image locally and checks the 7 headers on at least one path that really
  lands in each `location`, wired into the `frontend-image-headers` CI job on every push. `/` itself
  is served by `= /index.html` (`try_files … /index.html` is an internal rewrite that redoes location
  matching), so `location /` is probed with a root static file (`/favicon.svg`) instead. A new
  `location` added to `nginx.conf` gets its path added to that script, or it isn't covered.
- **`/.well-known/security.txt` is published and expires** (RFC 9116, audit A14):
  `frontend/public/.well-known/security.txt`, served `text/plain; charset=utf-8` by the `^~ /.well-known/`
  location (declared first and with `^~` so the hidden-files rule doesn't swallow it; `charset` is not an
  `add_header`, so header inheritance stays intact). `tools/audit-prod.sh` **fails on a past `Expires`** —
  that failure *is* the renewal reminder, there is no other. Contacts point at the repository's private
  advisory form and the site's contact page, the same policy as `SECURITY.md`.
- **Xdebug is inert in the preprod image and armed only from outside it** (audit A12). `xdebug.mode = "off"`
  is baked in; the only thing that arms it is `XDEBUG_MODE`, supplied by the **dedicated, optional** Secret
  `backend-xdebug-trigger` (its own `ExternalSecret`, mounted on preprod's `php-fpm` container alone, whose
  template yields `profile` only if the secret is at least 32 characters — absent, empty or short means
  `off`, and the backend starts fine without it). **The lock is on the mode, never on `trigger_value`**:
  an undefined env var interpolates to the empty string, and an empty `xdebug.trigger_value` means "any
  value triggers" — the setting meant to restrict profiling was opening it to anyone past the Basic Auth.
  **`profile` only, never `trace`**: a function trace writes call *arguments* verbatim, so a profiled
  `POST /api/login_check` would put a password on disk. Output goes to an `emptyDir` on `var/profiler`
  (read-only root, ADR 0005) with a timestamp+PID filename carrying nothing from the request, and the
  trigger travels in a **cookie** — Xdebug 3.5 does not read HTTP headers, and nginx logs the query string.
  Production receives none of this. Procedure in `k8s/README.md`.
- **No pod mounts a ServiceAccount token, and both namespaces carry Pod Security Admission labels**
  (audit A17). `automountServiceAccountToken: false` on all fifteen pod specs, the seven Jobs outside kustomize
  included — none of them talks to the Kubernetes API (RabbitMQ does no peer discovery here, ESO runs in
  its own namespace), so the default `default`-SA token was pure standing credential. The `preprod` and
  `prod` `Namespace` objects set `enforce: baseline` with `audit`/`warn: restricted`: RabbitMQ declares no
  container `securityContext` (constat A18, accepted — v0.5.0 already put it in `CrashLoopBackOff` by
  touching its run user) so `restricted` in `enforce` would refuse the pod outright, while `audit`/`warn`
  keep the strict policy reported without blocking. **Namespaces are created by hand**: the CI identity has
  `get`/`patch` on them, never `create`. A PSA refusal is *not* visible at `kubectl apply` — the namespace
  updates fine and the next pod creation fails — so validate in preprod first.
  Because this change rewrites the pod template of the `Recreate` workloads, `deploy-preprod`/`deploy-prod`
  now also wait for the rollout (`tools/wait-rollout.sh`) of **`postgres`, `rabbitmq` and `worker`**
  after `apply -k`, not just
  `backend`/`frontend`: without the wait the seed Job ran against a database that hadn't come back, and in
  prod a downed broker left the deploy green. Accept the corollary: such a change is a short, frank outage
  of those two stateful workloads.
- **Postgres/RabbitMQ carry state on a PVC** — a `kubectl apply --dry-run=server` proves nothing about runtime
  behaviour on an already-initialised volume. Release v0.5.0 put RabbitMQ in `CrashLoopBackOff` in production
  (~15 min of `POST /api/contact` returning 500) by adding `runAsNonRoot`/`fsGroup`: Erlang refuses to start
  when its `.erlang.cookie` is group-accessible, and the offending file survived the manifest rollback. Any
  change to those two workloads needs a real preprod rollout with `rollout status` + logs before promotion.
  `seccompProfile: RuntimeDefault` (audit I6) is a syscall filter and touches neither uid nor file modes, but
  the rule stands.
  **RabbitMQ's own settings live in `k8s/base/rabbitmq.conf`** (issue #477), mounted by `subPath` as
  `/etc/rabbitmq/conf.d/20-cp-ghostotof.conf` beside the image's `10-defaults.conf` — through the
  `rabbitmq-conf` `configMapGenerator` (hashed, so a change rolls the broker out, like `backend-nginx-conf`),
  and bind-mounted from the same file by `docker-compose.yml`. It sets `max_message_size = 1048576`: the
  default 16 MiB let anyone who can publish make the worker load a message whole before
  `AuthenticatedEnvelopeSerializer` refuses it (#469); headers are bounded by `frame_max` (128 KiB).
  `RabbitMqMessageSizeCapTest` pins the cap at exactly four times `MAX_ENVELOPE_BYTES`, a single
  `max_message_size` line, the generator (no `disableNameSuffixHash`, global or per overlay) and the k8s
  mount. **That file can stop the broker from booting** (an unknown key is refused), and `rollback-preprod`
  does not undo `rabbitmq`: `tools/check-rabbitmq-conf.sh` (`make rabbitmq-conf`, CI job `rabbitmq-conf`,
  needed by `release-version`) boots the pinned image with the file, reads the value the node kept, and
  checks the `docker-compose.yml` mount — the one thing the PHPUnit test cannot see. Its CLI calls run as
  the `rabbitmq` user: run as root before the server, the CLI creates `.erlang.cookie` owned by root and
  the node dies on `eacces`. It does not prove a boot on an initialised PVC — only preprod does; recover a
  broken preprod broker with `kubectl -n preprod rollout undo deployment/rabbitmq`. In dev the file is read
  at boot: `docker compose up -d rabbitmq` after changing it (and after any git operation that replaced
  it). **Any change to that file restarts the broker** (`Recreate`): a short outage of `POST /api/contact`
  and invitations, in the release notes, validated in preprod like any change here. **Publications wait for
  the broker's confirmation** (issue #479, `confirm_timeout: 5` on the `async` transport's options in
  `messenger.yaml`, never in the DSN Secret): a broker refusal is asynchronous, so without it a publish
  over the cap — or into a broker going down — returned fine, the request answered 2xx and the message
  was lost; with it, `dispatch` throws a `TransportException` (a 500 for `POST /api/contact`, a visible
  failure for a worker retry; the channel is recreated for the next publish). Measured ≈ +0.13 ms per
  publish on a reused connection, plus one `confirm.select` round trip per FPM request. The waits before it
  are bounded too — `connect_timeout`, `read_timeout`, `write_timeout`, `rpc_timeout` at 5 s, ext-amqp
  leaving them unlimited: a broker accepting TCP without answering held a request until the 65 s
  `request_terminate_timeout`. The worker polls by `basic.get`, so nothing waits while it idles (checked).
  Accepted limits: a connection lost after the broker stored a message but before its ack makes Symfony
  republish it (a duplicate contact e-mail, or an invitation token regenerated once more); after a confirm
  timeout the long-lived worker channel may take a late ack for the next publish; while RabbitMQ restarts
  (`Recreate`, a release) publications fail loudly instead of vanishing — and since #485 that failure is a
  named **503** (`MessageQueueUnavailableException`, see `.claude/rules/backend-architecture.md`) logged
  `warning`, never a `critical` 500, and a first invitation that could not be queued removes the account
  it had just created. The contact quota stays consumed **before** the send, on purpose (decided
  2026-10-11, review of #485): consuming it after the send let a concurrent burst through (×6 to ×12),
  could fail after a message had left (a duplicate on retry) and bounded nothing during an outage; the
  price kept is that a visitor retrying during a broker restart burns their quota. `AmqpPublisherConfirmsTest` bounds the five
  options in every declared environment; `AmqpPublisherConfirmsIntegrationTest` publishes against a real
  broker carrying `rabbitmq.conf` (`RABBITMQ_INTEGRATION_DSN`: Compose's broker in dev, the `rabbitmq-conf`
  boot kept running by `RABBITMQ_CONF_KEEP=1` in the `test-backend` job — not a `services:`, which cannot
  mount a repository file; missing in CI, the test fails rather than skips): 100 KB read back from the
  queue, 2 MiB refused with the broker's own `larger than configured max size`.
  **RabbitMQ's probes are `tcpSocket` on 5672, never `rabbitmq-diagnostics`** (issue #295): the CLI
  starts an Erlang node per call, which overran its 10 s timeout under CPU load — during a boot,
  precisely — and the kubelet killed a broker that had been up for 35 s (nine restarts in eight
  days in prod). A `startupProbe` gives the boot up to 5 min; `RabbitMqProbesTest` pins both.
  The worker's `wait-for-rabbitmq` initContainer (issue #298) waits for the same port (`nc -z`,
  BusyBox, bounded at 5 min then `exit 1`) before `messenger:consume` starts: a deploy that
  recreates both pods used to start the worker first, which crashed on "Could not connect to the
  AMQP server" and backed off (3 restarts in prod at v0.18.5). `WorkerWaitsForRabbitMqTest` pins it.
