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

## Project state

This repository started as a freshly generated project skeleton (single "Init" commit). Real backend code now
exists — the `Security` bounded context (`User` + `Authentication`), six `Portfolio` bounded contexts
(`Experience`, `Quality`, `About`, `Contribution`, `Incident`, `Watch`, see Backend architecture below) and an
`Ai` context whose first sub-context, `Translation`, is delivered (spec 0002, ADR 0004, v0.10.0/v0.10.1 — see `Ai/` below); every entity has a UUID v7 key (spec 0003, v0.11.0) and every ordered content is
reordered by drag-and-drop with an explicit FR/EN link in the database (spec 0004, v0.12.0, see
`Portfolio/Shared/` below) — and follows a DDD structure under
`src/<BoundedContext>/` — the generic `ApiResource/`, `Controller/`, `Entity/`, `Repository/` directories left
over from the skeleton have been deleted (they were empty placeholders, no code ever lived there); don't
recreate them, new code always goes under its bounded context. PHPUnit is configured (`phpunit.dist.xml`,
`tests/`, mirrors the `src/` bounded-context tree) and runnable via `php bin/phpunit` inside `make sh`.
**PHPStan** is configured (`backend/phpstan.dist.neon`, level `max` + `phpstan-strict-rules` +
`phpstan-symfony`/`-doctrine`/`-phpunit`/`-deprecation-rules` via `extension-installer`): run it with
`composer phpstan` inside `make sh` (warms the dev container first), CI job `phpstan-backend` (blocking,
`build-images` needs it). **No baseline** — level `max` + strict-rules is green across `src/` AND `tests/`, keep
it that way. Two scoped `ignoreErrors` in `phpstan.dist.neon` cover functional-test JSON payloads (a decoded
response body is `mixed`; the PHPUnit assertions are the shape check, not PHPStan) — `offsetAccess`/`foreach`
and `argument.type … mixed given`, `path: tests/` only. `src/Kernel.php` is `excludePaths`-excluded
(`getAllowedEnvs()` false-positive `method.unused`). The `$uriVariables` mixed-access pattern is solved by the
`App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables` trait (`uriVariableUuid`/`uriVariableString`/
`uriVariableLocale`) — reuse it in new Providers/Processors rather than casting `mixed`. Functional tests build request bodies via
`App\Tests\Support\HttpJson::jsonBody()` (not raw `json_encode`, which is `string|false`).
`tests/object-manager.php` boots the kernel for `phpstan-doctrine`; `tests/bootstrap.php` is
`excludePaths`-excluded (Flex-managed). **Rector** is configured (`backend/rector.php`, `withPhpSets()` +
prepared sets deadCode/codeQuality/typeDeclarations/earlyReturn/instanceOf): `composer rector` shows diffs,
`composer rector:fix` applies, CI job `rector-backend` runs `--dry-run` (blocking, `build-images` needs it).
`rector/rector-symfony`/`-doctrine`/`-phpunit` are deliberately omitted (their deps conflict with `symfony/*
8.1.*`). Some cosmetic rules are skipped in `rector.php` (`SortAttributeNamedArgs`, `NewMethodCallWithoutParentheses`,
`FlipTypeControlToUseExclusiveType`, `ClassPropertyAssignToConstructorPromotion` — entities keep explicit
properties) — extend that skip list rather than fighting a rule inline. One skip is **not** cosmetic:
`ArrowFunctionDelegatingCallToFirstClassCallableRector` puts Rector and PHPStan in head-on disagreement (Rector
demands the rewrite, PHPStan rejects it), so it can never be satisfied — leave it skipped. Psalm
*is* installed (`psalm/phar`, `backend/psalm.xml`) but **only** for taint analysis (`composer psalm`, CI job
`sast-backend`, audit point M4) — `errorLevel="8"`, it is not and must not become a second type-checker
alongside PHPStan; don't reach for Psalm annotations or raise its level. **Symfony Language Tools**
(issue #90) is the fourth check: `symfony lsp:check` (`make back-lsp`, part of `make back-quality`, CI job
`lsp-check-backend`) boots the kernel in `dev` (`backend/.symfony-lsp.json`, `releaseMetadata: false` so
it makes no call to symfony.com) and validates what PHPStan cannot see: service ids, route names,
Messenger transports, firewalls, constraint options, bundle config keys, Twig paths. It needs no database
(a refused connection is fine — a *DNS* failure once segfaulted the checker, don't point it at an
unresolvable host). It requires Symfony CLI ≥ 5.20.0 (`.env`, `versions.lock`); the CLI downloads the
latest stable Language Tools into its own cache (`symfony lsp:cache-dir`), so that part is **not pinned**
— which is why the job is blocking only on the `--fail-on` list of low-false-positive codes and is *not*
in `build-images`' `needs` yet (re-evaluate after a few weeks). No baseline: the repo is clean apart from
six `config.unknown_key` **warnings** on `ai.yaml` (`ai.agent.{translator,career_assistant}.model.name/options`, three per agent), keys that
Symfony accepts because the bundle declares `model` as a `variableNode` (its only rule: a string, or an array
with `name`) — Language Tools cannot know the keys under it, a false positive by construction, left visible
rather than baselined. **Monolog** is installed (`symfony/monolog-bundle`, audit 2026-09-16 constat A5 —
without it, HttpKernel's fallback logger emitted nothing below `warning` and no security event left a
trace at all). `backend/config/packages/monolog.yaml`: in `when@prod` (which covers preprod and prod,
same `APP_ENV`) every handler is a `stream` to `php://stderr` with `monolog.formatter.json`, because
Kubernetes only collects a container's stdout/stderr — `kubectl logs … | jq` is the whole tooling. The
`security` (Symfony's own) and `security_audit` (ours, declared in `monolog.channels`) channels get
their own handler at a hard-coded `info`, never `fingers_crossed`: a run of failed logins with no error
after it is exactly the trace worth keeping, and buffering would discard it. Everything else goes to
the `main` handler at `%env(default:app.log_level:LOG_LEVEL)%` (`LOG_LEVEL=warning` in `backend/.env`,
`debug` in the preprod image), which excludes those two channels so an event is emitted **once**. The
`default:` processor takes a *parameter name*, hence `app.log_level` in `services.yaml` — `default:warning:`
would look for a parameter called `warning` and fail at compile time. No duplication with
`error_log = /proc/self/fd/2` (`php.prod.ini`) either: Monolog writes to fd 2 itself, `error_log` only
ever receives the engine's own errors. The events are emitted by `SecurityAuditLogger` (see Phase 3 of
the remediation plan). The
frontend has moved past the default scaffold: it follows a layered clean architecture (see below) and has
Vitest configured with `npm test`. A `ROLE_SUPER`-gated backoffice (`/admin` on the frontend, `/api/backoffice/*`
on the backend) lets an authenticated super-admin manage all of the above content plus user accounts — see the
"Backoffice" subsections under Backend/Frontend architecture below.

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

### Dockerfile (`../docker/php/Dockerfile`) — multi-stage

`base` (PHP + extensions: intl, pdo_pgsql, zip, sockets, amqp, opcache) is shared by every target so dev and
prod run the identical PHP engine:

- **`dev`** — adds symfony-cli, bash/git/curl; creates a `dev` user matching the host `UID`/`GID` (from `../.env`)
  so bind-mounted files stay host-editable. Code is bind-mounted, not copied.
- **`vendor`** — isolated `composer install --no-dev` layer, cached on `composer.json`/`composer.lock` only.
- **`production`** — copies `vendor` + `../backend` source into the image (no mount), runs as a fixed non-root
  `app` user (UID 10001), read-only filesystem except `var/`. This is the real deployable artifact. It also
  **builds `config/watch/package-manifest.json`** here (`bin/build-package-manifest.php`, no Symfony kernel):
  the build context is the repo root, so this is the one place `composer.lock` and
  `frontend/package-lock.json` coexist — the manifest describes exactly what the image deploys and cannot
  drift from it. Keep that `COPY`/`RUN` pair *after* the big install layer so an npm bump doesn't invalidate it.
- **`preprod`** — built **`FROM production`** (not a parallel build) so its application layers are byte-identical
  to prod; only adds Xdebug (**inert in the image**, see "Deployment invariants") and verbose logs.
  Pipeline order is dev → preprod → prod regardless of declaration order in the Dockerfile.

The build context is the **repo root**, so `.dockerignore` is what keeps things out of it, and two entries
are there for secrecy rather than size (audit A13/A22): `backend/config/jwt/` — a workstation that has
generated its dev RS256 keypair would otherwise ship `private.pem` in an image layer, and the deployed
keys come from the `jwt-keys` Secret at runtime anyway — and `backend/.env.test`, `.claude/`, `tasks/`,
`.superpowers/`, which carry local configuration, real identity (`CLAUDE.local.md`) or uncorrected audit
findings. Never `COPY` something out of one of those; widen the ignore list instead. Development tooling
files are also excluded: `backend/tests/`, PHPStan/Psalm/Rector/PHPUnit/LSP configuration files, and
Composer/Flex recipe files — the production image has no test suite, and adding a new quality tool means
adding its config file to the ignore list.

### Backend architecture (`../backend/src`)

DDD structure: each bounded context is a top-level folder under `src/`, itself split into `Domain/`,
`Application/`, `Infrastructure/`, `Presentation/` layers — only the layers a context actually needs, no empty
ceremonial folders. `config/packages/doctrine.yaml`'s mapping scans all of `src/` (not a single `Entity/`
folder), so entities live inside their bounded context instead of a shared top-level directory.

**Every entity has a UUID v7 primary key, assigned in its constructor** (spec 0003, `v0.11.0`):
`#[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;` with no `GeneratedValue`,
`$this->id = Uuid::v7();` as the first assignment, right after the constructor's guards (three entities
validate an invariant first: `CpgUser`, `WatchedProduct`, `WatchSnapshot`), `getId(): Uuid` non-nullable.
Never a `setId()`, never an id supplied from outside on creation. The column is PostgreSQL's native `uuid`
type (`symfony/doctrine-bridge`'s `UuidType`, `symfony/uid` `8.1.*` a direct dependency), never
`VARCHAR(36)`. Every item operation declares `requirements: ['id' => Requirement::UUID]`
(`Symfony\Component\Routing\Requirement`), so a malformed `{id}` is a 404 from the router before the
firewall or any Provider runs; Providers/Processors read it via `ResolvesUriVariables::uriVariableUuid()`.
`tests/Security/ItemRouteRequirementTest.php` pins this invariant by walking the compiled router and
asserting every `/api` route whose path contains `{id}` declares it, with an explicit, justified allow-list
for API Platform's internal `{id}` paths that aren't entities.
`Requirement::UUID` is case-sensitive (lowercase hex), so an upper-case UUID in a URL is a router 404 too —
`toRfc4122()` always emits lowercase, don't widen the regex. DTOs expose `id` as an RFC 4122 string
(`$entity->getId()->toRfc4122()`), never the `Uuid` object — a read/write DTO's `id` stays
`?string $id = null` (absent on creation), but an output-only DTO's is non-nullable
(`BackofficeUserResource`). **`===`/`!==` between two `Uuid` compares objects, not values — always use
`->equals()`** (`CpgUserAdministrator`, `CpgUserRoleAdministrator`, `ExperienceTechnologyAdministrator` are
the three sites that need it). The migration to UUID is irreversible and monotone: PostgreSQL 18's
`uuidv7(interval)` assigns each existing row a v7 id offset by its rank in the old integer order, so
`ORDER BY id` keeps producing today's order — one migration per task of phase A (five files,
`backend/migrations/Version202609141{2..6}0000.php`), each `down()` throws rather than pretend the
original integers are recoverable. `ApiRouteExposureTest` substitutes `{id}` with a fixed valid UUID (not
`'1'`): with `requirements` in place, an integer placeholder would 404 at the router and the test would
silently stop covering every item route. Two traps hit while migrating: the API Platform metadata pool
survives `cache:clear` after a DTO's `id` type changes — `rm -rf var/cache/<env>` instead; and the usual
bind-mount desync (`docker compose restart backend`) can make a container run a stale Provider/Processor
mid-migration.

