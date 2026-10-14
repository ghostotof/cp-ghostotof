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

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Deployment invariants »; since #605 this file keeps only part of it, see the index below. The index of every rule is at the top of `.claude/CLAUDE.md`.

**Where the rest lives** (split by #605, so that each file reads in one `Read`). A reference elsewhere to
`.claude/rules/deploiement.md`, « <bullet> » points at one of these:

| Rule | Bullets |
|---|---|
| this file | `tests/Support/KubernetesManifests`, `watch-refresh-cronjob.yaml` in `resources:`, the two ConfigMaps' hashing, Xdebug, ServiceAccount tokens and Pod Security Admission, Postgres/RabbitMQ state on a PVC |
| `deploiement-pipeline.md` | migrations as a Job, what « never `kubectl exec` » covers (#520), the migration before the rollout, one rule for every Job's deadline, ExternalSecrets synced first, `smoke-test-preprod`'s message probe, deploy waits failing early, `DEPLOY_MAINTENANCE_WINDOW` |
| `deploiement-postgres.md` | the two PostgreSQL roles (#575), the bootstrap superuser reachable from the pod only (#593) |
| `deploiement-etat-hors-pod.md` | no application state on the pod's filesystem (ADR 0005): cache pools, locks, rate limiters, `lock_timeout` and `ConsoleLockWaitBoundTest` |
| `deploiement-nginx.md` | the six rate-limit zones, `/api/assistant/`'s `fastcgi_pass`, nginx 429s as problem+json, `real_ip`, `add_header` inheritance, `security.txt` |

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
- **`watch-refresh-cronjob.yaml` *is* in `kustomization.yaml`'s `resources:`** — the opposite of
  `migrate-job.yaml` (`.claude/rules/deploiement-pipeline.md`), and deliberately: it wants kustomize's image transformer, since it must run the
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
  success while running something else, same family as the stale-image incident in `.claude/rules/release.md`.
  `backend-config` is the **opposite** and must stay `disableNameSuffixHash: true`: it is referenced
  by literal name from `migrate-job`, `seed-job` and both CronJobs, all deliberately outside
  kustomize, which therefore cannot rewrite their references — a hashed name breaks them with
  `CreateContainerConfigError` (incident v0.6.0). The rule that decides: **hash it if kustomize owns
  every reference to it, don't if anything outside kustomize names it.** Verify a config change
  actually landed with `kubectl exec … -c nginx -- nginx -T | grep <the new directive>`.
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
  (audit A17). `automountServiceAccountToken: false` on all sixteen pod specs, the eight Jobs outside kustomize
  included (`postgres-hba-probe-job.yaml`, #596, among them) — none of them talks to the Kubernetes API (RabbitMQ does no peer discovery here, ESO runs in
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
  named **503** (`MessageQueueUnavailableException`, see `.claude/rules/backend-conventions.md`) logged
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
