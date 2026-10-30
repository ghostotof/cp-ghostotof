---
paths:
  - "frontend/src/application/mcp/**"
  - "frontend/src/application/assistant/**"
  - "frontend/src/application/admin/shared/backofficeAccessRefusal.ts"
  - "frontend/src/presentation/router/backofficeSessionExpiry.ts"
  - "frontend/src/infrastructure/admin/shared/BackofficeHttpClient.ts"
  - "frontend/src/domain/admin/shared/BackofficeSessionReaction.ts"
  - "frontend/src/presentation/ui/admin/AccessRefusedNotice.vue"
  - "frontend/tests/application/admin/shared/backofficeAccessRefusal.spec.ts"
  - "frontend/tests/presentation/ui/admin/AccessRefusedNotice.spec.ts"
---

# Frontend — a real refusal and the backoffice's reaction (reference)

> Split from `.claude/rules/frontend-session.md` (#748, St3-11; procedure of #605), which keeps the index of the parts. The index of every rule is at the top of `.claude/CLAUDE.md`.

## The backoffice reaction

- **`main.ts`** registers `createBackofficeSessionReaction(useAuth())` (`presentation/router/backofficeSessionExpiry.ts`), a
  `BackofficeSessionReaction` `{ stampAtSend(kind), ended, refused }` (`refused`: see « A real refusal ») over `markSessionExpired`: **no `router.replace`** (R1c's navigation unmounted the page at the very
  click of « Enregistrer », and a case study written for more than an hour — the JWT's lifetime — was lost with it). The
  verdict is judged by **session generation** (`frontend-session-retour.md`, « The session generation »), not « idempotent »: several lists of a page answering 401
  together leave under the same generation, the first end counts and the others announce nothing more. The registry lives
  in the module, so `infrastructure/` imports neither the session nor the router. The type (`BackofficeSessionReaction`
  and the send stamp `{ session, refusal }`, `refusal` a `RefusalScope`: `{ kind: 'read', scope }` or `{ kind: 'write' }`, R38, R54) lives in `domain/admin/shared/`, which all three layers may import:
  no `infrastructure/` file imports `application/` (R28).

## A real refusal (#748, R7)

A 403 that is not an ended session — a read (the CSRF subscriber never refuses a read), or a write that carried its
token — is `AccessForbiddenError` (the assistant: the reason `forbidden`). It comes from `access_control` refusing an
account without `ROLE_TRUSTED` (one that lost it while the page was open), or from a stale `XSRF-TOKEN` signature (an
`APP_SECRET` rotation). The same three pages (« Mes connexions MCP », the assistant, the consent) react the same way:

- **The session is never marked expired.** It holds for the rest of the site (the public pages, the base tier).
- **`/api/me` is re-read once, on the refusal** (`useAuth().followRefusal(isStillRefused, showSessionEnded)`, R27: the
  sequence « re-read, then `forbidden` becomes `session-ended` if it is still shown » written once, over
  `rereadAfterRefusal()`, #748 R15; called by `useMcpConnections`, `useAssistant` and `useOAuthConsent`; not awaited,
  since the page shows the refusal at once). **One re-read in
  flight**, held by the module like `recheckEndedSession()`'s: a read and a revocation refused together, or two pages,
  send one `/api/me`; it is silent (`isChecking` untouched), reads the generation at send time, and swallows a
  rejection (`'session-held'`). The backend fact behind it, checked: the `cpg_users` provider (the entity) reloads the
  `CpgUser` from the database on every request, `JWTAuthenticator::createToken()` (Lexik) builds the token with
  `$passport->getUser()->getRoles()`, and `access_control` requires `ROLE_TRUSTED` on `^/api/me` — so an account that
  lost the trusted tier takes a 403 there, which `HttpAuthRepository.me()` reads as the base tier, and the header
  returns to it; an account still trusted (a stale CSRF signature) answers 200 and **nothing moves**. `/api/me` never
  rejects on a 401/403 (it returns a tier), so a refusal on it cannot re-trigger the re-read: there is no loop.
  **F — the token may be gone, not only the role**: when `/api/me` answers the anonymous tier (a 401), the session **is
  ended**, and the refusal the page shows was its first symptom. `rereadAfterRefusal()` marks it
  (`markSessionExpired(sentAt)`, the generation of the departure; a session already ended under that generation is not
  announced a second time, since a re-read is no gesture of the person) and returns `'session-ended'`; the page then
  turns its `forbidden` into the ended-session state — MCP `refusal = 'session-ended'`, consent
  `failure = 'session-ended'`, assistant `AssistantError('unauthenticated')` — **only if the refusal shown is still that
  `forbidden`** (a `load()` started meanwhile has reset it). The page then offers the sign-in-again link, not « Changer
  de compte ». A response from a stale generation (a return arrived meanwhile) answers `'session-held'` and changes
  nothing.
- **The backoffice does the same, once for all its pages** (#748, R17, F4). `BackofficeHttpClient` calls the reaction's
  `refused()` on a 403 that is not an ended session (a read, or a write that carried its token) and **raises
  `AccessForbiddenError`**; `createBackofficeSessionReaction(useAuth())` (built by `main.ts` with
  `app.runWithContext`, the one place that has the `AuthRepository`: the thirteen composables never call `useAuth()`)
  counts the refusal (`backofficeAccessRefused`) and calls `rereadAfterRefusal()`, so a read and a write refused
  together send one `/api/me`. **A read refused after a navigation does not count; a write always does** (R28, CR3-8, R38): the
  send stamp carries the refusal scope (`{ kind: 'read', scope: refusalScope() }`, `{ kind: 'write' }` for a write: `get` stamps `'read'`, `mutate` `'write'`),
  every clearing advances it (a path or query change, not the fragment alone; an ended session; the alert's mount and unmount), and `markBackofficeAccessRefused(sentIn)` answers `false`, counting and re-reading nothing,
  for a read that left under another scope — the person made the gesture of a write and must learn it failed; a refusal sent under another session generation counts and re-reads nothing (R48, CR5-2), read or write; the alert also clears on mount, without advancing the scope (`discardBackofficeAccessRefusals`), so a write refused while no alert was mounted never shows later on an unrelated page (CR5-1). `useAdminLoad` (no `hasError`, nothing re-read on return) and `visibleMutationError`
  (`null`, the mutation resolves `false`, the input stays) keep it quiet like `SessionEndedError`; **one alert**,
  `AccessRefusedNotice` in `AdminLayout` next to the banner, says `common.accessRefused` with « Changer de compte » in
  **the same tab** (the author's decision of 2026-10-29: as R7, accepting that following it loses what is typed). It is
  shown while the count is above 0 **and** the session has not ended (a re-read that finds the session ended leaves the
  banner alone), a further refusal replaces the message (the `key` is on the `<span>`, the link is never destroyed), and
  it is cleared on every change of path or query (parameters sorted: vue-router runs a mere permutation, the alert does not follow it; not of the fragment: the skip link), on an ended session and on unmount. `admin.notLoaded` is neutral
  (« see the message at the top of the page »): a page whose read was refused is not « session ended ».
- **The message is neutral** (`common.accessRefused`): « the account may not have, or may no longer have, trusted
  access, **or the session needs to be reopened** » — a stale CSRF signature is a 403 too, and must not accuse an account
  that is still trusted (S8).
- **« Changer de compte »** (`common.switchAccount`, `switchAccountLocation`: `login?redirect=<this page>&reauth=1`) is the
  only link that opens the login to a signed-in person, and the new cookies it sets also mend a stale signature. **The
  same tab** on the three pages: the consent is a step of an OAuth flow (see `frontend-session-bandeau.md`, « The interface »), and on the assistant the lost
  conversation is accepted (the author's decision of 2026-10-29); « Mes connexions MCP » keeps no input to protect.
  This differs from the ended-session link (`SignInAgainLink`, a new tab, on the assistant and the MCP page; the same tab on the consent).
- **No « Réessayer »** on the MCP page (the same request takes the same verdict) and **every « Révoquer » disabled**; the
  focus goes to the `<h1>` on any refusal of a revocation. `useMcpConnections.refusal` (`'session-ended' | 'forbidden'
  | null`, reset at the start of each `load()` and `revoke()`) replaced `sessionExpired`.
- The login page opened by `reauth=1` announces a **neutral** `auth.reauthNotice` (the ten-minute rule is said by the
  consent's own alert, before its link).

**Limits removed by R7 and R8**: the header of an account stripped of `ROLE_TRUSTED` no longer stays at the trusted tier
until the next full load; a `GET` in 403 on the MCP page no longer shows the generic failure with a « Réessayer » that
cannot help; and the header no longer flickers while `/api/me` is read after a refusal (R8: every `checkAuth()` after the
first is silent, see « The re-check on return » in `frontend-session-retour.md`); and, since R15, a header left anonymous without `sessionEnded` — a refusal
whose `/api/me` answers 401 now ends the session on the interface. **Still accepted**: a refusal leaves the page it came from.

## Tests

- `backofficeSessionExpiry.spec.ts` (the session is closed, no navigation, parallel verdicts of one generation announce once, a verdict sent before the return refinishes nothing; `refused` counts the refusal and re-reads the tier once; a read sent before a navigation counts and re-reads nothing, R28; a write does, R38), `backofficeAccessRefusal.spec.ts` (the scope, a write's `{ kind: 'write' }`, two successive tests starting from zero), `AccessRefusedNotice.spec.ts` (a fragment change keeps the alert, a query change clears it, the same parameters in another order keep it, R54), `AdminLayout.sessionEnded.spec.ts` (a refused save back after a navigation is shown on the next page; after an account switch the late 403 of the first account's save shows nothing, R48), plus, for R48, a write refused after the layout unmounted does not show at the next mount (`AccessRefusedNotice.spec.ts`), a refusal sent under another generation counts nothing (`backofficeSessionExpiry.spec.ts`), another account read outside an ended session advances the generation before the signal (`useAuth.spec.ts`).
- For the real refusal (R7): `HttpMcpConnectionsRepository.spec.ts` (a 403 on the read, or on the `DELETE` with a token, is `AccessForbiddenError`; without a token, `SessionEndedError`), `useMcpConnections.spec.ts` (`refusal`, `/api/me` read once, a demoted account's header back at the base tier, a failing `/api/me` swallowed; R15: one `/api/me` for a read and a revocation refused together, a 401 turning `forbidden` into `session-ended`), `useAuth.spec.ts` (`rereadAfterRefusal`: one read in flight, 401 ends the session, 403 holds it, a stale or failing read holds it, `isChecking` untouched; `followRefusal`: ended and still shown, replaced meanwhile, held, R27), `useAssistant.spec.ts` and `useOAuthConsent.spec.ts` (`/api/me` read once on `forbidden`, not on an ended session; a 401 on that read says the session ended), `McpConnectionsPage.spec.ts` / `AssistantPage.spec.ts` / `OAuthConsentPage.spec.ts` (the exact neutral text in both languages, « Changer de compte » with `reauth=1`, no « Réessayer », « Révoquer » disabled) and `LoginPage.spec.ts` (the neutral announcement).
- The reactions `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`.
