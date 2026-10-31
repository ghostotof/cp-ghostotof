---
paths:
  - "frontend/src/*/admin/**"
  - "frontend/src/*/account/**"
  - "frontend/src/presentation/pages/admin/**"
  - "frontend/src/presentation/ui/admin/**"
  - "frontend/src/presentation/router/**"
  - "frontend/src/presentation/pages/SetPasswordPage.vue"
  - "frontend/tests/infrastructure/admin/shared/BackofficeHttpClient.spec.ts"
  - "frontend/tests/presentation/router/backofficeSessionExpiry.spec.ts"
  - "frontend/tests/presentation/ui/admin/SessionEndedBanner.spec.ts"
  - "frontend/tests/presentation/layout/usePublishedHeight.spec.ts"
  - "frontend/tests/presentation/layout/AdminLayout.sessionEnded.spec.ts"
  - "frontend/tests/infrastructure/admin/sessionEndedMapping.spec.ts"
  - "frontend/tests/application/admin/sessionEndedLoad.spec.ts"
  - "frontend/tests/presentation/pages/admin/emptyStateNeedsLoaded.spec.ts"
---

# Frontend — backoffice (`/admin`) and set-password

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Frontend architecture › Backoffice ». The index of every rule is at the top of `.claude/CLAUDE.md`.

#### Backoffice (`/admin`, `ROLE_SUPER`)

