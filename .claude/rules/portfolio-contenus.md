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
    on an entry already alone; `reattach()` with a group inherits its position; `inGroup()` throws
    `TranslationGroupHasSeveralPositionsException` (a `LogicException`, issue #338) on a group whose members disagree on the position, a pipeline bug, never a 4xx — checked **before** the
    "locale already there" 409, issue #384: with two locales the only corrupt group the unique index allows
    is an FR/EN pair, which always carries the requested locale, so the reverse order hid the bug as an `info`;
    when it fires, look at migrations and SQL writes, which bypass the scope lock below). `WatchedProduct` is not localized, so it is `Orderable` on its
    own id, and its `Administrator` computes the end of the catalogue itself.
    **Every write that computes a position runs under its scope's lock** (issue #389): `create()`, `update()`
    and `reorder()` of the nine `Administrator`s wrap their **whole body** in
    `Shared/Application/OrderScopeLockInterface::withLock(ORDER_SCOPE, …)`, `ORDER_SCOPE` being the table
    name (`about_me_card` too, wider than its per-category ordering scope, on purpose). Without it a "Create
    the XX version" landing between a `reorder()`'s read and its save kept the old position (a group on two
    positions, invisible to the exact-set rule), and two simultaneous creations without a group got the same
    `atEndOf()`. The adapter, `Shared/Infrastructure/Doctrine/PostgresAdvisoryOrderScopeLock`, runs the body
    in `Connection::transactional()` whose first statement is `pg_advisory_xact_lock(ORDR, hashtext(scope))`,
    released by the COMMIT/ROLLBACK itself. Three choices not to undo: a **scope** lock, never `SELECT … FOR
    UPDATE` (a blocked one does not see rows the other transaction inserted, and locks nothing on an empty
    table, so the creation race survives it); **not** `lock.factory` (a session lock on a second connection,
    which would have to be released after the ORM's COMMIT); **not** `wrapInTransaction()`, which closes the
    EntityManager on any exception, while a 409 inside the lock is an ordinary outcome. A read made *before*
    `withLock()` escapes it, hence the whole body; an entity already in the identity map (the `PUT` entry,
    loaded by the provider) keeps its pre-lock values, harmless since only its own position is rewritten.
    In an FPM worker the wait is bounded by `PGOPTIONS` `lock_timeout=5s` (a 500 past that). Guards:
    `OrderScopeLockCoverageTest` (a probe session holds each scope, every placing operation of every
    `Administrator` must wait — a new one written without `withLock()` turns it red) and
    `ContributionOrderConcurrencyTest` (two real processes, interleaved at the `preFlush`, both races).
    `delete()` takes no lock: it leaves a gap, never a collision. The **only client-driven
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

  **`ExperienceTechnology.years` is a finite number in `[0, 100]`, checked at three layers** (issue #372).
  A single non-encodable row (`Infinity`, `NaN`) is enough to put the public `GET /api/experience/technologies`
  in 500 for every visitor, and `PositiveOrZero` alone let `{"years":1e999}` (`json_decode` → `INF`) through.
  The rule is written once, in the Value Object `Domain/ValueObject/ExperienceYears` (`MIN`, `MAX`, `fromFloat`,
  `fromString`; `-0.0` is normalised to `0`). The entity, the registrar and the administrator only receive an
  `ExperienceYears`. The backoffice DTO validates through an `Assert\Callback` that delegates to it, so the
  422 names `years` with the domain's message. The CLI command parses `--years` before reaching the
  registrar. **And the database refuses the same bounds** (`ExperienceTechnologyRepository::YEARS_CHECK_CONSTRAINT`,
  migration `Version20261006120000`), because Doctrine never calls the constructor when hydrating, so no PHP
  guard sees a row written in SQL. One gap is accepted: the `CHECK` lets `-0` through (`-0 >= 0`), a value
  only SQL can write and the public list would publish as `"years":-0`. Enforcing it would take an unreadable
  sign test, for a cosmetic defect no write path produces. `ExperienceTechnologyYearsConstraintTest` reads both bounds from the Value Object and
  probes a billionth past each, so a constraint loosened or tightened by a hair turns it red. A test that must
  write a row the database now refuses lifts the constraint in a rolled-back transaction through the
  `LiftsExperienceYearsConstraint` trait. Never drop it for good, and never write that `ALTER TABLE` by hand.
  That migration clamped any existing faulty row **and set it `secondary`**, reporting each one as a
  `warning` with its original value in the migration Job's output. The invented duration is therefore never
  **displayed on the site**, since `ExperiencePage` shows no duration for a secondary technology, and the row
  stays one backoffice edit away from being fixed. The public API still carries its `years`: the duration is
  not protected data, and hiding it there would change the public contract. `InvalidExperienceYearsException` is deliberately **not** mapped to an HTTP status: from
  the API it is unreachable unless a write path bypasses the DTO, a server fault that must stay a
  `critical` 500.

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
