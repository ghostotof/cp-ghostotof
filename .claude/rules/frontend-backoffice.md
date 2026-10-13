---
paths:
  - "frontend/src/*/admin/**"
  - "frontend/src/*/account/**"
  - "frontend/src/presentation/pages/admin/**"
  - "frontend/src/presentation/ui/admin/**"
  - "frontend/src/presentation/router/**"
  - "frontend/src/presentation/pages/SetPasswordPage.vue"
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
Contributions, Anonymous CV, Quality ×2, About site cards + one table per me-card category, Watch) is
wired on the same shared bricks, and a new page must reuse them rather than grow its own copy —
they carry the security-neutral but a11y-critical behaviour (keyboard alternative, focus return,
status announcement) that a fork would silently lose:
- `domain/admin/shared/ordering/` — `groupByTranslationGroup` (entries × `SUPPORTED_LOCALES` → one row
  per group with `byLocale`/`missing`), `orderRowsByDraft`, `moveKey`, `translationGroupSelection`
  (`translationGroupOptions`/`firstEntry`/`hasSibling`/`rowLines`, the "Version of" rule: entries of
  **any other locale** whose group lacks the form's locale — nothing names FR or EN), all framework-free.
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
  in its own spec with `enableAutoUnmount` (a page left mounted keeps its listener); a page calls it,
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
  its category, under a 200. It is now `:disabled="isEditing"`, with a visible hint
  (`admin.about.meCard.categoryLockedHelp`: create the card in the right category, then delete this one)
  referenced by `aria-describedby` **only while editing** — the hint is not rendered otherwise, and an
  IDREF to a missing element is an a11y fault. The server refuses another category with a 409 of type
  `me-card-category-immutable` (`.claude/rules/portfolio-contenus.md`), which
  `HttpAdminAboutMeCardRepository` reads as the reason `category-immutable` — keyed on the `type`, the
  other 409 of the cards staying `translation-already-exists`; only a SPA cached from before #578 can
  still send that body. `AdminAboutPage.spec.ts` pins the gesture (red before: `category: 'hobby'`,
  `translationGroup: null`), the release on « Annuler » and the message;
  `HttpAdminAboutMeCardRepository.spec.ts` the mapping.
- **A me-card group spread across two categories is not signalled by the backoffice, by decision**
  (issue #573). Such a group (FR in one category, EN in another) predates #501's guard; the backend
  refuses, before any write, every write that would add a member to it or reorder one of its categories
  (#561, `.claude/rules/portfolio-contenus.md`) — a text edit and the detachment stay accepted.
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
(`TranslationDraft`, `AdminTranslationError` with reasons `validation|rate-limited|unavailable|unknown`)
→ `infrastructure/admin/translation/HttpAdminTranslationRepository.ts` (`POST /api/backoffice/translations`)
→ `application/admin/translation/useAdminTranslation.ts` (`translate()` returns the draft or `null` and
exposes `errorReason`; **it never touches a form nor persists anything**, ADR 0004 D4) + the two helpers in
`proseFields.ts` (`collectProseFields` drops blank fields, the API refuses them with a 422;
`applyTranslationDraft` leaves a field absent from the draft untouched) → `presentation/ui/admin/
TranslateEntryButton.vue` (label follows the form's locale, `aria-busy` while calling, emits `translate`).
**Each page alone decides which of its fields are prose** (spec D2 — the backend is content-agnostic) and
what to do with the draft. Since spec 0004 every per-entry form (Incidents, Contributions, Anonymous CV,
Quality principles/traits, About site/me cards) behaves the same way: the form switches to *creation* in
the target locale, the non-prose fields (`version`, `occurredAt`, `iconKey`…) are kept, **the source
entry's `translationGroup` is kept too** (spec 0004 D9, `sourceGroup` — distinct from the selector's
value, so a lone source still attaches its draft) so saving the proposed version links it with no
extra gesture, and a `role="status"` banner names the draft's source locale. The old "page-locale"
semantics is gone with the page-level selector (one locale per form, see above). About *settings* is
the one singleton per locale, so its draft is **deferred**: parked in `pendingDraft`, applied on the
return of `load()` for the target locale (hence the `flush: 'sync'` watcher, or the copy from the server
would overwrite it). The button is disabled while an order draft is dirty (a draft the locked form could
not save would burn quota for nothing). Never use `v-html` on text coming back from the model: it goes
through the form fields, then `RichText.vue`. The case-studies admin page (`/admin/case-studies`, issue #104,
closed 2026-09-14) is wired exactly like the anonymous-CV one — every field is prose, so "Create the XX
version" copies nothing and the assistant sends all five fields.

- `presentation/ui/{BaseTextInput,BaseTextarea,BaseNumberInput,BaseSelect}.vue` — the project's first reusable
  form components, used by every admin form. Reach for these before writing a new raw `<input>` in `admin/*`.
- `presentation/layout/AdminLayout.vue` — sub-navigation across the admin sections, rendered for every
  `/admin/*` route.
- **Route protection**: `RouteMeta` carries `requiresAuth?: boolean` and `roles?: readonly string[]`; every
  `/admin/*` route sets `{ requiresAuth: true, roles: [ROLE_SUPER] }`. A `router.beforeEach` guard in
  `presentation/router/index.ts` checks this: unauthenticated → redirect to `login` with
  `query: { redirect: to.fullPath }` (consumed by `LoginPage.vue`'s `redirectTarget()`, which only honors paths
  starting with a single `/` — open-redirect protection); authenticated but missing the role → redirect to
  `presentation/pages/ForbiddenPage.vue`.
  **Must call `await waitForAuthCheck()`** (`application/auth/useAuth.ts`) before making that decision — on a
  page reload/direct navigation, the guard can otherwise run before `main.ts`'s `checkAuth()` has resolved and
  wrongly redirect an actually-authenticated user to `/login` (regression-tested in
  `tests/presentation/router/adminGuard.spec.ts`). `hasRole(user, role)` (`domain/auth/services/hasRole.ts`) is a
  plain function (no Vue `inject()`) so it also works inside this router guard, outside component context.

