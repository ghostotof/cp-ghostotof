---
paths:
  - "frontend/**"
---

# Frontend — architecture, API-backed content, i18n, SEO, lint

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Frontend architecture ». The index of every rule is at the top of `.claude/CLAUDE.md`.

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

The assistant follows the same layered shape without being backoffice content: its slice (spec 0005,
`ROLE_TRUSTED`/`ROLE_SUPER`) is the streaming variant, `domain/assistant` →
`infrastructure/assistant/HttpAssistantRepository.ts` (reads the SSE stream with `fetch` + `ReadableStream`,
incremental parser `serverEvents.ts`) → `application/assistant/useAssistant.ts` (state machine
`idle|streaming|error`; the sliding window `buildConversationWindow` copies the backend's D6 bounds and counts
in code points, never `.length`; a 401/403 calls `markSessionExpired()`) →
`presentation/pages/AssistantPage.vue`.

The MCP connection slice follows it too (#650): `domain/mcp` → `infrastructure/mcp/HttpMcpConnectionsRepository.ts`
→ `application/mcp/useMcpConnections.ts` → `presentation/pages/McpConnectionsPage.vue` (route `mcp-connections`,
`ROLE_TRUSTED`/`ROLE_SUPER`, `noindex`; the list re-read from the server after a revocation, no state recomputed).
On desktop the trusted tier's header actions sit in `presentation/layout/AccountMenu.vue`, a WAI-ARIA **disclosure**
(not `role="menu"`); **the base tier keeps its actions flat on desktop** (the author's decision of 2026-10-28).
Details: `.claude/rules/mcp-oauth-revocation-personne.md`.

Three things the MCP slice shares with the rest of the frontend:

- **A refused session reaches the person in words** (#650, R5a). `useMcpConnections` exposes `sessionExpired` (the
  server answered 401/403 on the last read or revocation, besides `markSessionExpired()`); the page then shows « Votre
  session a pris fin » and a **sign-in-again link** (`login?redirect=<this page>&expired=1`) in place of the generic
  error and its retry, which would send the same request without a session. `LoginPage` reads `expired=1`
  (`presentation/router/sessionEnded.ts`, the single value `1`) and shows a `role="status"` notice « Votre session a
  pris fin, reconnectez-vous. » — the same flag the backoffice banner's link sets (`SessionEndedBanner`, which
  replaced R1c's redirect: a 401 mid-page no longer navigates, `.claude/rules/frontend-backoffice.md`). The flag
  opens nothing and grants nothing.
- **`redirect` is judged in one place, `presentation/router/internalRedirect.ts`** (`internalRedirectTarget()`, #650,
  F-I1 of the second review; `LoginPage` is its only reader). A target is an internal path when it starts with a
  **single** `/` and holds **no `\` and no control character** (C0, DEL; tab and line feeds included): a browser reads
  `\` as `/` in an `http(s)` URL and silently drops tabs and line feeds, so `/\host` and `/<TAB>/host` mean `//host`
  to it. The rule is a property of the string received, the same one handed to the router, so no reader can understand
  it differently. **Rejected: resolving the target with the URL parser and comparing its origin** — it judges the
  *normalised* form (`/a\b` comes out `/a/b`), not the string the router receives, depends on a parser that differs
  between browser and jsdom, and — measured — refuses nothing more once the syntactic rule holds. The target is
  returned as received (never normalised: the query and fragment of a consent request stay intact). **`%5C` is not
  refused**: a browser does not decode it in a path, it stays on the origin. `internalRedirect.spec.ts`,
  `LoginPage.spec.ts`.
- **`load()` keeps the last read launched** : a sequence number, and a response (or an error) older than the latest
  `load()` is dropped — « Réessayer » during a re-read after a revocation, or two revocations in a row, cross on the
  network.
- **The list's contract** (`McpConnectionList{connections, terminatedTruncated}`, strict parsing of the boolean):
  `codeExchanged` says whether the code was exchanged — the labels « Jamais aboutie » (`false`: an abandoned approval)
  and « Pas encore renouvelée » (`true`: tokens issued, `lastUsedAt` is written at the refresh only); `expiresAt` is
  `null` **exactly** when `state === 'revoked'` (the repository refuses any other pairing) and the cell reads
  « Sans échéance » to a screen reader; a note under the table says the history was cut, with no number
  (`data-testid="terminated-truncated"`, an ordinary paragraph). Shared test fixture:
  `frontend/tests/support/mcpConnectionList.ts`.

`HttpMcpConnectionsRepository` and `HttpOAuthConsentRepository` have their **own `fetch`**, not
`BackofficeHttpClient`: the 401 reaction is the composable's, with no redirect. **The two apps agree on a contract**:
the consent decision carries `accountId` (the `account.id` the summary showed). A frontend and a backend that differ
there fail: a new frontend before its backend cannot parse a summary without `account.id`, an old frontend page left
open after the new backend sends no `accountId` and gets a 422 (the page is terminal, a reload fixes it). **The
backend is deployed first**: its summary only adds a field, which an old frontend ignores.

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

#### Lint

`npm run lint` (`eslint .`, flat config in `eslint.config.js`): `eslint-plugin-vue` (`flat/recommended`) +
`typescript-eslint` `recommended` extended to `.vue`, with `vue-eslint-parser` delegating `<script lang="ts">`
to the TypeScript parser (non type-checked — type errors are already caught by `vue-tsc -b` in the
`build` script, ESLint here is style/correctness only) + `eslint-plugin-vuejs-accessibility`
(`flat/recommended`, see `.claude/rules/frontend-a11y.md`) + `@intlify/eslint-plugin-vue-i18n` (`flat/recommended`,
`settings['vue-i18n'].localeDir` points at `infrastructure/i18n/locales/*.json`) — this last one is why UI-chrome
strings and portfolio content are kept in separate files (see i18n above): mixing them in would make
`no-raw-text`/key-usage checks meaningless. `no-raw-text`'s `ignorePattern` is configured to skip strings with
no letters at all, for purely decorative glyphs (the header logo's `</>`, the "et aussi" middle dot). `npm run
lint:fix` for the auto-fixable (mostly formatting) rules.

**The TypeScript-in-SFC wiring is written by hand, not `@vue/eslint-config-typescript`** (issue #328,
2026-10-03). That wrapper pulled `fast-glob` → `micromatch` → `braces`, and `braces` ≤ 3.0.3 got a *high*
advisory with no patched release (GHSA-vfj7-8cjw-p6xm): `npm audit --audit-level=high` turned every branch
red. It only used `fast-glob` to list the `.vue` files to type-check, which this project never does, so
`eslint.config.js` now builds the same three blocks itself (see its docblock). The replacement was proven
by `eslint --print-config` on a `.ts`, a `.vue`, a spec and two config files — byte-identical before and
after — plus a clean lint of all 365 files; repeat that comparison if the block is ever touched.
Revisit it only on a real trigger: adopting type-aware rules (`recommendedTypeChecked` needs the
`projectService` and a per-file split of `.vue` files with and without TypeScript, which is what the
wrapper automated), or a wrapper release that drops `fast-glob`. **The general rule this set**: when a
blocking advisory in *dev tooling* has no fix, first look for a way to take the vulnerable package out of
the tree; if there is none, wait for the fix. Never `--omit=dev` (it would hide the next advisory in the
build chain, which can be a supply-chain hole) and never a silent audit exception.

### Base access (ADR 0003 D6)

> Moved from `.claude/CLAUDE.md`'s Goal #9 (#602). The backend side is in `.claude/rules/security-authentication.md`.

The header's « Accès instantané » calls `POST /api/account/base-access`; « Terminer cet accès » (issue #65)
calls `POST /api/logout`. The tier comes from the status of `GET /api/me` (401 / 403 / 200). The httpOnly
cookie cannot be read, so the response's `expiresAt` is the only way to know when it dies: `useAuth` arms a
timer on it, remembered in `localStorage` to survive a reload — a past value is ignored, the server is the
truth —, and any 401 on base-tier content calls `markBaseAccessExpired()`, so the header never shows
« Accès de base » above a page asking for it.

### Toolchain history (Node, jsdom, Vitest)

> Moved verbatim from `.claude/CLAUDE.md` (#602), section « Versions ». « The `make front-*` targets above »
> are those of `.claude/CLAUDE.md`, « Frontend day-to-day ».

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
