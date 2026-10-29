---
paths:
  - "frontend/src/infrastructure/http/**"
  - "frontend/src/application/auth/**"
  - "frontend/src/domain/auth/errors/**"
  - "frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts"
  - "frontend/src/application/admin/shared/useAdminLoad.ts"
  - "frontend/src/application/admin/shared/visibleMutationError.ts"
  - "frontend/src/infrastructure/auth/**"
  - "frontend/tests/infrastructure/auth/**"
  - "frontend/src/domain/auth/repositories/**"
  - "frontend/src/application/mcp/**"
  - "frontend/src/application/assistant/**"
  - "frontend/tests/application/auth/**"
  - "frontend/tests/support/signInAgain.ts"
  - "frontend/src/presentation/layout/usePublishedHeight.ts"
  - "frontend/src/infrastructure/mcp/**"
  - "frontend/src/infrastructure/assistant/**"
  - "frontend/src/presentation/ui/admin/SessionEndedBanner.vue"
  - "frontend/src/presentation/router/sessionEnded.ts"
  - "frontend/src/presentation/router/reauthentication.ts"
  - "frontend/src/presentation/router/routeLocale.ts"
  - "frontend/src/presentation/ui/SignInAgainLink.vue"
  - "frontend/tests/presentation/router/signInLocations.spec.ts"
  - "frontend/tests/presentation/ui/SignInAgainLink.spec.ts"
  - "frontend/tests/presentation/router/protectedRoutesCarryNoSecret.spec.ts"
  - "frontend/src/presentation/router/backofficeSessionExpiry.ts"
  - "frontend/src/presentation/layout/useSessionRecheckOnFocus.ts"
  - "frontend/tests/presentation/layout/useSessionRecheckOnFocus.spec.ts"
  - "frontend/tests/infrastructure/http/**"
  - "frontend/tests/application/admin/shared/useAdminLoad.spec.ts"
  - "frontend/tests/application/admin/shared/visibleMutationError.spec.ts"
  - "frontend/tests/application/admin/sessionEndedLoad.spec.ts"
  - "frontend/tests/infrastructure/admin/sessionEndedMapping.spec.ts"
  - "frontend/tests/infrastructure/admin/shared/BackofficeHttpClient.spec.ts"
  - "frontend/tests/presentation/ui/admin/SessionEndedBanner.spec.ts"
  - "frontend/tests/presentation/layout/usePublishedHeight.spec.ts"
  - "frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts"
---

# Frontend — an ended session and a refusal (reference)

> The one rule of #748 (R2). It was a bullet of `frontend-backoffice.md` and was repeated, in part, by
> `frontend.md`, `mcp-oauth.md` and `mcp-oauth-revocation-personne.md`: those now say their own case in a sentence
> and point here. The code-side counterpart is the docblock of `infrastructure/http/sessionAwareFetch.ts`. The
> index of every rule is at the top of `.claude/CLAUDE.md`. Later tasks of #748 (R4 to R9) add their sections here.

## The rule, in one function

