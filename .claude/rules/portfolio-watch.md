---
paths:
  - "backend/src/Portfolio/Watch/**"
  - "backend/tests/Portfolio/Watch/**"
  - "backend/bin/build-package-manifest.php"
  - "k8s/base/watch-refresh-cronjob.yaml"
  - "frontend/src/*/watch/**"
  - "frontend/src/*/admin/watch/**"
  - "frontend/src/presentation/pages/StackPage.vue"
---

# Backend — `Portfolio/Watch` (tech watch)

> Moved verbatim from `.claude/CLAUDE.md` (#346), section « Architecture › Backend architecture › `Portfolio/Watch/` ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **`Portfolio/Watch/`** — the tech-watch radar behind the public `/{locale}/stack` page: version
  lifecycles from **endoflife.date** and known vulnerabilities of the deployed packages from **OSV.dev**.
  It is the only context whose domain depends on third parties, and the rules below are what keep that
  dependency from becoming a liability. Full rationale in `docs/adr/0002-veille-technique.md`.
  - **No outbound call ever sits in a public render path.** A CronJob (`app:watch:refresh`) writes a
    `WatchSnapshot` per volet; `GET /api/watch` reads **only** that local snapshot. `WatchProvider` has
    **no dependency at all** on an external source, so it cannot reintroduce one by accident — a
    functional test pins it by substituting an HTTP client that fails on any call. The single
    deliberate exception is the backoffice slug check (below).
  - **A third party's response never crosses the `Infrastructure/` boundary.** `EndOfLifeDateClient` /
    `OsvClient` return domain Value Objects (`ProductReleaseCycles`, `KnownVulnerability`…), never a
    decoded `array`. That anti-corruption layer is what makes the domain testable without a network;
    **no test may go out on the wire** (`MockHttpClient` everywhere) — a test really calling
    endoflife.date is intermittent by construction and breaks CI the day the provider has an incident.
  - **Public sees an aggregate, `ROLE_SUPER` sees the detail.** `/api/watch` carries
    `{packagesScanned, affectedCount, checkedAt}` and never a CVE id, affected package name or
    vulnerable version; the detail lives at `/api/backoffice/watch/vulnerabilities`. The partition is
    enforced **at read time in the provider** — the snapshot itself keeps the full detail on purpose,
    since amputating it would rob the one person entitled to see it. A dedicated test asserts the
    absent keys in the anonymous payload; never relax it.
  - **Refusing to write is a feature.** If nothing could be refreshed, nothing is persisted — a
    "zero product" payload would wipe the page on the provider's first outage while yesterday's data
    is still perfectly readable. Likewise a missing manifest yields an explicit "not analysed" state,
    never a lying "0 vulnerabilities": *found nothing* ≠ *looked for nothing*.
  - **A third party's string never becomes an `href` unfiltered** (security review, 2026-09-08).
    `links.html` from endoflife.date reached `/stack`'s `:href` with no scheme check — Vue does not
    sanitize `:href` — so a `javascript:` value published in their **open dataset** landed intact in
    the DOM of a public page. *(The review first called this an exploitable stored XSS, on the
    strength of a second claim — "the frontend nginx has no CSP" — that was **wrong**: the grep
    behind it was truncated by a `head`. `docker/node/nginx.conf` serves a strict CSP,
    `script-src 'self'` with no `'unsafe-inline'`, which blocks `javascript:` navigation; the browser
    demo ran against Vite's dev server, which sends no CSP. The defect was mitigated in production.
    The filter is still right — a CSP is a mitigation, not a licence to republish an unchecked URL —
    and note that adding `'unsafe-inline'` to `script-src` would reopen exactly this door.)*
    `Domain/Service/ExternalUrlFilter` is an
    **allow-list** (`https` only — a `javascript:` denylist would still let `data:` and `vbscript:`
    through) and also rejects control characters, which browsers strip while parsing an href
    (`java\tscript:` runs). A refused URL becomes **absent, never "cleaned"**: repairing it means
    guessing the author's intent, which is how a neutralised payload gets reassembled. It is applied
    **twice, at write and at read** — a snapshot written before the fix still holds the raw value, so
    filtering only on write would leave every deployed installation exposed until the next refresh.
  - **`max_redirects: 0` on every outbound call.** Symfony's default follows 20, and the CronJob pod
    has no egress restriction — a hijacked provider would get a lever into the internal network.
  - **`/api/watch` is publicly cacheable, but briefly** (`cacheHeaders` on the `Get`: `public`,
    `max-age`/`s-maxage` 300, `stale-if-error` 3600). The short lifetime is *not* about the data —
    that is daily — but about the **label**: `freshness` is computed at read time and `StackPage.vue`
    trusts it instead of recomputing from `refreshedAt`, so a response cached for T seconds shows a
    label T seconds out of date. A day-long cache could therefore display "fresh" after refreshing
    had stopped, which is a lie about the one thing this page exists to show. `public` is only safe
    because `WatchProvider` ignores the caller entirely — remove it before making this response
    depend on who is asking. `WatchResourceTest` pins the ceiling (not the exact value) and pins the
    other side too: the `ROLE_SUPER` detail must never become publicly cacheable.
  - **PHP's and Symfony's versions come from the runtime**, not the backoffice
    (`VersionSource::RUNTIME_PHP` / `RUNTIME_SYMFONY`, `PhpAndSymfonyVersionResolver`) and the version
    field is refused for them (`InvalidWatchedProductException`, 422). The two most-looked-at versions
    cannot go stale through a forgotten edit.
  - **Freshness is computed at read time**, threshold `SnapshotFreshnessCalculator::STALE_AFTER_HOURS`
    = **36 h** — 1.5× the daily CronJob. It must stay strictly above the period, or a single missed
    cycle (or merely the hour before the run) would display "stale".
  - **The package manifest is built during `docker build`**, not by the app and not by CI:
    `bin/build-package-manifest.php` (standalone, **no Symfony kernel** — at build time neither
    `APP_SECRET` nor `DATABASE_URL` exists) merges `composer.lock` + `frontend/package-lock.json` in
    the `production` stage, the one place both lockfiles coexist. The file is gitignored and read-only
    in the image. `app:watch:build-manifest` is the same thing for local use.
  - `WatchedProductSlugExists` (an `Infrastructure/Validator/` constraint, not a domain rule) checks the
    slug against endoflife.date **when saving from the backoffice** — 2 s timeout, **non-blocking**: a
    provider outage must never prevent administering your own site. It lives in the validator rather
    than in `WatchedProductAdministrator` so that `app:watch:seed` (and its test) stay off the network.
  - Not localized (no `locale` column, unlike every other `Portfolio/*` context): a version number is a
    fact, not a translation. UI labels are handled frontend-side.
  - **A fresh environment starts with an empty `watched_product`**, and `app:watch:refresh` says so
    plainly (`Aucun produit surveillé : rien à rafraîchir.`) rather than failing — `/stack` then shows
    "never refreshed" until the catalogue is seeded. Preprod is seeded automatically on every deploy
    (see "Seeding" below); prod is populated and the guard keeps it that way.
  - **No version is ever typed in** (issue #19). Decision D2 started with PHP and Symfony reading the
    runtime; the other five used to be `MANUAL`, so bumping `POSTGRES_TAG` left `/stack` announcing
    the previous version until someone edited the backoffice — the page stating something untrue
    about what runs, which is exactly what it exists to prevent. They are now `VersionSource::DEPLOYED`,
    resolved from a record written at **`docker build`** into `config/watch/deployed-versions.json`
    (same shape as the package manifest: a builder that writes, a reader that reads back).
    The authority is **`k8s/base/*.yaml`** — the manifests the cluster actually applies — not `.env`,
    which only drives dev images and was kept in sync with them by a comment. That comment is gone.
  - **The tags reach the build as `--build-arg`, never as copied files.** Two independent reasons, and
    both must hold: `k8s/` is excluded by `.dockerignore` (an application image has no business
    carrying deployment manifests), and `COPY .env` would create a layer that keeps the file even
    after a later `rm`. The `Makefile` extracts the tags with `sed` from the manifests and passes
    them; the builder falls back to reading the files, which is the **dev** path — `docker-compose`
    mounts `k8s/`, `.env` and the npm lock read-only for that, and `app:watch:build-versions` is the
    local equivalent of the build step.
  - **Node is the odd one and stays labelled as such.** It is deployed nowhere: it builds the bundle
    and disappears, the frontend image serving only nginx. Its version comes from `.env` — which
    genuinely drives the build — and the page shows it as toolchain, not as something running. Vue
    likewise comes from the npm lock, being a bundle dependency rather than an image.
  - A missing record is not an error: the products show without a version, which the page already
    renders. Failing a build or a refresh over a renamed manifest would be out of proportion.
  - **The installed version is read at refresh time, not per request**: `WatchRefresher` resolves
    it (runtime or build record) and writes it into the snapshot, so `/stack` shows a new PHP or
    PostgreSQL only after the next `watch-refresh` run (04:41 UTC). To see it right after a deploy:
    `kubectl create job --from=cronjob/watch-refresh <name>` in the namespace, then delete the Job.

