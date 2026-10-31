---
paths:
  - "frontend/src/application/auth/**"
  - "frontend/src/presentation/layout/useSessionRecheckOnFocus.ts"
  - "frontend/src/presentation/layout/AppLayout.vue"
  - "frontend/tests/support/setup.ts"
  - "frontend/tests/support/signInAgain.ts"
  - "frontend/tests/application/auth/**"
  - "frontend/src/application/shared/singleFlight.ts"
  - "frontend/tests/application/shared/singleFlight.spec.ts"
  - "frontend/tests/presentation/layout/AppLayout.spec.ts"
  - "frontend/tests/presentation/layout/useSessionRecheckOnFocus.spec.ts"
---

# Frontend — the return, the session state and its generation (reference)

> Split from `.claude/rules/frontend-session.md` (#748, St3-11; procedure of #605), which keeps the index of the parts. The index of every
> rule is at the top of `.claude/CLAUDE.md`.

## The state, the re-check on return and the replay

- **The state** is `sessionEnded` in the session's shared state (`hasSessionEnded`), **raised** by `markSessionExpired()`
  (the header also turns anonymous) and **lowered by two descents** (next section): an account came back, with a write
  token (`restoreSession`), or the tab obtained the base access (`markBaseAccessGranted()`, R41, CR4-3: a session the tab
  decides closes the end, like `login()`, **with no signal** and no announcement, the generation having advanced).
  `applySession()` lowers nothing.
- **Where it lives** (#748, R25: `useAuth.ts` was split, its public API unchanged, everything still re-exported from it).
  Under `application/auth/`: `sessionState.ts` (the reactive state, **not exported** (R42): only named functions write it,
  `markFirstCheckDone`, `applySession`, `raiseSessionEnded`, `lowerSessionEnded`, the base-access marks, and everything else reads the
  read-only `authState`; each write is imported by its own modules only (`sessionLifecycle.ts` for `raiseSessionEnded` and
  `lowerSessionEnded`, `useAuth.ts` for the check and forget marks, both for `applySession`) and no other module of `src/` exports one,
  under any name — checked by identity on the loaded modules: `sessionStateEncapsulation.spec.ts`, R52, R69),
  `baseAccessExpiry.ts` (its timer, which takes its callback as a parameter and imports nothing: no cycle),
  `sessionGeneration.ts`, `sessionEvents.ts` (the two signals), `sessionLifecycle.ts` (`markSessionExpired`,
  `restoreSession`, and `canRestore`, the descent's condition written once: ended, an account, a write token), and
  `useAuth.ts` (the composable and the two shared re-reads, each a `createSingleFlight`), then `application/shared/singleFlight.ts`
  (generic, next to `staleRequestGuard.ts`; the reset for tests recreates the two slots, no `clear()` in its interface, R55). **Tests start
  from the module state of loading**:
  `resetAuthStateForTests()` (each module owns a `reset…ForTests`, tests only, never called by `src/`; a new module state
  is added to it) is called before each test by `tests/support/setup.ts`, the `setupFiles` of `vite.config.ts`, which also
  resets the backoffice's refusal and its registered reaction (R38) and calls `enableAutoUnmount` once.
- **The re-check on return** (#748, R8, CR7). **One mechanism, installed once**: `presentation/layout/useSessionRecheckOnFocus.ts`
  is called by `AppLayout` and listens to the window's `focus` from mount to unmount; it calls
  `useAuth().recheckEndedSession()` and nothing else. The banner used to carry its own listener, so only the backoffice
  noticed a session found again in another tab: « Mes connexions MCP », the assistant and the consent never did. The
  banner **no longer listens to `focus`** (a test pins it), so a return costs **one** `/api/me`, banner shown or not.
  - `recheckEndedSession()` does nothing outside an ended session; **one re-read in flight at a time** (a second call
    while the first runs returns the same promise, so two quick returns, or two `useAuth()` callers, send one request;
    the slot is `createSingleFlight` in `application/shared/singleFlight.ts` (R41, St4-4), written once for this re-read and for
    `rereadAfterRefusal`, and released by a `.finally` set **after** the promise is held, so a `me()` that throws
    synchronously never leaves a settled promise behind); a rejection of `/api/me` (network, or `me()` giving up after `ME_TIMEOUT_MS`, 15
    s, R49) is swallowed and the session stays ended, with no
    announcement. The bound holds for the first check too: `checkAuth()` swallows a rejection of `/api/me` only, like an anonymous answer,
    so `waitForAuthCheck()` resolves, the guard and the `login` route open, and `main.ts`, which does not await it, gets no unhandled
    rejection for it. An error raised after the response (`applyReadSession`, `scheduleBaseAccessExpiry`: a programming error) surfaces —
    `main.ts` does not catch it, on purpose, so it shows as an unhandled rejection — and the first check still ends (R65).
  - **It is silent: `isChecking` is never touched.** `isChecking` is born `true` and lowered once, when the load's
    `checkAuth()` ends (`markFirstCheckDone()`, the one `waitForAuthCheck` makes the route guard wait for); no re-read raises
    it (this one, the re-read after a real refusal: see « A real refusal » in `frontend-session-refus.md`). Raised on
    each return, it would hide the header's right-hand group (`v-if="!isChecking"`) and make it flicker at every return
    on the tab. `waitForAuthCheck()` is unaffected: it resolves on the first check, as before.
  - **A session is restored only if an account comes back *and* a write token exists**: `AuthRepository.hasWriteToken()`
    (a domain port; `HttpAuthRepository` answers from `hasCsrfToken()`, since the application does not read a cookie). A `/api/me` that
    finds the account while `XSRF-TOKEN` is gone (a
    browser that restores only one of the two cookies, or a session opened before R21) would
    be a session whose first write takes the CSRF 403, read as an ended session again: restoring it would announce
    « Session rétablie » and the loop would start over. The state is then left as it is. Only the account case restores:
    a re-read that finds the base tier or an anonymous one changes nothing (the header stays anonymous until a sign-in).
  - **Nobody but `useAuth` restores, and nobody else re-reads a list** (#748, R13): the restoration brings
    `authState.sessionEnded` down and `useAuth` **emits one signal for it**,
    `onSessionRestored(callback)` (also exported as a function, for the composables that need no
    `AUTH_REPOSITORY`): `callback` is called once, synchronously, at each such descent by the same account (next bullet) — never at the end
    of a session —,
    and the subscription is dropped at `onScopeDispose` (a page unmounted is no longer called). **A page that depends
    on the return subscribes to it; no page `watch`es `authState.sessionEnded` any more** (five did: it was the same
    condition written five times). Each reacts for what concerns it. The administration composables through
    `useAdminLoad` (R4, the read an ended session interrupted runs again, once); **« Mes connexions MCP »**
    reloads its list if `refusal` was `'session-ended'` (a real refusal is not reloaded: the same request, the same
    verdict) **and clears `lastOutcome` first** (A: `load()` resets `refusal`, and a revocation the ended session
    refused would otherwise show « la révocation a échoué », which no gesture triggered); **the consent** calls
    `reload()` if `failure` was `'session-ended'` (its other terminal failures stay); **the assistant** clears an
    `unauthenticated` error and goes back to `idle` — the draft stays (the question typed came back into the field at
    the failure), nothing is re-sent, and other errors (network, quota) are not the session's: they stay. The banner
    clears itself and announces the return (« Session rétablie »).
  - **Only the same account is restored in place; another one replays the navigation** (#748, R18, the author's
    decision of 2026-10-29; « the same account » = `LastAccount`, R26). `sessionState.ts` keeps **the last account
    displayed** (`username` and roles), set by every applied session that carries an account, **kept** when the header
    turns anonymous or falls to the base tier (the open page is still its), forgotten by `logout()` and by
    `markBaseAccessGranted()`. At the descent (return after an ended session): the same `username` **and no role lost**
    (`isSameAccount`; a role *gained* changes nothing) calls `onSessionRestored` (« Session rétablie », the interrupted
    reads run again, the typed input stays); **another** account, the same one with a role lost, or **none kept**
    has its session applied and calls **only** `onAccountSwitched(callback)` (same mechanics: a scope, dropped at
    `onScopeDispose`). **Outside an ended session** (`checkAuth()`, `rereadAfterRefusal()`, `applyLiveSession`) only a
    **different `username`** from the last account does the same (the generation advances first); a role lost there stays handled in place
    (R7/R17). Nobody announces « Session
    rétablie » for it, and no list is re-read: the open page is no longer that account's. `AppLayout` is the one
    subscriber: it replays the navigation to the current route with `router.replace({ path, query, hash, force: true })`
    — `force` because the router ignores a navigation to the address it is on, so the guard would never judge the new
    account (an account without `ROLE_SUPER` on `/admin` is sent to « forbidden », never shown the page with the first
    account's data) — **then** advances a `key` on the `RouterView` component, because the same path keeps the same
    instance (the assistant would keep the first account's conversation, a form its input). Replay first, remount
    after: the other order would mount a protected page, for an instant, for an account the guard is about to refuse.
    **The routed page is hidden during the replay** (R27, A3-13: `isReplaying`, set synchronously in the subscriber,
    lowered in the `finally` after the key advances, so also when the replay fails, **but only by the last replay**:
    each takes a token from `createStaleRequestGuard` (`application/shared/`, the guard the composables use), because the router cancels the
    first of two overlapping replays and
    settles its promise early, CR4-2): the new account's session is already
    applied, so the header is its, and the old page would stay under it with the first account's data until the guard
    resolved. The same-account return never replays: no hiding, no flicker.
    Rejected: `window.location.reload()` (loses the whole application's state and redoes the first `checkAuth`). **A
    `login()` is silent**: in an ended session it **always** restores (account, no write-token condition: the backend sets
    `XSRF-TOKEN` at sign-in, and if it is missing the first write takes the 403 and ends the session again), lowers
    `sessionEnded` and advances the generation but calls neither signal — the login
    page navigates itself — so tests that need a return go through `recheckEndedSession()` (`tests/support/signInAgain.ts`
    does, in an ended session). Pinned by `useAuth.spec.ts`, `AppLayout.spec.ts` (the guard re-judges, the page is
    remounted, the role-less account is redirected), `SessionEndedBanner.spec.ts` and `AssistantPage.spec.ts`.
  **The route guard still redirects to `login` at the next navigation** — the input is saved or abandoned by then. No
  dedicated « your password changed » message: Lexik's 401 body carries `{code, message}` and no stable `type`, and
  reading the text would be fragile.
- **Accepted limit** (#748, C7, the author's decision of 2026-10-29): an account switch in **another tab**, with **no**
  ended session in this one, is seen by nothing — the re-check on return only runs in an ended session. The header and the
  page stay the first account's until a refusal (the re-read, R26), a protected navigation or a reload, and writes leave
  with the second account's cookie. It predates #748 and assumes access to the same browser.

## The session generation (#748, R14)

A verdict that comes back from the network describes the session **as it was at send time**, and the session may have
changed since: another tab signed in again, or the end was already announced by a sibling request. `sessionGeneration.ts` holds a
module counter, **the generation** (`sessionStamp()`), advanced at each accepted **end**, at each **return** and by
**every session the tab itself decides** (R26, CR3-4): `login()`, `logout()` (before the send and when the anonymous is
applied), `markBaseAccessGranted()`, and `markBaseAccessExpired()` when it changes the tier — so a `/api/me` sent
before one never overwrites it — and when a read outside an ended session finds another account (`applyLiveSession`,
R48, CR5-2: before `onAccountSwitched`, so a 401 that left under the first account does not end the second's session; a refusal is judged on
the account, R63). Any other *read* session (`applyReadSession`) advances nothing: it is the response. A
caller reads it **before sending** and hands it back with the verdict:

- `markSessionExpired(sentAt?)` returns whether the session **is ended**. Without `sentAt`, or under the current
  generation: the end is accepted (header anonymous, `sessionEnded` up, `sessionEndedCount` +1, generation +1) and it
  returns `true`. Under a stale generation nothing changes: `true` if the session is ended (the verdict stands, already
  announced), `false` if it was restored since the send — the caller then treats the response as an **ordinary
  failure** (the MCP page: `status = 'error'` or `lastOutcome = 'failed'` with no `refusal`; the consent: the `unknown`
  failure; the assistant: `AssistantError('unknown')`; the backoffice client: the response is handed back to the repository,
  which translates it, and no `SessionEndedError` is raised). A request sent before a return can no longer end the
  session it did not belong to. Both directions share the one number: an end from before the return,
  and a return from before an end.
- **The descent by an account's return** (`restoreSession`, `sessionLifecycle.ts`) is asked, by `canRestore`, for **an
  account and a write token (`hasWriteToken()`)**; the caller judges the **current generation** itself
  (`isCurrentGeneration(sentAt)`), `login()` excepted — a sign-in is the person's own gesture and skips both. `login()`,
  `checkAuth()`, `recheckEndedSession()` and `rereadAfterRefusal()` (through `applyReadSession`) all go through it; **in an ended session a
  read that
  finds the base tier or the anonymous one sets nothing** (Sp3-3); it advances the generation and then calls the `onSessionRestored`
  subscribers (or `onAccountSwitched`'s for another account; `login()` calls neither, R18). A `/api/me` sent before a new
  refusal, or before a return, answers for a session that is gone: its result is dropped (it neither restores nor
  puts the header back to an account). `checkAuth()` no longer lowers `sessionEnded` for an account without a write token
  (F5: it did, while `recheckEndedSession()` required one).
- **A subscriber that throws** stops neither the next ones nor the outcome of the `login`/`checkAuth`/`recheckEndedSession`
  that restored the session: each call is isolated and the error is reported with `console.error` (the repository had no
  other convention for a non-fatal error; swallowing it silently would hide a bug in a page).
- Rejected: cancelling every request in flight at the return (an `AbortController` to wire in every client, and a
  cancelled write may well have succeeded server-side); a timestamp (two events in the same millisecond).

## Tests

- `singleFlight.spec.ts` (shared in flight, freed after a resolution, a rejection, a synchronous throw, R41); `useAuth.spec.ts` (including
  `sessionEndedCount`, `onSessionRestored` and `onAccountSwitched`: once, synchronously, never at the end, dropped with its scope, a
  subscriber that throws, the same account or another (R18); the generation in both directions, R14; the base access going anonymous at its
  deadline with no network call, R23; `lastAccountName` follows the last account displayed, R63), `resetAuthStateForTests.spec.ts` (every
  module's state back to its loading value, R25; a re-read in flight before the reset is not shared after, R55),
  `sessionStateEncapsulation.spec.ts` (`state` and `isSameAccount` are not exported, R42; the exact export list of `sessionState.ts`, the
  importers of each write, and no other module exporting one, R52, R69).
- For the re-check on return (R8): `useAuth.spec.ts` (the account found with its write token restores; **without `XSRF-TOKEN` the session
  stays ended**; nothing outside an ended session; one read in flight for two quick calls; a network failure swallowed; `isChecking`
  untouched by the re-check and by any `checkAuth()` after the first).
- `HttpAuthRepository.spec.ts` (`hasWriteToken`: present, absent, empty; `logout()` sends the CSRF token).
- `useSessionRecheckOnFocus.spec.ts` (`focus` re-reads, not after unmount), `AppLayout.spec.ts` (one `/api/me` per return with the banner
  shown, the re-check outside the backoffice, the session staying ended while no account is back; another account replays the navigation,
  then remounts the page, R18; the page hidden meanwhile, even when the replay fails, R27, and until the last of two overlapping replays,
  R39, and again with the router replaced by two held promises, R53).
- `SessionEndedBanner.spec.ts` (the banner does not listen to `focus`; the return is driven by `recheckEndedSession()`).