**A 401, or a 403 to a write sent without a CSRF token, mid-page ends the session on the interface, and moves
nobody** (#650, R1c, then S2 of the second review; the 403 since #748). The route guard plays at navigation only,
it never sees a session that dies while a page is open (the JWT expiring, or a password change cutting every session
opened before it, `.claude/rules/security-authentication-sessions.md`). The rule is **one function**,
`endsTheSession(status, sentWithoutCsrfToken)`, in `frontend/src/infrastructure/http/sessionAwareFetch.ts`, and its
facts are checked in the backend:

- the CSRF subscriber (priority 20) runs *before* the firewall and controls writes only (`POST`/`PUT`/`PATCH`/`DELETE`):
  a read is never refused for CSRF, and a write with no `X-XSRF-TOKEN` header gets a 403 before any authentication;
- since R1 (#748) the login gives `XSRF-TOKEN` the expiry of the JWT (`token_ttl`), as the base access always did:
  **at the JWT's expiry both cookies fall together**, so a read takes a 401 and a write leaves without a token and takes
  the CSRF's **403**. Before R1 `XSRF-TOKEN` was a session cookie, which a browser closed without session restore threw
  away while the persistent `BEARER` stayed: every write read as an ended session in a loop. A session opened before the
  deployment of R1 keeps a session-cookie `XSRF-TOKEN` for at most one hour (the JWT's lifetime);
- `/api/logout` and the refusal of a session opened before a password change expire both cookies too: a read takes a
  401, a write leaves without a token and takes the 403 (the person logged out in another tab).

**`sessionAwareFetch(url, init)` is the only send that judges the session.** It adds `credentials: 'include'`, reads
`XSRF-TOKEN` for any method other than `GET`/`HEAD` and sets `X-XSRF-TOKEN` when it is non-empty (an empty `XSRF-TOKEN=`
counts as absent), and returns `{ response, endedTheSession }`. **"With a token" is `hasCsrfToken()`
(`infrastructure/auth/csrfCookie.ts`), the only definition** — an empty or undecodable value (`XSRF-TOKEN=%E0`:
`readCsrfToken()` catches the `URIError` and answers `null`) is absent, for the header sent and for
`hasWriteToken()` alike. Its second parameter is typed `SessionAwareInit`, which the MCP and consent clients reuse. The verdict is taken **at send time**, on what left
(`isWrite && !token`), never by re-reading the cookie at reception (another tab may have set it back) nor by the 403's
`detail` (localisable, not a contract). A network failure or an `AbortError` rejects as it came: the client translates
it. The boolean no longer travels from function to function (`sentWithoutCsrfToken` is gone from every repository).
A client calls it, then judges `endedTheSession` **at one fixed place** of its error translation: after the only typed
case that precedes it (the consent's 401 `reauthentication-required`), before the `switch` on the status.
**A 403 to a read, or to a write that carried its token, keeps its meaning** (not authorized, CSRF signature
refused).

`sessionAwareFetch` is a shared **function**, not a client: `frontend.md` keeps `BackofficeHttpClient` for the
backoffice only (its global reaction, `setBackofficeSessionEndedHandler`, is not the one of the three other clients,
which mark the session in their composable); the MCP, consent and assistant repositories call the function, and so
does `BackofficeHttpClient`.

**Accepted limits**:
- **S5** — between the read of the cookie and the send, another tab can set or remove `XSRF-TOKEN`. The verdict stays
  right for the request that left (it did, or did not, carry a token); at worst a tab that has just signed in again
  reads its in-flight 403 as an ended session, and the re-check on return restores it.
- An `XSRF-TOKEN` present but whose signature no longer verifies (an `APP_SECRET` rotation) stays a refusal, although
  signing in again would repair it.
- A browser that drops the cookies of an earlier session reads a finished session: signing in again is indeed the
  remedy.

## The two shared errors

`frontend/src/domain/auth/errors/`:

- **`SessionEndedError`** — the session has ended (the verdict above). It replaces `SessionExpiredError` of the MCP
  domain; the consent failure literal `'session-expired'` became `'session-ended'`. It is not an error of the context
  that saw it pass.
- **`AccessForbiddenError`** — any other 403: an account without the trusted tier, or that lost it while the page was
  open, or a stale CSRF signature. It replaces `ConsentForbiddenError`. The session holds for the rest of the site, so
  nothing marks it expired.

## One section per client

- **Backoffice** — `BackofficeHttpClient` (`get` and `mutate`) goes through `sessionAwareFetch` and, on an ended
  session, calls the reaction registered once through `setBackofficeSessionEndedHandler()` and **raises
  `SessionEndedError`** (#748, R3): it no longer hands the response back, and the old per-context reason
  `session-ended`, the module `WeakSet` and `endedTheSession()` are gone. The 15 repositories **do not translate it**: it
  crosses them as it came and leaves the hierarchy of every context (it is not an `AdminXError`). **Two shared functions
  of `application/admin/shared/` keep it quiet, one per direction, and a new administration composable goes through
  them**:
  - `useAdminLoad({ read, apply, clear? })` for a read: a `SessionEndedError` raises no `hasError` and leaves the value already
    read; any other rejection raises it; a response older than the last `load()` is dropped (stale guard); `K` is the
    read's key (the locale, for the About settings; a new key runs `clear` first). `isLoaded` says the value of the
    current key was read: **a first read ended by the session leaves it false, and a page never says « aucune … » then**.
    On return (`authState.sessionEnded` true to false) the read it interrupted runs again, once, with its key (#748, R4,
    `.claude/rules/frontend-backoffice.md`);
  - `visibleMutationError(error, OwnError, fallback)` for a `catch` of a mutation: `null` for a `SessionEndedError` (the
    banner is the only announcement, the page adds no alert), the error itself when it is the context's, `fallback()`
    otherwise. Never a `catch` that turns what is not its own error into `unknown`: it would show « Une erreur est
    survenue » under the banner.
  **A mutation resolves to `true` only when it succeeded** (`create`/`update`/`remove`/`save`, `setSuperAdmin`,
  `resendInvitation`, `changePassword`): « no error to show » is no longer « succeeded », since an ended session shows
  none, and a page that reset its form on `!errorMessage` lost what the person had typed (the case #650 S2 protects).
  A page resets its form, announces a success or clears a field on that value, never on `errorMessage`. `useOrderDraft`
  and `useAdminTranslation` set no reason for an ended session either (the draft stays, to be replayed after signing
  in), and no page composes a key `admin.<context>.errors.session-ended`: the reason does not exist.
- **Connections MCP** — `HttpMcpConnectionsRepository` calls the function; a 401, or a 403 to the `DELETE` sent
  without a token, is a `SessionEndedError`, which `useMcpConnections` turns into `markSessionExpired()` and
  `refusal: 'session-ended'`; any other 403 is an `AccessForbiddenError` (`refusal: 'forbidden'`, see « A real
  refusal »).
- **Consent** — `HttpOAuthConsentRepository`: the typed 401 `reauthentication-required` first, then the verdict
  (`SessionEndedError`), then the `switch` (`403` → `AccessForbiddenError`, terminal `forbidden`, the session not
  marked expired).
- **Assistant** — `HttpAssistantRepository`: the verdict gives the reason `unauthenticated` (and
  `markSessionExpired()`), any other 403 the reason `forbidden` (see « A real refusal »).

## The interface

- **`main.ts`** registers `onBackofficeSessionEnded` (`presentation/router/backofficeSessionExpiry.ts`), which is
  `markSessionExpired()` and nothing else: **no `router.replace`** (R1c's navigation unmounted the page at the very click
  of « Enregistrer », and a case study written for more than an hour — the JWT's lifetime — was lost with it). It is
  idempotent, a state and not an event: several lists of a page answering 401 together need no de-duplication. The
  registry lives in the module, so `infrastructure/` imports neither the session nor the router.
- **The state** is `sessionEnded` in `useAuth`'s shared state (`hasSessionEnded`), **raised** by `markSessionExpired()`
  (the header also turns anonymous) and **lowered when a session of an account is applied** (login, a `/api/me` re-read
  that finds one); a re-read still anonymous or base does not lower it.
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
- **Every refusal re-announces** (S7): `markSessionExpired()` is idempotent for the state, so a second refusal while the
  banner is shown changed nothing in the DOM and nothing was announced again. `useAuth().sessionEndedCount` goes up by one
  on each `markSessionExpired()`, and the alert carries it as its `:key`: **a new refusal re-inserts the alert**, which
  screen readers announce; several refusals of the same tick render once, so insert once. `usePublishedHeight` follows
  the re-inserted element (its `ref` changes, the observer lets the old one go and measures the new one).
- **The return of the session is announced**: a `role="status"` region **present from the banner's mount** (a region
  inserted with its text may not be announced) receives « Session rétablie, vous pouvez enregistrer. » when
  `hasSessionEnded` goes from true to false (which `useAuth` does only when the re-check found an account *and* a write
  token), is `visually-hidden` while empty and is emptied at the next route change — **and when the session ends again**
  (CR6: « you can save » under a banner saying the opposite).
- **The sign-in-again link: one builder, one component** (#748, R6). It used to be computed in `AppHeader`,
  `SessionEndedBanner` and `McpConnectionsPage`, the language read from the route in one and from i18n in the others.
  - `routeLocale(route, fallback)` (`presentation/router/routeLocale.ts`): the route's `locale` param when it is a
    supported locale, else the fallback (the i18n language; the 404 page has no `:locale`). `AppHeader`'s `homeLink` uses it too.
  - `signInAgainLocation(route, fallback)` (`presentation/router/sessionEnded.ts`) is the **only** builder of the link:
    `login` in the route's language, `redirect` = the route's `fullPath` (the page the person comes back to), `expired=1`
    (the single value `1`, an announcement that opens nothing: it makes `LoginPage` say why it opened).
    `switchAccountLocation(route, fallback)` (`presentation/router/reauthentication.ts`) is its twin with `reauth=1`
    (« Changer de compte », the consent's re-authentication). `signInLocations.spec.ts` fails when another file names the
    two query parameters.
  - **`presentation/ui/SignInAgainLink.vue`** renders it: no prop, the default slot is the visible label, one root
    `RouterLink` (the classes passed land on it: the header's button), `target="_blank" rel="noopener"`, the opening said
    in the text (a visually hidden `common.opensInNewTab`, « (s'ouvre dans un nouvel onglet) », WCAG 3.2.5) and by an
    `external-link` icon (`aria-hidden`) for sighted people. **A new tab** in the banner, the header, « Mes connexions MCP »
    and the assistant: in the same tab the page would be left and what was typed lost; the session cookie is shared across
    tabs, `redirect` brings the new tab back to the page, and the re-check on return restores the original one.
  - **The consent page is the exception, on purpose**: its body links (« Se reconnecter », « Changer de compte ») are built by
    the same two functions but stay in the **same tab** — a step of an OAuth flow ending in `location.replace` towards the
    client; a second tab would leave the request open in the first. Its header's « Connexion » is the new-tab link like
    everywhere (`.claude/rules/mcp-oauth.md`, « The consent page »).
  - The assistant's link used to stay in the same tab and omit `expired=1`: it changed with R6 (the typed question is worth keeping).
- **The header follows the banner on a protected page** (#748): while the session is ended *and* the route has
  `meta.requiresAuth` (backoffice, MCP, assistant — the pages that ended it), `AppHeader`'s « Connexion » is that same link
  (`SignInAgainLink` with the button's classes: new tab, `redirect` to the current `fullPath`, `expired=1`, hidden text and `external-link` icon), and « Accès
  instantané » is **not offered**: it would replace the cookie and lead to the case studies, the page and its typed input
  with it. Rejected: hiding « Connexion » (a button that vanishes shifts the bar and removes a known path). On a public
  route nothing changes (`sessionEnded` survives there until a sign-in, and a new tab would make no sense);
  `AppHeader.spec.ts` pins the four cases.
- **The re-check on return** (#748, R8, CR7). **One mechanism, installed once**: `presentation/layout/useSessionRecheckOnFocus.ts`
  is called by `AppLayout` and listens to the window's `focus` from mount to unmount; it calls
  `useAuth().recheckEndedSession()` and nothing else. The banner used to carry its own listener, so only the backoffice
  noticed a session found again in another tab: « Mes connexions MCP », the assistant and the consent never did. The
  banner **no longer listens to `focus`** (a test pins it), so a return costs **one** `/api/me`, banner shown or not.
  - `recheckEndedSession()` does nothing outside an ended session; **one re-read in flight at a time** (a second call
    while the first runs returns the same promise, the module holds it, so two quick returns, or two `useAuth()`
    callers, send one request); a rejection of `/api/me` (network) is swallowed and the session stays ended, with no
    announcement.
  - **It is silent: `isChecking` is never touched.** `isChecking` is raised only by the *first* `checkAuth()` (`main.ts`,
    the one `waitForAuthCheck` makes the route guard wait for); a module flag, `hasCheckedOnce`, keeps every later
    `checkAuth()` — this re-check's neighbours, the re-read after a real refusal (see below) — from raising it. Raised on
    each return, it would hide the header's right-hand group (`v-if="!isChecking"`) and make it flicker at every return
    on the tab. `waitForAuthCheck()` is unaffected: it resolves on the first check, as before.
  - **A session is restored only if an account comes back *and* a write token exists**: `AuthRepository.hasWriteToken()`
    (a domain port; `HttpAuthRepository` answers from `readCsrfToken()`, an empty `XSRF-TOKEN=` counting as absent,
    since the application does not read a cookie). A `/api/me` that finds the account while `XSRF-TOKEN` is gone (the
    persistent `BEARER` survived a browser restart that dropped the session cookie, or a session opened before R1) would
    be a session whose first write takes the CSRF 403, read as an ended session again: restoring it would announce
    « Session rétablie » and the loop would start over. The state is then left as it is. Only the account case restores:
    a re-read that finds the base tier or an anonymous one changes nothing (the header stays anonymous until a sign-in).
  - **Nobody but `useAuth` restores, and nobody else re-reads a list**: the restoration brings `authState.sessionEnded`
    down (true to false), and each page reacts to that signal for what concerns it. The administration composables
    through `useAdminLoad` (R4, the read an ended session interrupted runs again, once); **« Mes connexions MCP »**
    reloads its list if `refusal` was `'session-ended'` (a real refusal is not reloaded: the same request, the same
    verdict); **the consent** calls `reload()` if `failure` was `'session-ended'` (its other terminal failures stay);
    **the assistant** clears an `unauthenticated` error and goes back to `idle` — the draft stays (the question typed
    came back into the field at the failure), nothing is re-sent, and other errors (network, quota) are not the
    session's: they stay. The banner clears itself and announces the return (« Session rétablie »).
  - **The header's « Connexion » in a new tab holds on every `requiresAuth` route**, not only the backoffice
    (`sessionEndedHere` reads `route.meta.requiresAuth`): `AppHeader.spec.ts` pins the assistant, « Mes connexions MCP »
    and the consent, taking each route's `meta` from the application's router, so a route that loses `requiresAuth`
    turns its case red. The consent's *body* links stay in the same tab (see above); its header's « Connexion » does not.
  **The route guard still redirects to `login` at the next navigation** — the input is saved or abandoned by then. No
  dedicated « your password changed » message: Lexik's 401 body carries `{code, message}` and no stable `type`, and
  reading the text would be fragile.

## A real refusal (#748, R7)

A 403 that is not an ended session — a read (the CSRF subscriber never refuses a read), or a write that carried its
token — is `AccessForbiddenError` (the assistant: the reason `forbidden`). It comes from `access_control` refusing an
account without `ROLE_TRUSTED` (one that lost it while the page was open), or from a stale `XSRF-TOKEN` signature (an
`APP_SECRET` rotation). The same three pages (« Mes connexions MCP », the assistant, the consent) react the same way:

- **The session is never marked expired.** It holds for the rest of the site (the public pages, the base tier).
- **`/api/me` is re-read once, on the refusal** (`useAuth().checkAuth()`, in `useMcpConnections`, `useAssistant` and
  `useOAuthConsent`; not awaited, since the page's state does not depend on it; a rejection is swallowed). The backend
  fact behind it, checked: the `cpg_users` provider (the entity) reloads the `CpgUser` from the database on every
  request, `JWTAuthenticator::createToken()` (Lexik) builds the token with `$passport->getUser()->getRoles()`, and
  `access_control` requires `ROLE_TRUSTED` on `^/api/me` — so an account that lost the trusted tier takes a 403 there,
  which `HttpAuthRepository.me()` reads as the base tier, and the header returns to it; an account still trusted (a
  stale CSRF signature) answers 200 and **nothing moves**. `/api/me` never rejects on a 401/403 (it returns a tier),
  so a refusal on it cannot re-trigger the re-read: there is no loop.
- **The message is neutral** (`common.accessRefused`): « the account may not have, or may no longer have, trusted
  access, **or the session needs to be reopened** » — a stale CSRF signature is a 403 too, and must not accuse an account
  that is still trusted (S8).
- **« Changer de compte »** (`common.switchAccount`, `switchAccountLocation`: `login?redirect=<this page>&reauth=1`) is the
  only link that opens the login to a signed-in person, and the new cookies it sets also mend a stale signature. **The
  same tab** on the three pages: the consent is a step of an OAuth flow (see above), and on the assistant the lost
  conversation is accepted (the author's decision of 2026-10-29); « Mes connexions MCP » keeps no input to protect.
  This differs from the ended-session link (`SignInAgainLink`, a new tab, on the assistant and the MCP page).
- **No « Réessayer »** on the MCP page (the same request takes the same verdict) and **every « Révoquer » disabled**; the
  focus goes to the `<h1>` on any refusal of a revocation. `useMcpConnections.refusal` (`'session-ended' | 'forbidden'
  | null`, reset at the start of each `load()` and `revoke()`) replaced `sessionExpired`.
- The login page opened by `reauth=1` announces a **neutral** `auth.reauthNotice` (the ten-minute rule is said by the
  consent's own alert, before its link).

**Limits removed by R7 and R8**: the header of an account stripped of `ROLE_TRUSTED` no longer stays at the trusted tier
until the next full load; a `GET` in 403 on the MCP page no longer shows the generic failure with a « Réessayer » that
cannot help; and the header no longer flickers while `/api/me` is read after a refusal (R8: every `checkAuth()` after the
first is silent, see « The re-check on return »). **Still accepted**: a refusal leaves the page it came from.

## Tests

`sessionAwareFetch.spec.ts` (the rule's table; a read leaves with the cookies and no CSRF header, a write carries the
header read in the cookie, a write without a cookie or with an empty one ends the session on a 403, a 401 on any method,
the given headers kept, a network failure or an abort rejecting as it came), `BackofficeHttpClient.spec.ts` (a 401 on read
and write, a 403 to a write without a token or with an empty cookie, each **rejects `SessionEndedError`** and calls the
reaction once, also with no reaction registered; 200/204/403/404/409/422/500 give the response back with no reaction when
the token was sent, a 403 to a read never; a network failure rises as it came), `useAdminLoad.spec.ts` (`isLoaded`, the key that empties, the read run again on return, `tests/support/signInAgain.ts` being the return) and
`visibleMutationError.spec.ts` (the two functions), `sessionEndedMapping.spec.ts` (the table of the 15 repositories: a 401
on every call each exposes, read, write, order, rejects `SessionEndedError` unchanged; a glob fails when a new repository
is missing from the table), `sessionEndedLoad.spec.ts` (the thirteen composables: an ended session on a load raises no
`hasError` and keeps the list, any other failure raises it; on a mutation it sets **no error and does not succeed**, any
other failure sets `unknown`; `useAdminTranslation` and `useOrderDraft` too), `HttpAdminUserRepository.spec.ts`,
`HttpMcpConnectionsRepository.spec.ts`, `HttpOAuthConsentRepository.spec.ts`, `HttpAssistantRepository.spec.ts`,
`backofficeSessionExpiry.spec.ts` (the session is closed, no navigation, idempotent), `SessionEndedBanner.spec.ts` (alert
inserted, no focus taken, the link and its icon, the re-read on `focus`, the sticky class, the status region present from
mount and its message on return and emptied on the next route or by a new end, a refusal re-inserting the alert once per tick, the height following it), `usePublishedHeight.spec.ts` (the height posted, updated on
a second reading, removed with the target), `AdminLayout.sessionEnded.spec.ts` (the banner a direct child of the section holding the page, and adding no element to its flow; the page stays mounted with its typed
input; red when `router.replace` is reintroduced; the banner is the only alert on a 401 and on a 403 without a token, a 403
with one shows the page's), `AdminCaseStudiesPage.spec.ts` and `OrderToolbar.spec.ts` (an ended session adds no alert of the
page's, and the input stays), `useAuth.spec.ts` (including `sessionEndedCount`), `signInLocations.spec.ts` (the two builders, and the guard that no other file names `expired`/`reauth`), `SignInAgainLink.spec.ts` (new tab, hidden text, icon, one root), `protectedRoutesCarryNoSecret.spec.ts` (every `requiresAuth` route is reviewed: its `fullPath` leaves in the `redirect` of a new tab), and, for the real refusal (R7): `HttpMcpConnectionsRepository.spec.ts` (a 403 on the read, or on the `DELETE` with a token, is `AccessForbiddenError`; without a token, `SessionEndedError`), `useMcpConnections.spec.ts` (`refusal`, `/api/me` read once, a demoted account's header back at the base tier, a failing `/api/me` swallowed), `useAssistant.spec.ts` and `useOAuthConsent.spec.ts` (`/api/me` read once on `forbidden`, not on an ended session), `McpConnectionsPage.spec.ts` / `AssistantPage.spec.ts` / `OAuthConsentPage.spec.ts` (the exact neutral text in both languages, « Changer de compte » with `reauth=1`, no « Réessayer », « Révoquer » disabled) and `LoginPage.spec.ts` (the neutral announcement); for the re-check on return (R8): `useAuth.spec.ts` (the account found with its write token restores; **without `XSRF-TOKEN` the session stays ended**; nothing outside an ended session; one read in flight for two quick calls; a network failure swallowed; `isChecking` untouched by the re-check and by any `checkAuth()` after the first), `HttpAuthRepository.spec.ts` (`hasWriteToken`: present, absent, empty), `useSessionRecheckOnFocus.spec.ts` (`focus` re-reads, not after unmount), `AppLayout.spec.ts` (one `/api/me` per return with the banner shown, the re-check outside the backoffice, the session staying ended while no account is back), `SessionEndedBanner.spec.ts` (the banner does not listen to `focus`; the return is driven by `recheckEndedSession()`), `AppHeader.spec.ts` (the new-tab « Connexion » on the assistant, the MCP page and the consent), and the reactions `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`.

Three guards of the first version are gone, because what they guarded no longer exists: `sessionEnded.spec.ts` (domain,
`isSessionEndedError`), `visibleErrorReason.spec.ts` and `errorReasonsHideSessionEnded.spec.ts` (a source guard: every file
composing `errors.${reason}` went through `visibleErrorReason`). With no `session-ended` reason there is nothing to hide,
and the behaviour they kept is held by the two functions' specs, the thirteen composables' and the page and layout ones
above. The one thing a source guard caught and these do not: a **new** composable that forgets the two functions (the rule
above and the review carry it).
