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
     (`BaseAccessController`, per-IP rate-limited, CSRF-excluded like `/api/contact`) issues a 15-minute
     JWT carrying exactly `ROLE_USER`, with **no account materialised in the DB**. The frontend's
     "Accès instantané" CTA calls it; "Terminer cet accès" (issue #65) ends it early through the
     unchanged `POST /api/logout`, which expires the cookie whoever holds it. The tier is read from the
     HTTP status of `GET /api/me` (401 anonymous / 403 base / 200 trusted) — no dedicated endpoint.
   - **Content that tier carries** (ADR 0003 D5): two contexts, both built — `Portfolio/CaseStudy`
     (`GET /api/case-studies/{locale}`) and `Portfolio/AnonymousCv` (`GET /api/anonymous-cv/{locale}`,
     skills, seniority and **achievements** per domain, no name/employer/client; the path deliberately
     avoids the `^/api/cv` prefix, which is `ROLE_TRUSTED`). The third content the ADR listed, an
     anonymised career path, was **dropped on 2026-09-13** (D5 amended): the time sequence is the most
     re-identifying element, and the chronology is precisely what the nominative tier adds — don't
     reintroduce it as "just durations and sectors". A real account never granted `ROLE_TRUSTED` (e.g.
     the dev-only `demo` account) still lands on that same base tier.
10. The modifications must follow the git flow planned for this project on GitHub (main branch "main", next release "develop", new feature "feature", etc...)
11. The resulting can be shown during an interview.
12. The resulting must be fully multilingual (French, English)

## Project state

This repository started as a freshly generated project skeleton (single "Init" commit). Real backend code now
exists — the `Security` bounded context (`User` + `Authentication`) and six `Portfolio` bounded contexts
(`Experience`, `Quality`, `About`, `Contribution`, `Incident`, `Watch`, see Backend architecture below) — and follows a DDD structure under
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
`App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables` trait (`uriVariableInt`/`uriVariableString`) — reuse
it in new Providers/Processors rather than casting `mixed`. Functional tests build request bodies via
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
alongside PHPStan; don't reach for Psalm annotations or raise its level. The
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
  to prod; only adds Xdebug in profiling-trigger mode (`XDEBUG_TRIGGER`, never `debug` mode) and verbose logs.
  Pipeline order is dev → preprod → prod regardless of declaration order in the Dockerfile.

### Backend architecture (`../backend/src`)

DDD structure: each bounded context is a top-level folder under `src/`, itself split into `Domain/`,
`Application/`, `Infrastructure/`, `Presentation/` layers — only the layers a context actually needs, no empty
ceremonial folders. `config/packages/doctrine.yaml`'s mapping scans all of `src/` (not a single `Entity/`
folder), so entities live inside their bounded context instead of a shared top-level directory.

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
    (`setSuperAdmin`, idempotent, anti-lockout guards) · `PasswordSetupRateLimiterInterface` (calqued on the
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
    that consumes the per-IP quota for `GET`/`POST` on `/api/account/password-setup/` **before** API Platform
    deserializes or validates anything; consuming it again in the Provider/Processor would halve the effective
    quota, so don't) + `Http/PasswordSetupRateLimitRetryAfterListener.php` (adds `Retry-After` on the 429);
    the API Platform providers/processors.
  - `Presentation/Command/CreateCpgUserCommand.php` (`app:user:create`, `--role` allow-list) and
    `Presentation/Controller/CurrentUserController.php` (`GET /api/me`). Everything else is API Platform
    resources — see "Backoffice" below for the `ROLE_SUPER` ones, plus the **public** (no auth, no CSRF,
    IP rate-limited) `AccountPasswordSetupStatusResource` (`GET /api/account/password-setup/{token}`) and
    `AccountPasswordSetupResource` (`POST …`, 204). `^/api/account/password-setup/` is a `EXCLUDED_PATH_PREFIXES`
    entry in `CsrfCookieRequestSubscriber` (anonymous caller, nothing to double-submit — same rationale as
    `/api/contact`).
- **`Security/Authentication/`** — login/logout/JWT/CSRF mechanics, deliberately kept out of `User/`: this is
  infrastructure wiring around Symfony Security + LexikJWTAuthenticationBundle, not a domain concept of its own.
  - `Infrastructure/Jwt/LoginSuccessSubscriber.php` (attaches the `XSRF-TOKEN` cookie, shapes the
    `login_check` response body — includes `roles` alongside `username`, needed by the frontend to gate `/admin`
    without waiting for a full `checkAuth()` round-trip) and `CookieLogoutListener.php` (expires both cookies on
    `/api/logout`).
  - `Infrastructure/Http/CsrfCookieRequestSubscriber.php` — double-submit-cookie CSRF check, a `kernel.request`
    listener at priority 20 (must run *above* the Security firewall's priority 8 — see the class docblock).
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

### Backoffice (`ROLE_SUPER`)

Content management for all of the above, plus user administration, gated end-to-end behind `ROLE_SUPER`
(the default role every account also has is `ROLE_USER`, cf. `CpgUser::getRoles()` — never sufficient here):

- **Authorization**: a single `access_control` entry in `config/packages/security.yaml`,
  `{ path: ^/api/backoffice, roles: ROLE_SUPER }`, which **must stay the first entry in the list** — Symfony
  applies only the first matching rule, so a later/looser rule (e.g. `^/api/me`) would never get a chance to
  override it, but a rule placed *before* it could accidentally widen backoffice access.
- **Non-negotiable rule for every new endpoint — "never send what the caller isn't entitled to"**: the API must
  never return protected data to an unauthenticated or unauthorized caller, *even when the frontend does not
  display it*. Hiding a field client-side is presentation, never protection — anyone can call the endpoint
  directly. Concretely: a new route is protected by default; making it public is a deliberate act.
  `tests/Security/ApiRouteExposureTest.php` enforces this automatically — it walks the router and asserts that
  **every** `/api` route outside its `PUBLIC_PATHS` allow-list answers 401/403 to an anonymous caller, that no
  `/api/backoffice*` path can ever be allow-listed, and that the whole backoffice answers 403 to an
  authenticated account lacking `ROLE_SUPER` (authenticated ≠ authorized). Adding a public endpoint therefore
  means adding an entry to `PUBLIC_PATHS` **with a written justification**; if you can't justify it, it isn't
  public. Never weaken or delete that test to make a new route pass.
- **API Platform pattern**, repeated identically across every backoffice resource
  (`BackofficeExperienceTechnologyResource`, `BackofficeQuality{Principle,Trait}Resource`,
  `BackofficeContributionResource`, `BackofficeIncidentResource`, `BackofficeWatchedProductResource`,
  `Backoffice{About}{Settings,SiteCard,MeCard}Resource`, `BackofficeUserResource`,
  `BackofficeUserPasswordResource`): a flat DTO (never the Doctrine entity itself) under
  `Presentation/ApiResource/`, backed by a `Provider` (`GetCollection`/`Get`) and a `Processor`
  (`Post`/`Put`/`Delete`) under `Infrastructure/ApiPlatform/`. Collection reads use a `?locale=` query filter
  (unlike the public `{locale}` path param — collections aren't per-locale routes). **`Put`/`Delete` operations
  need an explicit `provider:` set, not just `processor:`** — otherwise API Platform's default provider tries to
  resolve the DTO via Doctrine directly and 404s before ever reaching the processor.
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
  `/api/backoffice_users/{id}` — a second, undocumented path to the same data, which only stayed protected by
  the accident that `^/api/backoffice` (no trailing slash) matches `backoffice_users` by prefix. Declaring it
  removes that route. Plus dedicated one-operation
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
  `use App\Security\User\Domain\Exception\HasProblemType` (declare `problemType()` → a stable kebab slug +
  `problemStatus()`): API Platform then emits `type: /errors/<slug>` in the problem+json, which the client keys
  on instead of substring-matching the localized `detail`.

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
Existing resources: `technologies`, `quality` (principles + traits), `contributions`, `incidents`, `about` (settings + site cards +
me cards), `watch` (tracked products + the `ROLE_SUPER`-only vulnerability detail, read-only), `users` (list + **invite by email** + change-password + promote/demote + resend invitation + delete;
direct username+password creation stays CLI-only). `AdminUsersPage.vue` disables the delete and role buttons on
the current user's own row (compared by `username` via `useAuth()`); the `email` column shows the linked address
or a dash. The `domain/account` + `application/account/useAccountPasswordSetup` + `presentation/pages/SetPasswordPage.vue`
slice is the **public** counterpart: route `/(fr|en)/set-password/:token` (`meta.noindex`, no `requiresAuth`),
`useAccountPasswordSetup` state machine (`checking|ready|submitting|done|invalid|expired|error`), talks to the
public `/api/account/password-setup/{token}` endpoints.

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

### Deployment invariants (learned the hard way — don't undo these)

- **Doctrine migrations run as a Job, not `kubectl exec`** (audit C8). `k8s/base/migrate-job.yaml` is
  deliberately **outside** `kustomization.yaml`'s `resources:` — so kustomize's image transformer never sees
  it, hence the `${BACKEND_IMAGE}` placeholder that `envsubst` fills at apply time (`image: backend` would
  resolve to `docker.io/library/backend`). The deployer `Role` no longer has `pods/exec: create`: that verb
  granted a shell in any pod of the namespace, i.e. every mounted secret and arbitrary code execution in
  production. If a console command must run at deploy time, declare another Job — never bring `pods/exec` back.
  The RBAC is a **manual bootstrap the pipeline never replays**: after changing it, re-run the loop in
  `k8s/README.md` §4 *before* the next deploy, or the job fails on `cannot create resource "jobs"`.
- **`watch-refresh-cronjob.yaml` *is* in `kustomization.yaml`'s `resources:`** — the opposite of
  `migrate-job.yaml` above, and deliberately: it wants kustomize's image transformer, since it must run the
  same image as the Deployment. It is also **the only object in the cluster that makes outbound calls to
  third parties**; the namespace's NetworkPolicies restrict ingress only, so nothing extra is needed today —
  but adding an egress policy would break this Job first.
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
- **Three nginx rate-limit zones, two different jobs.** `contact` (10 r/m) and `pwsetup` (20 r/m)
  protect a *side effect* — sending mail, guessing a token. `publicapi` (600 r/m, burst 200, on
  `location /`) protects the *resource*: without it every public read reaches PHP and Postgres as
  often as asked. Its ceiling is deliberately far above real use — behind a mobile carrier's CGNAT
  thousands of visitors share one address, and a tight cap would cut them all off at once, which is
  the very DoS audit C7 was about. `/healthz` uses an exact-match `location =`, so kubelet probes are
  never capped.
- **nginx rate limits need `real_ip`** (audit C7). `limit_req_zone` keys on `$binary_remote_addr`, and behind
  the ingress the sidecar's TCP peer is the ingress-nginx pod — without the `set_real_ip_from` block, the whole
  internet shares one counter, which is a self-inflicted DoS. The trusted ranges mirror Symfony's
  `trusted_proxies: private_ranges`. `docker/nginx/default.conf` and `k8s/base/backend-nginx.conf` are
  mirrors of each other: change both.
- **The release notes live in the tag annotation.** `create-release` publishes the GitHub Release
  automatically once `deploy-prod` succeeds — never earlier: a release announces that a version
  *runs*, not that it compiled (v0.7.0 took four pipeline runs to reach production). The body is the
  annotated tag's message minus its first line, which becomes the title; a tag with only a subject
  falls back to GitHub's generated notes. So write the real notes into `git tag -a`, not afterwards
  into the GitHub UI — otherwise the automation publishes a thin release. **Tag with
  `--cleanup=verbatim`**: git's default cleanup for tag messages strips every line starting with
  `#`, so Markdown headings silently vanish between the file and the tag. v0.7.1 lost all three of
  its section headings that way, and the release had to be edited afterwards to restore them.

  ```bash
  git tag -a vX.Y.Z --cleanup=verbatim -F notes.md
  ```
- **A reused Git tag serves a stale image.** Pods default to `imagePullPolicy: IfNotPresent`, and a
  Git tag is mutable: re-cutting `vX.Y.Z` after a failed release makes the node reuse the image it
  already cached under that name. Release v0.7.0 spent two pipeline runs on this — a migration fix
  looked ineffective because the migrate Job kept running the *previous* build while reporting the
  right tag (registry digest and `imageID` on the pod differed). `k8s/base/migrate-job.yaml` now
  pins `imagePullPolicy: Always`, which is the right trade for a Job that must execute exactly the
  release's code. For anything else, prefer cutting a fresh tag over re-cutting one.
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
make back-quality      # phpstan + rector + psalm, in the container
```

`make init` and `make front-init` (re)run the Symfony/Vite project scaffolding — both are already applied in
this repo; `init-symfony` is idempotent (no-ops if `../backend/composer.json` exists) but `front-init` is
interactive and only meant to be run once.

Build/deploy images (no Compose, produce registry artifacts — default `TAG` is the short git SHA):

```bash
make build-prod                                              # backend prod image
make build-preprod                                            # backend preprod image (prod + Xdebug)
make build-front-prod API_URL=https://api.example.com TAG=1.2.3     # frontend prod image
make build-front-preprod API_URL=https://api-preprod.example.com TAG=1.2.3
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
which Dotenv overwrites them with the empty value from `.env`. Beware: `init-symfony.sh` returns early when
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
make back-quality   # phpstan + rector + psalm
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

### Domain docs

Single-context layout — root `CONTEXT.md` + `docs/adr/`. See `docs/agents/domain.md`.
ADRs:
- `docs/adr/0001-admin-user-provisioning.md` (invitation-by-email flow, `email` now stored, Twig for emails)
- `docs/adr/0002-veille-technique.md` (`Portfolio/Watch`: outbound calls out of the render path, snapshot in
  DB, public aggregate vs `ROLE_SUPER` detail, manifest built at `docker build`)
- `docs/adr/0003-paliers-d-acces.md` — **statut `accepté`, implémenté** (D1/D2/D4/D6/D7, D5 réduit à deux
  contenus par amendement du 2026-09-13; `tasks/plan.md` lists the housekeeping left). Makes `ROLE_USER` the bottom tier (one click, no credentials,
  discretion rather than secrecy) and puts the CV behind `ROLE_TRUSTED`. Read it before touching
  `access_control`, `CpgUser::getRoles()` or `BaseAccessController`: it turns on the fact that `getRoles()`
  grants `ROLE_USER` unconditionally, which is why a tier was added *above* rather than below.