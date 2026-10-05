# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
9. In its final state, the website implements the three-tier access model of ADR 0003 (anonymous, a
   discretion-only base tier, and a nominative trusted tier) rather than a shared generic guest account.
   Without `ROLE_TRUSTED`, the site must not expose any personal information that could identify me. This
   information becomes available once `ROLE_TRUSTED` is granted.
   - **Scope, as settled on 2026-09-04** (audit C3), **role updated 2026-09-12** (ADR 0003 D2/D4): this
     covers the **CV** (`GET /api/cv`, `ROLE_TRUSTED`) —
     real name, employers, career history — and `GET /api/me`. It does **not** cover the About page: its
     content is deliberately public in full, including the "hobbies" panel, because that content is authored
     through the backoffice and what gets published is decided at authoring time. Don't reintroduce a
     conditional filter there (see `AboutContentResource`'s docblock).
   - "Expose", not "display": hiding a field client-side is presentation, never protection. The enforcement
     rule and its automated guard live under "Backoffice" below.
   - **There is a base tier below the trusted one** (`ROLE_USER`, ADR 0003 D1/D2): granted to anyone
     authenticated, publishable and non-identifying by construction — it must never gate CV-level data.
     Only `ROLE_TRUSTED` does, and it is granted **nominatively** by a `ROLE_SUPER` account through the
     backoffice invitation flow (`CpgUserInviter::invite`), never via a shared or published credential.
     `ROLE_SUPER` inherits `ROLE_TRUSTED` via the `role_hierarchy` in `security.yaml`. See `docs/adr/0003`
     for the full model, `docs/adr/0001` (amended) for the invitation mechanics.
   - **The credential-free way to reach the base tier is built** (ADR 0003 D6): `POST /api/account/base-access`
     (`BaseAccessController`, per-IP rate-limited, double-submit-CSRF-excluded like `/api/contact` but
     guarded by `LoginCsrfRequestListener`, see `Security/Authentication` below) issues a 15-minute
     JWT carrying exactly `ROLE_USER`, with **no account materialised in the DB**. The frontend's
     "Accès instantané" CTA calls it; "Terminer cet accès" (issue #65) ends it early through the
     unchanged `POST /api/logout`, which expires the cookie whoever holds it. The tier is read from the
     HTTP status of `GET /api/me` (401 anonymous / 403 base / 200 trusted) — no dedicated endpoint.
     The response body carries `expiresAt`, the only way the frontend can know when the httpOnly cookie
     dies: `useAuth` arms a timer on it (remembered in `localStorage` to survive a reload, a past value
     is ignored — the server is the truth) and any 401 on base-tier content calls
     `markBaseAccessExpired()`, so the header never shows "Accès de base" above a page asking for it.
   - **Content that tier carries** (ADR 0003 D5): two contexts, both built — `Portfolio/CaseStudy`
     (`GET /api/case-studies/{locale}`) and `Portfolio/AnonymousCv` (`GET /api/anonymous-cv/{locale}`,
     skills, seniority and **achievements** per domain, no name/employer/client; the path deliberately
     avoids the `^/api/cv` prefix, which is `ROLE_TRUSTED`). The third content the ADR listed, an
     anonymised career path, was **dropped on 2026-09-13** (D5 amended): the time sequence is the most
     re-identifying element, and the chronology is precisely what the nominative tier adds — don't
     reintroduce it as "just durations and sectors". A real account never granted `ROLE_TRUSTED` still
     lands on that same base tier. **Never name a real account in this repo** (issue #78, pt 5): a valid
     username in a public repository is half a credential, `login_throttling` or not — the former
     shared demo account was removed from production for that reason, and its name scrubbed from here
     and from the migration docblock that mentioned it.
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
- **`../frontend`** — Vue 3 + TypeScript + Vite, talks to the backend only via `VITE_API_URL` (see below).

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

Content management for all of the above, plus user administration, gated end-to-end behind `ROLE_SUPER`
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
### Deployment invariants (learned the hard way — don't undo these)

- **A secret is never passed as a process argument, and never typed in an agent session.** Arguments are
  readable in `ps`, in the shell history and in the transcript of an assistant session — that is what
  forced the 2026-09-16 password change. Rotation procedures (`k8s/README.md` §2bis for the preprod Basic
  Auth, and the Xdebug secret above) go through files (`umask 077` + `mktemp -d`) and stdin: `htpasswd -niB`
  reading stdin, `scw secret … data=@file`, `gh secret set` from stdin — never `htpasswd -b`,
  `data="$(cat …)"` or `gh secret set --body`. Run them in a **separate terminal**, never behind a `!`
  prefix in a Claude Code session.
- **A release is a branch, the merge is the stop, the tag is a consequence** (spec 0006,
  2026-09-16, replacing the "tag = trigger" flow that cost v0.7.0 a stale image, v0.7.1 its
  Markdown headings and v0.11.0–v0.13.1 a literal `#` in their titles). The pipeline has three
  phases gated by the branch: quality on every push of `main`, `develop`, `feature/**`, `fix/**`,
  `hotfix/**`, `release/**`, `dependabot/**` (no `pull_request` event: the push's checks show on
  the PR, listening to both doubled every run); on `release/**` only, `release-version` →
  `build-images` → `deploy-preprod` → smoke tests + audit (→ `rollback-preprod`), and **the run
  stops there**; on `main` only, `deploy-prod` → `finalize-release` + `audit-prod`. Rules that
  hold, each with its guard:
  - **The version is computed, never chosen** — `tools/next-version.sh` (Conventional Commits
    since the last `v*` tag reachable from `origin/main`: `!`/`BREAKING CHANGE` → major, demoted
    to minor while in `0.x`; `feat` → minor; else patch; nothing conventional → "rien à livrer").
    The branch is named `release/<that version>`, `RELEASE_NOTES.md` at the repo root starts with
    `# v<that version> — <title>` (em dash, strict), and `release-version` fails naming the three
    values if they disagree: rename the branch, don't touch the CI. `git fetch --tags` first, or
    the script may answer a version already shipped.
  - **Images are `<version>-<7-char sha>` and `-preprod`, immutable.** `build-images` inspects the
    four manifests first: all present → green no-op (re-run), none → build + push, some → fail.
    That is what makes the old "reused tag serves a stale image" incident impossible;
    `k8s/base/migrate-job.yaml` keeps `imagePullPolicy: Always` anyway. `preprod` is still built
    `FROM production`.
  - **Every push on `release/*` redeploys preprod** (migration before rollout, seed, smoke tests,
    audit, rollback on failure, unchanged) and produces no pending approval. The preprod is
    unique, so one release at a time; a second `release/*` branch is warned about, not blocked.
  - **`main` only moves by the merge-commit of the release PR** (rulesets: PR required, merge
    method `merge` only — squash/rebase are disabled repo-wide because `deploy-prod` finds the
    release as `HEAD^2` —, `smoke-test-preprod` + `audit-preprod` required on the head SHA, no
    deletion, no force-push). `deploy-prod` never builds: three guards **before** the kubeconfig
    is even written — HEAD is a merge and `HEAD^2`'s `RELEASE_NOTES.md` gives the version
    (`tools/verify-release-merge.sh`), the four images exist on GHCR, a `success` run of the
    workflow exists for `release/<version>` at that exact commit (`gh run list --commit` wants
    the **full** SHA; the run survives the branch's deletion). The `production` environment keeps
    its secrets but no longer requires a reviewer: the merge is the stop.
  - **`finalize-release` runs after a green prod**, six idempotent steps in
    `tools/finalize-release.sh` (a re-run changes nothing): annotated tag `vX.Y.Z` on `HEAD^2`
    with `RELEASE_NOTES.md` read *at that commit* as the annotation (`--cleanup=verbatim`, so the
    Markdown headings survive; the first line minus its `#` becomes the release title, the rest
    its body); push of the tag; GitHub Release; copy of the notes to `docs/releases/vX.Y.Z.md`
    committed on `main` with `[skip ci]`, the root file staying in place for the next release;
    deletion of `release/X.Y.Z`; `git push main:develop`, whose non-fast-forward refusal is the
    intended clean stop (green, the summary prints the commands to report `main` through a
    `fix/sync-main-vX.Y.Z` branch cut from `develop`, issue #293 — **never a `main` → `develop`
    PR**: its head is the `[skip ci]` copy commit, no required check ever runs on that SHA and the
    PR stays `BLOCKED` forever; the branch must keep a prefix the pipeline listens to; once that
    PR is merged, a re-run says "déjà reporté" instead of printing the commands again). Its pushes
    use the **`release-bot` deploy key** (secret `RELEASE_DEPLOY_KEY`, host key pinned from `gh api
    meta`, checkout with `persist-credentials: false`), the only bypass actor of the three
    rulesets (`main`, `develop`, tags `v*` — a personal repo refuses the `github-actions` app as
    a bypass actor). Deploy-key pushes **do** trigger workflows, unlike `GITHUB_TOKEN`'s, hence
    the `[skip ci]` on the copy commit. Rotation and the whole D9 setup: `tools/github-settings.sh`
    (idempotent) and its README.
  - **Never write `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]` or `[actions skip]` in a
    commit or merge message** — GitHub silently skips the push's run (it happened to the very
    commit that introduced `finalize-release`). Only the copy commit above carries it.
  - **Pipeline `run:` steps are `bash -e` without `pipefail`**: never `cmd | tee >> $GITHUB_OUTPUT`,
    a failing `cmd` goes unnoticed (write to a file, then append). The runner has no git
    identity: scripts that tag or commit pass `-c user.name`/`user.email`. Both learned in T5/T8.
  - **Every job declares `timeout-minutes`, and so does every step calling `apt-get`** (issue
    #285): GitHub's default is 360 minutes, and a frozen apt mirror held v0.18.3's `build-images`
    for over 30 minutes without failing. Size a job at 3–10× its usual duration, a deploy job
    above the sum of its internal `kubectl` waits — a timeout that cuts `deploy-prod` mid-apply
    is worse than a slow run. `tools/check-workflow-timeouts.sh` (run by `tools-tests`) turns
    red on a job or apt step added without one.
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

`jsdom` used to be held back at `^26.x` because `jsdom@30` requires Node `>=22.22` and fails on anything
older — including a workstation below the project's `NODE_TAG`. **That pin is gone** (issue #26): the fix was
to stop letting the host matter, via the `make front-*` targets above, not to constrain a dependency to suit
one machine. A host-only constraint has no business in `package.json`.

Node 26's own native (experimental) `localStorage`/`sessionStorage` globals conflict with jsdom's: any test
touching the bare `localStorage` global (not `window.localStorage`) before jsdom's environment fully
initializes used to fail with `Cannot read properties of undefined (reading 'clear')`. The workaround was
`NODE_OPTIONS=--no-experimental-webstorage` on `frontend/package.json`'s `test`/`test:watch` scripts, kept
through the jsdom 30 move (issue #26), which did not make it obsolete.

**Vitest 5 does** (vitest-dev/vitest#10293, "don't emit localStorage warnings on Node 26"), so the flag is
gone. Verified both ways rather than assumed, since the failure is environment-dependent and a green suite
alone proves nothing: on Vitest 4 without the flag, 11 tests fail on `setItem`/`clear`; on Vitest 5 without
it, all 490 pass. If those failures ever come back, the flag is the fix — but check first whether Vitest or
Node changed, because reinstating it would hide a real regression just as well as it hides this one.

`vue-i18n`/`@intlify/*` (and transitively a few ESLint tooling packages) declare `engines.node >= 22`. The
container satisfies it, so this is only ever an `EBADENGINE` warning if someone installs outside it — one
more reason the `make front-*` targets exist.

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
runs the full suite (see "Project state" above for CI/PHPStan/Rector wiring).

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
exact same route, with a dummy forced value in test: no test may reach the real API. Beware: `init-symfony.sh` returns early when
`composer.json` exists, so an already-initialised checkout needs those two lines added to `.env.local` by hand.
Don't re-add either file to git — extend `docker/php/init-symfony.sh`
instead if a fresh-clone default needs to change. Locally, `init-symfony.sh` generates both `.env.local` (dev)
and `.env.test.local` (test) with the Docker-internal `DATABASE_URL` (`database:5432`); since Symfony never
loads `.env.local` when `APP_ENV=test`, the test env needs its own `.env.test.local` — same database name as
dev is fine, `config/packages/doctrine.yaml`'s `dbname_suffix: '_test'` (Flex default, meant for ParaTest)
already separates the real test DB (`<name>_test`) from dev data. `.env.dev`/`.env.test.local` also carry
`JWT_PASSPHRASE`, which **must be identical between the two** — `lexik_jwt_authentication.yaml` doesn't split
the keypair path per environment, so one `config/jwt/*.pem` (gitignored) is shared by both; after changing
either passphrase, regenerate it with `php bin/console lexik:jwt:generate-keypair --overwrite`. CI
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
ADRs:
- `docs/adr/0001-admin-user-provisioning.md` (invitation-by-email flow, `email` now stored, Twig for emails)
- `docs/adr/0002-veille-technique.md` (`Portfolio/Watch`: outbound calls out of the render path, snapshot in
  DB, public aggregate vs `ROLE_SUPER` detail, manifest built at `docker build`)
- `docs/adr/0003-paliers-d-acces.md` — **statut `accepté`, implémenté et clos** (D1/D2/D4/D6/D7, D5 réduit à deux
  contenus par amendement du 2026-09-13; closed 2026-09-15, see its « Clôture » section — what is left is
  editorial content entry, not engineering). Makes `ROLE_USER` the bottom tier (one click, no credentials,
  discretion rather than secrecy) and puts the CV behind `ROLE_TRUSTED`. Read it before touching
  `access_control`, `CpgUser::getRoles()` or `BaseAccessController`: it turns on the fact that `getRoles()`
  grants `ROLE_USER` unconditionally, which is why a tier was added *above* rather than below.
  **Amended 2026-09-21** (3rd audit, A9), end of « Conséquences »: the accepted risk that **`POST /api/logout`
  does not revoke the JWT** — it only expires the cookies, the firewalls are stateless and the payload has no
  `jti`, so a token copied beforehand stays valid until it expires (1 h for a login token, 15 min for the base
  tier). Read it before adding a revocation list, lengthening `token_ttl`, or gating anything more sensitive
  than the CV; it also names the two remedies and what each one costs.
- `docs/adr/0004-assistance-ia.md` — **statut `accepté` (2026-09-14), phases 1 et 2 livrées** (phase 1: spec 0002, issues
  `spec-0002` closed, v0.10.0/v0.10.1 in production the same day; phase 2: spec 0005, v0.19.0 in production on
  2026-10-04; the case-studies admin page, #104, joined
  on 2026-09-14, so every admin form now has the button). Rules for anything that calls a language model: one importing class behind an interface,
  bundle pinned exact, no call from a public render path, only publishable backoffice content leaves, human in
  the loop, bounded cost, offline tests. **Amended 2026-09-15**: D7 is now the `ROLE_TRUSTED` career
  assistant on Scaleway (not an MCP server), D3 lets the nominative CV reach a model operated by the site's
  host or self-hosted only, D2 admits calls on behalf of `ROLE_TRUSTED` (never the base tier), D5 adds a
  bounded input. Read it before adding any `Symfony\AI` usage or a new `ai.agent`.
- `docs/adr/0005-etat-hors-du-pod.md` — **statut `accepté` (2026-09-16), livré par le hotfix v0.14.1**.
  No application state on the pod's filesystem: `cache.app` on Doctrine DBAL, why an `emptyDir`
  or a PHPUnit test would not have done, and the two backstops (nginx `login` zone, preprod
  smoke test that exercises the throttling). Read it before touching `cache.yaml`, adding a
  rate limiter, or mounting anything under `var/`.