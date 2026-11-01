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
  - "frontend/tests/presentation/pages/admin/noLoadInOnMounted.spec.ts"
---

# Frontend — a real refusal and the backoffice's reaction (reference)

> Split from `.claude/rules/frontend-session.md` (#748, St3-11; procedure of #605), which keeps the index of the parts.
> The index of every rule is at the top of `.claude/CLAUDE.md`.

## The backoffice reaction

- **`main.ts`** registers `createBackofficeSessionReaction(useAuth())` (`presentation/router/backofficeSessionExpiry.ts`), a
  `BackofficeSessionReaction` `{ stampAtSend(kind), ended, refused }` (`refused`: see « A real refusal ») over `markSessionExpired`: **no
  `router.replace`** (R1c's navigation unmounted the page at the very click of « Enregistrer », and a case study written for more than an
  hour — the JWT's lifetime — was lost with it). The verdict is judged by **session generation** (`frontend-session-retour.md`, « The
  session generation »), not « idempotent »: several lists of a page answering 401 together leave under the same generation, the first end
  counts and the others announce nothing more. The registry lives in the module, so `infrastructure/` imports neither the session nor the
  router. The type (`BackofficeSessionReaction` and the send stamp `{ session, account, refusal }`, `refusal` a `RefusalScope`:
  `{ kind: 'read', scope, epoch }` or `{ kind: 'write' }`, R38, R54) lives in `domain/admin/shared/`, which all three layers may import: no
  `infrastructure/` file imports `application/` (R28).

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
- **The backoffice does the same, once for all its pages** (#748, R17, F4). `BackofficeHttpClient` calls the
  reaction's `refused()` on a 403 that is not an ended session (a read, or a write that carried its token) and
  **raises `AccessForbiddenError`**; `createBackofficeSessionReaction(useAuth())` (built by `main.ts` with
  `app.runWithContext`, the one place that has the `AuthRepository`: the thirteen composables never call `useAuth()`)
  counts the refusal (`backofficeAccessRefused`) and calls `rereadAfterRefusal()`, so a read and a write refused
  together send one `/api/me`. **What a refusal must still concern to count** is read on the send stamp
  `{ session, account, refusal }`:
  - **the account** (R63, decision 2 of the sixth review): `account` is the last account displayed at send time; a
    refusal is dropped — neither counted nor re-read, read or write — only when the account at send time and the account
    now differ, `null` on one side included (none on both sides is the same « none » and counts); the identity is the
    **name** (R77): the same name after a sign-out and a sign-in, or after a lost role, is the same account, on purpose;
    a deleted account recreated under the same name passes for the same (accepted, C8, the `__Host-` ticket); `null` at
    send time then an account drops the refusal, with no path to it (the backoffice guard waits for the first check).
    The same account's 403 stays said across an end and a return, or a tier change; the generation judges `ended()` only.
  - **the scope, for a read** (R28, CR3-8, R38): `get` stamps `stampBackofficeRead()` (`{ kind: 'read', scope, epoch }`; a
    verb, since it also closes the landing load below; `currentRefusalScope()` reads the same pair with no effect, and no
    code of `src/` calls it outside the module, R143, R158), `mutate` `{ kind: 'write' }`. The module holds the scope, the
    epoch and the open landing load as one immutable object replaced as a block (`RefusalModuleState`, R143). The scope
    advances at every clearing of the alert, and only there: a path or query change (the query's parameters sorted: vue-router
    runs a navigation that only permutes them, the alert ignores it, R54; not the fragment alone: the skip link), an ended
    session, the alert's unmount. A read that left under another scope came from a page left:
    `markBackofficeAccessRefused(sentIn, account)` answers `false`. A write carries no scope: the person made the gesture
    and must learn it failed. A read also carries the **epoch**, which the alert's mount advances (and nothing else): a read
    sent before the mount belongs to the landing page's load.
  - **the alert's mount advances the epoch only, never the scope, and settles what was counted without an alert** (R64,
    decision 1 of the sixth review, amending Q1 of the fifth): a read's refusal is forgotten (the tier was re-read), a
    **write**'s is shown with its own text, `common.earlierSaveRefused` (« Un enregistrement précédent a été refusé par le
    serveur… »), **only to the account that sent it** (R76, decision 2 of the seventh review:
    `markBackofficeAccessRefused(sentIn, account)` keeps the write's account, the mount passes `lastAccountName()`, and
    another account at the mount resets everything), the alert inserted after the mount so that it is announced
    (`frontend-a11y.md`); every read **sent before the mount** — the landing page's load, stamped by its `setup` — keeps that
    text and re-announces it until the first read **stamped** after the mount closes that load (R122, Q2 of the eleventh
    review: the bound is a send, not a delay). Any stamped read closes it, whatever started it: a re-read after a save, a
    section rendered later by a `v-if`, a watcher, a replay (R143, CR12-4). **A backoffice page starts its load in `setup`**
    (or an `immediate` watcher), both stamped before the alert's `onMounted`: a read sent from the page's own `onMounted`
    would carry the new epoch and say `common.accessRefused` for the earlier save. `noLoadInOnMounted.spec.ts` refuses a
    load or a repository call that runs after the mount, in `presentation/pages/admin` and `presentation/ui/admin` (R121, R144,
    R178). **Its criterion is the first execution of the callback**: it reads what never runs in `setup`. The function or the name
    passed to `onMounted`, `onUpdated`, `onBeforeUpdate`, `onActivated`, `onBeforeUnmount`, `onUnmounted`, `onDeactivated`,
    `onBeforeRouteUpdate` or `onBeforeRouteLeave` (the `vue` ones under their import alias too), `nextTick`, `setTimeout`,
    `setInterval`, `queueMicrotask`, `requestAnimationFrame`, `watchPostEffect`, the two callbacks of `then`, `catch` and `finally`
    (any receiver, a whole chain), a `watchEffect` with `flush: 'post'`, or a `watch` **without a literal `immediate: true`**. An
    `immediate` `watch` passes, posted or not: its **first** run is synchronous in `setup`, and the later ones answer a change of
    its source, which the guard accepts. **Not seen, in full** (the review holds it): a read sent after an `await`; a sub-component
    rendered later and outside the two folders; a function of another module that loads under a name the filter does not know; a
    function declared in the file and passed by reference (read by its name alone, never by what it calls: seen if the name starts
    with `load` or matches the filter); a hook held in a variable (`const hook = onMounted`) or imported as a namespace
    (`Vue.onMounted`); a `vue-router` hook under an import alias; `promise['then']`; an alias set by an assignment
    (`const refresh = load`); a callback handed to a third-party function (`debounce(load)`, `emitter.on('x', load)`).
    Any other refusal, read or write, brings back `common.accessRefused` (R112, decision 3 of the tenth review, amending the
    letter of decision 1 of the ninth: no load window, no counter).
  - **Accepted limits** (Q3 (a) of the twelfth review, R143): the module cannot tell a read the person relaunched from one
    the page started alone, so a read sent after an `await` before `get`, or by a sub-component rendered later, closes the
    landing load all the same. The text shown next is `common.accessRefused`, which is true too. No admin page has a
    « Réessayer »: nothing here depends on one.

  `useAdminLoad` (no `hasError`, nothing re-read on return) and `visibleMutationError` (`null`, the mutation resolves
  `false`, the input stays) keep it quiet like `SessionEndedError`; **one alert**, `AccessRefusedNotice` in
  `AdminLayout` next to the banner, says it with « Changer de compte » in **the same tab** (the author's decision of
  2026-10-29: as R7, accepting that following it loses what is typed). It is shown while the count is above 0 **and**
  the session has not ended (a re-read that finds the session ended leaves the banner alone); a further refusal
  replaces the message (the `key` is on the `<span>`, the link is never destroyed). `admin.notLoaded` is neutral
  (« see the message at the top of the page »): a page whose read was refused is not « session ended ».
