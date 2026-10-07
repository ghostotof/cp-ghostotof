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
  exercises a real pod (6 wrong logins, the 6th must say "Too many failed login attempts")
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
  and also checks no other manifest runs the image.
  **Shared storage is not atomicity: every limiter also takes a lock shared across pods**
  (issue #272, ADR 0005 amended 2026-09-30). Without `symfony/lock`, `consume()` was a
  non-atomic read-modify-write — 20 simultaneous calls on one key counted **one** unit.
  `lock.yaml` sets `framework.lock: '%env(pg_advisory:DATABASE_URL)%'`: the
  `Shared/Infrastructure/Lock/PostgresAdvisoryLockDsnEnvVarProcessor` suffixes the scheme with
  `+advisory`, which is what makes `StoreFactory` pick `DoctrineDbalPostgreSqlStore` (advisory
  lock, no table — a bare `postgresql://` would give the table-based `DoctrineDbalStore`); any
  other scheme is refused, the URL never echoed. With the component configured, every
  `rate_limiter.yaml` limiter (`lock_factory: 'auto'`) and both `login_throttling` ones get
  `lock.factory`, and `RateLimiterStorageTest` asserts that store for each. **Never `flock` or
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
  request), pinned by `FpmLockWaitBoundTest`; `docker/php` is mounted read-only in the dev
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
  resolve to `docker.io/library/backend`). The deployer `Role` no longer has `pods/exec: create`, so the
  CI identity cannot open an interactive shell in a pod — but **that is not a secret boundary** (3rd audit,
  A3/D4): `jobs create` + `pods/log` + `externalsecrets create/update` read every Secret of the namespace
  by construction (a Job that prints its environment is enough). The Role is a full deployer of its
  namespace; what bounds the exposure is the **token**, not the verb list: a bound, 90-day token
  (`kubectl create token`) issued by `tools/rotate-deployer-token.sh <preprod|prod>`, published as an
  environment secret, rotated quarterly — never the durable `kubernetes.io/service-account-token` Secret
  it replaced. If a console command must run at deploy time, declare another Job — never bring `pods/exec`
  back. The RBAC is a **manual bootstrap the pipeline never replays**: after changing it, re-run the loop in
  `k8s/README.md` §4 *before* the next deploy, or the job fails on `cannot create resource "jobs"`.
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
  migration, cut a new tag. The discipline that makes this order safe, to respect in every migration:
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
  still-serving release back to the one before it.
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
  `ImagePullBackOff` persisting 60 s **together** (the kubelet alternates them); a Job with
  `Failed=True` fails at once instead of waiting out its timeout. `CrashLoopBackOff` is deliberately
  not fatal. Slicing must not swallow kubectl's own errors: a slice that exits non-zero *without*
  having expired ("timed out waiting for the condition", or "context deadline exceeded" when a slow
  API outlasts the slice before the cache syncs) — Forbidden, NotFound, ProgressDeadlineExceeded —
  is tolerated twice, the third in a row fails quoting it. **Known gap**: a missing Secret or
  ConfigMap mounted as a non-optional *volume* (`jwt-keys`, `backend-nginx-conf`) leaves the pod in
  `ContainerCreating` with only a `FailedMount` event, which the deployer Role cannot read (no
  `events` verb) — that case still ends in a plain timeout. The reasons live in one table,
  `REASON_CLASS`, with their delays in `GRACE`. It never reads a Secret and needs nothing beyond
  the deployer Role's `get/list/watch`.
  Each wait may overrun its timeout by ~25 s, counted in `timeout-minutes`. Fail-closed is
  unchanged: a failure before `apply -k` leaves the previous release serving; after it, no automatic
  rollback in prod, and `rollback-preprod` still runs only on a successful deploy. The 60 s
  `rollout status` checks of `smoke-test-preprod` are not deploy waits and stay. Offline test:
  `tools/tests/wait-rollout.test.sh`.
- **`DEPLOY_MAINTENANCE_WINDOW` (repository variable) opts a deploy into a maintenance window** — added
  for v0.11.0's irreversible integer→UUID primary-key migrations, where the new code cannot read the old
  schema **and vice versa**, so no pod may serve a request while the migration runs. That is the only
  case that needs it since #175; an additive migration doesn't. When it equals `true`,
  `deploy-preprod`/`deploy-prod` in `pipeline.yml` patch `backend` and `worker` to `replicas: 0` (a
  `kubectl patch` on `spec.replicas` — the deployer `Role` has no `deployments/scale` subresource, so
  never `kubectl scale`), wait for their pods to disappear, run `migrate-job.yaml` against the quiet
  database, then let `kubectl apply -k .` restore the manifests' replica counts and the existing
  rollout waits (`tools/wait-rollout.sh`) wait for the new pods. The frontend keeps serving; only the API returns 503 through
  the ingress for the window's duration. It is opt-in specifically so an ordinary release stays
  zero-downtime — **set it before pushing the release tag and unset it right after the production
  deploy**: a forgotten `true` turns every subsequent deploy into a downtime deploy for no reason.
  **Fail-closed on a migration failure**: the script exits before reaching `kubectl apply -k .`, so
  `backend`/`worker` stay at 0 replicas until someone intervenes — deliberate, never serve traffic
  against a half-migrated schema. To recover: if the migration wrote nothing, `kubectl apply -k .` on
  that overlay redeploys the previous image against the still-old schema; otherwise fix the migration
  and cut a new tag.
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
  untouched (no `fastcgi_intercept_errors`), `Retry-After` included. **Accepted limit**: these 429s
  carry no CORS headers (only `nelmio_cors` sets them), so a browser on another origin cannot read
  them and `fetch` throws — the frontend sees a network error, not "rate-limited". Guard:
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
  (audit A17). `automountServiceAccountToken: false` on all ten pod specs, the Jobs outside kustomize
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
  **RabbitMQ's probes are `tcpSocket` on 5672, never `rabbitmq-diagnostics`** (issue #295): the CLI
  starts an Erlang node per call, which overran its 10 s timeout under CPU load — during a boot,
  precisely — and the kubelet killed a broker that had been up for 35 s (nine restarts in eight
  days in prod). A `startupProbe` gives the boot up to 5 min; `RabbitMqProbesTest` pins both.
  The worker's `wait-for-rabbitmq` initContainer (issue #298) waits for the same port (`nc -z`,
  BusyBox, bounded at 5 min then `exit 1`) before `messenger:consume` starts: a deploy that
  recreates both pods used to start the worker first, which crashed on "Could not connect to the
  AMQP server" and backed off (3 restarts in prod at v0.18.5). `WorkerWaitsForRabbitMqTest` pins it.
