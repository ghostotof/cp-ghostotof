---
paths:
  - "frontend/src/infrastructure/http/**"
  - "frontend/src/domain/auth/errors/**"
  - "frontend/src/domain/auth/repositories/**"
  - "frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts"
  - "frontend/src/application/admin/shared/useAdminLoad.ts"
  - "frontend/src/application/admin/shared/visibleMutationError.ts"
  - "frontend/src/infrastructure/auth/**"
  - "frontend/src/infrastructure/mcp/**"
  - "frontend/src/infrastructure/assistant/**"
  - "frontend/tests/infrastructure/auth/**"
  - "frontend/tests/infrastructure/http/**"
  - "frontend/tests/application/admin/shared/useAdminLoad.spec.ts"
  - "frontend/tests/application/admin/shared/visibleMutationError.spec.ts"
  - "frontend/tests/application/admin/sessionEndedLoad.spec.ts"
  - "frontend/tests/infrastructure/admin/sessionEndedMapping.spec.ts"
  - "frontend/tests/infrastructure/admin/shared/BackofficeHttpClient.spec.ts"
---

# Frontend — an ended session and a refusal (reference)

> The one rule of #748 (R2). It was a bullet of `frontend-backoffice.md` and was repeated, in part, by
> `frontend.md`, `mcp-oauth.md` and `mcp-oauth-revocation-personne.md`: those now say their own case in a sentence
> and point here. The code-side counterpart is the docblock of `infrastructure/http/sessionAwareFetch.ts`. The
> index of every rule is at the top of `.claude/CLAUDE.md`. Split by sub-domain in #748 (St3-11, procedure of #605): the original kept the rule below and the index of where each part went.

## Where the rest of this area went

