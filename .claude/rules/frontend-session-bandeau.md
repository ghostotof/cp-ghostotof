---
paths:
  - "frontend/src/presentation/ui/admin/SessionEndedBanner.vue"
  - "frontend/src/presentation/ui/admin/AccessRefusedNotice.vue"
  - "frontend/src/presentation/ui/SignInAgainLink.vue"
  - "frontend/src/presentation/router/loginLocation.ts"
  - "frontend/src/presentation/router/sessionEnded.ts"
  - "frontend/src/presentation/router/reauthentication.ts"
  - "frontend/src/presentation/router/routeLocale.ts"
  - "frontend/src/presentation/layout/AppHeader.vue"
  - "frontend/src/presentation/layout/usePublishedHeight.ts"
  - "frontend/src/presentation/layout/AdminLayout.vue"
  - "frontend/tests/presentation/ui/admin/SessionEndedBanner.spec.ts"
  - "frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts"
  - "frontend/tests/presentation/ui/SignInAgainLink.spec.ts"
  - "frontend/tests/presentation/router/signInLocations.spec.ts"
  - "frontend/tests/presentation/router/protectedRoutesCarryNoSecret.spec.ts"
  - "frontend/tests/presentation/layout/usePublishedHeight.spec.ts"
  - "frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts"
  - "frontend/tests/presentation/layout/AppHeader.spec.ts"
---

# Frontend — the banner, the sign-in-again link and the header (reference)

