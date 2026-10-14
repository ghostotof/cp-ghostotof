---
paths:
  - "backend/src/Portfolio/**"
  - "backend/tests/Portfolio/**"
  - "frontend/src/presentation/pages/**"
  - "frontend/src/presentation/ui/**"
---

# Backend — `Portfolio` content contexts

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Portfolio/Shared/` to `Portfolio/Incident/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

**Where the rest lives** (split by #605, so that each file reads in one `Read`). A reference elsewhere to
`.claude/rules/portfolio-contenus.md`, « <paragraph> » or « the ordering section » points at one of these:

| Rule | Paragraphs |
|---|---|
| this file | `Portfolio/Shared/`'s `Locale`, then `Experience`, `Quality`, `About`, `Contribution`, `Incident`: `AboutSettings` without a lock, `ExperienceTechnology` (`years`, `name`, its row lock) |
| `portfolio-ordre.md` | content ordering and translation groups (D1–D3), the deferred trigger refusing a group on several positions (#390), who names the refusal (#506), the scope lock refusing an open transaction (#526) and `withLocks()` (#567), the EntityManager closed on a refused COMMIT (#547, #549) |
| `portfolio-ordre-migrations.md` | the trap for future migrations (#505), what the trigger does not see and the net behind it (#503) |
| `portfolio-ordre-ecritures.md` | a me-card group across two categories (#501), the application refusing such a group by name (#561), the two client errors first, every write of an ordered `Administrator` under its scope's lock (#389), detached and removed instances (#402, #548, #557), what is not covered |

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
- **`Portfolio/Experience/`**, **`Portfolio/Quality/`**, **`Portfolio/About/`**, **`Portfolio/Contribution/`**,
  **`Portfolio/Incident/`** — DB-backed
  content that used to be (or, for `Experience`, always was) hardcoded in the frontend. Each follows the same
  shape: a Doctrine entity per concept (`ExperienceTechnology`; `QualityPrinciple`/`QualityTrait`;
  `AboutSettings`/`AboutSiteCard`/`AboutMeCard`, the latter with an `AboutMeCardCategory` enum), a public
  read-only API Platform resource (`GetCollection('/experience/technologies')`, or an aggregating `Provider` for
  `/quality/{locale}` and `/about/{locale}` that returns `{principles, traits}` / `{settings, siteCards, meCards}`
  in one call), and a backoffice CRUD resource (see `.claude/rules/backoffice-api.md`). Seeded via idempotent `app:{about,quality,contributions,incidents}:seed`
  console commands (content already there: they decline, unless `--force`, which purges the whole table — every
  locale, spec 0004 D3 — then recreates; **`AboutSettings` is created when missing, rewritten with `--force`
  only, never purged**, see below).

  **`AboutSettings` has no lock, by decision** (issue #553). It is the singleton per locale of the About page's
  section texts, and **nothing in the application deletes it**: `AboutSettingsRepositoryInterface` has no
  `remove()`, the backoffice resource only `Get`/`Put`, and `app:about:seed` never purges it (`ensureSettings()`:
  a direct `save()` when the locale has no row; `update()` through the `Administrator` with `--force` only;
  otherwise the row is kept as edited). Its `countExisting()` leaves it out, so with no card left the
  command runs without `--force` — it then recreates the cards and creates missing settings, but **never
  rewrites existing ones** (issue #568: before, that run reset the five section texts edited in the
  backoffice, the one rewrite of an existing row by a guarded seed, contrary to `.claude/rules/seeding.md`).
  `SeedAboutContentCommandTest` pins kept, created and `--force`-reset settings. The defect of #499/#545 — a
  `PUT` answering 200 on a row deleted since the provider loaded it — does not arise, because `AboutSettingsAdministrator::update()` re-reads the database:
  `findByLocale()` is a `findOneBy()`, a search by criteria whose `SELECT` Doctrine **always** runs, so a row
  gone is `null` (**404**, `AboutSettingsNotFoundException`) and a row recreated under another id is the one
  written — unlike `find($id)`, which hands back the identity-map instance without a query (#545). Its fields
  are not refreshed, but `AboutSettings::update()` overwrites all five editable ones, and `id`/`locale` never
  change. **Keep that search by criteria**: a `find($id)`, a second-level or result cache, or a memoizing decorator
  without a lock reopens the 200 on a vanished row. **Accepted**: a deletion in SQL — or a deletion and re-creation under
  another id — committed between that `SELECT` and the `flush()`'s `UPDATE` still answers 200, the `UPDATE` on the
  old id touching no row (the write is lost; milliseconds, SQL only, very low severity); two simultaneous `PUT`s of one locale, last
  writer wins, like every field (no `#[ORM\Version]`); two concurrent creations in SQL meet the unique index
  `uniq_about_settings_locale` (two seeds no longer can: since #567 `app:about:seed` writes the settings inside
  its one transaction, under the locks of the two card scopes, so the second seed waits, re-reads the count
  and declines — the settings have no lock of their own, still on purpose: no position, no group).
  **Trigger**: an application deletion of the settings (a seed purge, a `DELETE`) first requires the
  row lock of #545 (`ExperienceTechnologyLockInterface`'s pattern on `PostgresLockedTransaction`).
  `AboutSettingsUpdateReadsCommittedRowTest` pins it: settings loaded as the provider does, deleted in SQL → not
  found; deleted then re-inserted under a new id → the new row is written (both red with a read from the identity
  map, measured); and the trigger itself — the repository interface exposes exactly `findByLocale()`/`save()`, the
  backoffice resource exactly `Get`/`Put` (red with a `remove()` or a `Delete` added, measured). Turning it red
  means adding the lock first, then updating the test. That guard is partial: an `EntityManager::remove()`, a DQL
  or DBAL `DELETE` written elsewhere (a command, another processor) does not trip it — review is the net there.

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

  **`ExperienceTechnology.name` is a `Domain/ValueObject/TechnologyName`** (issue #386): valid UTF-8, trimmed
  of **Unicode** blanks (`\s`, `\p{Z}`, zero-width characters, BOM — `trim()` only knows ASCII, and a pasted
  non-breaking space rebuilt the duplicate), not blank, no control character (PostgreSQL refuses NUL in a
  `varchar`: the DBAL exception was a `critical` 500), at most `MAX_LENGTH` (the column's 180) characters. The
  entity, the registrar and the administrator only receive one, so the uniqueness check (`findOneByName()`)
  runs on the trimmed value — trimming in the entity alone would have let `" PHP "` through to the unique
  index, whose exception closes the EntityManager. The backoffice DTO validates `name` through an
  `Assert\Callback` that delegates to the Value Object, exactly like `years` — never a copied `NotBlank` or
  `Length`, which is how the NUL and the non-breaking space got through; `InvalidTechnologyNameException` is
  unmapped on purpose, like `InvalidExperienceYearsException`. No `CHECK` in the database, unlike `years`: an untrimmed name is a
  duplicate on the public page, not a 500, and no write path reaches SQL without the Value Object. `relatedTechnologyName` is a free label, not trimmed.

  **A technology's `PUT` and `DELETE` run under its row lock** (issue #545). The defect was the one #402 and
  #499 fixed for ordered content: the provider loads the technology before the processor, `find()` hands back
  that identity-map instance, and a deletion committed in between left the `UPDATE` or `DELETE` touching no
  row — Doctrine counts affected rows only for a versioned entity —, so the `PUT` answered **200** on a
  vanished technology and the loser of two simultaneous `DELETE`s **204**. `ExperienceTechnologyAdministrator`'s
  `update()` and `delete()` now wrap their **whole body** in `Application/ExperienceTechnologyLockInterface::whileLocked($id, …)`;
  the adapter, `Infrastructure/Doctrine/PostgresExperienceTechnologyRowLock`, runs it on the shared skeleton
  (`Shared/Infrastructure/Lock/PostgresLockedTransaction`, like the order scope lock and the two account
  locks): **one** DQL `SELECT … FOR UPDATE` (`LockMode::PESSIMISTIC_WRITE`) with `Query::HINT_REFRESH` locks the
  row, says whether it still exists (`null`) and refreshes the managed instance in place, handed to the
  operation as the acquisition returned it (the skeleton passes it on since #560). Row gone: the stale instance is
  detached — unless it is scheduled for insertion or removal, whose pending write detaching would cancel, the
  #402 filter — so a caller still holding it cannot save it again (issue #548, see `portfolio-ordre-ecritures.md`); query,
  detachment and filter are `Shared/Infrastructure/Doctrine/RereadUnderLock::row()`'s (#560), and the
  technology is not found (**404**, mapping
  unchanged; the admin frontend already reads it as `not-found`). So a deletion runs wholly before the write or
  wholly after it. Not `find($id, LockMode::PESSIMISTIC_WRITE)`: on an instance already managed it is a
  `refresh()` with `FOR UPDATE`, which hydrates nothing when the row has gone and returns the stale instance —
  existence must be read explicitly. A **row** lock, not the order scope lock: nothing is placed (no position,
  no translation group), only the written row is held — two technologies never wait for each other — and,
  unlike an advisory lock, PostgreSQL enforces it on every `UPDATE`/`DELETE` of the row, so a deletion in SQL
  waits too. `create()` (`ExperienceTechnologyRegistrar`) stays outside: nothing can vanish under it, and its
  race is the unique index on `name`. The name check of `update()` runs under the lock but only holds that row:
  the unique index stays the last net, its violation still converted to 409 (the failed `flush()` closes the
  EntityManager, as before). Its wait is bounded like the other locks of the skeleton (`lock_timeout=5s` under
  FPM, the skeleton's fallback elsewhere); past it, `ExperienceTechnologyLockTimeoutException` (unmapped, a
  `critical` 500); inside a transaction already open, `ExperienceTechnologyLockInsideTransactionException`
  before anything is locked. **A refused COMMIT**: no `onCommitRefused` is passed, there is nothing to name —
  the table has no deferred constraint (the unique name and the `years` `CHECK` are immediate and refuse at
  the `flush()`), so only a session lost at that moment can refuse it; the `DriverException` surfaces as is
  and the skeleton **closes the EntityManager** (issue #547, as for every lock of the skeleton, see
  `portfolio-ordre.md`): its UnitOfWork held as written what the refusal had just rolled back; an exception
  the operation throws after a write closes it too (issue #549, same section); the 409 of a name already
  taken leaves it open when it comes from the check before the `save()`, while the unique index's 409 (two
  racing `PUT`s) comes after a failed `flush()`, which has already closed it. Cost per
  `PUT`/`DELETE`, all under the lock: `BEGIN`, the lock-bound `set_config`, the locked read, the `flush()`'s
  `SAVEPOINT`, write and `RELEASE SAVEPOINT`, `COMMIT` — sub-millisecond round trips each. Guards: `ExperienceTechnologyWritesUnderRowLockTest` (a technology loaded as the
  provider does, then deleted in SQL: `update()` and `delete()` say not found; a probe session holds the row,
  and each write must fail on the lock's named timeout — without the lock, the `flush()` waited on the row too
  but failed as a bare `DriverException`, having decided on the provider's instance) and
  `PostgresExperienceTechnologyRowLockTest` (re-read, not found and detached, its re-save refused rather than
  re-inserted (#548), a pending insert not found but
  kept, a `flush()` refused inside the operation rolled back and surfacing as is — the path of the 409 —, wait
  bound, named timeout, nesting refused, a COMMIT refused after a successful `flush()` closing the EntityManager
  and a new read seeing the database, #547). No two-process test (decided for #545): the probe already proves the write waits for the
  row before reading it.

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
