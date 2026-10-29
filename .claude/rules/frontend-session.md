---
paths:
  - "frontend/src/infrastructure/http/**"
  - "frontend/src/application/auth/**"
  - "frontend/src/domain/auth/errors/**"
  - "frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts"
  - "frontend/src/infrastructure/mcp/**"
  - "frontend/src/infrastructure/assistant/**"
  - "frontend/src/presentation/ui/admin/SessionEndedBanner.vue"
  - "frontend/src/presentation/router/sessionEnded.ts"
  - "frontend/src/presentation/router/reauthentication.ts"
  - "frontend/src/presentation/router/backofficeSessionExpiry.ts"
  - "frontend/tests/infrastructure/http/**"
  - "frontend/tests/presentation/ui/admin/SessionEndedBanner.spec.ts"
  - "frontend/tests/presentation/layout/usePublishedHeight.spec.ts"
  - "frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts"
---

# Frontend — an ended session and a refusal (reference)

> The one rule of #748 (R2). It was a bullet of `frontend-backoffice.md` and was repeated, in part, by
> `frontend.md`, `mcp-oauth.md` and `mcp-oauth-revocation-personne.md`: those now say their own case in a sentence
> and point here. The code-side counterpart is the docblock of `infrastructure/http/sessionAwareFetch.ts`. The
> index of every rule is at the top of `.claude/CLAUDE.md`. Later tasks of #748 (R3 to R9) add their sections here.

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
counts as absent), and returns `{ response, endedTheSession }`. The verdict is taken **at send time**, on what left
(`isWrite && !token`), never by re-reading the cookie at reception (another tab may have set it back) nor by the 403's
`detail` (localisable, not a contract). A network failure or an `AbortError` rejects as it came: the client translates
it. The boolean no longer travels from function to function (`sentWithoutCsrfToken` is gone from every repository).
A client calls it, then judges `endedTheSession` **at one fixed place** of its error translation: after the only typed
case that precedes it (the consent's 401 `reauthentication-required`), before the `switch` on the status.
**A 403 to a read, or to a write that carried its token, keeps its meaning** (not authorized, CSRF signature
refused).

`sessionAwareFetch` is a shared **function**, not a client: `frontend.md` keeps `BackofficeHttpClient` for the
backoffice only (its global reaction, `setBackofficeUnauthorizedHandler`, is not the one of the three other clients,
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
  session, calls the reaction registered once through `setBackofficeUnauthorizedHandler()` and returns the response as it
  came; the 15 repositories keep their own status mapping, **none repeats the reaction**, and each tests
  `client.endedTheSession(response)` **first** in its error translator, answering the reason `session-ended`
  (`domain/admin/shared/errors/sessionEnded.ts`; `AdminOrderError` for the order), which composables and pages keep quiet
  (`isSessionEndedError()`, `visibleErrorReason()`): the banner is the only announcement. (R3 turns this into an
  error raised by the client.) A new administration client goes through `BackofficeHttpClient`.
- **Connections MCP** — `HttpMcpConnectionsRepository` calls the function; a 401, or a 403 to the `DELETE` sent
  without a token, is a `SessionEndedError`, which `useMcpConnections` turns into `markSessionExpired()` and
  `sessionExpired`; any other 403 is the generic failure for now.
- **Consent** — `HttpOAuthConsentRepository`: the typed 401 `reauthentication-required` first, then the verdict
  (`SessionEndedError`), then the `switch` (`403` → `AccessForbiddenError`, terminal `forbidden`, the session not
  marked expired).
- **Assistant** — `HttpAssistantRepository`: the verdict gives the reason `unauthenticated` (and
  `markSessionExpired()`), any other 403 the reason `forbidden`.

## The interface

- **`main.ts`** registers `onBackofficeUnauthorized` (`presentation/router/backofficeSessionExpiry.ts`), which is
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
- **The return of the session is announced**: a `role="status"` region **present from the banner's mount** (a region
  inserted with its text may not be announced) receives « Session rétablie, vous pouvez enregistrer. » when
  `hasSessionEnded` goes from true to false, is `visually-hidden` while empty and is emptied at the next route change.
- **The sign-in-again link.** The banner carries a « Se reconnecter » link to `login?redirect=<current fullPath>&expired=1`
  with `target="_blank" rel="noopener"` and the opening said in the link text (a visually hidden « (s'ouvre dans un nouvel
  onglet) », WCAG 3.2.5) and by an `external-link` icon (`aria-hidden`) for sighted people: in the same tab the typed text
  would be lost; the session cookie is shared across tabs and `redirect` brings the new tab back to the page.
  `expired=1` (`presentation/router/sessionEnded.ts`, the single value `1`, an announcement that opens nothing) makes
  `LoginPage` say why it opened.
- **The header follows the banner on a protected page** (#748): while the session is ended *and* the route has
  `meta.requiresAuth` (backoffice, MCP, assistant — the pages that ended it), `AppHeader`'s « Connexion » is that same link
  (new tab, `redirect` to the current `fullPath`, `expired=1`, hidden text and `external-link` icon), and « Accès
  instantané » is **not offered**: it would replace the cookie and lead to the case studies, the page and its typed input
  with it. Rejected: hiding « Connexion » (a button that vanishes shifts the bar and removes a known path). On a public
  route nothing changes (`sessionEnded` survives there until a sign-in, and a new tab would make no sense);
  `AppHeader.spec.ts` pins the four cases.
- **The re-check on return.** On the window's `focus`, while the session is ended, the banner re-reads `/api/me`
  (`checkAuth()`): an account found clears the banner and « Enregistrer » works again (the CSRF token is read from the
  cookie at each call); a network failure leaves the banner. **The route guard still redirects to `login` at the next
  navigation** — the input is saved or abandoned by then. No dedicated « your password changed » message: Lexik's 401 body
  carries `{code, message}` and no stable `type`, and reading the text would be fragile.

## Tests

`sessionAwareFetch.spec.ts` (the rule's table; a read leaves with the cookies and no CSRF header, a write carries the
header read in the cookie, a write without a cookie or with an empty one ends the session on a 403, a 401 on any method,
the given headers kept, a network failure or an abort rejecting as it came), `BackofficeHttpClient.spec.ts` (401 on read
and write, a 403 to a write without a token or with an empty cookie, 200/204/403/404/409/422/500 with no reaction when the
token was sent, a 403 to a read never, no handler registered), `sessionEndedMapping.spec.ts` (the repositories: a 401
read, a 403 write without a token and a 401 reorder are `session-ended`, a 403 write with one keeps its mapping; a glob
fails when a new repository is missing from its table), `HttpAdminUserRepository.spec.ts`,
`HttpMcpConnectionsRepository.spec.ts`, `HttpOAuthConsentRepository.spec.ts`, `HttpAssistantRepository.spec.ts`,
`backofficeSessionExpiry.spec.ts` (the session is closed, no navigation, idempotent), `SessionEndedBanner.spec.ts` (alert
inserted, no focus taken, the link and its icon, the re-read on `focus`, the sticky class, the status region present from
mount and its message on return and emptied on the next route), `usePublishedHeight.spec.ts` (the height posted, updated on
a second reading, removed with the target), `AdminLayout.sessionEnded.spec.ts` (the page stays mounted with its typed
input; red when `router.replace` is reintroduced; the banner is the only alert on a 401 and on a 403 without a token, a 403
with one shows the page's), `useAuth.spec.ts`.