- **`Security/User/`** — the `CpgUser` aggregate. Two creation paths (see ADR 0001): the CLI command
  (`app:user:create`, bootstrap — notably the first `ROLE_SUPER`) and **invitation from the backoffice**
  (`CpgUserInviter`, `POST /api/backoffice/users`). Login identifier is a plain `username`; an `email` **is**
  stored for invited accounts (nullable, unique, `ROLE_SUPER`-only, never exposed before authentication —
  Goal #9 still holds). Invited accounts are created "pending activation" (empty password) and the person sets
  their own password via an emailed link.
  - `Domain/Entity/CpgUser.php` (+ `email`, `invitedAt`, `activatedAt`, `isPendingActivation()`),
    `Domain/Entity/PasswordSetupToken.php` (SHA-256 of the token only, 48h expiry, single-use, FK `ON DELETE CASCADE`),
    `Domain/Repository/{CpgUser,PasswordSetupToken}RepositoryInterface.php` (the DIP boundary — `Application/`
    never depends on Doctrine directly).
    `CpgUser::ROLE_SUPER` is the role reserved for backoffice access; `CpgUser::MIN_PASSWORD_LENGTH` /
    `MAX_PASSWORD_LENGTH` are the single source of truth, reused by the CLI, the backoffice password-change
    endpoint, and the public set-password endpoint.
    Domain exceptions: `UsernameAlreadyUsedException`, `EmailAlreadyUsedException`,
    `InvalidPasswordSetupTokenException` (→404), `PasswordSetupTokenExpiredException` (→410, covers "already
    used"), `CannotModifyOwnRolesException` / `CannotDemoteLastSuperAdminException` (→409),
    `AccountNotAwaitingActivationException` (→409), `AccountInvitationDeliveryException` (→503),
    `PasswordSetupRateLimitExceededException` (→429).
  - `Domain/Service/UsernameGenerator.php` — derives a free username from the email local part
    (filter to `[a-z0-9_.-]`, cap 60, pad with `user` if < 3, numeric suffix on collision).
  - `Application/` use cases (each with an interface, autowired single-impl):
    `CpgUserRegistrar` (CLI creation, password provided up front) · `CpgUserInviter`
    (`invite(email, Locale)` / `reinvite(user, Locale)`: derives username, creates/marks the pending account,
    then **only** dispatches `SendAccountInvitationMessage`) · `PasswordSetupService` (`validate` / `complete`
    the public flow) · `CpgUserAdministrator` (delete / change-password) · `CpgUserRoleAdministrator`
    (`setSuperAdmin`, idempotent, anti-lockout guards; on demotion `ROLE_TRUSTED` is kept **only if the account
    has an `email`** — nominative grant, ADR 0003 D1, issue #78 pt 3 — a CLI account falls back to the base
    tier) · `PendingInvitationPurger` (deletes accounts whose password hash is still empty — the literal
    definition of "never activated", issue #238, so a password set for the person from the backoffice
    exempts the account — invited more than N days ago, 30 by default, never a `ROLE_SUPER` one;
    `app:user:purge-pending-invitations`, daily in the housekeeping CronJob, `--dry-run` for a hand run;
    retention ≥ 1 day, `InvalidPurgeRetentionException` → exit 2, a shorter one would otherwise purge nearly
    every pending account in one manual run) · `PasswordSetupRateLimiterInterface` (calqued on the
    Contact rate limiter). Presenters: `CpgUserPresenter` (`/api/me`), `CpgUserAdminPresenter`
    (backoffice list — `id`, `username`, `email`, `roles`, `status`).
  - **The invitation token is created by the Messenger handler, never by the use case** (audit C2):
    `SendAccountInvitationMessage` carries `{userId, locale}` and nothing else, so a message parked in the
    doctrine `failure_transport` (SMTP down…) exposes no usable secret. `SendAccountInvitationHandler` reloads
    the user (missing or already activated → `logger->warning` + return, no retry), then in a single
    `wrapInTransaction` purges the previous `PasswordSetupToken` and issues a fresh one. Keep it that way: do
    not move token creation back up into `CpgUserInviter`, and never add a secret to the message.
  - `Infrastructure/` — `Doctrine/{CpgUser,PasswordSetupToken}Repository.php` (sole impls);
    `Messenger/SendAccountInvitationHandler.php` (creates the token — see above — then the `TemplatedEmail`
    `emails/account_invitation.*`, subject + localized strings built here);
    `RateLimiter/SymfonyPasswordSetupRateLimiter.php`;
    `Http/PasswordSetupRateLimitRequestListener.php` (audit C1 — a `kernel.request` listener at priority 15
    that consumes the per-IP quota on **`POST`** for the two exact paths of the flow **before** API Platform
    deserializes or validates anything; consuming it again in the Processor would halve the effective
    quota, so don't) + `Http/PasswordSetupRateLimitRetryAfterListener.php` (adds `Retry-After` on the 429);
    the API Platform processors.
  - `Presentation/Command/CreateCpgUserCommand.php` (`app:user:create`, `--role` allow-list) and
    `Presentation/Controller/CurrentUserController.php` (`GET /api/me`). Everything else is API Platform
    resources — see "Backoffice" below for the `ROLE_SUPER` ones, plus the two **public** (no auth, no CSRF,
    IP rate-limited) ones of the set-password flow.
  - **A secret never travels in a URL path** (audit A7, T4.1/T4.2). `AccountPasswordSetupValidationResource`
    (`POST /api/account/password-setup/validate` `{token}`) and `AccountPasswordSetupResource`
    (`POST /api/account/password-setup` `{token, password}`) both carry the token **in the body**, both
    answer 204, and both answer identically on failure — missing/blank/over-255 token 422, unknown 404,
    expired *or already used* 410 (merged on purpose: a distinct status would say a link had served).
    They replace `GET|POST /api/account/password-setup/{token}`, removed along with
    `AccountPasswordSetupStatusResource` and `AccountPasswordSetupProvider`; a `GET` cannot carry a secret
    anywhere but its URL, and a URL is written to the nginx sidecar's and the ingress's access logs, a body
    is not. **Never reintroduce a `{token}` in a `uriTemplate`.** Consequences to keep aligned: the two
    paths are listed **exactly** (not as a prefix) in `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` and in
    `PasswordSetupRateLimitRequestListener::RATE_LIMITED_PATHS` — a prefix would silently exempt a future
    sibling route such as `…/password-setup-other`; both have their own justified `PUBLIC_PATHS` entry in
    `ApiRouteExposureTest`; the nginx `pwsetup` zone is `location ^~ /api/account/password-setup` (no
    trailing slash, both confs) so it covers the root path too.
    `AccountPasswordSetupResourceTest` pins the status matrix and the fact that no trailing-slash variant
    is served.
- **`Security/Authentication/`** — login/logout/JWT/CSRF mechanics, deliberately kept out of `User/`: this is
  infrastructure wiring around Symfony Security + LexikJWTAuthenticationBundle, not a domain concept of its own.
  - `Infrastructure/Jwt/LoginSuccessSubscriber.php` (attaches the `XSRF-TOKEN` cookie, shapes the
    `login_check` response body — includes `roles` alongside `username`, needed by the frontend to gate `/admin`
    without waiting for a full `checkAuth()` round-trip) and `CookieLogoutListener.php` (expires both cookies on
    `/api/logout`).
  - **`Infrastructure/Http/AuthCookieFactory.php` is the only place that builds a `BEARER` or `XSRF-TOKEN`
    cookie** (issue #87): names (`AuthCookieFactory::BEARER` / `::XSRF_TOKEN`, also what
    `CsrfCookieRequestSubscriber` reads), `Path=/`, no `Domain`, `SameSite=Lax`, `HttpOnly` on `BEARER`
    only, `Secure` iff `prod`. `bearer()` / `xsrf()` take an optional expiry (session cookie without),
    `expired()` mirrors the issued attributes with a past date — a cleared cookie whose attributes differ
    from the set one is *not* removed by the browser, which is the drift the factory exists to prevent. The
    three sites (`LoginSuccessSubscriber`, `BaseAccessController`, `CookieLogoutListener`) go through it;
    the one exception is the login `BEARER`, still set by Lexik from `lexik_jwt_authentication.yaml`
    (`set_cookies` + `when@prod`), and `tests/Security/Authentication/AuthCookieAttributesTest.php` pins
    that config to the factory by comparing the real `Set-Cookie` of login, base-access and logout per
    name. Any new auth cookie goes through the factory, never `Cookie::create()` or `clearCookie()`
    inline. `__Host-` prefixing is a separate, preprod-tested step (it renames what Lexik and the frontend
    read by name).
  - `Infrastructure/Http/CsrfCookieRequestSubscriber.php` — double-submit-cookie CSRF check, a `kernel.request`
    listener at priority 20 (must run *above* the Security firewall's priority 8 — see the class docblock).
  - `Infrastructure/Http/LoginCsrfRequestListener.php` — **login-CSRF guard** (issue #76) on the two anonymous
    routes that *set* a `BEARER` cookie, `POST /api/login_check` and `POST /api/account/base-access`. Both are
    rightly outside the double-submit (an anonymous caller has no `XSRF-TOKEN` to echo), but a cross-site
    HTML form could submit them top-level: the victim's cookie isn't sent (`SameSite=Lax`), yet the
    response's `Set-Cookie` *is* accepted and **replaces** the trusted `BEARER` with the attacker's (or a
    15-minute guest one). The guard requires the `X-Requested-With` header, which a form cannot set and
    which makes a cross-site `fetch()` fail its CORS preflight. **Presence is the protection, not the
    value.** Same priority 20 as the CSRF subscriber: above the firewall (or `json_login` sets the cookie
    first) and above the rate limiters (15), so a forged submission never burns the victim's IP quota.
    `nelmio_cors.yaml` lists the header in `allow_headers`; the frontend sends it via
    `infrastructure/http/loginCsrfHeader.ts` on both calls. Functional tests therefore pass
    `'HTTP_X_REQUESTED_WITH' => 'fetch'` in `server:` on every login/base-access request.
  - **Any listener that decides on the request path must use `App\Shared\Infrastructure\Http\CanonicalPath::of()`,
    never `getPathInfo()` directly** (issue #77). `getPathInfo()` is *not* decoded, while the router, the
    firewalls and `access_control` all decide on `rawurldecode()`: `POST /%61pi/logout` reached the
    `LogoutListener` without ever passing the CSRF check, and `/api/account/base%2Daccess` escaped its rate
    limiter. Five sites go through the helper — the four `kernel.request` guards
    (`CsrfCookieRequestSubscriber`, `LoginCsrfRequestListener`, `BaseAccessRateLimitRequestListener`,
    `PasswordSetupRateLimitRequestListener`), each with a `%XX` regression test, plus
    `Shared/Infrastructure/Http/ApiJsonErrorFormatListener` on `kernel.exception` (see "Errors under `/api`"
    below) — and `SecurityAuditLogger` logs the canonical form for the same reason.
  - **The `login` firewall is anchored on its `check_path`, the `api` one deliberately is not** (audit A23,
    `security.yaml`). `login` is `^/api/login_check$`: it holds a `json_login` and reads **no** JWT, so any
    route ever added under a looser `^/api/login` would be served anonymously, the visitor's `BEARER` never
    looked at. `api` stays the bare `^/api` **on purpose** — anchoring it would push a neighbour like
    `/apix` out of *every* firewall, hence out of every `access_control` (the `AccessListener` is a firewall
    listener), i.e. remove protection rather than add it; it is allow-listed with that justification in
    `tests/Security/AccessControlAnchoringTest.php`, which pins firewall patterns as well as access rules.
    `tests/Security/Authentication/LoginFirewallScopeTest.php` pins the behaviour.
  - **No session, in any environment** (`framework.session: false`, audit A23). Nothing needs one — both
    firewalls are `stateless`, auth is a JWT in a cookie, the CSRF is a double-submit cookie, there is no
    Symfony form. The point is not tidiness: the default handler writes under `var/`, and on a
    `readOnlyRootFilesystem` pod that is exactly the silent failure ADR 0005 exists to forbid. Without a
    session the error is loud (`SessionNotFoundException`) and surfaces in test or dev instead. Re-enabling
    `framework.form` or a session-token CSRF would need one — `tests/Security/StatelessSessionTest.php`
    pins the invariant, including in `test`.
  - **`Infrastructure/Security/FailedLoginTimingEqualizer.php` — a failed login must not be timeable**
    (audit A10). The three failures already return the same 401, but not in the same time: a known active
    account paid a full bcrypt/argon `verify()`, while an **unknown identifier** (the user is never loaded)
    and an **account pending activation** (empty hash, `password_verify($p, '')` returns at once) cost
    nothing — the gap said which identifiers exist and which are pending invitations. On `LoginFailureEvent`,
    `login` firewall only, priority **-100** (after the throttling counter and the audit log, neither of
    which should wait on a hash), it pays a dummy `hash()` of a constant with `CpgUser`'s configured hasher
    in **those two cases only**: never for an active account (the real hash already happened — a second one
    would recreate the gap the other way), never for the throttling (its answer must stay cheap), never for
    a blank password (refused before any DB access, identically for everyone). `hash()` of a constant, not
    `verify()` against a frozen hash, so it follows `password_hashers: auto` if the algorithm or cost
    changes. The submitted password is never read. The response is untouched — the three 401s must stay
    byte-identical, which is the other half of non-enumeration.
    `FailedLoginTimingEqualizerTest` + `tests/Security/Authentication/LoginFailureTimingTest.php` pin it.
    Related fact worth knowing: `login_throttling` answers **401** with `Too many failed login attempts`,
    not 429 — it is Lexik's failure handler that shapes the response (`LoginThrottlingTest`,
    `tools/smoke-login-throttling.sh`).
  - **`Infrastructure/Log/SecurityAuditLogger.php` is the single entry point of the security audit log**
    (3rd audit, A5/D5, Monolog channel `security_audit`, `info`, JSON on stderr in prod — see
    `monolog.yaml`). Implements `Application/SecurityAuditLoggerInterface`, one method per event:
    `login-succeeded`, `login-failed`, `login-throttled`, `logged-out`, `base-access-issued`,
    `csrf-rejected`, `backoffice-access-denied`, `user-invited`, `user-reinvited`, `role-changed`
    (`superAdmin` bool), `password-changed`, `user-deleted`, `account-activated`, `user-purged`
    (`actor: system` — the one event whose actor is not read from the token storage; `record()` takes an
    explicit actor for CLI callers). Every record carries
    `event` (the stable kebab-case key to filter on), `actor` (identifier from the token storage, or
    `anonymous`), `ip`, `path` (canonical), plus `user` and — for an existing account — `userId` (RFC 4122).
    **Never a password, a token (JWT, XSRF, invitation), an e-mail, a request body or a serialized
    exception** in any context: an invited account is named by `username` + `userId`, not by its e-mail.
    `SecurityAuditLoggerTest::testNoContextValueEverCarriesAPasswordATokenOrAnEmail` pins that with
    sentinel values run through every method; extend it when adding one. Who calls what:
    `Infrastructure/Log/SecurityEventsSubscriber` for `LoginSuccessEvent`/`LoginFailureEvent` (**`login`
    firewall only** — the `api` firewall re-authenticates the JWT on every request and dispatches the
    same events), `LogoutEvent`, and the backoffice 403 on `kernel.exception` at priority 0 (after the
    firewall's `ExceptionListener` at 1, which wraps the voter's `AccessDeniedException` in an
    `AccessDeniedHttpException` — that `previous` is required, so the CSRF guards' bare
    `AccessDeniedHttpException` isn't logged twice; an anonymous hit is a 401 that never reaches it); the
    two CSRF guards call `csrfRejected()` right before throwing (actor is `anonymous` there by
    construction — priority 20 runs before the firewall); `BaseAccessController` logs the `guest-…`
    identifier, never the token; the `Security/User/Application` use cases and the housekeeping
    `PendingInvitationPurger` log after the successful action. Functional tests read the records through
    `tests/Support/ReadsSecurityAuditLog.php` (a
    Monolog `test` handler on the channel, `when@test`, found among `monolog.logger.security_audit`'s
    handlers — that logger is public in every env, so phpstan-symfony's dev dump knows it); the kernel
    reboots between requests, so the handler holds the *last* request's records. `Psr\Log\Test\TestLogger`
    no longer ships with psr/log 3, unit tests use Monolog's `TestHandler`. `path` is logged **verbatim**
    (canonical form), with no redaction, and that is only correct because **no route carries a secret in
    its path** any more (audit A7 — the set-password token moved into the body, see `Security/User` above).
    A route that ever needed one would be the bug, not a reason to add a redaction back here. A use case logs only
    an *effective* action: `CpgUserRoleAdministrator`'s idempotent no-op writes nothing, a refused
    action writes nothing (the unit tests pin `never()` on every error path).
- **`Portfolio/Shared/`** — `Domain/ValueObject/Locale.php`, the `enum Locale: string { FR = 'fr'; EN = 'en' }`
  shared by every `Portfolio/*` context. Two entry points, and the distinction matters (audit I3):
  - **`Locale::fromString()` for anything coming from outside** (a `{locale}` URL segment, a command
    argument). It throws `InvalidLocaleException` (`Portfolio/Shared/Domain/Exception/`), the only thing mapped
    to 404 in `exception_to_status`. Providers/Processors get it in one step via `uriVariableLocale()` on the
    `ResolvesUriVariables` trait — use that rather than re-deriving it.
  - **`Locale::from()` stays for already-validated values** (backoffice DTO fields, bounded upstream by
    `#[Assert\Choice]`, or values read back from the DB). A `\ValueError` there is a genuine bug and must
    surface as a 500.
  - There used to be a blanket `ValueError: 404` mapping. It has been **removed and must not come back**: it
    disguised *every* `ValueError` in the HTTP stack as a plausible "404 Not Found", which is exactly how a
    real defect goes unnoticed.
  - **Content ordering and translation groups** (spec 0004, `v0.12.0`) also live here, because nine
    contexts share one rule. The eight localized entities with a position (`QualityPrinciple`,
    `QualityTrait`, `AboutSiteCard`, `AboutMeCard`, `Contribution`, `Incident`, `AnonymousCvSection`,
    `CaseStudy`) carry `translation_group uuid NOT NULL` with a unique `(translation_group, locale)`
    index (D1): two rows of the same group are the same content in two languages, and the group is an
    **identifier, not an entity** — the day something needs to hang *on the group*, that UUID becomes
    the primary key of a new table and the rows already reference it. **Nothing hard-wires the FR/EN
    pair** (D2): every locale `#[Assert\Choice]` points at `Locale::values()`, `AboutMeCardCategory`'s
    at its own `values()`, the frontend renders its language badges from `SUPPORTED_LOCALES` — a third
    language is a third row per group. **`position` is never typed in** (D3): it is out of every write
    DTO (`#[ApiProperty(writable: false)]`, a body still carrying it is accepted and ignored) and every
    `create`/`update` signature; `Domain/Service/ContentPlacement` derives it (a new entry without a
    group takes `max + 1` of its scope, one attached to a group **inherits the group's position**;
    `detach()` on a `PUT` with `translationGroup: null` detaches **and moves the entry to the end of
    its scope** (issue #169 — keeping the position let the old group receive that locale again via
    "Create the XX version" and inherit the same position: two keys on one position), and is a no-op
    on an entry already alone; `reattach()` with a group inherits its position; `inGroup()` throws a
    `LogicException` on a group whose members disagree on the position, a pipeline bug, never a 4xx). `WatchedProduct` is not localized, so it is `Orderable` on its
    own id, and its `Administrator` computes the end of the catalogue itself. The **only client-driven
    writer of `position` is `PUT /api/backoffice/<x>/order`** (`ContentPlacement` derives it server-side,
    no request body ever chooses it) (`Backoffice<X>OrderResource`, `read: false`,
    `output: false`, 204, one per context, `{groups: [uuid…]}` — `{ids: […]}` for Watch, plus
    `category` for me-cards): `Domain/Service/OrderAssigner` enforces the **exact-set rule** (D4) —
    an unknown key is 422 `unknown-order-entry`, a missing one 422 `incomplete-order`, both
    `ProblemExceptionInterface` slugs the frontend keys on — and renumbers `0…n-1` on every entity
    sharing a key. That 422 is not validation, it is optimistic concurrency without a version column:
    a row created or deleted between the page load and the save changes the set, the server refuses,
    the frontend reloads and says so. Never relax it to "ignore unknown keys". The
    `Version20260914170000` migration paired the existing FR/EN rows by `position` (and `category`)
    **only when the pair was unique on both sides**, giving every other row its own group — a wrong
    link would survive every proofreading, an isolated row is one "Version of" click away — and it is
    the first migration of the UUID era that is **reversible**.
- **`Portfolio/Experience/`**, **`Portfolio/Quality/`**, **`Portfolio/About/`**, **`Portfolio/Contribution/`**,
  **`Portfolio/Incident/`** — DB-backed
  content that used to be (or, for `Experience`, always was) hardcoded in the frontend. Each follows the same
  shape: a Doctrine entity per concept (`ExperienceTechnology`; `QualityPrinciple`/`QualityTrait`;
  `AboutSettings`/`AboutSiteCard`/`AboutMeCard`, the latter with an `AboutMeCardCategory` enum), a public
  read-only API Platform resource (`GetCollection('/experience/technologies')`, or an aggregating `Provider` for
  `/quality/{locale}` and `/about/{locale}` that returns `{principles, traits}` / `{settings, siteCards, meCards}`
  in one call), and a backoffice CRUD resource (see below). Seeded via idempotent `app:{about,quality,contributions,incidents}:seed`
  console commands (purge-by-locale then recreate — safe to rerun).

  `Contribution` is the odd one out and deliberately so: it carries a long `body` (the argument, not
  just a link to it) alongside `title`/`project`/`reference`/`url`/`summary`. That text is **plain
  text**, paragraphs separated by a blank line, rendered by splitting on those blanks —
  `ContributionsPage.vue` never uses `v-html`. The only markup honoured is `` `backticks` ``, turned
  into `<code>` by splitting on a capturing regex, so every segment stays an interpolated text node.
  The content is authored through the backoffice, so treating it as HTML would trade formatting for
  a stored-XSS hole on a public page — there is a regression test pinning that
  (`tests/presentation/pages/ContributionsPage.spec.ts`).

  `Incident` follows the same shape with a post-mortem's fields — `impact`, `rootCause`,
  `resolution` and, crucially, **`invariant`, which is `NOT NULL`**. That constraint is the page's
  editorial line made structural: an entry you cannot save without stating the rule you drew from
  the outage cannot drift into a confession. Don't relax it to "make an entry easier to add".

  Both pages render their prose through **`presentation/ui/RichText.vue`** — paragraphs split on
  blank lines, `` `backticks` `` turned into `<code>`, never `v-html`. It is shared rather than
  duplicated precisely because it carries a security guarantee: a fix applied to one copy would
  silently leave the other exposed. Reach for it for any backoffice-authored prose.
- **`Portfolio/Watch/`** — the tech-watch radar behind the public `/{locale}/stack` page: version
  lifecycles from **endoflife.date** and known vulnerabilities of the deployed packages from **OSV.dev**.
  It is the only context whose domain depends on third parties, and the rules below are what keep that
  dependency from becoming a liability. Full rationale in `docs/adr/0002-veille-technique.md`.
  - **No outbound call ever sits in a public render path.** A CronJob (`app:watch:refresh`) writes a
    `WatchSnapshot` per volet; `GET /api/watch` reads **only** that local snapshot. `WatchProvider` has
    **no dependency at all** on an external source, so it cannot reintroduce one by accident — a
    functional test pins it by substituting an HTTP client that fails on any call. The single
    deliberate exception is the backoffice slug check (below).
  - **A third party's response never crosses the `Infrastructure/` boundary.** `EndOfLifeDateClient` /
    `OsvClient` return domain Value Objects (`ProductReleaseCycles`, `KnownVulnerability`…), never a
    decoded `array`. That anti-corruption layer is what makes the domain testable without a network;
    **no test may go out on the wire** (`MockHttpClient` everywhere) — a test really calling
    endoflife.date is intermittent by construction and breaks CI the day the provider has an incident.
  - **Public sees an aggregate, `ROLE_SUPER` sees the detail.** `/api/watch` carries
    `{packagesScanned, affectedCount, checkedAt}` and never a CVE id, affected package name or
    vulnerable version; the detail lives at `/api/backoffice/watch/vulnerabilities`. The partition is
    enforced **at read time in the provider** — the snapshot itself keeps the full detail on purpose,
    since amputating it would rob the one person entitled to see it. A dedicated test asserts the
    absent keys in the anonymous payload; never relax it.
  - **Refusing to write is a feature.** If nothing could be refreshed, nothing is persisted — a
    "zero product" payload would wipe the page on the provider's first outage while yesterday's data
    is still perfectly readable. Likewise a missing manifest yields an explicit "not analysed" state,
    never a lying "0 vulnerabilities": *found nothing* ≠ *looked for nothing*.
  - **A third party's string never becomes an `href` unfiltered** (security review, 2026-09-08).
    `links.html` from endoflife.date reached `/stack`'s `:href` with no scheme check — Vue does not
    sanitize `:href` — so a `javascript:` value published in their **open dataset** landed intact in
    the DOM of a public page. *(The review first called this an exploitable stored XSS, on the
    strength of a second claim — "the frontend nginx has no CSP" — that was **wrong**: the grep
    behind it was truncated by a `head`. `docker/node/nginx.conf` serves a strict CSP,
    `script-src 'self'` with no `'unsafe-inline'`, which blocks `javascript:` navigation; the browser
    demo ran against Vite's dev server, which sends no CSP. The defect was mitigated in production.
    The filter is still right — a CSP is a mitigation, not a licence to republish an unchecked URL —
    and note that adding `'unsafe-inline'` to `script-src` would reopen exactly this door.)*
    `Domain/Service/ExternalUrlFilter` is an
    **allow-list** (`https` only — a `javascript:` denylist would still let `data:` and `vbscript:`
    through) and also rejects control characters, which browsers strip while parsing an href
    (`java\tscript:` runs). A refused URL becomes **absent, never "cleaned"**: repairing it means
    guessing the author's intent, which is how a neutralised payload gets reassembled. It is applied
    **twice, at write and at read** — a snapshot written before the fix still holds the raw value, so
    filtering only on write would leave every deployed installation exposed until the next refresh.
  - **`max_redirects: 0` on every outbound call.** Symfony's default follows 20, and the CronJob pod
    has no egress restriction — a hijacked provider would get a lever into the internal network.
  - **`/api/watch` is publicly cacheable, but briefly** (`cacheHeaders` on the `Get`: `public`,
    `max-age`/`s-maxage` 300, `stale-if-error` 3600). The short lifetime is *not* about the data —
    that is daily — but about the **label**: `freshness` is computed at read time and `StackPage.vue`
    trusts it instead of recomputing from `refreshedAt`, so a response cached for T seconds shows a
    label T seconds out of date. A day-long cache could therefore display "fresh" after refreshing
    had stopped, which is a lie about the one thing this page exists to show. `public` is only safe
    because `WatchProvider` ignores the caller entirely — remove it before making this response
    depend on who is asking. `WatchResourceTest` pins the ceiling (not the exact value) and pins the
    other side too: the `ROLE_SUPER` detail must never become publicly cacheable.
  - **PHP's and Symfony's versions come from the runtime**, not the backoffice
    (`VersionSource::RUNTIME_PHP` / `RUNTIME_SYMFONY`, `PhpAndSymfonyVersionResolver`) and the version
    field is refused for them (`InvalidWatchedProductException`, 422). The two most-looked-at versions
    cannot go stale through a forgotten edit.
  - **Freshness is computed at read time**, threshold `SnapshotFreshnessCalculator::STALE_AFTER_HOURS`
    = **36 h** — 1.5× the daily CronJob. It must stay strictly above the period, or a single missed
    cycle (or merely the hour before the run) would display "stale".
  - **The package manifest is built during `docker build`**, not by the app and not by CI:
    `bin/build-package-manifest.php` (standalone, **no Symfony kernel** — at build time neither
    `APP_SECRET` nor `DATABASE_URL` exists) merges `composer.lock` + `frontend/package-lock.json` in
    the `production` stage, the one place both lockfiles coexist. The file is gitignored and read-only
    in the image. `app:watch:build-manifest` is the same thing for local use.
  - `WatchedProductSlugExists` (an `Infrastructure/Validator/` constraint, not a domain rule) checks the
    slug against endoflife.date **when saving from the backoffice** — 2 s timeout, **non-blocking**: a
    provider outage must never prevent administering your own site. It lives in the validator rather
    than in `WatchedProductAdministrator` so that `app:watch:seed` (and its test) stay off the network.
  - Not localized (no `locale` column, unlike every other `Portfolio/*` context): a version number is a
    fact, not a translation. UI labels are handled frontend-side.
  - **A fresh environment starts with an empty `watched_product`**, and `app:watch:refresh` says so
    plainly (`Aucun produit surveillé : rien à rafraîchir.`) rather than failing — `/stack` then shows
    "never refreshed" until the catalogue is seeded. Preprod is seeded automatically on every deploy
    (see "Seeding" below); prod is populated and the guard keeps it that way.
  - **No version is ever typed in** (issue #19). Decision D2 started with PHP and Symfony reading the
    runtime; the other five used to be `MANUAL`, so bumping `POSTGRES_TAG` left `/stack` announcing
    the previous version until someone edited the backoffice — the page stating something untrue
    about what runs, which is exactly what it exists to prevent. They are now `VersionSource::DEPLOYED`,
    resolved from a record written at **`docker build`** into `config/watch/deployed-versions.json`
    (same shape as the package manifest: a builder that writes, a reader that reads back).
    The authority is **`k8s/base/*.yaml`** — the manifests the cluster actually applies — not `.env`,
    which only drives dev images and was kept in sync with them by a comment. That comment is gone.
  - **The tags reach the build as `--build-arg`, never as copied files.** Two independent reasons, and
    both must hold: `k8s/` is excluded by `.dockerignore` (an application image has no business
    carrying deployment manifests), and `COPY .env` would create a layer that keeps the file even
    after a later `rm`. The `Makefile` extracts the tags with `sed` from the manifests and passes
    them; the builder falls back to reading the files, which is the **dev** path — `docker-compose`
    mounts `k8s/`, `.env` and the npm lock read-only for that, and `app:watch:build-versions` is the
    local equivalent of the build step.
  - **Node is the odd one and stays labelled as such.** It is deployed nowhere: it builds the bundle
    and disappears, the frontend image serving only nginx. Its version comes from `.env` — which
    genuinely drives the build — and the page shows it as toolchain, not as something running. Vue
    likewise comes from the npm lock, being a bundle dependency rather than an image.
  - A missing record is not an error: the products show without a version, which the page already
    renders. Failing a build or a refresh over a renamed manifest would be out of proportion.

- **`Ai/`** — everything that talks to a language model, and nothing else does (ADR 0004,
  `docs/adr/0004-assistance-ia.md`; spec `.claude/specs/archive/2026-09-14-spec-0002-assistant-traduction/0002-ai-translation-assistant.md`). Sub-context per
  usage: `Ai/Translation/` (phase 1, **delivered 2026-09-14**, v0.10.0 then v0.10.1: the backoffice FR/EN
  translation assistant, `POST /api/backoffice/translations`, `ROLE_SUPER`) and later `Ai/Assistant/` (phase 2,
  **D7 amended on 2026-09-15**: a conversational "ask about my career" assistant on the site, reserved to
  `ROLE_TRUSTED`, on Scaleway Generative APIs — the site's own host, `fr-par` — which is the one operator the
  nominative CV may reach (D3 amended); corpus injected in the context from the tier's existing providers,
  no tools, no vector store, nothing persisted, streamed response; the MCP server originally planned is
  now an *alternative écartée*; **in progress** on the branch `feature/spec-0005-career-assistant`, spec
  `.claude/specs/0005-career-assistant.md`, task 1 (#260) done: the `career_assistant` agent in `ai.yaml`,
  `mistral-small-3.2-24b-instruct-2506`, `max_tokens` 1024, `tools: false`, prompt preamble in
  `config/ai/prompts/career_assistant.txt`, key `SCALEWAY_AI_API_KEY` routed exactly like `ANTHROPIC_API_KEY`).
  **The Scaleway platform is declared in `services.yaml` (`app.ai.platform.scaleway`, the bridge's
  `Factory::createPlatform`), not in `ai.yaml`** (spec 0005 D2): `symfony/ai-bundle` 0.13.0 hard-codes the
  default `http_client` for a `scaleway` platform and ignores its `http_client` option, so the ADR's dedicated
  client (`ai.scaleway.http_client`, `framework.yaml`: timeout 40 s, `max_redirects: 0`) can only reach it that
  way. Don't move it back into `ai.yaml` until a bundle version honours the option;
  `tests/Ai/Assistant/Infrastructure/ScalewayPlatformWiringTest` pins the wiring (mock behind
  `ai.scaleway.http_client.scoping.inner`, asserts URL, bearer, timeout, `max_redirects`, model, bounds). The
  Scaleway recipe's `ai_scaleway_platform.yaml`/`ai_generic_platform.yaml` (the latter from the transitive
  `symfony/ai-generic-platform`) were deleted — delete them again if a recipe update recreates them. The
  shared test agent is `tests/Ai/Support/FakeAgent`. The bundle is **Symfony AI**, pinned in **exact version** (`symfony/ai-bundle`,
  `symfony/ai-anthropic-platform`, `symfony/ai-scaleway-platform`, `symfony/ai-agent`, all `0.13.0`, no `^` while 0.x); the platform and the
  `translator` agent (`claude-sonnet-5`, `max_tokens` 4096 — the Anthropic wire name, the bridge merges
  options as-is —, `tools: false`, system prompt in `config/ai/prompts/translator.txt`) are declared in
  `config/packages/ai.yaml` on a dedicated scoped client `ai.http_client` (`framework.yaml`: timeout 40 s,
  `max_redirects: 0`). Rules that must hold, in the ADR's words: **only one class imports `Symfony\AI\*`**
  (`Infrastructure/SymfonyAi/…`, behind an application interface); **no model call from a public render
  path**, a visitor-triggered Messenger handler or a render CronJob — backoffice only, synchronous, with a
  timeout; **only backoffice-authored content meant for publication may be sent** to a provider, never
  `cpg_user`, a token, the nominative CV or a contact message; **a suggestion is never persisted** without a
  human action (the endpoint reads and writes nothing, the frontend fills a *new* form); **cost is bounded by
  construction** (per-account quota `translation_assistant`, 30/h keyed on the `username`, `max_tokens`,
  timeout); **no test goes on the wire** (unit tests: a `FakeAgent`; functional tests: the concrete client
  behind the scoped one, `ai.http_client.scoping.inner`, replaced by a `MockHttpClient` answering in the
  Messages API format — **with `$client->disableReboot()`**, otherwise `KernelBrowser` rebuilds the kernel
  between the login and the call and the request really leaves for `api.anthropic.com`; dummy
  `ANTHROPIC_API_KEY` forced in `phpunit.dist.xml`, so such a leak fails 401 → 503 instead of costing money).
  An anonymous `POST` there answers **403, not 401**: the CSRF subscriber runs before the firewall. Token
  usage and duration are logged, the content never is. `claude-sonnet-5` rejects `temperature`/`top_p`/`top_k`
  (400): no sampling option anywhere. The Flex recipes come from the official `symfony/recipes` (they apply
  despite `allow-contrib: false`); the `ai_anthropic_platform.yaml` they generate is merged into `ai.yaml`,
  delete it again if a recipe update recreates it.

### Backoffice (`ROLE_SUPER`)

Content management for all of the above, plus user administration, gated end-to-end behind `ROLE_SUPER`
(the default role every account also has is `ROLE_USER`, cf. `CpgUser::getRoles()` — never sufficient here):

- **Authorization**: a single `access_control` entry in `config/packages/security.yaml`,
  `{ path: ^/api/backoffice(/|$), roles: ROLE_SUPER }`, which **must stay the first entry in the list** — Symfony
  applies only the first matching rule, so a later/looser rule (e.g. `^/api/me`) would never get a chance to
  override it, but a rule placed *before* it could accidentally widen backoffice access. **Every `path` is
  anchored with `(/|$)`** (issue #78, pt 2): a rule covers its route and its subtree, nothing else, so
  `/api/cv-export` is *not* `ROLE_TRUSTED` by accident and a future `/api/case-studies-drafts` is *not*
  `ROLE_USER` by accident. A sibling path therefore inherits no implicit protection — write its rule, or
  `ApiRouteExposureTest` flags it. `tests/Security/AccessControlAnchoringTest.php` pins the anchors against
  the compiled `AccessMap`; keep the pattern when adding a rule. The same test also pins the **firewall**
  patterns, which follow a different rule and for a stated reason — see `Security/Authentication` above.
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
- **API Platform pattern**, repeated identically across every backoffice resource
  (`BackofficeExperienceTechnologyResource`, `BackofficeQuality{Principle,Trait}Resource`,
  `BackofficeContributionResource`, `BackofficeIncidentResource`, `BackofficeWatchedProductResource`,
  `Backoffice{About}{Settings,SiteCard,MeCard}Resource`, `BackofficeUserResource`,
  `BackofficeUserPasswordResource`): a flat DTO (never the Doctrine entity itself) under
  `Presentation/ApiResource/`, backed by a `Provider` (`GetCollection`/`Get`) and a `Processor`
  (`Post`/`Put`/`Delete`) under `Infrastructure/ApiPlatform/`. Collection reads use a `?locale=` query filter
  (unlike the public `{locale}` path param — collections aren't per-locale routes). **`Put`/`Delete` operations
  need an explicit `provider:` set, not just `processor:`** — otherwise API Platform's default provider tries to
  resolve the DTO via Doctrine directly and 404s before ever reaching the processor. Since `v0.12.0` the
  localized collections (Quality, About cards) are read **without** the `?locale=` filter by the admin
  pages, which display every language in one grouped table (spec 0004 D8); the filter still exists.
  Each ordered context adds a one-operation `Backoffice<X>OrderResource` (`Put …/order`, `read: false`,
  `output: false`, 204) whose input uses the `CarriesOrderedKeys` trait — `keys()` returns the
  validated `list<string>` and is the only thing the Processor reads; mirror that rather than
  re-validating the array by hand (`debug:router | grep /order` must list exactly one route per context,
  none synthesised).
- **A read-only resource with no Doctrine identifier needs `#[ApiProperty(identifier: false)]`** on its
  `id` field — `BackofficeVulnerabilityResource` (`GetCollection /backoffice/watch/vulnerabilities`,
  read straight from the snapshot) is the case in point. Without it API Platform infers `id` as the
  identifier, synthesises an item operation to build IRIs, and publishes
  `/api/backoffice_vulnerabilities/{id}`: a second, undocumented route to the same data. Same trap as
  audit C6 on `BackofficeUserResource`, arrived at from the other end. Check `debug:router` after
  adding any resource.
- **`Security/User` backoffice resources** (`ROLE_SUPER`): `BackofficeUserResource` —
  `GetCollection` (list, `normalizationContext: skip_null_values=false` so `email` is always present),
  `Post /backoffice/users` (**invite** by `{email, locale}`, input DTO `BackofficeUserInviteInput`, → 201; direct
  username+password creation stays CLI-only), an explicit `Get /backoffice/users/{id}` and
  `Delete /backoffice/users/{id}`. The `Get` is declared **on purpose** (audit C6): without an item operation,
  API Platform silently synthesises one to build IRIs, published on its default template
  `/api/backoffice_users/{id}` — a second, undocumented path to the same data, which at the time only stayed
  protected by the accident that the then-unanchored `^/api/backoffice` matched `backoffice_users` by prefix.
  That accident is gone (the rule is now `^/api/backoffice(/|$)`, see "Authorization" above), so such a route
  would be served to anyone — `ApiRouteExposureTest` would turn red, but check `debug:router` first. Declaring
  the `Get` removes that route. Plus dedicated one-operation
  resources: `BackofficeUserPasswordResource` (`Put …/{id}/password`, `output: false`),
  `BackofficeUserRoleResource` (`Put …/{id}/roles` `{superAdmin}`, `output: false`),
  `BackofficeUserInvitationResource` (`Post …/{id}/invitation` `{locale}`, resend, `read: false`, → 202).
  The `Put`/`Post`-with-id ones each set an explicit `provider` (or `read: false` for the id-in-path `Post`) so
  API Platform doesn't 404 at the read stage on the non-Doctrine DTO.
- **Exceptions**: every new Domain `NotFoundException`/`AlreadyExistsException` needs an entry in
  `config/packages/api_platform.yaml`'s `exception_to_status` map (e.g. `ExperienceTechnologyNotFoundException`,
  `CpgUserNotFoundException`, `CannotDeleteOwnAccountException`) — otherwise API Platform surfaces an unmapped
  exception as a generic 500 instead of a meaningful 4xx. When two exceptions share a status code but the
  frontend must tell them apart (e.g. the two `PUT …/roles` 409s: self-modification vs last-super-admin), make
  the exception `implements ApiPlatform\Metadata\Exception\ProblemExceptionInterface` and
  `use App\Shared\Domain\Exception\HasProblemType` (declare `problemType()` → a stable kebab slug +
  `problemStatus()`): API Platform then emits `type: /errors/<slug>` in the problem+json, which the client keys
  on instead of substring-matching the localized `detail`.
  **Two traps of that map, both paid for** (audit A15): declaring `exception_to_status` **replaces** API
  Platform's defaults instead of extending them, so the three it ships with are restored explicitly at the
  **end** of the list (`Serializer\ExceptionInterface: 400`, `ApiPlatform\Metadata\Exception\InvalidArgumentException: 400`,
  `Doctrine\ORM\OptimisticLockException: 409`) — without them, unparsable JSON or a wrongly-typed field
  answered **500 on every POST, public ones included**, i.e. an anonymous caller could manufacture 500s at
  will and drown real server errors in the logs. And resolution takes the **first matching entry**, with
  `is_a()` matching interfaces and parents too, so a broad entry must stay **below** the precise ones: add a
  new exception *above* those three restored defaults, never after. Since 2026-09-22 (issue #239)
  `defaults.collect_denormalization_errors: true` narrows what that 400 covers: a **wrongly-typed field**
  (`{"name":123}`) is collected instead of aborting the deserialization and comes out as a **422 with
  `violations` naming the field**, the same shape the admin forms already render for an `Assert`; only
  unreadable JSON stays a 400. `MalformedRequestBodyTest` pins both boundaries. The API Platform metadata
  pool survives a change to that option — `rm -rf var/cache/test` before trusting a red test.
- **Errors under `/api` come out as JSON, never as Symfony's HTML page** (audit A15). Two families escaped
  API Platform's own error handling: the router's 404/405 (raised before API Platform exists for that
  request) and the 403s of our own `kernel.request` guards on non-API-Platform routes (`POST /api/logout`
  without the CSRF header, `POST /api/login_check` without `X-Requested-With`).
  `Shared/Infrastructure/Http/ApiJsonErrorFormatListener` sets `json` as the request format for any
  canonical path under `/api` (exactly `/api` or `/api/…`, never `/apix`), and
  Symfony's `ProblemNormalizer` then renders RFC 7807. Two things to leave alone: it is on **`kernel.exception`
  at priority -100**, *not* `kernel.request` — setting the format on every request breaks API Platform's
  content negotiation, and `GET /api/docs` without an `Accept` header answered **406** — and -100 sits
  between API Platform's own `ExceptionListener` (-96) and Symfony's `ErrorListener` (-128), so it never
  runs on errors API Platform already handled. `ApiJsonErrorFormatListenerTest` + `ApiErrorFormatTest` pin it.

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
resource for existing content: follow the API Platform pattern above rather than reinventing a controller.

### Frontend architecture (`../frontend/src`)

Single-page app in clean-architecture layers, `PortfolioContentRepository` is the DIP boundary:

- `domain/portfolio/entities/` — plain TS interfaces (no Vue import).
- `domain/portfolio/repositories/PortfolioContentRepository.ts` — abstraction; add a method here first when
  exposing new content.
- `infrastructure/portfolio/StaticPortfolioContentRepository.ts` — today's only implementation (hardcoded
  content); a future `HttpPortfolioContentRepository` would slot in without touching application/presentation.
- `application/portfolio/usePortfolioContent.ts` — composable, injects the repository via an `InjectionKey`
  provided once in `main.ts` (composition root).
- `presentation/{ui,layout,sections,pages,router,i18n}/` — Vue components; `pages/LandingPage.vue` assembles
  the sections, `pages/AboutPage.vue` is a standalone routed page (no `sections/` file — sections are only for
  blocks assembled *inside* `LandingPage.vue`).
- `presentation/layout/AppLayout.vue` — the shared chrome (`AppHeader` + `<RouterView>`), rendered once by
  `App.vue`; it's the one place `siteIdentity`/`navigationLinks` are pulled from `usePortfolioContent()` for
  every page, so individual pages only destructure the content they actually render.
- `presentation/router/index.ts` — Vue Router 4 (history mode), routes are lazy-loaded (`() => import(...)`)
  per page. `NavigationLink.to` is a router target as a plain string, locale already included
  (e.g. `/fr/about`, `/en#technologies` for an in-page anchor on the landing page) — never a vue-router type,
  to keep the domain entity framework-free.

To add a new content block: entity → repository interface method (with a `locale: Locale` parameter) →
`infrastructure/portfolio/content/{fr,en}.ts` (structured content) → expose it from `usePortfolioContent` →
new `presentation/sections/*.vue` → wire into `LandingPage.vue`. Note: this "static content" flow only still
applies to `hero`/`technologies` — About/Quality moved to the API-backed flow below.

To add a new page: new route in `presentation/router/index.ts` (nested under `/:locale(fr|en)`, with
`meta.titleKey`/`meta.descriptionKey` for SEO — see below) → new `presentation/pages/*.vue` (its own
`usePortfolioContent()` call for its own content) → new `NavigationLink` entry (`to` + `isEnabled`) in
`StaticPortfolioContentRepository`. `AppHeader` derives the active nav link from `useRoute()`, not from props.

#### API-backed content (About/Quality/Contributions/Incidents/Watch)

Unlike `PortfolioContentRepository` (hero/technologies, synchronous, hardcoded), the About/Quality/Contributions content
now lives in the backend DB and is fetched asynchronously, each with its own small vertical slice:
`domain/{about,quality,contributions}/repositories/*Repository.ts` (interface) →
`infrastructure/{about,quality,contributions}/Http*Repository.ts` (the implementation, calls the public
`/api/{about,quality,contributions}/{locale}` endpoints) → `application/{about,quality,contributions}/use*.ts` (composable
exposing `content`/`isLoading`/`hasError`, injected the same `InjectionKey` way as `usePortfolioContent`) →
consumed by `AboutPage.vue` / `LandingPage.vue`'s Quality section, each rendering a loading state, an
error state (`role="alert"`), and the content. `main.ts` provides both repositories alongside the existing
`PortfolioContentRepository` one. `domain/watch` → `infrastructure/watch/HttpWatchRepository.ts` →
`application/watch/useWatch.ts` → `presentation/pages/StackPage.vue` follows the identical shape, with one
difference that comes from the backend: **its endpoint has no `{locale}` segment** (`GET /api/watch`) — a
version number is a fact, not a translation, so only the surrounding UI strings are localized. Don't add new content here unless it's genuinely backend-managed (i.e. editable
from the backoffice) — purely static content still belongs in `infrastructure/portfolio/content/{fr,en}.ts`.

#### Backoffice (`/admin`, `ROLE_SUPER`)

Content/user management UI, mirrored per-resource under `domain/admin/<resource>/{entities,repositories,errors}`
→ `infrastructure/admin/<resource>/Http*Repository.ts` → `application/admin/<resource>/use*.ts` →
`presentation/pages/admin/Admin*Page.vue` (form + Bootstrap table, `window.confirm()` for deletes — no modals).
Existing resources: `technologies`, `quality` (principles + traits), `contributions`, `incidents`, `anonymousCv`,
`caseStudies` (the two base-tier contents of ADR 0003 D5, both prose-only, editorial rule reminded above the
form: no client or employer name — no filter does it for you), `about` (settings + site cards +
me cards), `watch` (tracked products + the `ROLE_SUPER`-only vulnerability detail, read-only), `users` (list + **invite by email** + change-password + promote/demote + resend invitation + delete;
direct username+password creation stays CLI-only). `AdminUsersPage.vue` disables the delete and role buttons on
the current user's own row (compared by `username` via `useAuth()`); the `email` column shows the linked address
or a dash. The `domain/account` + `application/account/useAccountPasswordSetup` + `presentation/pages/SetPasswordPage.vue`
slice is the **public** counterpart: route `/(fr|en)/set-password` (`meta.noindex`, no `requiresAuth`),
`useAccountPasswordSetup` state machine (`checking|ready|submitting|done|invalid|expired|error`), talking to the
two public `POST /api/account/password-setup(/validate)` endpoints — a 422 on `validate` means "invalid link",
a 422 on the completion means "password refused", and they must keep being told apart.

**The invitation token reaches the page in the URL *fragment*, and leaves the URL immediately** (audit A7,
T4.2). The emailed link is `…/{locale}/set-password#<token>`: a fragment is never sent to the server, so it
reaches neither the frontend nginx's access log nor the ingress's — the same reason the token moved out of the
API path. Three things follow, none of them optional:
- the page reads the fragment and nothing else; the token then lives **in memory only**. The `:token?`
  path segment that served as a 48 h fallback for links sent before the fragment was **removed** (T4.4,
  2026-09-26): `…/set-password/<anything>` is now a router 404, and that is the point — a secret must not be
  able to arrive through the path at all. Don't reintroduce a segment "for compatibility";
- it is erased from the URL with **`router.replace`, never `history.replaceState`** — vue-router stores the
  `fullPath` in `history.state`, so a `replaceState` that preserved that state would preserve the token with it;
- `<link rel="canonical">` and the `hreflang` alternates are built by `presentation/router/seo.ts` from
  `to.path`, which never contains the fragment, so nothing special is needed to keep the token out of the DOM
  (the `meta.canonicalPath` override that protected the old segment went away with it — a route that ever
  carried a secret in its path would be the bug, not a reason to bring it back). The router's scroll/anchor
  handling skips the `set-password` hash — it is a token, not an anchor.
No token at all ⇒ `invalid` with **no network call**. `SetPasswordPage.spec.ts`, `router/seo.spec.ts` and
`router/adminGuard.spec.ts` (the 404 on an old segment link) pin all of it.

**Ordering and translation groups** (spec 0004, `v0.12.0`): every ordered admin page (Incidents,
Contributions, Anonymous CV, Quality ×2, About site cards + one table per me-card category, Watch) is
wired on the same shared bricks, and a new page must reuse them rather than grow its own copy —
they carry the security-neutral but a11y-critical behaviour (keyboard alternative, focus return,
status announcement) that a fork would silently lose:
- `domain/admin/shared/ordering/` — `groupByTranslationGroup` (entries × `SUPPORTED_LOCALES` → one row
  per group with `byLocale`/`missing`), `orderRowsByDraft`, `moveKey`, `translationGroupSelection`
  (`translationGroupOptions`/`firstEntry`/`hasSibling`/`rowLines`, the "Version of" rule: entries of
  **any other locale** whose group lacks the form's locale — nothing names FR or EN), all framework-free.
- `application/admin/shared/` — `useOrderDraft` (the draft follows the server keys until the first
  `move`, then detaches until `reset`/`save`; on a 422 `stale-order` it reloads and re-syncs, on any
  other error it keeps the draft so the admin can retry), `useRowDragAndDrop` (native HTML5, the
  dragged index lives in the composable, **never in `dataTransfer`** — jsdom has none, and that is
  what keeps the flow testable with plain events), `useOrderHandleFocus` (one instance **per table**,
  focus back on the moved row's handle after ↑/↓; the `ref` callback is a stable function keyed on
  `data-order-key`, never an inline lambda).
- `presentation/ui/admin/OrderHandle.vue` (a real `<button>`, ↑/↓, the arrows described by
  `aria-describedby` to a rendered hint, issue #170 F2) and `OrderToolbar.vue` (status, Cancel, "Save
  order", the order error, **and the one `role="status"` live region of the table**, fed by
  `useOrderHandleFocus().lastMove` — it used to live in the handle, i.e. inside the moved `<tr>`, which
  is re-parented in the same render cycle and can swallow the announcement, #170 F3; Cancel stays
  enabled while an order error is shown, so a `stale-order` alert can be dismissed, #170 F4), i18n
  under `admin.order.*`.
- **Page rules**: the table shows **every language** (D8 — the row is the group, languages stacked in the
  content cell, a missing one reads "Missing translation"); **the per-language actions live in a last
  "Actions" column** (Edit/Delete, or "Create the XX version" which opens a creation already attached to
  the group with the non-prose fields copied), stacked with the same `v-for` and the shared
  `.admin-locale-line` class (`style.css`, a common `min-height`) so each language stays in front of its
  buttons — the specs locate a line's buttons by index in `td:last-child`, keep that structure; **one locale per form**, never a page-level locale selector shared
  by several forms (B9 lesson: it silently overwrote the locale of an entry being edited in the other
  panel; About's top selector only drives the *settings* singleton). While the order draft is dirty,
  **every mutation is disabled** (Edit, Delete, Create version, submit, the translate button) with a
  visible hint referenced by `aria-describedby` — never a `title`, Bootstrap's `.btn:disabled` has
  `pointer-events: none` — and leaving the route asks `window.confirm`, closing the tab goes through
  `beforeunload` — both in `application/admin/shared/useUnsavedOrderGuard(isDirty)` (#170 F5), tested once
  in its own spec with `enableAutoUnmount` (a page left mounted keeps its listener); a page calls it,
  never re-implements it. `startEdit` sends back the group it read whenever the entry has a
  sibling, otherwise `''` → `null`, which `ContentPlacement::reattach` treats as a no-op on a lone
  entry. The `Position` number field is gone from every form; `BaseNumberInput` survives only for
  genuinely numeric content (years of experience).

**Translation assistant** (ADR 0004 phase 1, spec 0002): one more admin slice, `domain/admin/translation`
(`TranslationDraft`, `AdminTranslationError` with reasons `validation|rate-limited|unavailable|unknown`)
→ `infrastructure/admin/translation/HttpAdminTranslationRepository.ts` (`POST /api/backoffice/translations`)
→ `application/admin/translation/useAdminTranslation.ts` (`translate()` returns the draft or `null` and
exposes `errorReason`; **it never touches a form nor persists anything**, ADR 0004 D4) + the two helpers in
`proseFields.ts` (`collectProseFields` drops blank fields, the API refuses them with a 422;
`applyTranslationDraft` leaves a field absent from the draft untouched) → `presentation/ui/admin/
TranslateEntryButton.vue` (label follows the form's locale, `aria-busy` while calling, emits `translate`).
**Each page alone decides which of its fields are prose** (spec D2 — the backend is content-agnostic) and
what to do with the draft. Since spec 0004 every per-entry form (Incidents, Contributions, Anonymous CV,
Quality principles/traits, About site/me cards) behaves the same way: the form switches to *creation* in
the target locale, the non-prose fields (`version`, `occurredAt`, `iconKey`…) are kept, **the source
entry's `translationGroup` is kept too** (spec 0004 D9, `sourceGroup` — distinct from the selector's
value, so a lone source still attaches its draft) so saving the proposed version links it with no
extra gesture, and a `role="status"` banner names the draft's source locale. The old "page-locale"
semantics is gone with the page-level selector (one locale per form, see above). About *settings* is
the one singleton per locale, so its draft is **deferred**: parked in `pendingDraft`, applied on the
return of `load()` for the target locale (hence the `flush: 'sync'` watcher, or the copy from the server
would overwrite it). The button is disabled while an order draft is dirty (a draft the locked form could
not save would burn quota for nothing). Never use `v-html` on text coming back from the model: it goes
through the form fields, then `RichText.vue`. The case-studies admin page (`/admin/case-studies`, issue #104,
closed 2026-09-14) is wired exactly like the anonymous-CV one — every field is prose, so "Create the XX
version" copies nothing and the assistant sends all five fields.

- `presentation/ui/{BaseTextInput,BaseTextarea,BaseNumberInput,BaseSelect}.vue` — the project's first reusable
  form components, used by every admin form. Reach for these before writing a new raw `<input>` in `admin/*`.
- `presentation/layout/AdminLayout.vue` — sub-navigation across the admin sections, rendered for every
  `/admin/*` route.
- **Route protection**: `RouteMeta` carries `requiresAuth?: boolean` and `roles?: readonly string[]`; every
  `/admin/*` route sets `{ requiresAuth: true, roles: [ROLE_SUPER] }`. A `router.beforeEach` guard in
  `presentation/router/index.ts` checks this: unauthenticated → redirect to `login` with
  `query: { redirect: to.fullPath }` (consumed by `LoginPage.vue`'s `redirectTarget()`, which only honors paths
  starting with a single `/` — open-redirect protection); authenticated but missing the role → redirect to
  `presentation/pages/ForbiddenPage.vue`.
  **Must call `await waitForAuthCheck()`** (`application/auth/useAuth.ts`) before making that decision — on a
  page reload/direct navigation, the guard can otherwise run before `main.ts`'s `checkAuth()` has resolved and
  wrongly redirect an actually-authenticated user to `/login` (regression-tested in
  `tests/presentation/router/adminGuard.spec.ts`). `hasRole(user, role)` (`domain/auth/services/hasRole.ts`) is a
  plain function (no Vue `inject()`) so it also works inside this router guard, outside component context.

#### i18n (French/English)

Every route is prefixed by locale (`/fr`, `/en/about`…), the single source of truth for the active language —
`presentation/router/index.ts`'s `beforeEach` syncs `i18n.global.locale` from `to.params.locale` on every
navigation (also handled for the `/:pathMatch(.*)*` 404 catch-all, which has no `:locale` param: the guard
falls back to parsing the URL's first path segment so the 404 page itself stays in the right language).
`/` redirects to the user's previously chosen locale (`localStorage`) or their browser language, defaulting
to `fr`.

Two deliberately separate content sources, to keep `@intlify/eslint-plugin-vue-i18n`'s key-usage checks
meaningful (see Lint below):
- `infrastructure/i18n/locales/{fr,en}.json` — short UI-chrome strings only (nav labels, buttons, aria-labels,
  SEO title/description per page), consumed exclusively via `useI18n()`'s `t()`/`$t()`. Wired into vue-i18n by
  `presentation/i18n/index.ts` (`createAppI18n()` factory + an `i18n` singleton used by `main.ts` and the
  router). Tests call `createAppI18n()` themselves for an isolated instance, the same pattern the router specs
  already use for a fresh `createRouter(...)` per test.
- `infrastructure/portfolio/content/{fr,en}.ts` — the structured portfolio content still hardcoded on the
  frontend (hero, technologies — About/Quality moved to the backend DB, see "API-backed content" above),
  typed against `PortfolioLocaleContent` and read directly by `StaticPortfolioContentRepository` (never through
  vue-i18n). Keep new "content" here, not in the i18n JSON, unless it's genuinely a short UI string and it isn't
  meant to be backoffice-editable.

`StaticPortfolioContentRepository` stays framework-free infrastructure: it reads the raw locale JSON/TS files
directly, and never imports the vue-i18n runtime (that lives in `presentation/i18n`, which infrastructure must
not depend on).

#### SEO

No SSR (plain SPA), so `presentation/router/seo.ts` (`applySeoMeta`, called from a `router.afterEach`) updates
`document.title`, `<meta name="description">`, `<link rel="canonical">`, and `<link rel="alternate" hreflang>`
(one per locale plus `x-default`) on every navigation — only visible to crawlers that execute JS, but Googlebot
does. The 404 route gets `<meta name="robots" content="noindex, nofollow">` instead of canonical/hreflang.

Icons: domain/content only ever holds a string `iconKey`; the mapping to an actual `unplugin-icons` component
(`~icons/lucide/...`, `~icons/simple-icons/...`) lives solely in `presentation/ui/icons.ts`.

#### Accessibility (a11y)

Baseline for keyboard/screen-reader users, established across `AppLayout.vue`/`AppHeader.vue`/the landing
sections/`AboutPage.vue` — follow these conventions when adding new UI rather than reintroducing the gaps they
fixed:

- **Skip link**: `AppLayout.vue` renders a `.skip-link` (Bootstrap's `visually-hidden-focusable`, visible only
  on keyboard focus) targeting `<main id="main-content" tabindex="-1">`, so keyboard/screen-reader users can
  bypass the header/nav on every page.
- **No heading-level skips**: every section title is a real `<h2>`/`<h3>`, never a styled `<p>` — screen readers
  navigate by heading level, and a "looks like a title" paragraph is invisible to that navigation
  (`TechnologiesSection`/`QualitySection` eyebrows). `BaseCard` takes a
  `headingLevel` prop (`2 | 3`, default `3`) specifically so a card grid sitting directly under a page's `<h1>`
  (e.g. `AboutPage.vue`'s `site.cards`) can render `<h2>` instead of skipping straight to `<h3>`.
- **Text contrast**: use the `.text-eyebrow` class (`style.css`, `color: var(--bs-link-color)`) for actual text,
  never Bootstrap's `text-primary` — `--bs-primary` (`#7c3aed`) is only ~3.45:1 against the dark background,
  below the 4.5:1 AA threshold for text. `text-primary` stays fine for icons/decorative dots (non-text UI only
  needs 3:1, and they're `aria-hidden`).
- **Nav landmarks**: `AppHeader.vue` has two `<nav>` elements (desktop + mobile disclosure) — both need a
  distinct `aria-label` (`common.mainNavigation` / `common.mobileNavigation`) so they aren't ambiguous to
  assistive tech, and the active link gets `aria-current="page"` (not just a CSS class).
- **Static a11y linting is wired in** (`eslint-plugin-vuejs-accessibility`, `flat/recommended`, in
  `npm run lint` — blocking in CI). One rule is loosened: `label-has-for` defaults to requiring a label
  both wrapped around its field *and* carrying a `for`, stricter than WCAG, which accepts either; the
  `Base*.vue` components use `for`/`id`, so it's set to `some`.
  It only sees what's readable in the template — it says nothing about contrast or tab order, which need
  a real render. **The manual Tab-through, heading outline and contrast check remain necessary**; the
  linter complements them. Two caveats learned doing it: computing contrast against a translucent
  panel's own `background-color` produces phantom failures (composite the alpha down to the first
  opaque layer — `.surface-panel` sits on `rgb(16,15,25)`, not white), and a `Tab` keypress sent
  through browser automation leaves focus on `BODY`, which makes a working skip link look broken.
- **axe-core audits the rendered DOM** (issue #12), via `tests/support/axe.ts` →
  `expectNoAccessibilityViolation(wrapper)`. It complements the linter rather than replacing it: the
  linter reads the template, axe inspects what exists once rendered — heading skips, duplicate ids
  from a loop, wrong `th`/`td` scope, badly nested ARIA. Applied to `Stack`, `Incidents`,
  `Contributions` and `About`; add it to a new page's spec as one more `it`.
  The helper re-attaches the wrapper to `document.body` for the run, because `@vue/test-utils` mounts
  detached and axe then answers *"No elements found for include in page Context"* — which reads like
  "no violations". That trap is handled once, in the helper.
- **What that audit will never see, and it matters.** jsdom does no layout and no colour computation,
  so `color-contrast` **silently disables itself** — a green test says nothing about contrast. The
  helper therefore disables it explicitly (naming what you don't check beats letting it look checked)
  and asserts that the rules which *should* run actually did, so a future axe release cannot quietly
  turn one off and leave the suite green for the wrong reason. Contrast, focus visibility, tab order
  and the relevance of alt text still need a real browser and a human — automated tooling covers
  roughly a third of WCAG.

Tests live under `tests/`, mirroring the `src/` tree rather than being colocated (e.g.
`src/presentation/layout/AppHeader.vue` is tested by `tests/presentation/layout/AppHeader.spec.ts`, the same
pattern the backend already uses for `tests/<BoundedContext>/`). Vitest picks them up via the
`tests/**/*.spec.ts` glob in `vite.config.ts`; `tsconfig.app.json`'s `include` also lists `tests/**/*.ts` so
`vue-tsc -b` (the `build` script) still type-checks them. Specs import the module under test with a relative
path back into `src/` (e.g. `../../../src/presentation/layout/AppHeader.vue`) — there is no path alias.
Components are mounted with `@vue/test-utils` and a stub/real repository injected via the same `InjectionKey`
as `main.ts`. Any component using `usePortfolioContent()`, `useI18n()`, or `useRoute()`/`RouterLink` needs the
corresponding plugin(s) in `global.plugins` when mounted in a test (`createAppI18n()` from `presentation/i18n`,
and a locally-built `createRouter(...)` — never the app's own `router`/`i18n` singletons, to keep tests
isolated from each other).

To add a test for a new file: create it at the mirrored path under `tests/`, not next to the source file.

#### Lint

`npm run lint` (`eslint .`, flat config in `eslint.config.js`): `eslint-plugin-vue` (`flat/recommended`) +
`@vue/eslint-config-typescript` (non type-checked — type errors are already caught by `vue-tsc -b` in the
`build` script, ESLint here is style/correctness only) + `eslint-plugin-vuejs-accessibility`
(`flat/recommended`, see the a11y section above) + `@intlify/eslint-plugin-vue-i18n` (`flat/recommended`,
`settings['vue-i18n'].localeDir` points at `infrastructure/i18n/locales/*.json`) — this last one is why UI-chrome
strings and portfolio content are kept in separate files (see i18n above): mixing them in would make
`no-raw-text`/key-usage checks meaningless. `no-raw-text`'s `ignorePattern` is configured to skip strings with
no letters at all, for purely decorative glyphs (the header logo's `</>`, the "et aussi" middle dot). `npm run
lint:fix` for the auto-fixable (mostly formatting) rules.

### Frontend build/deploy

`../docker/node/Dockerfile` mirrors the same idea: in dev the plain `node` image runs `npm install && npm run dev`
directly (no image build). For deployable images, the API URL is a **runtime** setting, not a build-time one:
`docker-entrypoint.sh` runs `envsubst` on `config.template.js` using the container's `API_URL` env var, producing
`/usr/share/nginx/html/config.js` (served no-cache, loaded by `index.html` before the app bundle) that the app reads
via `window.__APP_CONFIG__` (`frontend/src/infrastructure/config/getApiUrl.ts`, falling back to Vite's
`import.meta.env.VITE_API_URL` for `npm run dev`, which never serves `config.js`). This means a single frontend
image — like the backend — is built once and promoted from preprod to prod unchanged, only the `API_URL` env var
differs per environment; `make build-front-prod`/`build-front-preprod` no longer take an `API_URL` argument.

**The version shown in the footer is the opposite case, and deliberately so**: it is a property of the *image*,
not of the environment, so it is fixed at **build** time — `make build-front-*` passes `--build-arg
APP_VERSION=$(TAG)` (the pipeline's `<version>-<sha>`), `docker/node/Dockerfile` exports it as
`VITE_APP_VERSION`, Vite inlines it, and `infrastructure/config/getAppVersion.ts` (the mirror of `getApiUrl.ts`)
parses it into `{version, build, releaseUrl}`. `AppFooter.vue` renders `v0.17.0` as a link to the GitHub release
(build sha in the `title`); a tag that isn't a release (a local build on a bare sha) shows as plain text, and an
empty value (`npm run dev`) shows nothing. Don't move it to `config.js`: a promoted image *must* announce the same
version in preprod and prod, which is exactly what build-time gives for free.

**Share cards** (`frontend/scripts/og/`): one HTML template rendered by system Chrome + ImageMagick
(`npm run og:generate`, no Playwright — see the script's header) into **two variants** of the same design
that differ only by headline and size: `frontend/public/og.png` (1200×630, the site's `og:image`,
headline = real name, the owner's deliberate choice for the site) and `.github/social-preview.png`
(1280×640, headline = the `ghostotof` pseudonym, because the repo is pseudonymous end to end: URL,
LICENSE, README). Both are committed and both are regenerated by `test-frontend` (script control on
every push) and `build-images` (before the frontend image is built); the GitHub one is also uploaded
as the `social-preview` artifact of each release. **GitHub has no API for the social preview**: the
file is uploaded by hand in Settings → General → Social preview, so a change to the template means
re-uploading it — the artifact exists to make that one download away. Never put the real name in the
GitHub variant, and never serve it from the site (it lives under `.github/`, not `frontend/public/`).

### Deployment invariants (learned the hard way — don't undo these)

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
  timeout), then `kubectl apply -k .` + `rollout status`. **Fail-closed**: a failed Job exits before the
  `apply`, so the previous release keeps serving on the old schema, which is exactly the safe state
  (PostgreSQL DDL is transactional and Doctrine wraps each migration in its own transaction) — fix the
  migration, cut a new tag. The discipline that makes this order safe, to respect in every migration:
  a column added `NOT NULL` has a default or is filled by the migration itself
  (`Version20260914170000` is the model); **never drop or rename a column in the release that stops
  reading it**, only in the next one; a Messenger message in flight at deploy time must stay readable
  by both versions (v0.11.0's `SendAccountInvitationMessage.userId` note). Only migrations the old code
  cannot survive fall outside this order — see the maintenance window below.
- **`DEPLOY_MAINTENANCE_WINDOW` (repository variable) opts a deploy into a maintenance window** — added
  for v0.11.0's irreversible integer→UUID primary-key migrations, where the new code cannot read the old
  schema **and vice versa**, so no pod may serve a request while the migration runs. That is the only
  case that needs it since #175; an additive migration doesn't. When it equals `true`,
  `deploy-preprod`/`deploy-prod` in `pipeline.yml` patch `backend` and `worker` to `replicas: 0` (a
  `kubectl patch` on `spec.replicas` — the deployer `Role` has no `deployments/scale` subresource, so
  never `kubectl scale`), wait for their pods to disappear, run `migrate-job.yaml` against the quiet
  database, then let `kubectl apply -k .` restore the manifests' replica counts and the existing
  `rollout status` wait for the new pods. The frontend keeps serving; only the API returns 503 through
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
  success while running something else, same family as the stale-image incident below.
  `backend-config` is the **opposite** and must stay `disableNameSuffixHash: true`: it is referenced
  by literal name from `migrate-job`, `seed-job` and both CronJobs, all deliberately outside
  kustomize, which therefore cannot rewrite their references — a hashed name breaks them with
  `CreateContainerConfigError` (incident v0.6.0). The rule that decides: **hash it if kustomize owns
  every reference to it, don't if anything outside kustomize names it.** Verify a config change
  actually landed with `kubectl exec … -c nginx -- nginx -T | grep <the new directive>`.
- **Five nginx rate-limit zones, two different jobs.** `contact` (10 r/m), `pwsetup` (20 r/m),
  `baseaccess` (20 r/m, issue #77 — each call signs an RS256 JWT) and `login` (10 r/m, burst 10,
  ADR 0005 — the backstop under Symfony's `login_throttling`, which is the real ceiling) protect a
  *side effect* — sending mail, guessing a token, minting a token, guessing a password.
  `publicapi` (600 r/m, burst 200, on
  `location /`) protects the *resource*: without it every public read reaches PHP and Postgres as
  often as asked. Its ceiling is deliberately far above real use — behind a mobile carrier's CGNAT
  thousands of visitors share one address, and a tight cap would cut them all off at once, which is
  the very DoS audit C7 was about. `/healthz` uses an exact-match `location =`, so kubelet probes are
  never capped.
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
  now also `rollout status` **`postgres`, `rabbitmq` and `worker`** after `apply -k`, not just
  `backend`/`frontend`: without the wait the seed Job ran against a database that hadn't come back, and in
  prod a downed broker left the deploy green. Accept the corollary: such a change is a short, frank outage
  of those two stateful workloads.
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
    intended clean stop (green, the summary asks for a `main` → `develop` PR). Its pushes use the
    **`release-bot` deploy key** (secret `RELEASE_DEPLOY_KEY`, host key pinned from `gh api
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
  - **What to do by hand, in order**: `git switch develop && git pull && git fetch --tags`;
    `git switch -c release/$(tools/next-version.sh)`; write `RELEASE_NOTES.md`; open the PR to
    `main` as a draft; iterate until the run is green (a `fix/*` PR targets the release branch);
    mark ready, merge. Nothing else — no `git tag`, no approval click, no `develop` sync unless
    the summary asks for it. Everything is testable offline: `tools/tests/*.test.sh` (run by the
    `tools-tests` job on temporary git repositories, shellcheck at `warning`+, actionlint pinned).
- **Postgres/RabbitMQ carry state on a PVC** — a `kubectl apply --dry-run=server` proves nothing about runtime
  behaviour on an already-initialised volume. Release v0.5.0 put RabbitMQ in `CrashLoopBackOff` in production
  (~15 min of `POST /api/contact` returning 500) by adding `runAsNonRoot`/`fsGroup`: Erlang refuses to start
  when its `.erlang.cookie` is group-accessible, and the offending file survived the manifest rollback. Any
  change to those two workloads needs a real preprod rollout with `rollout status` + logs before promotion.
  `seccompProfile: RuntimeDefault` (audit I6) is a syscall filter and touches neither uid nor file modes, but
  the rule stands.

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
- `docs/adr/0004-assistance-ia.md` — **statut `accepté` (2026-09-14), phase 1 livrée** (spec 0002, issues
  `spec-0002` closed, v0.10.0/v0.10.1 in production the same day; the case-studies admin page, #104, joined
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