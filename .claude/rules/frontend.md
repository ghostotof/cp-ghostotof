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
(`flat/recommended`, see the a11y section above) + `@intlify/eslint-plugin-vue-i18n` (`flat/recommended`,
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