| Part | Holds | Read it before… |
|---|---|---|
| `frontend-session-bandeau.md` | the banner (`SessionEndedBanner`: alert, sticky height, two roots, re-announcement, return announced), the sign-in-again link (`loginLocation`, `SignInAgainLink`, the flags, `LoginPage`'s two notices), the header that follows the banner | touching the banner, `AdminLayout`'s notices, `AppHeader`'s « Connexion », a link to the login, `usePublishedHeight` |
| `frontend-session-retour.md` | the shared session state and where it lives (`application/auth/`, the reset for tests), the re-check on return, `onSessionRestored` / `onAccountSwitched`, the replay and the hidden page, the session generation | touching `useAuth` or one of the `application/auth/` modules, `useSessionRecheckOnFocus`, `AppLayout`'s replay, `tests/support/setup.ts` |
| `frontend-session-refus.md` | the backoffice's reaction (`BackofficeSessionReaction`, `main.ts`), a real refusal: `AccessForbiddenError`, the re-read of `/api/me`, `AccessRefusedNotice`, « Changer de compte », the MCP page's refusal | touching a 403 that is not an ended session, `followRefusal`, `backofficeSessionExpiry.ts`, `backofficeAccessRefusal.ts`, `common.accessRefused` |

What stays here: the rule (`sessionAwareFetch`, « with a token »), its accepted limits, the two shared errors, one section per client.

## The rule, in one function

**A 401, or a 403 to a write sent without a CSRF token, mid-page ends the session on the interface, and moves
nobody** (#650, R1c, then S2 of the second review; the 403 since #748). The route guard plays at navigation only,
it never sees a session that dies while a page is open (the JWT expiring, or a password change cutting every session
opened before it, `.claude/rules/security-authentication-sessions.md`). The rule is **one function**,
`endsTheSession(status, sentWithoutCsrfToken)`, in `frontend/src/infrastructure/http/sessionAwareFetch.ts`, and its
facts are checked in the backend:

- the CSRF subscriber (priority 20) runs *before* the firewall and controls writes only (`POST`/`PUT`/`PATCH`/`DELETE`):
  a read is never refused for CSRF, and a write with no `X-XSRF-TOKEN` header gets a 403 before any authentication;
- since R21 (#748, F1, the author's decision of 2026-10-29) **`BEARER` and `XSRF-TOKEN` are session cookies** (no
  `Expires`/`Max-Age`), at the login (`set_cookies.BEARER.lifetime: 0`) as at the base access: a browser closed without
  session restore ends the session, read and write; the JWT keeps its `exp`. At that `exp` **the first request takes a
  401 that clears both cookies** (`StaleSessionCookiesClearingListener`, for any refused cookie): a read takes the 401,
  a write the 401 then, the cookies gone, the CSRF's **403** without token; the next request is anonymous. A session
  opened before the deployment keeps its persistent cookies, one hour at most;
- `/api/logout` and the refusal of a session opened before a password change expire both cookies too: a read takes a
  401, a write leaves without a token and takes the 403 (the person logged out in another tab).

**`sessionAwareFetch(url, init)` is the only send that judges the session.** It adds `credentials: 'include'`, reads
`XSRF-TOKEN` **once** for any method other than `GET`/`HEAD` (`readWriteToken()`, R28, CR3-10), sets `X-XSRF-TOKEN` from that
value and takes the verdict from the same value, and returns `{ response, endedTheSession }`. **"With a token" is
`readWriteToken() !== null` (`infrastructure/auth/csrfCookie.ts`), the only definition**, which `hasCsrfToken()` (for
`hasWriteToken()`) applies too — an empty or undecodable value (`XSRF-TOKEN=%E0`: the `URIError` is caught, `null`) is
absent. Two reads could be split by another tab, the header gone while the verdict said « with a token ». Its second parameter is typed `SessionAwareInit`, which the MCP and consent clients reuse. The verdict is taken **at send time**, on what left
(`isWrite && !token`), never by re-reading the cookie at reception (another tab may have set it back) nor by the 403's
`detail` (localisable, not a contract). A network failure or an `AbortError` rejects as it came: the client translates
it. The boolean no longer travels from function to function (`sentWithoutCsrfToken` is gone from every repository).
A client calls it, then judges `endedTheSession` **at one fixed place** of its error translation: after the only typed
case that precedes it (the consent's 401 `reauthentication-required`), before the `switch` on the status.
**A 403 to a read, or to a write that carried its token, keeps its meaning** (not authorized, CSRF signature
refused).

`sessionAwareFetch` is a shared **function**, not a client: `frontend.md` keeps `BackofficeHttpClient` for the
backoffice only (its global reaction, `setBackofficeSessionReaction`, is not the one of the three other clients,
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
- **After the `exp` of a JWT (login or base access) the first 401 only reaches a route that requires a role** (#748, the
  author's decision of 2026-10-29): the server serves a public route as anonymous whatever the cookie (R35; in dev, two origins, public reads leave
  without cookies, so R35 is exercised there by PHPUnit and the preprod smoke only), and the SPA
  reads `/api/me` at every load, which clears a dead cookie.
  The login's expiry is not exposed to the frontend, so its first authenticated request takes the 401 and the session
  reads as ended (banner, sign-in again); « Accès instantané » ignores the dead cookie (one call, R37). The base access expires
  on the client on the **clock of the workstation**, a display effect only: the header goes anonymous at `expiresAt`
  with no network call (no `POST /api/logout`, no `focus` listener); the cookie, a session one, stays until the browser
  closes or the first 401 clears it.

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
  session, calls the reaction registered once through `setBackofficeSessionReaction()` and **raises
  `SessionEndedError`** (#748, R3): it no longer hands the response back, and the old per-context reason
  `session-ended`, the module `WeakSet` and `endedTheSession()` are gone. The 15 repositories **do not translate it**: it
  crosses them as it came and leaves the hierarchy of every context (it is not an `AdminXError`). **Two shared functions
  of `application/admin/shared/` keep it quiet, one per direction, and a new administration composable goes through
  them**:
  - `useAdminLoad({ read, apply, clear? })` for a read: a `SessionEndedError` raises no `hasError` and leaves the value already
    read; any other rejection raises it; a response older than the last `load()` is dropped (stale guard); `K` is the
    read's key (the locale, for the About settings; a new key runs `clear` first). `isLoaded` says the value of the
    current key was read: **a first read ended by the session leaves it false, and a page never says « aucune … » then**.
    Each `load()` also declares itself to the backoffice's refusal (`beginBackofficeLoad`, `frontend-session-refus.md`).
    On return (`onSessionRestored`, the same account) the read it interrupted runs again, once, with its key (#748, R4,
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
  refusal » in `frontend-session-refus.md`).
- **Consent** — `HttpOAuthConsentRepository`: the typed 401 `reauthentication-required` first, then the verdict
  (`SessionEndedError`), then the `switch` (`403` → `AccessForbiddenError`, terminal `forbidden`, the session not
  marked expired).
- **Assistant** — `HttpAssistantRepository`: the verdict gives the reason `unauthenticated` (and
  `markSessionExpired()`), any other 403 the reason `forbidden` (see « A real refusal » in `frontend-session-refus.md`).

## Tests

The specs of the rule and of the clients; those of the banner and the links are in `frontend-session-bandeau.md`, of the return and the generation in `frontend-session-retour.md`, of the refusal in `frontend-session-refus.md`.

- `sessionAwareFetch.spec.ts` (the rule's table; a read leaves with the cookies and no CSRF header, a write carries the
header read in the cookie, a write without a cookie or with an empty one ends the session on a 403, a 401 on any method,
the given headers kept, the cookie read once even if it vanishes meanwhile (R28), a network failure or an abort rejecting as it came), `csrfCookie.spec.ts` (`readWriteToken`: an empty or undecodable `XSRF-TOKEN=%E0` is absent, no exception, R12) and `problemJson.spec.ts` (`hasProblemType`: the slug, the whole segment only, any other or no type).
- `BackofficeHttpClient.spec.ts` (a 401 on read
and write, a 403 to a write without a token or with an empty cookie, each **rejects `SessionEndedError`** and calls the
reaction once, also with no reaction registered; 200/204/404/409/422/500 give the response back with no reaction; a 403 to a read, or to a write with its token, calls `refused` once with the send stamp and **rejects `AccessForbiddenError`**, also with no reaction (R17); a network failure rises as it came).
- `useAdminLoad.spec.ts` (`isLoaded`, the key that empties, the read run again on return, `tests/support/signInAgain.ts` being the return, the load declared and ended on every outcome, R102) and
`visibleMutationError.spec.ts` (the two functions), `sessionEndedMapping.spec.ts` (the table of the 15 repositories: a 401
on every call each exposes, read, write, order, rejects `SessionEndedError` unchanged; a glob fails when a new repository
is missing from the table), `sessionEndedLoad.spec.ts` (the thirteen composables: an ended session on a load raises no
`hasError` and keeps the list, any other failure raises it; on a mutation it sets **no error and does not succeed**, any
other failure sets `unknown`; `useAdminTranslation` and `useOrderDraft` too), `HttpAdminUserRepository.spec.ts`,
`HttpMcpConnectionsRepository.spec.ts`, `HttpOAuthConsentRepository.spec.ts`, `HttpAssistantRepository.spec.ts`.
- `noOmittedCredentials.spec.ts` (no file of `src/` holds a string literal `'omit'` — typed or in the options object; read by `tests/support/sourceText.ts` on TypeScript's syntax tree, the files of `src/` loaded once for it and `sessionStateEncapsulation.spec.ts` by `tests/support/srcSources.ts` (R97), template parts included, a `.vue` by its `<script>` blocks and the expressions of its `<template>` — `v-for` and `v-slot` included, each read as the JavaScript fragment Vue compiles, every fragment of `src/` required to parse, R68, R80, R96, R110 —, which drops preprod's Basic Auth, R37, R51).
- The backend facts it relies on are pinned by `AuthCookieAttributesTest` (session cookies), `BaseAccessControllerTest` and `RefusedSessionCookiesAreClearedTest` (a refused cookie cleared by the first 401 of a protected route), `JwtClockSkewTest` (`clock_skew` 5 s, R29), `cookiePolicy.spec.ts` (the published cookie policy says session cookies, R32), `.claude/rules/security-authentication-sessions.md`.

Three guards of the first version are gone, because what they guarded no longer exists: `sessionEnded.spec.ts` (domain,
`isSessionEndedError`), `visibleErrorReason.spec.ts` and `errorReasonsHideSessionEnded.spec.ts` (a source guard: every file
composing `errors.${reason}` went through `visibleErrorReason`). With no `session-ended` reason there is nothing to hide,
and the behaviour they kept is held by the two functions' specs, the thirteen composables' and the page and layout ones
above. The one thing a source guard caught and these do not: a **new** composable that forgets the two functions (the rule
above and the review carry it).
