---
paths:
  - "frontend/src/presentation/**"
  - "frontend/tests/**"
---

# Frontend — accessibility

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Frontend architecture › Accessibility ». The index of every rule is at the top of `.claude/CLAUDE.md`.

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