Content/user management UI, mirrored per-resource under `domain/admin/<resource>/{entities,repositories,errors}`
→ `infrastructure/admin/<resource>/Http*Repository.ts` → `application/admin/<resource>/use*.ts` →
`presentation/pages/admin/Admin*Page.vue` (form + Bootstrap table, `window.confirm()` for deletes — no modals).
Existing resources: `technologies`, `quality` (principles + traits), `contributions`, `incidents`, `anonymousCv`,
`caseStudies` (the two base-tier contents of ADR 0003 D5, both prose-only, editorial rule reminded above the
form: no client or employer name — no filter does it for you), `about` (settings + site cards +
me cards), `watch` (tracked products + the `ROLE_SUPER`-only vulnerability detail, read-only), `users` (list + **invite by email** + change-password + promote/demote + resend invitation + delete;
direct username+password creation stays CLI-only). `AdminUsersPage.vue` disables the delete and role buttons on
the current user's own row (compared by `username` via `useAuth()`); the `email` column shows the linked address
or a dash. The `domain/account` + `application/account/useAccountPasswordSetup` + `presentation/pages/SetPasswordPage.vue`
slice is the **public** counterpart: route `/(fr|en)/set-password` (`meta.noindex`, no `requiresAuth`),
`useAccountPasswordSetup` state machine (`checking|ready|submitting|done|incomplete|unusable|error`), talking to the
two public `POST /api/account/password-setup(/validate)` endpoints — a 422 on `validate` means "incomplete link",
a 422 on the completion means "password refused", and they must keep being told apart. **`unusable` is one
screen for every dead link** (issue #362): the backend answers the same 404 whether the link is unknown,
replaced, purged, expired or already used, so the page never tries to tell them apart — its message covers
each case (reopen the link from the e-mail, else ask for a new one) and, outside the `role="alert"` region,
offers « Vous avez déjà défini votre mot de passe ? Se connecter ». A 410 is read as `unusable` too: that is
what a backend from before #362 answers, during a rollout or after a rollback. `incomplete` keeps its own
message, for what the URL alone reveals (no fragment, a 422 on `validate`).

**The invitation token reaches the page in the URL *fragment*, and leaves the URL immediately** (audit A7,
T4.2). The emailed link is `…/{locale}/set-password#<token>`: a fragment is never sent to the server, so it
reaches neither the frontend nginx's access log nor the ingress's — the same reason the token moved out of the
API path. Three things follow, none of them optional:
- the page reads the fragment and nothing else; the token then lives **in memory only**. The `:token?`
  path segment that served as a 48 h fallback for links sent before the fragment was **removed** (T4.4,
  2026-09-26): `…/set-password/<anything>` is now a router 404, and that is the point — a secret must not be
  able to arrive through the path at all. Don't reintroduce a segment "for compatibility";
- it is erased from the URL with **`router.replace`, never `history.replaceState`** — vue-router stores the
  `fullPath` in `history.state`, so a `replaceState` that preserved that state would preserve the token with it;
- `<link rel="canonical">` and the `hreflang` alternates are built by `presentation/router/seo.ts` from
  `to.path`, which never contains the fragment, so nothing special is needed to keep the token out of the DOM
  (the `meta.canonicalPath` override that protected the old segment went away with it — a route that ever
  carried a secret in its path would be the bug, not a reason to bring it back). The router's scroll/anchor
  handling skips the `set-password` hash — it is a token, not an anchor.
No token at all ⇒ `incomplete` with **no network call**. `SetPasswordPage.spec.ts`, `router/seo.spec.ts` and
`router/adminGuard.spec.ts` (the 404 on an old segment link) pin all of it.

**Ordering and translation groups** (spec 0004, `v0.12.0`): every ordered admin page (Incidents,
Contributions, CV sans identité, Quality ×2, About site cards + one table per me-card category, Watch) is
wired on the same shared bricks, and a new page must reuse them rather than grow its own copy —
they carry the security-neutral but a11y-critical behaviour (keyboard alternative, focus return,
status announcement) that a fork would silently lose:
- `domain/admin/shared/ordering/` — `groupByTranslationGroup` (entries × `SUPPORTED_LOCALES` → one row
  per group with `byLocale`/`missing`), `orderRowsByDraft`, `moveKey`, `translationGroupSelection`
  (`translationGroupOptions`/`firstEntry`/`hasSibling`/`rowLines`, the "Version of" rule: entries of
  **any other locale** whose group lacks the form's locale — nothing names FR or EN), all framework-free.
- `infrastructure/admin/shared/orderProblems.ts` — **the two 422 slugs of the exact-set rule** (D4,
  `.claude/rules/portfolio-ordre-ecritures.md`): `unknown-order-entry` (a key the server no longer
  knows, an entry deleted since the load) and `incomplete-order` (a key missing, an entry created
  since), listed once in `STALE_ORDER_PROBLEM_TYPES` and matched on the problem+json `type` by
  `hasProblemType` (which lives in `infrastructure/http/problemJson.ts` with `ApiProblemBody`, shared with the MCP
  repositories), never on the localized `detail`. Every ordered repository reads a 422 carrying
  either as `AdminOrderError('stale-order')`, anything else as `unknown`. A slug renamed on the backend
  is renamed here in the same change: otherwise a concurrent edit reads as a failed save that the
  admin retries in vain instead of a reload.
- `application/admin/shared/` — `useOrderDraft` (the draft follows the server keys until the first
  `move`, then detaches until `reset`/`save`; on a 422 `stale-order` it reloads and re-syncs, on any
  other error it keeps the draft so the admin can retry), `useRowDragAndDrop` (native HTML5, the
  dragged index lives in the composable, **never in `dataTransfer`** — jsdom has none, and that is
  what keeps the flow testable with plain events), `useOrderHandleFocus` (one instance **per table**,
  focus back on the moved row's handle after ↑/↓; the `ref` callback is a stable function keyed on
  `data-order-key`, never an inline lambda).
- `presentation/ui/admin/OrderHandle.vue` (a real `<button>`, ↑/↓, the arrows described by
  `aria-describedby` to a rendered hint, issue #170 F2) and `OrderToolbar.vue` (status, Cancel, "Save
  order", the order error, **and the one `role="status"` live region of the table**, fed by
  `useOrderHandleFocus().lastMove` — it used to live in the handle, i.e. inside the moved `<tr>`, which
  is re-parented in the same render cycle and can swallow the announcement, #170 F3; Cancel stays
  enabled while an order error is shown, so a `stale-order` alert can be dismissed, #170 F4), i18n
  under `admin.order.*`.
- **Page rules**: the table shows **every language** (D8 — the row is the group, languages stacked in the
  content cell, a missing one reads "Missing translation"); **the per-language actions live in a last
  "Actions" column** (Edit/Delete, or "Create the XX version" which opens a creation already attached to
  the group with the non-prose fields copied), stacked with the same `v-for` and the shared
  `.admin-locale-line` class (`style.css`, a common `min-height`) so each language stays in front of its
  buttons — the specs locate a line's buttons by index in `td:last-child`, keep that structure; **one locale per form**, never a page-level locale selector shared
  by several forms (B9 lesson: it silently overwrote the locale of an entry being edited in the other
  panel; About's top selector only drives the *settings* singleton). While the order draft is dirty,
  **every mutation is disabled** (Edit, Delete, Create version, submit, the translate button) with a
  visible hint referenced by `aria-describedby` — never a `title`, Bootstrap's `.btn:disabled` has
  `pointer-events: none` — and leaving the route asks `window.confirm`, closing the tab goes through
  `beforeunload` — both in `application/admin/shared/useUnsavedOrderGuard(isDirty)` (#170 F5), tested once
  in its own spec (unmounted by the global `enableAutoUnmount` of `tests/support/setup.ts`; a page left mounted keeps its listener); a page calls it,
  never re-implements it. `startEdit` sends back the group it read whenever the entry has a
  sibling, otherwise `''` → `null`, which `ContentPlacement::detach` treats as a no-op on a lone
  entry. The `Position` number field is gone from every form; `BaseNumberInput` survives only for
  genuinely numeric content (years of experience).
- **What a `PUT` cannot change is disabled in edit, not hidden**: the locale of every per-entry form
  (#170 R2; the server refuses another one with a 409 of type `locale-immutable` since #582, which no
  repository maps — nothing in the UI can send it, so it would read as `translation-already-exists`) and
  the **category of a me-card**
  (issue #578). The category used to stay active: changing it emptied « Version de » (the
  `flush: 'sync'` watcher, meant for creation), the `PUT` left with `translationGroup: null`, and the
  server, which ignored the category, detached the card from its translation and sent it to the end of
  its category, under a 200. It is now disabled in editing (and, since R79b, while the form holds a translation
  proposal), with a visible hint
  (`admin.about.meCard.categoryLockedHelp`: create the card in the right category, then delete this one)
  referenced by `aria-describedby` **only while the lock holds** (editing; during a translation proposal the
  category is described by the form's proposal text instead, R93) — the hint is not rendered otherwise, and an IDREF to a missing element
  is an a11y fault. The server refuses another category with a 409 of type
  `me-card-category-immutable` (`.claude/rules/portfolio-ordre-ecritures.md`), which
  `HttpAdminAboutMeCardRepository` reads as the reason `category-immutable` — keyed on the `type`, the
  other 409 of the cards staying `translation-already-exists`; only a SPA cached from before #578 can
  still send that body. `AdminAboutPage.spec.ts` pins the gesture (red before: `category: 'hobby'`,
  `translationGroup: null`), the release on « Annuler » and the message;
  `HttpAdminAboutMeCardRepository.spec.ts` the mapping.
- **A locale selector says why it is locked, and the help it always has** (#582; #748, R79b D-a, R92): About's settings
  selector (`AdminAboutPage.vue`) carries a static `aria-describedby` to its always-rendered help; the per-entry forms
  (site and me cards, Quality's principles and traits, which also always render a help, and the four others) bind it.
  Every per-entry form's lock goes through `presentation/ui/admin/useEntryFormLock.ts` (`state`: `'none' | 'editing' |
  'proposal'`), whose `EntryFormLockHint.vue` text is rendered **only during the lock** and referenced by
  `aria-describedby` only then, after the permanent help when there is one (`localeDescribedBy`: one or two ids); one
  text per state. A proposal is born of an edit, of « Créer la version XX » or of a blank form, and the language lock
  holds in the three (the proposed prose is in the target language). `tests/support/entryFormLock.ts`
  (`describedTexts()`, `describeEntryFormLock()`) reads the texts an `aria-describedby` designates, fails on a dangling
  IDREF and runs the lock cases on the eight forms (it creates each case's translation repository and counts its
  calls); `AdminAboutPage.spec.ts` and `AdminQualityPage.spec.ts` pin the
  permanent help.
- **A me-card group spread across two categories is not signalled by the backoffice, by decision**
  (issue #573). Such a group (FR in one category, EN in another) predates #501's guard; the backend
  refuses, before any write, every write that would add a member to it or reorder one of its categories
  (#561, `.claude/rules/portfolio-ordre-ecritures.md`) — a text edit and the detachment stay accepted.
  What the admin sees if one ever exists: editing one of its members, « Version de » **looks empty** —
  `startEdit` keeps the card's own group (`hasSibling` looks across categories) while the options only
  list the form's category (`translationGroupOptions` keeps a retained group only when an entry of
  another locale in `entries` carries it); « Créer la version XX » on its row and « Version de »
  towards it from the other category end on a 500 read as the generic `unknown` (« Une erreur est
  survenue. Réessayez plus tard. »), and **any** reorder of either category on « L'enregistrement de
  l'ordre a échoué. Réessayez plus tard. » — retrying never helps. The fix: **edit the stray card,
  choose « — aucune — », save** (a `PUT` with `translationGroup: null`, which the server reads across
  categories and turns into a real detachment, #561), or delete it and recreate its version in the
  right category; saving a member without touching the selector sends its own group, a no-op, so the
  cards stay editable. Why no detection, badge or disabled actions: the application cannot produce such
  a group (no category setter; « Créer la version » copies the category; « Version de » is filtered;
  the server answers 422); `Version20261012120000` (#390), which reports every spread group, gave no
  warning at the v0.24.0 production deploy (an operational record, not provable from the repository);
  once `Version20261012160000` (#501) is deployed, the database refuses at COMMIT any write creating
  one — bar the accepted limit of two concurrent SQL transactions that skip the scope lock —, so only a
  group older than it can remain, and that migration lists each in a `warning`; the backend's
  `critical` log names the group, its categories and the fix. Rejected: an alert listing the spread
  groups with a guided option in the selector (code and i18n for a state that cannot arise), plus
  disabling the refused actions (the order bricks shared by every page, and the backend rule copied
  into the frontend), a typed 409 from the backend (reverses #561's named 500, loses the `critical`
  alert, and a static `detail` could not name the group). **Reopen it** if the migration of #501
  reports « groupe de traduction … réparti sur plusieurs valeurs de category » in preprod or prod (fix
  it as above first: that frozen warning only names delete-and-recreate, detaching through « aucune »
  works as well since #561), or if the trigger is ever disabled. `AdminAboutPage.spec.ts` (« groupe
  réparti… ») pins, for both members, the empty-looking selector, the own group sent back and the detachment
  through « aucune »; it turns red if `startEdit` looks for a sibling in the form's category only, which would
  make a text edit detach the card.

**Translation assistant** (ADR 0004 phase 1, spec 0002): one more admin slice, `domain/admin/translation`
(`TranslationProposal`, `AdminTranslationError` with reasons `validation|rate-limited|unavailable|unknown`)
→ `infrastructure/admin/translation/HttpAdminTranslationRepository.ts` (`POST /api/backoffice/translations`)
→ `application/admin/translation/useAdminTranslation.ts` (`translate()` returns the proposal or `null` and
exposes `errorReason`; **it never touches a form nor persists anything**, ADR 0004 D4) + the two helpers in
`proseFields.ts` (`collectProseFields` drops blank fields, the API refuses them with a 422;
`applyTranslationProposal` leaves a field absent from the proposal untouched) → `presentation/ui/admin/
TranslateEntryButton.vue` (label follows the form's locale, `aria-busy` while calling, emits `translate`).
**Each page alone decides which of its fields are prose** (spec D2 — the backend is content-agnostic) and
what to do with the proposal. Since spec 0004 every per-entry form (Incidents, Contributions, CV sans identité,
Quality principles/traits, About site/me cards) behaves the same way: the form switches to *creation* in
the target locale, the non-prose fields (`version`, `occurredAt`, `iconKey`…) are kept, **the source
entry's `translationGroup` is kept too** (spec 0004 D9, `sourceGroup` — distinct from the selector's
value, so a lone source still attaches its proposal) so saving the proposed version links it with no
extra gesture, and a `role="status"` banner names the proposal's source locale; while the form
holds the proposal its locale selector is locked, as in editing (R78, R79, Sp7-1: the
kept group would make a second version of the source locale), and so are « Proposer la version XX » (R93, decision 1
of the eighth review: a second translation took the form back to the source locale, group kept; `useAdminTranslation`
refuses the call itself, not only the disabled button: whenever that button is disabled (`isRefused`, one term per
condition of its `:disabled`: no prose to translate, a save in flight, a dirty order draft or the page's order lock, the
form's proposal lock; for the About settings a proposal shown, R127, R127b) and while a call is in flight, and a
response whose form no longer has the identity read at send time (the entry, the locale, the source group:
`formIdentity`, never the prose) is discarded, whatever gesture replaced it and even if the form later regains that
identity, a deleted entry being discarded by `discard()` (R124); **one model call in flight per form**: a discarded
call still running refuses the next one (`isDiscardPending`, R125, decision 2 of the eleventh review: no `AbortSignal`, the
call already left costs what it costs), and the button says why it waits through a text referenced by `aria-describedby`
only while it waits, `admin.translation.discardPending`), « Version de » (R94,
decision 3: « aucune » would detach the proposal from its source) and the me-card
**category** selector (R79b, D-b: a version in the other language does not change category, #501/#561; « Créer la
version XX » keeps the category free, by decision), all described by one text, `admin.entryLock.proposal`, which says
that « Annuler » drops the proposal and empties the form (R93, CR8-4). **While a translation runs, the form's prose fields
are `readonly`** (not `disabled`: readable and focusable), each described by `admin.translation.proseReadonly`
(`TranslationReadonlyHint`), rendered only then: the answer replaces prose nobody could change meanwhile, which is why the
prose is out of `formIdentity` and a failed translation's message (429, 503) survives typing, cleared only by a real change
of form or the next translation (R126, decision 3 of the eleventh review; the author's decision of 2026-10-31). A proposal
whose target locale the group already has (from « Créer la version XX », or from an entry whose group has both) ends on the server's 409
`translation-already-exists` at save, « Annuler » being the way out (accepted, Q1 of the eighth review: detecting it
would copy the server's uniqueness rule, as #573 refused). The old "page-locale"
semantics is gone with the page-level selector (one locale per form, see above). About *settings* is
the one singleton per locale, so its proposal is **deferred**: parked in `pendingProposal`, applied on the
return of `load()` for the target locale (hence the `flush: 'sync'` watcher, or the copy from the server
would overwrite it). The button is disabled while an order draft is dirty (a proposal the locked form could
not save would burn quota for nothing) and, for the settings, while a proposal is shown (a second translation would
replace it with no « Annuler »), said by `admin.about.settingsProposalLockedHelp`, which names « Abandonner » (R127c):
a `type="button"` shown only during the proposal, no confirmation, that drops it and re-reads the shown language's form
(saved values back, focus to the first field, not announced like the per-entry « Annuler »); switching language alone
keeps the proposal, only the return to its target re-reads it (R103).
Never use `v-html` on text coming back from the model: it goes
through the form fields, then `RichText.vue`. The case-studies admin page (`/admin/case-studies`, issue #104,
closed 2026-09-14) is wired exactly like the CV sans identité one — every field is prose, so "Create the XX
version" copies nothing and the assistant sends all five fields.

- `presentation/ui/{BaseTextInput,BaseTextarea,BaseNumberInput,BaseSelect}.vue` — the project's first reusable
  form components, used by every admin form. Reach for these before writing a new raw `<input>` in `admin/*`.
- `presentation/layout/AdminLayout.vue` — sub-navigation across the admin sections, rendered for every
  `/admin/*` route.
- **Route protection**: `RouteMeta` carries `requiresAuth?: boolean` and `roles?: readonly string[]`; every
  `/admin/*` route sets `{ requiresAuth: true, roles: [ROLE_SUPER] }`. A `router.beforeEach` guard in
  `presentation/router/index.ts` checks this: unauthenticated → redirect to `login` with
  `query: { redirect: to.fullPath }` (consumed by `LoginPage.vue`'s `redirectTarget()`, which only honors an internal
  path, `internalRedirectTarget()` — open-redirect protection, `.claude/rules/frontend.md`); authenticated but missing the role → redirect to
  `presentation/pages/ForbiddenPage.vue`.
  **Must call `await waitForAuthCheck()`** (`application/auth/useAuth.ts`) before making that decision — on a
  page reload/direct navigation, the guard can otherwise run before `main.ts`'s `checkAuth()` has resolved and
  wrongly redirect an actually-authenticated user to `/login` (regression-tested in
  `tests/presentation/router/adminGuard.spec.ts`). `hasRole(user, role)` (`domain/auth/services/hasRole.ts`) is a
  plain function (no Vue `inject()`) so it also works inside this router guard, outside component context.
- **No `requiresAuth` route carries a secret in its query or its fragment** (#748, R6, S6). When a session ends on a
  protected page, the sign-in-again link (`SignInAgainLink`, `.claude/rules/frontend-session.md`) opens a new tab whose URL
  holds `redirect=<the page's fullPath>`: that path, query and fragment included, reaches the frontend's web server (nginx
  logs the request line) and the browser history. A protected route whose query or fragment held a secret (an invitation
  token, a code, a key) would send it there. The guard is `tests/presentation/router/protectedRoutesCarryNoSecret.spec.ts`:
  `REVIEWED_PROTECTED_ROUTES` names every `requiresAuth` route (the router's, read-only, merged `meta` of the parent
  included, so the `admin-*` children) with the reason its query and fragment carry no secret; a route added without that
  review turns the suite red, and the message says why. `oauth-consent` is the one whose query matters (`client_id`,
  `redirect_uri`, `state`, `code_challenge`): none is a secret (the PKCE challenge is a digest, `state` the client's own
  anti-CSRF value) and the navigation that opened the page already sent them to the web server. `set-password`, whose
  fragment holds the invitation token, is **not** protected (see above), and the same spec pins that: giving it
  `requiresAuth` would put its token in a logged URL.
- **A 401, or a 403 to a write sent without a CSRF token, mid-page ends the session on the interface, and moves
  nobody** (#650, R1c, #748). `BackofficeHttpClient` (`get` and `mutate`) sends through
  `infrastructure/http/sessionAwareFetch.ts`, calls the reaction registered by `main.ts`
  (`setBackofficeSessionReaction()`: `markSessionExpired(sentAt.session)`, judged by session generation, and no navigation) and **raises `SessionEndedError`** (or, for a response sent before the session was restored, hands it back as an ordinary failure, #748 R14): no
  repository translates it, and the composables keep it quiet through `useAdminLoad` (reads) and `visibleMutationError`
  (mutations), the banner being the only announcement. **A new administration client goes through
  `BackofficeHttpClient`, a new composable through those two functions, and its mutations resolve to `true` only on
  success** (a page resets its form on that, never on `errorMessage`). **Any other 403** (a read, or a write that carried its token) raises `AccessForbiddenError` after the reaction's `refused()` (#748, R17; a read sent before a navigation is not counted, R28; a write always is, R38): kept quiet the same way, and `AccessRefusedNotice` in `AdminLayout` says it once (`common.accessRefused`, « Changer de compte » in the same tab). The rule, its backend facts, the shared errors, the
  banner, the header's link and the re-check on return: `.claude/rules/frontend-session.md`.
- **Not loaded is not empty, a new key empties the old value, and what an ended session interrupted is read again on
  return** (#748, R4). `useAdminLoad` exposes `isLoaded` (the value of the current key was read and set: false before the
  first read, after a first read that ended on `SessionEndedError`, and as soon as a new key starts reading); an
  administration page says « aucune … » **only when `isLoaded`**, and shows `admin.notLoaded` (« Contenu non chargé… »,
  not a live region: the banner or the « accès refusé » alert announces; the text is neutral) otherwise. Without it a first read in an ended session left the list at `[]`
  with no error, and twelve empty states claimed the content did not exist. `emptyStateNeedsLoaded.spec.ts` is the source
  guard: every file of `presentation/pages/admin` that composes a `…empty` state reads `isLoaded` at least as many times in
  its template. A new list page adds the `isLoaded` guard before its empty state.
  **The About settings are the one composable with a key** (the locale), hence `clear`: a `load(key)` with a key other
  than the held value's empties it (`settings = null`) *before* reading. Otherwise the form of one locale stayed under
  the header of another when the read of the new one ended in an ended session, and « Enregistrer » sent `save(props.locale)`
  — the French texts over the English record, silently (CR2). `AdminAboutSettingsForm` renders the form only if `isLoaded`,
  and a pending translation proposal is set by a synchronous watcher of `isLoaded` (not after `await load()`: the read
  that ended the session never resolves a value), so it lands on the form it targets, at the return if need be.
  **The key guards the writes too, and it has one source** (#748, R11, R20): the current key is the last one given to
  `load()`, held by `useAdminLoad` (`isCurrentKey(key)`, reactive; no composable keeps a copy). A save's response goes
  through `write(key, value)`: it replaces the value only under the current key, and then **invalidates the reads in
  flight of that key** — a `GET` served before the `PUT`'s COMMIT answers the old values and would put them back over
  the new ones (FR saved slowly, EN, back to FR: F10-1). A save sent in French and answered after the page went to
  English has succeeded server-side (`save()` returns `true`), but must not put the French values under the English
  header: the next « Enregistrer » would overwrite the English record. **The error stays with its language** (F10-2):
  `errorMessage` is the failure of the current locale's last save (R31, Sp3-4: **one error per locale**, `failures`), and
  comes back on returning to that locale, until **that locale's** next save: a save of one locale neither clears nor
  replaces the other's error (a failed FR save, then a successful or failed EN one, leaves the FR error for the way back).
  **One save per locale in flight** (R50, the author's decision of 2026-10-30, CR5-3/CR5-4): `save(locale)` refuses a
  second save of a locale while its first is in flight (no request, `false`), so the response that arrives is always the
  only one of its locale. The form disables « Enregistrer » for the locale shown only (`isSaving`, the current key's: an English save is not
  held by a French one), and « Proposer la traduction » while any locale — with two, the one shown or the target — has a save in
  flight (`isAnySaving`, R67, R86, R98, decision 3 of the sixth review: the target's response would overwrite the proposal it
  lands on; a save of the target started while the translation runs still does, and the notice goes with it, R66).
  Reloading a locale never clears its error: the form copies the settings read, so that error is the only trace of the refused save.
  **A proposal notice is its target locale's** (F11, R50, R66, decision 4 of the sixth review): `proposal`
  (`{ source, target }`) shows under the form of `target` only, survives a refused save, and goes whenever that
  locale's form is re-read from the server — the response of its successful save, a reload, a return to it —, which
  loses the proposal; settings of the other locale arriving leave it (R103, decision 2 of the ninth review); it shows only
  under a loaded form (`isLoaded`), so a refused or interrupted re-read of its target shows nothing (R113). The About
  settings are the only keyed composable, so the only one with these races.
  **The return is the one signal that exists** (R13): `useAuth().onSessionRestored(callback)`, called once at each
  descent of `authState.sessionEnded` by the same account (another one replays the navigation instead, R18). `useAdminLoad` subscribes to it; if its
  last read ended on `SessionEndedError`, it re-runs `load(same key)` **once**. A newer `load()` replaces the
  interrupted one; a read that succeeded leaves nothing to replay. A mechanism that re-reads the session (the shared
  re-check on focus) only has to restore it: it never re-reads lists itself, so nothing is doubled. A page does not
  `watch` `authState.sessionEnded` for this.

