# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Where the rest lives: `.claude/rules/` (issue #346)

This file is the index: what every session needs (the repo's invariants, the routine commands, the git
flow in short) and where the rest lives. Everything specific to one area lives in a **path-scoped rule**
under `.claude/rules/`, loaded automatically when a file matching its `paths:` is read or written.

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
| `paliers-et-exposition.md` | Goal #9 in full (the three access tiers' invariants), the « never send what the caller isn't entitled to » rule and its guards (`ApiRouteExposureTest`, `RouterScopeTest`, the parity tests) | adding or changing a route, `security.yaml`, an `access_control` rule, nominative or base-tier content |
| `environnement-dev.md` | the stack in detail, the two sets of Compose files, versions, every `make` command, the backend and frontend day-to-day, the `.env*` files and dev secrets, services and ports | touching `Makefile`, a Compose file, `.env`, `versions.lock`, `docker/php/`, `phpunit.dist.xml`, an `.env*` question |
| `flux-git-et-release.md` | Goal #10 in full (git flow, the hotfix exception), the release by hand, `tasks/` and spec archiving | cutting a `hotfix/*` or `release/*` branch, a release, closing a spec, touching `RELEASE_NOTES.md` |
| `backend-outillage.md` | backend quality tooling, Monolog, API Platform's version and its deprecation guard | touching PHPStan/Rector/Psalm/`lsp:check`, a CI quality job, logging, the `api-platform/*` version, `.github/dependabot.yml` |
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
| `mcp.md` | `Mcp/`, the MCP server on `/api/mcp` — and the index of the two below | touching a tool, `mcp.yaml`, the `mcp` firewall |
| `mcp-prompt-d8.md` | the prompt `present_for_role`, the D8 guard (the author never named) | touching the prompt, a published description, `AuthorNeverNamedTest` |
| `mcp-journaux.md` | the SDK's `mcp` channel, the call log `mcp_usage` | touching `Mcp/Infrastructure/Log`, `monolog.yaml`'s MCP handlers |
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

Numbered as they always were: rules and scripts cite « Goal #9 » and « Goal #10 ».

- **1, 11.** A personal demonstration of the best practices for a modern PHP application with a separated
  frontend (Vue + TypeScript), that can be shown during an interview.
- **2–4.** The frontend is decoupled from the backend, a Symfony API-only application; the whole is
  deployable as a Docker Compose stack.
- **5–7.** Fully testable (frontend: Vitest, backend: PHPUnit), documented (Vitest, PHPDoc) and linted
  (ESLint, PHPStan).
- **8.** The security must be a top priority.
- **12.** Fully multilingual (French, English).

Goals #9 and #10, the two with invariants:


9. The three access tiers of ADR 0003 — anonymous, base, trusted (vocabulary in `CONTEXT.md`), each
   invariant enforced **server-side** — in full in `.claude/rules/paliers-et-exposition.md`:
   - **No nominative data without `ROLE_TRUSTED`** (nominative CV `GET /api/cv`, `GET /api/me`): "expose",
     not "display" — hiding a field client-side is never protection.
   - **The base tier (`ROLE_USER`) is discretion, not protection**: its contents are publishable by
     construction and never carry the professional chronology.
   - **`ROLE_TRUSTED` is granted nominatively** by a `ROLE_SUPER` account via the invitation flow.
   - **Never name a real account in this repo** (issue #78, pt 5).
   - The About page is **public in full, on purpose** (audit C3).
10. The project's git flow (spec 0006), in full in `.claude/rules/flux-git-et-release.md`: `feature/*` and
    `fix/*` are cut from an up-to-date `develop` and return to it by PR; a spec's tasks stack on the spec's
    own feature branch; **never push to `main` or `develop`, never tag by hand** (rulesets refuse both, the
    pipeline tags). Read that rule before cutting a `hotfix/*` or `release/*` branch, before a release and
    before closing a spec.

## Architecture

`backend/` (Symfony 8.1 API-only, API Platform 4.4, Doctrine ORM 3 on PostgreSQL, Messenger over RabbitMQ,
Twig only for outgoing e-mails) and `frontend/` (Vue 3 + TypeScript + Vite, talks to the backend only via
`VITE_API_URL`), behind a shared Docker network. The stack is the **root** `docker-compose.yml` +
`docker-compose.override.yml`, never `backend/compose*.yaml` (unused Flex leftovers). Detail, versions,
ports and `.env*` files: `.claude/rules/environnement-dev.md`.

**Every new endpoint: "never send what the caller isn't entitled to"** — a new route is protected by default,
making it public is a deliberate, justified act (`PUBLIC_PATHS`, `BASE_TIER_PATHS`), the whole backoffice is
`ROLE_SUPER`, and security or a route is never declared for a single environment. `ApiRouteExposureTest`
enforces it: never weaken or delete it to make a new route pass. Detail:
`.claude/rules/paliers-et-exposition.md`.

## Invariants from the shell (learned the hard way — don't undo these)

- **A secret is never passed as a process argument, and never typed in an agent session.** Arguments are
  readable in `ps`, in the shell history and in the transcript of an assistant session — that is what
  forced the 2026-09-16 password change. Rotation procedures (`k8s/README.md` §2bis for the preprod Basic
  Auth, and the Xdebug secret in `.claude/rules/deploiement.md`) go through files (`umask 077` + `mktemp -d`) and stdin: `htpasswd -niB`
  reading stdin, `scw secret … data=@file`, `gh secret set` from stdin — never `htpasswd -b`,
  `data="$(cat …)"` or `gh secret set --body`. Run them in a **separate terminal**, never behind a `!`
  prefix in a Claude Code session.
- **No `.env.<env>` file is versioned in `backend/`**: never re-add one; real values come from `.env.local`
  (dev), Secret Manager (preprod/prod) and `phpunit.dist.xml` (test).
- **Never write `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]` or `[actions skip]` in a commit or
  merge message**, and **never a `main` → `develop` PR** (issue #293).
- **A release is a branch, the merge is the stop, the tag is a consequence**: the version is computed by
  `tools/next-version.sh`, never chosen. The steps by hand are in `.claude/rules/flux-git-et-release.md`, the
  pipeline's internals in `.claude/rules/release.md`.

## Commands

Run from the repo root (Docker Compose v2); they run in the container, which is the point (issue #26) —
prefer them to anything run by hand on the host. The full list (scaffolding, image builds, the backend
console, the tech watch) is in `.claude/rules/environnement-dev.md`.

```bash
make up / make down    # start (detached) / stop the stack (volumes kept)
make sh / make sh-front  # shell into backend as `dev` / into the frontend container
make db-migrate        # doctrine:migrations:migrate --no-interaction
make front-test        # vitest run
make front-lint        # eslint
make front-build       # vue-tsc -b + vite build
make back-test         # phpunit
make back-quality      # phpstan + rector + psalm + lsp:check
make back-style-fix    # sort `use` + qualify compiler-optimized natives (pinned php-cs-fixer, #398)
```

A container showing stale source (a host edit not reflected inside): `docker compose restart <service>`
resyncs the bind mount.

## Agent skills

### Issue tracker

Issues live in GitHub Issues for this repo. See `docs/agents/issue-tracker.md`.

### Task plans (`tasks/`) and spec archiving

`tasks/plan.md` and `tasks/todo.md` live on the feature branch only, never on `develop` or `main`; a spec's
tasks stack on its own branch, and the PR that closes the feature archives the spec and the whole `tasks/`
under `.claude/specs/archive/<YYYY-MM-DD>-<slug>/`. The procedure: `.claude/rules/flux-git-et-release.md`.

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