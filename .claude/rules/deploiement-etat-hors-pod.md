---
paths:
  - "k8s/**"
  - "docker/**"
  - "backend/config/packages/cache.yaml"
  - "backend/config/packages/lock.yaml"
  - "backend/config/packages/rate_limiter.yaml"
  - "backend/config/packages/monolog.yaml"
  - "backend/src/Shared/Infrastructure/Lock/**"
---

# Deployment invariants — no application state on the pod (ADR 0005)

> Split out of `.claude/rules/deploiement.md` (#605), whose index lists every deployment rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

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
  Without it a console session has `lock_timeout = 0` (measured). The bound is per lock statement: an
  `app:*:seed` on two tables (About, Quality) takes its two scope locks one statement each under
  `withLocks()` (issue #567, `.claude/rules/seeding.md`), so the seed Job can wait up to 2 × 5 s behind two
  busy scopes before failing. An operator's read-only `exec`
  (`deploiement-pipeline.md`, « What « never `kubectl exec` » covers ») repeats `-c lock_timeout=5s` in its own `PGOPTIONS`, which replaces the variable whole.
  For the worker, a handler waiting past 5 s raises `55P03` and Messenger retries it. **The
  application's own PostgreSQL locks keep a fallback bound, now a safety net** (issue #512, kept on
  2026-10-12, #525) for sessions with none — the dev container's console, CI: the order-scope lock, the account lock, the username
  allocation lock, the technology row lock (#545) and the account administration lock (#551) share one skeleton, `Shared/Infrastructure/Lock/PostgresLockedTransaction`
  (an *Execute Around*: hand-made transaction, what `acquire` returns handed to the operation — the entity a
  row lock re-read while locking it, issue #560 —, wait bounded, `55P03` — defined there only, as
  `LOCK_NOT_AVAILABLE` — renamed per lock, a refused COMMIT closing the EntityManager the skeleton
  receives, issue #547, and so does an exception the operation throws after a `flush()` that had
  something to write, issue #549 — `WrittenFlushWatch` on `onFlush`, one per transaction, unhooked
  whatever happens, closing after the ROLLBACK; a refusal before any write leaves it open, see
  `.claude/rules/portfolio-ordre.md` —, a refused COMMIT never masked, nor the cause by a
  ROLLBACK the database refuses after it, issue #526: that failure is logged `error` with classes and
  SQLSTATE only, the session is closed — never reused, PostgreSQL rolls it back, DBAL reopens one —, and
  the cause is rethrown as is; a programming error of the ROLLBACK, `NoActiveTransaction`, still
  surfaces; **never inside a transaction already open**, issues #526, #532: `run()` refuses before
  opening anything, under the name each adapter gives its required `onNested` —
  `OrderScopeLockInsideTransactionException`, `AccountLockInsideTransactionException`,
  `UsernameAllocationLockInsideTransactionException`, `ExperienceTechnologyLockInsideTransactionException`,
  `AccountAdministrationLockInsideTransactionException`, `LogicException`s, unmapped `critical` 500s —, since
  nested its COMMIT would only be a `RELEASE SAVEPOINT` and what each lock promises « after the commit »
  would not hold; the check lives in the skeleton, required, so a new lock cannot forget it, and DBAL's
  count is the judge — a raw `executeStatement('BEGIN')` is not seen), which gives the
  transaction `FALLBACK_LOCK_TIMEOUT` (`5s`, the FPM value) through `set_config('lock_timeout', …, true)`
  **only when the session has none** (`0`): a bound already chosen (`PGOPTIONS`, a test) is kept,
  neither lengthened nor shortened. A new PostgreSQL lock goes through it, and a lock that re-reads what the
  EntityManager loaded before it goes through `Shared/Infrastructure/Doctrine/RereadUnderLock` too (#560,
  `.claude/rules/backend-architecture.md`). `docker/php` is mounted read-only in the dev
  container for that test. **Never consume a limiter inside `wrapInTransaction`**: the lock and
  the `cache_items` row live on two connections PostgreSQL does not relate, which both deadlocks
  (until `lock_timeout`) and republishes the window after the lock is released. The Monolog
  `lock` channel has its own handler at `warning` and no other prod handler (`main`, `console`)
  receives it (`LockLogChannelTest`, issue #315): the component logs every acquire/release in
  `debug` and every failure in `notice`, always with the resource — an IP or a username — and
  never above `notice`, so the channel is silent in prod; a lock failure stays visible through
  `RateLimiterLockFailureListener`'s `error` line, which never names the resource. A future lock
  taken outside an `/api` request would fail silently: revisit this before adding one.