- **The message is neutral** (`common.accessRefused`): « the account may not have, or may no longer have, trusted
  access, **or the session needs to be reopened** » — a stale CSRF signature is a 403 too, and must not accuse an account
  that is still trusted (S8).
- **« Changer de compte »** (`common.switchAccount`, `switchAccountLocation`: `login?redirect=<this page>&reauth=1`) is the
  only link that opens the login to a signed-in person, and the new cookies it sets also mend a stale signature. **The
  same tab** on the three pages: the consent is a step of an OAuth flow (see
  `frontend-session-bandeau.md`, « The interface »), and on the assistant the lost
  conversation is accepted (the author's decision of 2026-10-29); « Mes connexions MCP » keeps no input to protect.
  This differs from the ended-session link (`SignInAgainLink`, a new tab, on the assistant and the MCP page; the same tab on the consent).
- **No « Réessayer »** on the MCP page (the same request takes the same verdict) and **every « Révoquer » disabled**; the
  focus goes to the `<h1>` on any refusal of a revocation. `useMcpConnections.refusal`
  (`'session-ended' | 'forbidden' | null`, reset at the start of each `load()` and `revoke()`) replaced `sessionExpired`.
- The login page opened by `reauth=1` announces a **neutral** `auth.reauthNotice` (the ten-minute rule is said by the
  consent's own alert, before its link).

**Limits removed by R7 and R8**: the header of an account stripped of `ROLE_TRUSTED` no longer stays at the trusted tier
until the next full load; a `GET` in 403 on the MCP page no longer shows the generic failure with a « Réessayer » that
cannot help; and the header no longer flickers while `/api/me` is read after a refusal (R8: the re-reads never touch
`isChecking`, see « The re-check on return » in `frontend-session-retour.md`); and, since
R15, a header left anonymous without `sessionEnded` — a refusal
whose `/api/me` answers 401 now ends the session on the interface. **Still accepted**: a refusal leaves the page it came from.

## Tests

- The backoffice's reaction and its counter:
  - `backofficeSessionExpiry.spec.ts`: the session is closed, no navigation; parallel verdicts of one generation announce once;
    a verdict sent before the return refinishes nothing; `refused` counts the refusal and re-reads the tier once; a read sent
    before a navigation counts and re-reads nothing (R28); a write does (R38).
  - `backofficeAccessRefusal.spec.ts`: the scope, a write's `{ kind: 'write' }`, two successive tests starting from zero.
    The landing load (R122): every read sent before the mount keeps the text, the first one stamped after closes it, and
    `currentRefusalScope()`, a read with no effect, closes nothing (R143). A spec that sends a read reads the scope of its
    stamp, as the fake client does; a probe that sends nothing reads `currentRefusalScope()`, exported for that (R158, Q8,
    R177): through `stampBackofficeRead()` it would close the landing load too, and a test could pass for that reason.
  - `AccessRefusedNotice.spec.ts`: a fragment change keeps the alert, a query change clears it, the same parameters in
    another order keep it (vue-router runs that navigation, the sorted key ignores it, R54).
  - `AdminLayout.sessionEnded.spec.ts`: a refused save back after a navigation is shown on the next page; after an account
    switch the late 403 of the first account's save shows nothing (R48); the landing load on a real two-list page (R122).
  - A write refused after the layout unmounted shows at the next mount with `common.earlierSaveRefused`, a read's is
    forgotten (R64); another account at the mount sees nothing; every read sent before the mount keeps the text, a read sent
    after it brings back the current one (R76, R112, R122), in `AccessRefusedNotice.spec.ts`,
    `backofficeAccessRefusal.spec.ts` and `backofficeSessionExpiry.spec.ts`.
  - A refusal is dropped only for another account, the same account's counts across an end and a return (R63:
    `backofficeSessionExpiry.spec.ts`, `AdminLayout.sessionEnded.spec.ts`); another account read outside an ended session
    advances the generation before the signal (`useAuth.spec.ts`).
- For the real refusal (R7): `HttpMcpConnectionsRepository.spec.ts` (a 403 on the read, or on the `DELETE` with a token, is
  `AccessForbiddenError`; without a token, `SessionEndedError`), `useMcpConnections.spec.ts` (`refusal`, `/api/me` read once, a demoted
  account's header back at the base tier, a failing `/api/me` swallowed; R15: one `/api/me` for a read and a revocation refused together, a
  401 turning `forbidden` into `session-ended`), `useAuth.spec.ts` (`rereadAfterRefusal`: one read in flight, 401 ends the session, 403
  holds it, a stale or failing read holds it, `isChecking` untouched; `followRefusal`: ended and still shown, replaced meanwhile, held,
  R27), `useAssistant.spec.ts` and `useOAuthConsent.spec.ts` (`/api/me` read once on `forbidden`, not on an ended session; a 401 on that
  read says the session ended), `McpConnectionsPage.spec.ts` / `AssistantPage.spec.ts` / `OAuthConsentPage.spec.ts` (the exact neutral text
  in both languages, « Changer de compte » with `reauth=1`, no « Réessayer », « Révoquer »
  disabled) and `LoginPage.spec.ts` (the neutral announcement).
- `noLoadInOnMounted.spec.ts` (R121, R144, R178: no load or repository call after the mount of a backoffice page or component, which
  would carry the mounted alert's epoch; one fake file per form it sees, in `tests/support/sourceText.spec.ts` for the reader).
- The reactions `useMcpConnections.spec.ts`, `useOAuthConsent.spec.ts`, `useAssistant.spec.ts`.
