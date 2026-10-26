# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Where the rest lives: `.claude/rules/` (issue #346)

This file only carries what applies everywhere, plus what runs from the shell (git flow, release,
`make` commands, secrets). Everything specific to one area lives in a **path-scoped rule** under
`.claude/rules/`, loaded automatically when a file matching its `paths:` is read or written.

- **Read before you write.** A rule is loaded by the `Read`/`Write`/`Edit` tools, **never by a shell
  command**: `cat`, `sed`, `grep` or `head` on a file load nothing (verified on 2026-10-05, see #346).
  Before modifying a file of an area, or running a command that writes there, read at least one file of
  that area with `Read`. When in doubt, read the rule itself.
- **Where a new invariant goes.** In the rule of its area, or in a new rule with its own `paths:`. This
  file only receives what is cross-cutting or shell-driven. `tools/check-claude-rules.sh` (run by
  `tools-tests`) fails on a rule without `paths:`, on a glob that matches no tracked file, and on this
  file or a rule growing past 50 000 bytes (#605: past `Read`'s ceiling a rule is read truncated). A
  rule that outgrows it is split by sub-domain, the original file keeping an index of where each part
  went.

| Rule (`.claude/rules/`) | Area | Read it before… |
|---|---|---|
| `backend-outillage.md` | backend quality tooling, Monolog | touching PHPStan/Rector/Psalm/`lsp:check`, a CI quality job, logging |
| `backend-architecture.md` | backend DDD layout, UUID v7 keys, timestamps — and the index of the two below | adding an entity, a bounded context, a migration |
| `backend-integrite.md` | authenticated envelopes and cache values, derived keys, `KeyedMac` | touching Messenger serialization, a cache pool, an HMAC, `APP_SECRET` |
| `backend-conventions.md` | exceptions, `#[SensitiveParameter]`, regexes, imports, `sprintf` | writing any backend class |
| `security-user.md` | `Security/User` | touching accounts, invitations, password setup |
| `security-authentication.md` | `Security/Authentication` | touching login, JWT, cookies, CSRF, a `kernel.request` guard, the audit log |
| `portfolio-contenus.md` | `Portfolio/*` content contexts — and the index of the three below | touching a content context |
| `portfolio-ordre.md` | ordering and translation groups: model, trigger, scope lock | touching `position`, `translation_group`, the order scope lock |
| `portfolio-ordre-migrations.md` | migrations on the eight ordered tables, the trigger's blind spots | writing a migration that touches an ordered table |
| `portfolio-ordre-ecritures.md` | application refusals, writes under the scope lock, detached instances | touching an ordered content's `Administrator` or a write path |
| `portfolio-watch.md` | `Portfolio/Watch` | touching the tech watch, `/stack`, an outbound call |
| `ai.md` | `Ai/` (translation, career assistant) | touching anything that calls a language model |
| `mcp.md` | `Mcp/`, the MCP server on `/api/mcp` — and the index of the three below | touching a tool, `mcp.yaml`, the `mcp` firewall |
| `mcp-prompt-d8.md` | the prompt `present_for_role`, the D8 guard (the author never named) | touching the prompt, a published description, `AuthorNeverNamedTest` |
| `mcp-journaux.md` | the SDK's `mcp` channel, the call log `mcp_usage` | touching `Mcp/Infrastructure/Log`, `monolog.yaml`'s MCP handlers |
| `mcp-oauth.md` | `Mcp/Connection`, the OAuth 2.1 authorization server of phase 2, the consent page | touching an OAuth endpoint, a token, the consent, `ingress-oauth-metadata.yaml` |
| `mcp-oauth-cycle-de-vie.md` | lifetime of an MCP connection and its tokens (#649): durations in configuration, monotone expiry deadlines | touching a token or connection lifetime, `ConnectionLifetimes`, the expiry, `messenger-purge-cronjob.yaml` |
| `backoffice-api.md` | backoffice API, API Platform pattern, API errors | adding an endpoint, an API Platform resource, an exception mapping |
| `seeding.md` | `app:*:seed`, reference-data migrations | touching a seed command or the meaning of seeded data |
| `frontend.md` | frontend layers, API-backed content, i18n, SEO, lint | any frontend change |
| `frontend-backoffice.md` | `/admin`, set-password, route guard | touching an admin page or the router |
| `frontend-a11y.md` | accessibility | adding or changing UI |
| `images-docker.md` | Dockerfiles, frontend image, share cards | touching `docker/`, the image build, `.dockerignore` |
| `deploiement.md` | Kubernetes manifests, ConfigMaps, pod security, stateful PVCs — and the index of the four below | touching `k8s/`, `docker/`, a workflow |
| `deploiement-pipeline.md` | deploy Jobs, `kubectl exec` boundary, migration before rollout, deploy waits | touching a Job, `pipeline.yml`'s deploy jobs, `run-job.sh`, `wait-rollout.sh`, a migration |
| `deploiement-postgres.md` | PostgreSQL roles, bootstrap superuser, `pg_hba.conf` | touching `postgres*`, `postgres-roles/`, the migrator, a migration |
| `deploiement-etat-hors-pod.md` | no state on the pod (ADR 0005) | touching `cache`/`lock`/rate limiters, a volume, `lock_timeout` |
| `deploiement-nginx.md` | nginx rate limits, headers, `security.txt` | touching an nginx conf, a rate-limit zone, a response header |
| `release.md` | release flow, pipeline internals | touching `pipeline.yml`, a release script, `RELEASE_NOTES.md` |

## Goals

1. This project has vocation to be a personal demonstration of the best practices for a modern PHP application with a separated frontend (Vue + TypeScript).
2. The frontend is decoupled from the backend.
3. The backend is a Symfony API-only application.
4. The resulting application is fully deployable as a Docker Compose stack.
5. The resulting application is fully testable (frontend and backend).
   - The frontend is fully testable with Vitest.
   - The backend is fully testable with PHPUnit.
6. The resulting application is fully documented (frontend and backend).
   - The frontend is fully documented with Vitest.
   - The backend is fully documented with PHPDoc.
7. The resulting application is fully linted (frontend and backend).
   - The frontend is fully linted with ESLint.
   - The backend is fully linted with PHPStan.
8. The security must be a top priority.
9. The website implements the three access tiers of ADR 0003 — anonymous, base, trusted; the vocabulary
   (palier anonyme / de base / de confiance, accès de base, nominatif) is in `CONTEXT.md`. Invariants, each
   enforced **server-side**:
   - **No nominative data without `ROLE_TRUSTED`**: nothing that could identify me — the nominative CV
     (`GET /api/cv`: real name, employers, career history) and `GET /api/me` — reaches a caller without it.
     "Expose", not "display": hiding a field client-side is presentation, never protection; the guard is
     `ApiRouteExposureTest` (see "Backoffice" below).
   - **The base tier (`ROLE_USER`) is discretion, not protection**: anyone gets it in one click, with no
     account (`POST /api/account/base-access`: a 15-minute JWT carrying **exactly** `ROLE_USER`), and every
     account has it (`CpgUser::getRoles()`). It never gates nominative data: its contents
     (`Portfolio/CaseStudy`, `Portfolio/AnonymousCv`) are publishable by construction — no name, employer
     nor client —, never served under the `^/api/cv` prefix (`ROLE_TRUSTED`), and never carry the
     professional chronology — it re-identifies even without names, even reduced to durations and sectors
     (ADR 0003 D5 amended, 2026-09-13).
   - **`ROLE_TRUSTED` is granted nominatively** by a `ROLE_SUPER` account via the invitation flow, never
     through a shared or published credential; `ROLE_SUPER` inherits it through `role_hierarchy` (the CLI,
     `app:user:create`, never grants `ROLE_TRUSTED` alone: `--role ROLE_SUPER` for the bootstrap, or a
     base-tier account).
   - **Never name a real account in this repo** (issue #78, pt 5): a valid username in a public repository is
     half a credential, `login_throttling` or not.
   - The About page is **public in full, on purpose** (audit C3): what it publishes is decided at authoring
     time. Don't reintroduce a conditional filter there (see `AboutContentResource`'s docblock).
   Mechanics: `docs/adr/0003`, `docs/adr/0001` (amended, invitations), `.claude/rules/security-authentication.md`
   (base access), `.claude/rules/frontend.md` (its expiry on the frontend).
10. The modifications must follow the project's git flow (spec 0006, archived under
    `.claude/specs/archive/2026-09-16-spec-0006-flux-de-release/`): `main` is exactly what runs in
    production and only advances by the merge of a `release/<X.Y.Z>` branch; `develop` is the
    integration branch; `feature/*`, `fix/*` and `hotfix/*` are cut from an up-to-date `develop`
    and return to it by PR (a hotfix differs only in that its release is cut right away — if
    `develop` then carries unfinished features, the release branch is cut from `main` with the
    hotfix cherry-picked, the one exception to "a release starts from `develop`"); a `release/*`
    branch is cut from `develop`, named after the version `tools/next-version.sh` computes, and
    ends in `main`. Never push to `main` or `develop` directly and never tag by hand: GitHub
    rulesets refuse both, and the pipeline does the tagging (see "Deployment invariants").
    A spec's tasks stack on the spec's own feature branch, `develop` receives the feature once
    (see "Task plans and spec archiving").
11. The resulting can be shown during an interview.
12. The resulting must be fully multilingual (French, English)

## Architecture

Two independently deployed apps behind a shared Docker network, generated by an internal
`create-symfony-project.sh` script:

- **`../backend`** — Symfony 8.1, API-only skeleton (`BACKEND_FLAVOR=api` in `../.env`, fixed at generation time —
  changing it requires manually rerunning `../docker/php/init-symfony.sh` logic). Exposes an API via **API Platform
  4.3** (`api-platform/doctrine-orm` + `api-platform/symfony`), persistence via **Doctrine ORM 3** on **PostgreSQL**,
  and async messaging via **Symfony Messenger** over **RabbitMQ** (`symfony/amqp-messenger`). No AssetMapper, and
  **Twig only for composing outgoing emails** (`symfony/twig-bundle` + `TemplatedEmail`, templates under
  `backend/templates/emails/`, see ADR 0001) — never for rendering pages: the frontend is fully decoupled.
- **`../frontend`** — Vue 3 + TypeScript + Vite, talks to the backend only via `VITE_API_URL` (see `.claude/rules/images-docker.md`, "Frontend build/deploy").

Request path in dev: browser → `web` (nginx, port `${HTTP_PORT}`, serves `../backend/public` and proxies
`*.php` via FastCGI to `backend:9000`) or directly to Vite's dev server on `${VITE_PORT}` for the frontend.

### Docker Compose layout (important: two sets of compose files exist)

The **root** `../docker-compose.yml` / `../docker-compose.override.yml` are the actual generated stack and are what
`make` targets use. `../backend/compose.yaml` / `../backend/compose.override.yaml` are leftover default recipes from
Symfony Flex's `doctrine/doctrine-bundle` (unused, not wired into `make` — don't confuse the two when editing
Compose config).

Root compose defines: `backend` (PHP-FPM, build context is the **repo root**, not `../backend`, so that the
production/preprod Dockerfile stages can `COPY backend/`), `web` (nginx), `database` (Postgres), `rabbitmq`.
`../docker-compose.override.yml` adds `frontend` (Node/Vite) and is loaded automatically by Compose.

Services communicate over the `app` bridge network by service name (`database`, `rabbitmq`, `backend`).

### Backoffice (`ROLE_SUPER`)

Content management for all of the bounded contexts (see `.claude/rules/`), plus user administration, gated end-to-end behind `ROLE_SUPER`
(the default role every account also has is `ROLE_USER`, cf. `CpgUser::getRoles()` — never sufficient here):

- **Non-negotiable rule for every new endpoint — "never send what the caller isn't entitled to"**: the API must
  never return protected data to an unauthenticated or unauthorized caller, *even when the frontend does not
  display it*. Hiding a field client-side is presentation, never protection — anyone can call the endpoint
  directly. Concretely: a new route is protected by default; making it public is a deliberate act.
  `tests/Security/ApiRouteExposureTest.php` enforces this automatically — it walks the router and asserts that
  **every** `/api` route outside its `PUBLIC_PATHS` allow-list answers 401/403 to an anonymous caller, that no
  `/api/backoffice*` path can ever be allow-listed, and that the whole backoffice answers 403 to an
  authenticated account lacking `ROLE_SUPER` (authenticated ≠ authorized). Adding a public endpoint therefore
  means adding an entry to `PUBLIC_PATHS` **with a written justification**; if you can't justify it, it isn't
  public. `tests/Security/RouterScopeTest.php` closes the boundary on the other side: any route compiled
  outside `/api` must be justified in its own `NON_API_PATHS` allow-list and protected by its own firewall
  and `access_control` rule, or the suite turns red. The same test carries `BASE_TIER_PATHS` (issue #78, invariant n°6): with a base-tier token
  (`POST /api/account/base-access`, `ROLE_USER`) **every** route outside `PUBLIC_PATHS ∪ BASE_TIER_PATHS`
  must answer 403, and every listed entry must actually open — so a new `ROLE_USER` content route needs its
  own justified entry there, and a `ROLE_USER` rule on an identifying route turns the suite red. Never
  weaken or delete that test to make a new route pass.
  **Every one of these guards reads the `test` container only, so never declare security or a route for a
  single environment** (issue #659; preprod and prod run `APP_ENV=prod`): `SecurityConfigEnvironmentParityTest`,
  `RouteEnvironmentParityTest` and, for the security services' compiled definitions (issue #698),
  `SecurityServicesEnvironmentParityTest` enforce it — details in `.claude/rules/backoffice-api.md`, « Authorization ».
### Deployment invariants (learned the hard way — don't undo these)

- **A secret is never passed as a process argument, and never typed in an agent session.** Arguments are
  readable in `ps`, in the shell history and in the transcript of an assistant session — that is what
  forced the 2026-09-16 password change. Rotation procedures (`k8s/README.md` §2bis for the preprod Basic
  Auth, and the Xdebug secret in `.claude/rules/deploiement.md`) go through files (`umask 077` + `mktemp -d`) and stdin: `htpasswd -niB`
  reading stdin, `scw secret … data=@file`, `gh secret set` from stdin — never `htpasswd -b`,
  `data="$(cat …)"` or `gh secret set --body`. Run them in a **separate terminal**, never behind a `!`
  prefix in a Claude Code session.
- **A release is a branch, the merge is the stop, the tag is a consequence** (spec 0006). The pipeline's
  internals — its three phases, immutable images, `deploy-prod`'s guards, `finalize-release`'s six steps,
  the `release-bot` deploy key, the rules for editing a workflow — live in `.claude/rules/release.md`. What
  holds from the shell:
  - **The version is computed, never chosen** — `tools/next-version.sh`. The branch is named
    `release/<that version>`, `RELEASE_NOTES.md` at the repo root starts with `# v<that version> — <title>`
    (em dash, strict), and `release-version` fails naming the three values if they disagree: rename the
    branch, don't touch the CI. `git fetch --tags` first, or the script may answer a version already shipped.
  - **`main` only moves by the merge-commit of the release PR** (squash and rebase are disabled repo-wide),
    once `smoke-test-preprod`, `audit-preprod` and `postgres-hba-preprod` are green on its head. Every push
    on `release/*` redeploys the single preprod: one release at a time.
  - **Never write `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]` or `[actions skip]` in a commit or
    merge message** — GitHub silently skips the push's run (it happened to the very commit that introduced
    `finalize-release`). Only `finalize-release`'s copy commit carries it.
  - **Never a `main` → `develop` PR** (issue #293): when `finalize-release`'s summary asks for a sync, run its
    `fix/sync-main-vX.Y.Z` commands as given.
  - **What to do by hand, in order**: `git switch develop && git pull && git fetch --tags`;
    `git switch -c release/$(tools/next-version.sh)`; write `RELEASE_NOTES.md`; open the PR to
    `main` as a draft; iterate until the run is green (a `fix/*` PR targets the release branch);
    mark ready, merge. Nothing else — no `git tag`, no approval click, no `develop` sync unless
    the summary asks for it (then run its `fix/sync-main-…` commands as given). Everything is
    testable offline: `tools/tests/*.test.sh` (run by the
    `tools-tests` job on temporary git repositories, shellcheck at `warning`+, actionlint pinned).

### Versions

All image/tool versions are pinned in `../.env` and mirrored in `versions.lock`. The only deliberate exception is
Composer, pinned to major branch `2` only (see comments in `../.env`) so 2.x patches land on every `make build`
without ever silently jumping to Composer 3.

The frontend toolchain's history (the `jsdom` pin, Node 26's `localStorage`, the Vitest 5 fix, `EBADENGINE`
warnings) lives in `.claude/rules/frontend.md`, « Toolchain history ».

## Commands

Run from the repo root; requires Docker Compose v2.

```bash
make help              # list all targets
make up                # start the stack (detached)
make down              # stop the stack (volumes kept)
make restart           # down + up
make logs              # follow logs for all services
make sh                # shell into backend as the `dev` user
make sh-front          # shell into the frontend container
make db-migrate        # doctrine:migrations:migrate --no-interaction
make consume           # messenger:consume async -vv (Messenger worker)

make front-test        # vitest run, in the container
make front-lint        # eslint, in the container
make front-build       # vue-tsc -b + vite build, in the container
make back-test         # phpunit, in the container
make back-quality      # phpstan + rector + psalm + lsp:check, in the container
make back-lsp          # symfony lsp:check alone (Symfony Language Tools)
make back-style-fix    # sort `use` + qualify compiler-optimized natives (pinned php-cs-fixer, #398)
```

`make init` and `make front-init` (re)run the Symfony/Vite project scaffolding — both are already applied in
this repo; `init-symfony` is idempotent (no-ops if `../backend/composer.json` exists) but `front-init` is
interactive and only meant to be run once.

Build/deploy images (no Compose, produce registry artifacts — default `TAG` is the short git SHA;
the pipeline passes `<version>-<sha>`, e.g. `0.14.0-2c86b65`, never a bare version):

```bash
make build-prod                                              # backend prod image
make build-preprod                                            # backend preprod image (prod + Xdebug)
make build-front-prod API_URL=https://api.example.com TAG=0.14.0-2c86b65     # frontend prod image
make build-front-preprod API_URL=https://api-preprod.example.com TAG=0.14.0-2c86b65
```

### Backend day-to-day (inside `make sh`)

Standard Symfony/Composer/Doctrine CLI applies: `php bin/console ...` (MakerBundle is available in dev —
`make:entity`, `make:controller`, etc.), `composer require ...`. PHPUnit is configured — `php bin/phpunit`
runs the full suite (see `.claude/rules/backend-outillage.md` for CI/PHPStan/Rector wiring).

Tech-watch upkeep (`Portfolio/Watch`, ADR 0002) — in dev these are run by hand, in production by the
`watch-refresh` CronJob:

```bash
php bin/console app:watch:seed            # catalogue of tracked products (declines if already populated)
php bin/console app:watch:build-manifest  # composer.lock [+ package-lock.json] -> package manifest
php bin/console app:watch:refresh         # queries endoflife.date + OSV.dev, writes the snapshots
php bin/console app:watch:refresh --dry-run
```

Without a manifest (the normal state of a dev container — it is built into the production image, and
gitignored), `/api/watch` reports the vulnerability volet as *not analysed*, which is the intended
behaviour, not a bug.

**No `.env.<env>` file is versioned in `backend/`** — `.env.dev` and `.env.test` used to be (Symfony's own
default convention: `.env.$APP_ENV` is normally committed), but both were untracked after a GitGuardian alert
flagged a disposable `JWT_PASSPHRASE` placeholder value as an exposed secret; only `backend/.env` (no real
value, `APP_SECRET=` empty) stays tracked now. `CONTACT_SENDER_EMAIL` / `CONTACT_RECIPIENT_EMAIL` are empty
there too (audit I4 — real addresses in a public repo feed harvesters and reveal the Scaleway TEM sending
identity); their values come from outside the repo: `.env.local` in dev, Secret Manager in preprod/prod, and
**`phpunit.dist.xml` in test**, since the test env never loads `.env.local` — with `force="true"`, without
which Dotenv overwrites them with the empty value from `.env`. `ANTHROPIC_API_KEY` (ADR 0004) follows the
exact same route, with a dummy forced value in test: no test may reach the real API. `backend/composer.json` is
tracked, so `init-symfony.sh` skips the project creation on every clone, but since #481 it still **creates
`.env.local` / `.env.test.local` when missing** (never overwriting them) and sets `APP_SECRET`; an existing
`.env.local` older than a new line (those two, say) still needs it added by hand. `make init` runs the copy
baked into the dev image (`/usr/local/bin/init-symfony`): `make build` after changing the script.
Don't re-add either file to git — extend `docker/php/init-symfony.sh`
instead if a fresh-clone default needs to change. Locally, `init-symfony.sh` generates both `.env.local` (dev)
and `.env.test.local` (test) with the Docker-internal `DATABASE_URL` (`database:5432`); since Symfony never
loads `.env.local` when `APP_ENV=test`, the test env needs its own `.env.test.local` — same database name as
dev is fine, `config/packages/doctrine.yaml`'s `dbname_suffix: '_test'` (Flex default, meant for ParaTest)
already separates the real test DB (`<name>_test`) from dev data. **`APP_SECRET` comes from the same two
files** (issue #481): `.env` leaves it empty, and since #455 an empty secret fails every derived key (CSRF,
Messenger envelopes, cache values) — the dev contact form answered 500. `docker/php/dev-env-secrets.sh`,
called by `init-symfony.sh` and by `make dev-secrets` (in the container), sets a random one, distinct per
file, only where it is missing or empty — `APP_SECRET=""`, `''` or blanks read as empty, as Dotenv reads
them —, and never prints it (its scratch file lives outside the repo, removed on exit). `JWT_PASSPHRASE` **must be
identical between dev and test** — `lexik_jwt_authentication.yaml` doesn't split the keypair path per
environment, so one `config/jwt/*.pem` (gitignored) is shared by both; locally it is empty in both (each
resolves it from `.env` unless a local file sets it), the keypair being generated without one — the script
never touches it, an empty value is not a missing one. After changing it, regenerate the keypair with
`php bin/console lexik:jwt:generate-keypair --overwrite`. CI
(`.github/workflows/pipeline.yml`, job `test-backend`) doesn't use any of this — it writes its own
`backend/.env.test.local` with fresh random secrets at the start of every run.

If `make sh` / `docker compose exec backend` shows stale source (edits made on the host not reflected in the
container — this bit a `.env.test.local` edit once), the bind-mount view has desynced — `docker compose
restart backend` resyncs it (same symptom/fix as the frontend note below).

### Frontend day-to-day

**Use the make targets — they run in the container, which is the point** (issue #26):

```bash
make front-test     # vitest run
make front-lint     # eslint
make front-build    # vue-tsc -b + vite build
make back-test      # phpunit
make back-quality   # phpstan + rector + psalm + lsp:check
```

The host's Node version must not influence the project's behaviour. The container pins `NODE_TAG`; a
workstation pins nothing, so anything run by hand there gives a machine-dependent answer — and that gap had
already leaked into `package.json`, where `jsdom` was held back to accommodate an older host Node. It no
longer is. Prefer these targets over `make sh-front` + `npm …`, and add a target rather than documenting a
manual incantation when a new command becomes routine.

`make sh-front` remains, for exploring inside the container. `npm run test:watch` (watch mode) has no target
on purpose — it is interactive, so run it from that shell.

If `make sh-front` / `docker compose exec frontend` shows stale source (edits made on the host, e.g. a new
`package.json` dependency, not reflected in the container), the container's bind-mount view has desynced —
`docker compose restart frontend` resyncs it.

### Services and ports (dev)

| Service | Role | Host access |
|---|---|---|
| `web` | nginx, single HTTP entrypoint for the backend | `http://localhost:${HTTP_PORT}` (8080) |
| `backend` | PHP-FPM + Symfony | via `make sh` |
| `database` | PostgreSQL | `localhost:${POSTGRES_PORT}` (5432) |
| `adminer` | Interface admin PostgreSQL (dev uniquement) | `http://localhost:${ADMINER_PORT}` (8081) |
| `rabbitmq` | RabbitMQ + management UI | `http://localhost:${RABBITMQ_UI_PORT}` (15672) |
| `frontend` | Node + Vite dev server | `http://localhost:${VITE_PORT}` (5173) |

If another developer clones the repo, they must set `UID`/`GID` in `../.env` to their own (`id -u`/`id -g`) and
run `make build` — these are baked into the dev image's `dev` user, not read at container start.

## Agent skills

### Issue tracker

Issues live in GitHub Issues for this repo. See `docs/agents/issue-tracker.md`.

### Task plans (`tasks/`) and spec archiving

The planning skill writes `tasks/plan.md` and `tasks/todo.md` at the repo root. **They live on the
feature branch, for the duration of the feature, and never reach `develop`** (rule set on
2026-09-15): `tasks/` does not exist on `develop` or `main`, and a PR that still carries it is not
ready to merge. Workflow:

1. During the feature, commit `tasks/` on the feature branch as needed (checkpoints, ticked tasks).
   **Every task of a spec lands on the feature branch that carries the spec, never directly on
   `develop`** (rule set on 2026-09-16): a task gets its own branch, stacked on the previous
   task's, and its PR targets the previous task's branch (the first one targets the spec's
   branch); when a task merges, the next PR is retargeted **before** its base is deleted (see
   the stacked-PR trap in memory). `develop` receives the feature **once**, through the PR the
   spec's branch opened at the start, which becomes the closing PR of step 2.
2. In the merge into `develop` that **fully closes** the feature, build the archive folder
   `.claude/specs/archive/<YYYY-MM-DD>-<slug>/` (closing date, one folder per feature — see the
   `README.md` there for the existing ones) with, **since 2026-09-16** (issue #192):
   - the spec itself, `git mv`ed from `.claude/specs/<NNNN>-<slug>.md` (so `.claude/specs/` only
     lists specs still open — a "livrée" status line is no longer what tells them apart), and
   - the **whole `tasks/` directory**, `git mv`ed as-is to a `tasks/` subfolder (not just
     `plan.md`/`todo.md`: whatever else the feature parked there, checkpoints, notes, goes with it),
   which removes `tasks/` from the tree in that same PR. Update the README's table there, and fix
   the links that pointed at the spec's old path (`CLAUDE.md`, ADRs, other specs — grep for it).
3. A plan whose feature is still open is not archived; a feature that spans several PRs keeps
   `tasks/` on its branches until the last one. A feature with no spec (an audit remediation, an
   ADR-only change) archives `tasks/` alone.

The archived files are frozen: they describe the plan as it stood at closing, ticked boxes,
checkpoints and open questions included. The five plans written before the rule existed were
extracted from git history and archived in one go (PR #189), as flat `plan.md`/`todo.md` files —
that flat layout is left as is, not migrated — and their specs (0001 to 0004) joined them on
2026-09-26, so `.claude/specs/` really does list only the open specs (spec 0001's plan came from a
since-deleted `.claude/plans/`, the location that predated `tasks/`). A "livrée" status line inside
a spec is never what tells open from closed; its location is.

### Domain docs

Single-context layout — root `CONTEXT.md` + `docs/adr/`. See `docs/agents/domain.md`.
ADRs (status, dates and amendments live in each ADR):
- `docs/adr/0001-admin-user-provisioning.md` — invitation-by-email flow, `email` stored, Twig for e-mails.
- `docs/adr/0002-veille-technique.md` — `Portfolio/Watch`: outbound calls out of the render path, relevé in
  DB, public aggregate vs `ROLE_SUPER` detail, manifest built at `docker build`.
- `docs/adr/0003-paliers-d-acces.md` — the three access tiers. Read it before touching `access_control`,
  `CpgUser::getRoles()` (it grants `ROLE_USER` unconditionally, which is why a tier was added *above*) or
  `BaseAccessController`; and before adding a revocation list, lengthening `token_ttl` or gating anything
  more sensitive than the CV: **`POST /api/logout` does not revoke the JWT** (accepted risk, amended
  2026-09-21).
- `docs/adr/0004-assistance-ia.md` — anything that calls a language model: one importing class behind an
  interface, bundle pinned exact, no call from a public render path, only publishable backoffice content
  leaves (the nominative CV only to the site's host or self-hosted), never a call on behalf of the base
  tier, human in the loop, bounded cost, offline tests. Read it before adding any `Symfony\AI` usage or a
  new `ai.agent`.
- `docs/adr/0005-etat-hors-du-pod.md` — no application state on the pod's filesystem. Read it before
  touching `cache.yaml`, adding a rate limiter, or mounting anything under `var/`.
- `docs/adr/0006-serveur-mcp.md` — the MCP server (issue #379): read-only, no model call, serves
  nothing a route of the same tier doesn't; anonymous + base tier without a token (amends 0003 D1);
  the trusted tier through an OAuth 2.1 MCP connection only, checked in DB on every request, never a
  cookie on `/api/mcp`. Read it before touching `/api/mcp`, `mcp.yaml` or anything OAuth.
