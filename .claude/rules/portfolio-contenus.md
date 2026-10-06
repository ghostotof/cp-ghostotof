---
paths:
  - "backend/src/Portfolio/**"
  - "backend/tests/Portfolio/**"
  - "frontend/src/presentation/pages/**"
  - "frontend/src/presentation/ui/**"
---

# Backend — `Portfolio` content contexts

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Portfolio/Shared/` to `Portfolio/Incident/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **`Portfolio/Shared/`** — `Domain/ValueObject/Locale.php`, the `enum Locale: string { FR = 'fr'; EN = 'en' }`
  shared by every `Portfolio/*` context. Two entry points, and the distinction matters (audit I3):
  - **`Locale::fromString()` for anything coming from outside** (a `{locale}` URL segment, a command
    argument). It throws `InvalidLocaleException` (`Portfolio/Shared/Domain/Exception/`), the only thing mapped
    to 404 in `exception_to_status`. Providers/Processors get it in one step via `uriVariableLocale()` on the
    `ResolvesUriVariables` trait — use that rather than re-deriving it.
  - **`Locale::from()` stays for already-validated values** (backoffice DTO fields, bounded upstream by
    `#[Assert\Choice]`, or values read back from the DB). A `\ValueError` there is a genuine bug and must
    surface as a 500.
  - There used to be a blanket `ValueError: 404` mapping. It has been **removed and must not come back**: it
    disguised *every* `ValueError` in the HTTP stack as a plausible "404 Not Found", which is exactly how a
    real defect goes unnoticed.
  - **Content ordering and translation groups** (spec 0004, `v0.12.0`) also live here, because nine
    contexts share one rule. The eight localized entities with a position (`QualityPrinciple`,
    `QualityTrait`, `AboutSiteCard`, `AboutMeCard`, `Contribution`, `Incident`, `AnonymousCvSection`,
    `CaseStudy`) carry `translation_group uuid NOT NULL` with a unique `(translation_group, locale)`
    index (D1): two rows of the same group are the same content in two languages, and the group is an
    **identifier, not an entity** — the day something needs to hang *on the group*, that UUID becomes
    the primary key of a new table and the rows already reference it. **Nothing hard-wires the FR/EN
    pair** (D2): every locale `#[Assert\Choice]` points at `Locale::values()`, `AboutMeCardCategory`'s
    at its own `values()`, the frontend renders its language badges from `SUPPORTED_LOCALES` — a third
    language is a third row per group. **`position` is never typed in** (D3): it is out of every write
    DTO (`#[ApiProperty(writable: false)]`, a body still carrying it is accepted and ignored) and every
    `create`/`update` signature; `Domain/Service/ContentPlacement` derives it (a new entry without a
    group takes `max + 1` of its scope, one attached to a group **inherits the group's position**;
    `detach()` on a `PUT` with `translationGroup: null` detaches **and moves the entry to the end of
    its scope** (issue #169 — keeping the position let the old group receive that locale again via
    "Create the XX version" and inherit the same position: two keys on one position), and is a no-op
    on an entry already alone; `reattach()` with a group inherits its position; `inGroup()` throws a
    `LogicException` on a group whose members disagree on the position, a pipeline bug, never a 4xx). `WatchedProduct` is not localized, so it is `Orderable` on its
    own id, and its `Administrator` computes the end of the catalogue itself. The **only client-driven
    writer of `position` is `PUT /api/backoffice/<x>/order`** (`ContentPlacement` derives it server-side,
    no request body ever chooses it) (`Backoffice<X>OrderResource`, `read: false`,
    `output: false`, 204, one per context, `{groups: [uuid…]}` — `{ids: […]}` for Watch, plus
    `category` for me-cards): `Domain/Service/OrderAssigner` enforces the **exact-set rule** (D4) —
    an unknown key is 422 `unknown-order-entry`, a missing one 422 `incomplete-order`, both
    `ProblemExceptionInterface` slugs the frontend keys on — and renumbers `0…n-1` on every entity
    sharing a key. That 422 is not validation, it is optimistic concurrency without a version column:
    a row created or deleted between the page load and the save changes the set, the server refuses,
    the frontend reloads and says so. Never relax it to "ignore unknown keys". The
    `Version20260914170000` migration paired the existing FR/EN rows by `position` (and `category`)
    **only when the pair was unique on both sides**, giving every other row its own group — a wrong
    link would survive every proofreading, an isolated row is one "Version of" click away — and it is
    the first migration of the UUID era that is **reversible**.
- **`Portfolio/Experience/`**, **`Portfolio/Quality/`**, **`Portfolio/About/`**, **`Portfolio/Contribution/`**,
  **`Portfolio/Incident/`** — DB-backed
  content that used to be (or, for `Experience`, always was) hardcoded in the frontend. Each follows the same
  shape: a Doctrine entity per concept (`ExperienceTechnology`; `QualityPrinciple`/`QualityTrait`;
  `AboutSettings`/`AboutSiteCard`/`AboutMeCard`, the latter with an `AboutMeCardCategory` enum), a public
  read-only API Platform resource (`GetCollection('/experience/technologies')`, or an aggregating `Provider` for
  `/quality/{locale}` and `/about/{locale}` that returns `{principles, traits}` / `{settings, siteCards, meCards}`
  in one call), and a backoffice CRUD resource (see `.claude/rules/backoffice-api.md`). Seeded via idempotent `app:{about,quality,contributions,incidents}:seed`
  console commands (purge-by-locale then recreate — safe to rerun).

  `Contribution` is the odd one out and deliberately so: it carries a long `body` (the argument, not
  just a link to it) alongside `title`/`project`/`reference`/`url`/`summary`. That text is **plain
  text**, paragraphs separated by a blank line, rendered by splitting on those blanks —
  `ContributionsPage.vue` never uses `v-html`. The only markup honoured is `` `backticks` ``, turned
  into `<code>` by splitting on a capturing regex, so every segment stays an interpolated text node.
  The content is authored through the backoffice, so treating it as HTML would trade formatting for
  a stored-XSS hole on a public page — there is a regression test pinning that
  (`tests/presentation/pages/ContributionsPage.spec.ts`).

  `Incident` follows the same shape with a post-mortem's fields — `impact`, `rootCause`,
  `resolution` and, crucially, **`invariant`, which is `NOT NULL`**. That constraint is the page's
  editorial line made structural: an entry you cannot save without stating the rule you drew from
  the outage cannot drift into a confession. Don't relax it to "make an entry easier to add".

  Both pages render their prose through **`presentation/ui/RichText.vue`** — paragraphs split on
  blank lines, `` `backticks` `` turned into `<code>`, never `v-html`. It is shared rather than
  duplicated precisely because it carries a security guarantee: a fix applied to one copy would
  silently leave the other exposed. Reach for it for any backoffice-authored prose.
