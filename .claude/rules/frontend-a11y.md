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
  needs 3:1, and they're `aria-hidden`). The same goes for the two other Bootstrap colours that miss AA on the
  dark background (#748):
  - **Red text is `text-danger-emphasis` (`#ea868f`, ~7.8:1), never `text-danger`** (`#dc3545`, 4.34:1).
    `btn-outline-danger` stays, but `style.css` overrides its four colour variables (text, border, and their
    disabled twins) to `var(--bs-danger-text-emphasis)` (~6.1:1); hover keeps Bootstrap's solid red, white on
    `#dc3545` (4.53:1). Not a solid `btn-danger` (4.53:1 only, and a red block per row).
  - **Grey text is `text-body-secondary` (`#94a3b8`, ~7.7:1) or, for a footer link, `.link-muted`
    (`style.css`; same colour, underline driven by the `link-underline-opacity-*` utilities), never
    `text-secondary`/`link-secondary`** (`#6c757d`, 4.19:1).
  - **`--bs-danger-rgb` and `--bs-secondary-rgb` are deliberately not redefined**: they also colour
    `text-bg-danger` / `text-bg-secondary` (white text on a red or grey fill), which would drop under 4.5:1
    (`text-bg-secondary` is already ~2.6:1 and is not a text colour in this project). Nor is `.text-danger`
    redefined: a utility class that no longer does what its name says.
  - jsdom computes no contrast, so these are pinned by source guards: `tests/presentation/colorClasses.spec.ts`
    (no `text-danger`, `text-secondary` or `link-secondary` in any `src/**/*.{vue,ts}`) and
    `tests/presentation/styleSheet.spec.ts` (the `style.css` blocks; it reads the file with `node:fs`, because
    Vitest blanks any CSS import, `?raw` included). The measure in a real browser stays to do.
- **Nav landmarks**: `AppHeader.vue` has two `<nav>` elements (desktop + mobile disclosure) — both need a
  distinct `aria-label` (`common.mainNavigation` / `common.mobileNavigation`) so they aren't ambiguous to
  assistive tech, and the active link gets `aria-current="page"` (not just a CSS class).
- **The header at 320 px** (WCAG 1.4.10, #748): `AppHeader.vue` gives way on the **brand**, never on the actions. The
  brand link has `min-width: 0` and its label `text-truncate` (an ellipsis; the link's accessible name stays the whole
  label), the logo glyph and the right-hand group (`div.flex-shrink-0`: language switch, « Connexion » or the account
  menu, « Accès instantané ») never shrink. The ticket measured that group alone at 331 px at the trusted tier, 389 px
  anonymous and in English, for 301 px of usable width: truncating the brand does not absorb that, so **the group's
  width at each tier, in French and English, is measured in a real browser at 320 px** (`scrollWidth` of the document
  must equal 320) — jsdom does no layout; `AppHeader.spec.ts` (« en-tête à 320 px ») pins only the classes.
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
- **A disclosure's `aria-controls` must name an element that exists** (#650, R5a): `AccountMenu.vue` hides its list
  with `v-show`, not `v-if`, so the button's `aria-controls` is valid while the menu is closed; the hidden list is
  `display: none`, neither read nor reachable by keyboard. Its tests therefore read « nothing outside the menu »
  (`outsideDesktopMenu` in `AppHeader.spec.ts`), never « nothing in the DOM ».
- **A live region is announced when it is *inserted*, or when its content changes** (#650, S2): the backoffice's
  `SessionEndedBanner` is a `role="alert"` **inserted by `v-if`** at the moment a 401, or a 403 to a write sent without a CSRF token, ends the session, so it is
  announced and interrupts, which is what follows a failed « Enregistrer ». **It never takes the focus** (no
  `focus()`): the person may be in the middle of a sentence, and the link sits in the reading order, reachable by
  keyboard. Its link is `presentation/ui/SignInAgainLink.vue` (#748, R6): it opens a new tab, said in the link text (a
  visually hidden `common.opensInNewTab`, « (s'ouvre dans un nouvel onglet) », WCAG 3.2.5) and shown by an
  `external-link` icon, `aria-hidden` (#748: the text is hidden, sighted people had nothing); the same component serves
  the header, « Mes connexions MCP » and the assistant, and `.claude/rules/frontend-session.md` gives the rest. **A region that announces a return exists before its message** (#748): the banner's own
  `role="status"` is in the DOM from the mount, empty and `visually-hidden`, and only its content changes when the
  session comes back (« Session rétablie »), because a live region inserted already full may not be announced. The
  banner is sticky under the header, `scroll-padding-top` keeping the focus out from under both (WCAG 2.4.11,
  `.claude/rules/frontend-backoffice.md`). It differs from `LoginPage`'s notice (next bullet) by when it appears: the banner is inserted after
  the first render (announced), the notice is present from it (may not be). **An alert already shown is announced again
  only if its content is replaced** (#748, R9, S7, R19, E): a second refusal while the banner is displayed changes nothing in an
  idempotent state, so the message `<span>` carries a `:key` (`useAuth().sessionEndedCount`, +1 per accepted refusal, see `.claude/rules/frontend-session.md`, « The session generation ») that makes
  Vue replace it. **Never the element that carries a link**: the alert stays, so the « Se reconnecter » link a keyboard
  user has focused is not destroyed (the focus would fall on `body`).
- **A `role="status"` present at first render may not be announced** (`LoginPage`'s « Votre session a pris fin »
  notice, `expired=1`, #650): some screen readers announce a live region only when its content *changes*, and this
  one is in the page from the start. It is still readable as ordinary text in the reading order, which is why it is
  kept a status (nothing is broken, no `role="alert"`); announcement on arrival is part of the browser check still
  to do, with the task 10 one (`.claude/rules/mcp-oauth-revocation-personne.md`).
- **A gesture that moves the focus and announces an outcome: focus first, announce after a short delay** (#748,
  `presentation/ui/useFocusThenAnnounce.ts`, `ANNOUNCE_AFTER_FOCUS_MS` = 250 ms): **the pattern has one implementation,
  `useFocusThenAnnounce`** (`announced`, `focusThenAnnounce(target, value)`, `cancel()`, `isUnmounted()`); a page that
  needs it calls it and holds no timer of its own (`McpConnectionsPage.vue` is the first). The `role="status"` region is written only once the
  focus has moved, because an announcement written at the moment of the move can be cut off by the reading of the
  element that takes the focus. Not `aria-describedby` from the title to the message: whether a description is read
  at focus depends on the screen reader's settings (VoiceOver reads it after a delay, NVDA only if « announce
  descriptions » is on), and it would stay stuck to the title at every later focus. **Never `aria-busy` on an
  ancestor of a live region** (it silences it): the busy marker goes on what is loading (the table's container). The
  pending timer is cancelled by the next gesture and on unmount (nothing is written to a component that is gone).
  **A gesture that restarts a read clears the outcome already announced** (CR10: « Réessayer » after a revocation
  announced « révoquée » and whose re-read then failed): a new read says nothing of the earlier revocation, so the
  page calls `cancel()` first.
  A button that disappears under the focus (the « Réessayer » replaced by the loading state) hands the focus back
  explicitly: to the title on success, to the new button on a new failure. Not checked with a real screen reader
  (NVDA, VoiceOver): the delay is a reasoned value, to listen to in the browser check.

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
isolated from each other). `enableAutoUnmount(afterEach)` is called **once**, by `tests/support/setup.ts` (the
`setupFiles` of `vite.config.ts`, #748 R25): a spec never calls it again (VTU throws on a second call), and every
mounted component is unmounted at the end of its test.

To add a test for a new file: create it at the mirrored path under `tests/`, not next to the source file.