> Split from `.claude/rules/frontend-session.md` (#748, St3-11; procedure of #605), which keeps the index of the parts. The index of every
> rule is at the top of `.claude/CLAUDE.md`.

## The interface

- **The banner**, `presentation/ui/admin/SessionEndedBanner.vue`, rendered **once** by `AdminLayout` above the
  `RouterView`, shows « Votre session a pris fin » while it holds: a `role="alert"` **inserted by `v-if`** (announced;
  see `.claude/rules/frontend-a11y.md`), **never taking the focus** (the person may be mid-sentence), **sticky under the
  header** (#748: on a long page it scrolled away and the person no longer knew why « Enregistrer » did nothing) —
  `position: sticky; top: var(--app-header-height)`, opaque background, `z-index` 1010 under the header's 1020 so its menus
  pass over it. The header's height is not a constant (open mobile menu, header alerts), so
  `presentation/layout/usePublishedHeight.ts` measures it live (`ResizeObserver`, `borderBoxSize` rounded up) and
  publishes it on `<html>` as `--app-header-height` (`AppHeader`), the banner's own as `--admin-notice-height`; both
  properties are removed when their element goes (`v-if`) and on unmount, and nothing happens without `ResizeObserver`
  (jsdom). `style.css`'s `html { scroll-padding-top }` adds the two, so a focused field never lands under the header or
  the banner (WCAG 2.4.11; it also fixes the sticky header hiding focus site-wide). Rejected: teleporting the banner into
  the header (it would enter the `banner` landmark, outside `AdminLayout`, and `Teleport` needs a mounted target) and a
  hard-coded header height.
  **The banner has two roots** (#748, R9, CR1/D1): the alert (`v-if`) and the `status` region, direct children of
  `AdminLayout`'s `<section class="d-flex flex-column gap-4">`, with no wrapping `<div>`. A `position: sticky` element
  sticks only inside its parent, and the old wrapper was exactly as tall as the alert (no travel: it scrolled away with
  it); now the parent is the section, which holds the whole page. The same wrapper, always rendered, was a flex item of
  the section and added a `gap-4` even when empty; now nothing is rendered without an ended session, and the region is
  `visually-hidden` (`position: absolute`, out of the flow, no `gap`). `AdminLayout.sessionEnded.spec.ts` pins both
  (jsdom computes no sticking: the browser check, task 11, measures it). Rejected: `display: contents` on the wrapper
  (one more CSS rule to know, and known losses of semantics in Safari) and moving the region into `AdminLayout` (the
  banner's behaviour split across two files). A component with two roots takes no fallthrough attribute: `AdminLayout`
  passes none.
- **Only a new refusal re-announces** (S7, reviewed by R14, E by R19): `useAuth().sessionEndedCount` goes up by one on
  each **accepted** `markSessionExpired()` and the banner's **message `<span>`** carries it as its `:key` — a refusal
  sent under the *current* generation, a gesture made while the session is already ended (« Enregistrer » pressed under
  the banner). The parallel reads of a page's load (`AdminWatchPage`, `AdminQualityPage`, `AdminAboutPage` send two to
  four) leave under one generation: the first counts, the others do not announce again. **The alert itself is never
  re-inserted** (its `ref` and the observer of `usePublishedHeight` stay on one element): it holds the sign-in link, and
  replacing it dropped a keyboard user's focus on `body`.
- **The return of the session is announced**: a `role="status"` region **present from the banner's mount** (a region
  inserted with its text may not be announced) receives « Session rétablie, vous pouvez enregistrer. » at the
  descent of `sessionEnded` (which `useAuth` does only when the re-check found an account *and* a write
  token), is `visually-hidden` while empty and is emptied at the next route change — **and when the session ends again**
  (CR6: « you can save » under a banner saying the opposite).
- **The sign-in-again link: one builder, one component** (#748, R6). It used to be computed in `AppHeader`,
  `SessionEndedBanner` and `McpConnectionsPage`, the language read from the route in one and from i18n in the others.
  - `routeLocale(route, fallback)` (`presentation/router/routeLocale.ts`): the route's `locale` param when it is a
    supported locale, else the fallback (the i18n language; the 404 page has no `:locale`). `AppHeader`'s `homeLink` uses it too.
  - `loginLocation(route, fallback, flag)` (`presentation/router/loginLocation.ts`, R16) is the **only** builder of a link
    to the login: `login` in the route's language, the flag (`'session-ended'` → `expired=1`, `'reauthentication'` →
    `reauth=1`, the single value `1`), and `redirect` = the route's `fullPath` (the page the person comes back to)
    **only when `route.meta.requiresAuth`** (F8): `protectedRoutesCarryNoSecret` reviews those routes alone, so a public
    or 404 page does not put its path in a URL the web server logs — its link has no `redirect`, and the login brings the
    person home. `signInAgainLocation` (`router/sessionEnded.ts`, `expired=1`: it makes `LoginPage` say why it opened)
    and `switchAccountLocation` (`router/reauthentication.ts`, `reauth=1`: « Changer de compte », the consent's
    re-authentication) delegate to it. The flags' constants live in `loginLocation.ts`, which imports neither module (St3-9: no cycle,
    `signInLocations.spec.ts` checks it); the spec also fails when a file other than those three names the parameters.
  - **Both flags open the login to a signed-in person, and nothing more** (R16, B/F2): the `login` route's `beforeEnter`
    first awaits `waitForAuthCheck()` (bounded: `me()` gives up after 15 s, R49), like the guard of protected routes (R40: in a fresh tab
    `user` is null while `/api/me`
    is in flight), then sends an account home unless `reauth=1` **or** `expired=1`. Why `expired=1`: a `BEARER` still alive with no
    `XSRF-TOKEN` leaves `authState.user` set while every write is refused, and the banner's link would otherwise land on
    the home page — a dead end for up to an hour. The flag grants no right; `redirect` stays judged by
    `internalRedirectTarget()` in `LoginPage`. **What the page says depends on the tier and on whether an account is
    displayed** (R30, A3-10, R40): anonymous → « votre session a pris fin » (`login-session-ended-notice`); an account →
    the neutral `auth.reauthNotice` (`login-reauth-notice`), as for `reauth=1`; **base tier → nothing** (the access holds,
    no account to confirm: the ordinary login). Not `authState.sessionEnded`: the link opens a new tab, whose application
    is fresh (`sessionEnded` false) while the account may still be shown.
  - **`presentation/ui/SignInAgainLink.vue`** renders it: no prop, the default slot is the visible label, one root
    `RouterLink` (the classes passed land on it: the header's button), `target="_blank" rel="noopener"` (but see
    `meta.signInInSameTab` below), the opening said
    in the text (a visually hidden `common.opensInNewTab`, « (s'ouvre dans un nouvel onglet) », WCAG 3.2.5) and by an
    `external-link` icon (`aria-hidden`) for sighted people. **A new tab** in the banner, the header, « Mes connexions MCP »
    and the assistant: in the same tab the page would be left and what was typed lost; the session cookie is shared across
    tabs, `redirect` brings the new tab back to the page, and the re-check on return restores the original one.
  - **The consent page is the exception, on purpose, and its route says so** (`RouteMeta.signInInSameTab`, R16, F7): a
    step of an OAuth flow ending in `location.replace` towards the client; a second tab would leave the request open in
    the first. `SignInAgainLink` reads the flag on the current route and then renders **no `target`, no `rel`, no hidden
    text and no icon** (it opens nothing new). The body's « Se reconnecter » is that component and so is the header's
    « Connexion » there; « Changer de compte » and « Me reconnecter pour autoriser » are plain `RouterLink`s built by
    `switchAccountLocation`, also in the same tab (`.claude/rules/mcp-oauth.md`, « The consent page »).
  - The assistant's link used to stay in the same tab and omit `expired=1`: it changed with R6 (the typed question is worth keeping).
- **The header follows the banner on a protected page** (#748): while the session is ended *and* the route has
  `meta.requiresAuth` (backoffice, MCP, assistant — the pages that ended it), `AppHeader`'s « Connexion » is that same link
  (`SignInAgainLink` with the button's classes: new tab, `redirect` to the current `fullPath`, `expired=1`, hidden text and `external-link`
  icon — the same tab, with none of the last two, on the consent, `meta.signInInSameTab`), and « Accès
  instantané » is **not offered**: it would replace the cookie and lead to the case studies, the page and its typed input
  with it. Rejected: hiding « Connexion » (a button that vanishes shifts the bar and removes a known path). On a public
  route nothing changes (`sessionEnded` survives there until a sign-in, and a new tab would make no sense);
  `AppHeader.spec.ts` pins the four cases.
  - **The header's « Connexion » in a new tab holds on every `requiresAuth` route**, not only the backoffice
    (`sessionEndedHere` reads `route.meta.requiresAuth`), **except where `meta.signInInSameTab` says otherwise** (the
    consent, R16): `AppHeader.spec.ts` pins the assistant and « Mes connexions MCP » (new tab) and the consent (same tab,
    no hidden text, no icon), taking each route's `meta` from the application's router, so a route that loses
    `requiresAuth` or the flag turns its case red.

## Tests

- `SessionEndedBanner.spec.ts` (alert
inserted, no focus taken, the link and its icon, no listener on `focus`, the sticky class, the status region present from
mount and its message on return and emptied on the next route or by a new end, a refusal replacing the message, once per tick, and never the
alert nor the focused link; the height measured on one element), `usePublishedHeight.spec.ts` (the height posted, updated on
a second reading, removed with the target), `AdminLayout.sessionEnded.spec.ts` (the banner a direct child of the section holding the page,
and adding no element to its flow; the page stays mounted with its typed
input; red when `router.replace` is reintroduced; the banner is the only alert on a 401 and on a 403 without a token, a 403
with a token shows the single « accès refusé » alert (`AccessRefusedNotice.spec.ts` too), with « Changer de compte » in the same tab, the
input kept, `/api/me` read once, the alert cleared on navigation and masked when the re-read finds the session ended),
`AdminCaseStudiesPage.spec.ts` and `OrderToolbar.spec.ts` (an ended session adds no alert of the
page's, and the input stays).
- `signInLocations.spec.ts` (`loginLocation`, the two wrappers, no `redirect` off a protected route, and the guard that no other file names
  `expired`/`reauth`), `SignInAgainLink.spec.ts` (new tab, hidden text, icon, one root; no `redirect` on a public route; the same tab on
  `signInInSameTab`), `adminGuard.spec.ts` (an account opens the login with `expired=1` as with `reauth=1`, with no flag it goes home; the
  route waits for the first check), `protectedRoutesCarryNoSecret.spec.ts` (every `requiresAuth` route is reviewed: its `fullPath` leaves in
  the `redirect` of a new tab).
- `AppHeader.spec.ts` (the new-tab « Connexion » on the assistant, the MCP page and the consent).
