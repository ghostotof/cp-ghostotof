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

# Deployment invariants — PostgreSQL roles and the bootstrap superuser

> Split out of `.claude/rules/deploiement.md` (#605), whose index lists every deployment rule. The index of every rule is at the top of `.claude/CLAUDE.md`.

- **The application and the migrations run under two PostgreSQL roles, neither a superuser** (#575).
  Under the image's `POSTGRES_USER`, the **bootstrap superuser** and owner of every table, an SQL
  injection could disable the deferred trigger of the translation groups (#390), read or rewrite any
  table, or run `COPY … PROGRAM`. So `backend-secrets.DATABASE_URL` is **`cpg_app`** (`SELECT, INSERT,
  UPDATE, DELETE` on every table, `USAGE, SELECT` on every sequence; no `TRUNCATE`, no DDL, no
  ownership, not `doctrine_migration_versions`) for FPM, the worker, both CronJobs and every
  applicative Job, and **`migrate-job.yaml` alone** reads **`cpg_migrator`** — owner of the database,
  of `public` and of every object — from its own `backend-migration-secrets` (an `env` entry, which
  beats `envFrom`; `MigrationRoleSecretsTest` pins the wiring and that no other backend-image
  manifest names that Secret). A third role, **`cpg_reader`** (`pg_read_all_data`, no password: reachable from inside the
  `postgres` pod only — local socket and `127.0.0.1`, `trust` —, refused over the network by the
  repository's `pg_hba.conf`, #593), runs the runbook's human `psql`s (`OperatorExecBoundaryTest`
  requires `-U cpg_reader`).
  The SQL lives in `k8s/base/postgres-roles/{roles,privileges,verify}.sql`, played **by hand,
  preprod then prod**, by the runbook's procedure (`k8s/README.md`, « Rôles PostgreSQL », which also
  gives the rollback): a `kubectl exec -i deploy/postgres -c postgres` running `psql` on the **local
  socket** under the bootstrap role — its only path left, see the next bullet —, the three files of
  the checkout and the passwords on **stdin** (`\set app_pw` / `migrator_pw` / `rotate` read from
  private files by `cat`, never an argument; `roles.sql` refuses a missing or short password and a
  `rotate` other than 0 or 1 before any write). One transaction whose `ACCESS EXCLUSIVE` locks are held
  until its COMMIT, a few seconds, rerunnable. **The stream opens with `BEGIN;` and ends with
  `commit.sql`, which commits only if `verify.sql` ran to its end** — its last statement sets
  `verify_passed`, which `roles.sql` unsets first; otherwise `commit.sql` raises (exit 3). Never
  `--single-transaction`, nor an unconditional final `COMMIT;`: the first commits at the end of input
  whatever arrived (measured on the pinned image: the three roles created, no ownership transfer, no `verify.sql`, psql
  exit 0), the second commits even when a `cat` failed (run outside the checkout's root, a file
  renamed). A stream cut before `commit.sql` writes nothing; **an interrupted command is an unknown
  state** all the same (the ~20 KB stream is usually in the pod already, psql may reach the COMMIT
  unseen): rerun step 2 alone with the **same** `$TMP` files and `ROTATE`, never step 1. `roles.sql`
  silences the server's statement logs **before** its first statement carrying a password (the length
  guard), and prints whether it wrote each password (`t`/`f`, never the value) — **never publish an
  URL whose password was not written**, the restart after would cut the application. **The session is
  set by the exec's `PGOPTIONS`**, never by a `SET` in a file (#608): a connection option beats a
  setting of the database or of a role in it from the connection on, `BEGIN` included —
  `lock_timeout=5s`, `idle_in_transaction_session_timeout=30s` (a stalled stream would hold the
  ownership transfers' locks in front of the API), `statement_timeout=0`, `transaction_timeout=0`,
  `idle_session_timeout=0`, `default_transaction_read_only=off`, `role=none` (a database-level `role`
  made the superuser session run as the migrator — measured; `RESET ROLE` falls back to that same
  value), `search_path=pg_catalog,pg_temp`, `password_encryption=scram-sha-256` (the only method the
  repository's `pg_hba.conf` accepts), `client_min_messages=notice` (at `error`, set on the database,
  it silenced the repair's notices — measured) and `standard_conforming_strings=on` (at `off`, the
  backslash of `DoctrineMigrations\Version…` vanished from the literals and the procedure failed on
  every pass as a false « checkout behind » — measured); `PostgresRolesProcedureTest` lists them with
  their reasons, no more, no less. The SQL files hold no transaction control and no reconnection.
  `PostgresRolesProcedureTest` pins the exec line word for word, the stream, `commit.sql` and its
  mark, and the files (no echo of a variable, a password cited only in its three places, no shell,
  `\copy`, `\w`, `\setenv` nor `PROGRAM`, the rotation gate). That `exec` is the **one written exception** to #520
  (`.claude/rules/deploiement-pipeline.md`): no Job can play the procedure, a Job connects over TCP,
  which the `pg_hba.conf` refuses to the superuser. **A rerun never rewrites an existing password**
  (`ROTATE=1` asks for it): rewriting `cpg_app`'s would cut the application until its URL is
  republished and the pods restarted.
  **Future tables need no GRANT**: `ALTER DEFAULT PRIVILEGES FOR ROLE cpg_migrator` covers what a
  migration creates — an object created by hand under the superuser does not inherit them: rerun
  the procedure. **The guard is `ApplicationRolePrivilegesTest`**, which replays `privileges.sql` on
  the migrated test schema with throwaway roles (`SET ROLE`, one rolled-back transaction): every
  table and sequence open to the application role, a table the migrator creates later too, the
  trigger still firing under it, and `DISABLE TRIGGER`, `DROP TRIGGER`/`FUNCTION`,
  `session_replication_role`, DDL and `TRUNCATE` refused in `42501`. **Dev and
  CI keep the single superuser role**, on purpose: the tests that lift a constraint, disable the
  trigger or set `session_replication_role` need it, and the guard above is what proves the
  privileges. A new statement the application needs (a `LOCK TABLE`, a temp table, a new schema) is
  added to the guard first, then to `privileges.sql`. Accepted blind spot: CI migrates as the
  superuser, so a migration that needs more than ownership (`CREATE EXTENSION`, `SECURITY DEFINER`,
  `session_replication_role`) passes CI and fails first in preprod, fail-closed, under `cpg_migrator`
  — none does today; write one and preprod says so; and `roles.sql`/`verify.sql` (psql meta-commands)
  run in no CI job: they were played against the pinned image by hand for #593 (first pass, rerun,
  rotation, refusals, truncated stream) and for #597 (two passes, then each of `verify.sql`'s four
  #597 refusals) and for #608 (a fresh database, a migrated one twice, a rotation, then each `ALTER`
  below) and for #611 (a fresh database, then a migrated one twice, then each gesture above refused in
  exit 3, then passing once undone) and for #610 (a database restored by `pg_dump` from the migrated
  test database, every `#608` gesture made by `cpg_migrator`, `cpg_app` and `cpg_reader` themselves —
  `client_min_messages` and `standard_conforming_strings` included —, the evidence query before,
  repaired with its notices and committed, a silent rerun, a rotation, then the trapped view refused
  in exit 3), and preprod is their proof at each change. **Accepted limits** (#575): any role may
  `ALTER ROLE` itself (password, `SET` defaults) — an injection could cut the application durably; the
  procedure resets the three roles' attributes and `RESET ALL` at every pass, and `verify.sql` refuses
  a stray attribute — `RESET ALL` misses a setting scoped to the database
  (`ALTER ROLE … IN DATABASE … SET`, which `cpg_app` may set on itself, measured), so `verify.sql`
  refuses any such setting, whatever its role (#608), and `roles.sql` removes it (#610, below).
  **The migrator owns the database, and an owner may cut it** (#608, measured on the pinned image): a database-level
  setting applied to every new session (`search_path`, `role`, `idle_session_timeout`,
  `default_transaction_read_only`, `statement_timeout`, `password_encryption`…), `CONNECTION LIMIT`,
  `IS_TEMPLATE`, `REVOKE CONNECT … FROM PUBLIC` — `superuser` parameters, `RENAME` (no `CREATEDB`) and
  `ALLOW_CONNECTIONS false` on the current database are refused to it. `verify.sql`'s own `#608` block
  admits **one** database setting, the time zone, and only once `Version20261011120000` ran — its row
  in `doctrine_migration_versions`, not the table's existence: a restored database or an interrupted
  first `migrate` carries the table without the time zone —, at the exact value that frozen migration
  writes, equal to `php.prod.ini`'s `date.timezone` (`PostgresRolesProcedureTest` ties the three); it
  refuses a connection limit, a template flag and a missing `CONNECT` for any of the three roles, and
  prints the settings' **names**, never their values (chosen by whoever holds the migrator).
  `REVOKE TEMPORARY` is let through: the application uses no temporary table. That block is pure SQL,
  so `DatabaseOwnerSettingsVerificationTest` replays it on the migrated test database: it passes as
  is, and refuses each alteration above with its cause. The procedure's own session ignores these settings (`PGOPTIONS`, above), so the one that
  would have stopped it reaches `verify.sql`. **And `roles.sql` repairs every one of them** (#610):
  otherwise the procedure would fail as long as one held, and a writing `exec` outside it is
  forbidden (#520). Its step 4, a pure-SQL `DO` block (no psql variable), puts the database back in
  the state the `#608` block expects, writing only on a gap (a healthy database is not rewritten, its
  default ACL stays implicit): the database settings reset, then the time zone set again **only once
  `Version20261011120000` ran** (its row, as `verify.sql` reads it) at the value that frozen migration
  writes — the migration cannot be replayed, it also converts the timestamps; `PostgresRolesProcedureTest`
  ties the value to it —; every `ALTER ROLE … IN DATABASE` setting, **whatever the role** (`verify.sql`
  refuses them all, a superuser-set one included); `CONNECT` granted back **to `PUBLIC`**, the image's
  default (`privileges.sql` revokes it on `postgres` and `template1` only; `pg_hba.conf` filters the roles);
  no connection limit, no template flag. `TEMPORARY` and `ALLOW_CONNECTIONS` are left as `verify.sql` leaves
  them; `CONNECT` is granted only when one of the three roles lacks it — `verify.sql`'s own criterion, so a
  database granting it to them alone is neither rewritten nor reopened to `PUBLIC`. The block sets its own
  `search_path`. Each repair prints a `NOTICE: #610 : …` — setting names and counts, never a value nor a
  role name (the superuser's is in a Secret) —, committed with the rest after `conforme`; the runbook says
  what such a notice means (an incident: someone holding the migrator, `cpg_app` or `cpg_reader` altered the
  database), gives a read-only `cpg_reader` query to run **before** the procedure to keep the values the
  repair erases, and says that a `#608` refusal left after it is a defect of the repair, never a hand fix.
  `DatabaseOwnerSettingsVerificationTest` replays the repair after every gesture the `#608` block
  refuses (refused first, then repaired, then the block passes), on healthy databases (state
  unchanged), and before the time-zone migration or without its table (none set).
  **The migrations table is never read under the superuser** (#610, measured on the pinned image): `cpg_migrator` owns it
  and can replace it with a view whose function — or give its column a type of `public` whose cast —
  would run **as the procedure's superuser** on the first read; `SET LOCAL ROLE` does not hold, the
  code read does `RESET ROLE`. So `roles.sql`'s step 4 reads it **once**, inside a `SECURITY DEFINER`
  function of `pg_temp` **owned by `cpg_migrator`** (its code runs under him, and the server refuses
  any role or session change inside a security-definer function), **dropped right after the call**
  (the planted code, under the owner, could redefine it for a later call), and hands what it read to
  the repair and to `verify.sql` through transaction settings (`cpg.migrations`,
  `cpg.derniere_migration`, `cpg.fuseau_migre`) — without them `verify.sql` refuses.
  `PostgresRolesProcedureTest` allows a single read of the table in all the files, in that function;
  `DatabaseOwnerSettingsVerificationTest` plants the trapped view. The other superuser statements of
  the procedure (`ALTER … OWNER`, `GRANT`, `has_*_privilege`, `pg_get_*def`) touch the catalog without
  running user code. **Owner of the database and of every object of `public`, the migrator may also
  empty the application's DML without any error** (#611, each gesture measured on the pinned image): row-level security
  without a policy (`cpg_app`, no `BYPASSRLS`, reads no row, its `UPDATE`/`DELETE` touch nothing); a
  schema named after `cpg_app` and open to it, which the default `search_path` (`"$user", public`)
  puts before `public`; a rule (`DO INSTEAD NOTHING`); a trigger that cancels (`BEFORE`) **or erases**
  (`AFTER`) the row; an operator of `public` more exact than the catalog's (the CVE-2018-1058 pattern:
  `username = 'x'` finds nothing). A list of gestures never closes, so `verify.sql`'s `#611` block is
  an **allow-list of the whole schema**: one canonical line per object outside the system — schemas,
  then in `public` relations (replica identity included), columns, indexes, constraints, triggers and
  rules with their enabled state, policies and their roles, inheritance, sequences, publications,
  functions, operators, types, casts and the rest of the catalog —, without owners or privileges
  (#575 checks those) nor dropped columns, read under a fixed `search_path` (`pg_get_*def` qualify a
  name by it), each line hashed (a line holding a newline cannot pass for two), and the `md5` of the
  whole compared with the fingerprints admitted for the **migrations applied: the latest and their
  count** (`''`, 0: a fresh database; the count tells apart a migration merged after a more recent
  one, which keeps the latest unchanged). An empty migrations table (a first `migrate` interrupted)
  has its own refusal. Measured: a dev database migrated step by step since the project's start, a
  fresh one migrated under a non-superuser owner to the same version, and a `pg_dump` restoration all
  give the same lines. Hashes only in a refusal, never a name; the runbook holds a byte-identical copy
  of the query (`PostgresRolesProcedureTest` pins both) to name the lines that differ. Every
  fingerprint is kept: a procedure played from a checkout ahead of the environment still knows its
  migrations, and one behind it (or a database older than the fingerprints) is refused as such, not as
  an alteration. Several fingerprints may be admitted for the same migrations — a PostgreSQL bump that
  changes how a definition is printed needs two until every environment has it; the image is pinned
  identically in `.env`, `versions.lock`, `postgres.yaml` and CI's `test-backend` service
  (`tools/tests/postgres-image-pins.test.sh`).
  **Every migration adds its fingerprint to that block in the same change**:
  `DatabaseOwnerSettingsVerificationTest` computes the migrated test database's with the block's own
  query (between its « empreinte » markers) and turns red, its message giving the line to add; it also
  replays the block — measured premises, then each gesture refused, one family of lines at a time,
  and for a gesture on an object a migration would create (a rule disabled, a policy given to another
  role), the fingerprint before and after. Proving such a test still bites takes a
  mutation that keeps the reference database's fingerprint (replace a column by the value it has
  everywhere there), or every case fails for the wrong reason. Not a boundary against whoever holds
  the migrator — they can delete rows outright —, a net against a persistent gesture that would cut
  the application silently. **Nothing repairs these states** (unlike `#608`'s, which `roles.sql`
  repairs, #610): the procedure fails as long as one holds, and a writing `exec` is #520's exception
  alone; `cpg_reader` is a second net against an operator's mistake, not a boundary against whoever
  holds `exec` (`trust` on the local socket gives the superuser too) — a `port-forward` or an
  ephemeral container reaches the loopback, where only `cpg_reader` is trusted, so it reads every
  table without a password but does not get the superuser. `verify.sql` **fails the procedure** on any
  expectation betrayed (psql exits 3 before the COMMIT), so a false state never commits. The
  passwords never cross the cluster network (#593): the exec stream, then the local socket.
- **The bootstrap superuser is reachable from inside the `postgres` pod only** (#593). The superuser
  has no network use (#575), yet the image's default `pg_hba.conf`, ending on
  `host all all all scram-sha-256`, would let any pod of the namespace reach it with the password of
  `postgres-credentials` (still in Secret Manager and readable by the CI token, §4) — a leak would
  give back everything #575 removed. **`k8s/base/postgres-pg_hba.conf`** replaces it, mounted by the hashed `postgres-hba`
  `configMapGenerator` (subPath, so a change **restarts PostgreSQL**, `Recreate`: a short outage, in
  the release notes, validated in preprod like any change of that workload) at
  `/etc/postgresql/pg_hba.conf`, read through `-c hba_file=…` in `postgres.yaml`'s `args` — outside
  PGDATA, so valid on an initialised PVC, and the entrypoint passes the same args to its init server
  (socket only). Rules: `local all all trust` (as in the image: probes, entrypoint, the roles
  procedure), then `host postgres,template0,template1 all all reject` (#597: `PUBLIC` keeps `CONNECT`
  and `TEMP` on the image's databases, so the role line would let both roles into `postgres` and
  `template1`, and the reader's loopback `trust` would let it in too — measured; the application's
  database is named in a Secret, so the image's are refused by name, for every role and address,
  **before** every accepting rule, loopback included. That is an exclusion: a database the superuser
  creates later — a restoration — would escape it, hence `verify.sql`'s checks (below) and
  `privileges.sql`'s `REVOKE CONNECT, TEMPORARY ON DATABASE postgres, template1 FROM PUBLIC`, the
  second barrier; Adminer needs the database name typed in, an empty field means `postgres`), then
  loopback `trust` **for `cpg_reader` only** (the `SHOW` of the keepalives over `127.0.0.1`) — the
  image's `host all all 127.0.0.1/32 trust` gives the superuser without a password to a
  `kubectl port-forward` or an ephemeral container (`kubectl debug`), which reach the pod's loopback
  (measured) —, then `host all cpg_app,cpg_migrator all scram-sha-256`,
  `host replication all all reject` (`all` does not cover replication: refused by its own rule, not
  for lack of one), and `host all all all reject`. **The role line's address stays `all`** (#597, measured in preprod): a pod
  arrives with its own pod address (no NAT), but the node hosting `postgres` arrives with its
  `cilium_host` address, **inside the pod range** too — indistinguishable from a pod, drawn anew when
  Kapsule replaces the node, and the path of any `hostNetwork` pod of that node, whatever its
  namespace, since Cilium applies it no NetworkPolicy —, while a pod of another namespace or another
  node never reaches the server (`default-deny-ingress` + `allow-datastores-from-app`, timeout
  measured). A pod range in `pg_hba` would refuse nothing that can connect today (only a future
  NodePort or hostPort exposure, SNATed to the node's address, which no manifest makes); don't add one
  without measuring again (the measure: a fictitious role from `kubectl debug node/…`, refused by the
  last `reject`, which quotes the address it saw). **Named roles, never an exclusion**: the
  bootstrap's name lives in a Secret, not in the repo — a new role reaching the network is added to
  that line on purpose. `pg_hba` cannot tell one pod from another, so **no TCP rule can admit the
  superuser "for one Job only"**: that is why the roles procedure is an `exec`, and why a rollback of
  the application to the superuser URL (#575's rollback) needs #593 undone first. `NOLOGIN` on the
  bootstrap was ruled out (the probes and the entrypoint use it). Adminer is a pod like any other:
  `cpg_app` or `cpg_migrator` only. Two guards: `PostgresHbaTest` pins these rules, their order, the
  hashed generator, the arg and the mount (every accepting TCP rule either loopback `trust` for
  `cpg_reader` alone or SCRAM for the two named roles — never `all`, a `+group`, an `@file`, a
  `/regex` as its role —, no `include` anywhere in the file, replication named only by its reject
  rule; a list written with blanks around a comma is refused — PostgreSQL reads it, the test's split
  would shift the fields);
  `tools/check-postgres-hba.sh` (`make postgres-hba`, CI job `postgres-hba`, needed by
  `release-version`) boots the pinned image under the manifest's `runAsUser` with the file and
  **measures** it: every role admitted or refused as the rules say — over the network, on the
  loopback (`127.0.0.1`, `::1`) and the superuser on the socket —, `cpg_app`/`cpg_migrator` refused
  with a wrong password, the image's databases (`pg_database` must hold exactly `postgres`,
  `template0` and `template1` beside the application's) refused by `pg_hba.conf` **before any
  authentication**, replication included — `pg_isready` is replayed but authenticates nobody, so it says the server answers, nothing about the
  file. An invalid file stops the server at init, and `rollback-preprod` does not undo `postgres`.
  Neither proves a boot on an initialised PVC: preprod does — and **in the cluster, `verify.sql`
  checks the file the server actually reads** at every pass of the roles procedure (`hba_file` equal
  to `postgres.yaml`'s path, no rule in error, no `trust` off the socket but for the reader, last rule
  `reject`, the image databases' `reject` before every accepting rule, no database besides the
  application's and the image's three, an application database not named like one of them, no
  `CONNECT`/`TEMPORARY` left to `PUBLIC` on `postgres` and `template1` — each case played on the
  pinned image, refused in exit 3): an args patch or an old
  manifest would bring back PGDATA's file, or an older ConfigMap, in silence. **The preprod pipeline
  measures it at every deploy** (#596), in its own job `postgres-hba-preprod` (#600):
  `tools/run-job.sh` launches `k8s/base/postgres-hba-probe-job.yaml` (outside kustomize, the server's
  image written in it — run-job.sh's image argument is `sans-objet` —, waited 120 s, deadline 180),
  which tries `database` over TCP as the bootstrap superuser **without a password**, on the
  application's database **and on `postgres`** (a rule reopening the superuser on another database
  would pass otherwise), then as **`cpg_app` on `postgres`**, without a password either (#597: the
  superuser is refused everywhere by both the #593 and the #597 files, so only the application role tells a ConfigMap
  without the image databases' `reject` — a password prompt; an application database named
  `postgres` prompts too), and exits 0 only when all three answer `pg_hba.conf rejects connection`
  (last line `#596 : OK`): `reject` refuses before any authentication, so the pod gets the
  superuser's *name* and the database's from `postgres-credentials` key by key (`secretKeyRef`, never
  `envFrom`, which would bring `POSTGRES_PASSWORD`), `PGPASSWORD` refused, `PGPASSFILE` pointed at a
  path that cannot exist. A password prompt (PGDATA's file admits the superuser by SCRAM), a
  connection or a server still unreachable after 4 tries (`Connection refused` / `timeout expired`
  only — a fresh PVC's init server listens on the socket alone; ~55 s at worst, the second database
  skipped then) fail it, named on a `#596 : ÉCHEC (…)` line the pipeline's `::error::` points to.
  **That failure never triggers `rollback-preprod`** (#600): the rollback undoes `backend`, `worker`
  and `frontend`, which the probe does not accuse, and never `postgres`, which it does — inside
  `smoke-test-preprod`, the probe's failure would take the release's application away from preprod,
  leave the superuser open, skip the #470 message probe placed after it and make the diagnosis run the
  previous release against the faulty `postgres`. So the job runs beside `smoke-test-preprod` and
  `audit-preprod`, `needs: [deploy-preprod]` only (whose `Deploy` step already waits for `postgres`'
  rollout), and is **not an ancestor of `rollback-preprod`** (`failure()` there is true as soon as any
  ancestor fails, directly or not); the release stays in place against the `postgres` to fix — fix it
  by a new commit on the `release/*` branch. It is the **third required check of `main`**'s ruleset
  (`MAIN_REQUIRED_CHECKS` in `tools/github-settings.sh`, a manual run of the script after changing
  it): a red job outside the required checks would let the release PR merge, and `deploy-prod`'s
  guard 3 (a `success` run required) would refuse only **after** `main` moved. `rollout undo` of
  `postgres` was ruled out (a `Recreate` outage on a workload carrying state, back to a revision that
  may itself predate #593 and read PGDATA's file). `tools/check-release-gates.sh` (its test,
  `tools/tests/check-release-gates.test.sh`, runs in `tools-tests`) keeps the three facts together:
  the probe's manifest launched by some job and that job no ancestor of `rollback-preprod`; every
  required check of both rulesets a job of `pipeline.yml`; every job that `needs` `deploy-preprod`,
  the rollback aside, a required check of `main`. A new probe accusing a workload the rollback does
  not bring back joins its `NO_ROLLBACK_PROBES` list. **The datastores' `rollout status` checks stay
  in `smoke-test-preprod`** (#603), the one case where a check accusing `postgres` or `rabbitmq` may
  trigger the rollback. A datastore that does not come up with the release — new manifest, node, PVC,
  pull, or already down — fails `deploy-preprod`, whose `Deploy` step waits for all three
  (`wait-rollout.sh`, 300 s), so there is no rollback at all. What the smoke still sees is a datastore
  `Ready` at the end of the deploy and down a minute or two later; moving the check out, as for the
  probe, would not spare the rollback, since the login-throttling step (accounts and `cache.app` in
  PostgreSQL) and the #470 message probe (RabbitMQ) would trigger it anyway — and those test the
  application, they must. The rollback is then useless on the cause, never harmful (it is the smoke's
  ordinary rollback). So the check comes **first in the job**, right after `Configure kubectl`, so
  that the first red names the workload instead of a « broken » throttling; no offline guard holds
  that order, it only serves the diagnosis.
  The probe is **`k8s/base/postgres-hba-probe.sh`**, copied byte for byte (bar the final newline,
  which Symfony's YAML parser drops) into the Job's `command` — under `k8s/`, because the dev
  container mounts it and not `tools/` —, written without any `${…}` (run-job.sh refuses one left
  after envsubst, comments included), and it **masks the superuser's and the database's names** in
  what it prints — the Job's logs reach the pipeline's —, **for display only**: the patterns are
  matched on psql's raw output (masked first, a secret name overlapping « connection » breaks the
  match), in one pass, longest name first, also truncated to 63 bytes, under a single `<masqué>`
  marker (no `<superutilisateur>_prod` leftover, no visible equality of the two).
  `PostgresHbaProbeJobManifestTest` pins the copy, the image and uid of `postgres.yaml`, the env
  (`PGHOST` = the Service's name), no password, the hardening; `tools/check-postgres-hba.sh` runs the probe file as the Job runs (manifest's uid,
  `--read-only`, `PGCONNECT_TIMEOUT`) **both ways, in CI**: success against the repo's file, trap
  names included (`conn`, `conn_rejects`); failure, named, against every file the probe must accuse
  (no `-c hba_file`; the `postgres` database reopened, the reopening rule copied in **first** in the
  file, where #597's `reject` cannot hide it; #597's `reject` missing) and on a closed port —
  and checks no output names the role or the database; it refuses a missing probe file and wants the
  `#596 : OK` marker (Docker mounts a missing source as an empty directory, where `sh` exits 0 —
  measured). **In preprod only the passing direction is measured**, at every deploy (reopening the
  superuser there to see the probe fail is not worth it). Preprod only, like every smoke: in prod the
  runbook's command stays the release's check.
  **Dev and CI keep the image's file** (single superuser role, on purpose, see above).
