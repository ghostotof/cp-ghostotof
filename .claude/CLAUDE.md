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
  slot must too) and an `error_page 429` that answers problem+json `/errors/rate-limited` — Symfony's
  own 429s are not intercepted.
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