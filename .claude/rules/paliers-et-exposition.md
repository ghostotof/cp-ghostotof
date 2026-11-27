---
paths:
  - "backend/config/packages/security.yaml"
  - "backend/config/routes.yaml"
  - "backend/config/routes/**"
  - "backend/src/**/Presentation/**"
  - "backend/src/**/Infrastructure/ApiPlatform/**"
  - "backend/src/Security/**"
  - "backend/src/Portfolio/About/**"
  - "backend/src/Portfolio/AnonymousCv/**"
  - "backend/src/Portfolio/CaseStudy/**"
  - "backend/src/Portfolio/Cv/**"
  - "backend/tests/Security/**"
  - "frontend/src/presentation/router/**"
---

# Access tiers and route exposure

> Moved verbatim from `.claude/CLAUDE.md` (2026-11-10, lighter per-session base), sections « Goals › 9 » and
> « Architecture › Backoffice (`ROLE_SUPER`) ». `CLAUDE.md` keeps a one-line summary of each invariant. The
> index of every rule is at the top of `.claude/CLAUDE.md`.

## The three access tiers (Goal #9)

9. The website implements the three access tiers of ADR 0003 — anonymous, base, trusted; the vocabulary
   (palier anonyme / de base / de confiance, accès de base, nominatif) is in `CONTEXT.md`. Invariants, each
   enforced **server-side**:
   - **No nominative data without `ROLE_TRUSTED`**: nothing that could identify me — the nominative CV
     (`GET /api/cv`: real name, employers, career history) and `GET /api/me` — reaches a caller without it.
     "Expose", not "display": hiding a field client-side is presentation, never protection; the guard is
     `ApiRouteExposureTest` (see "Backoffice" below).
   - **The base tier (`ROLE_USER`) is discretion, not protection**: anyone gets it in one click, with no
     account (`POST /api/account/base-access`: a 15-minute JWT carrying **exactly** `ROLE_USER`), and every
     account has it (`CpgUser::getRoles()`). It never gates nominative data: its contents
     (`Portfolio/CaseStudy`, `Portfolio/AnonymousCv`) are publishable by construction — no name, employer
     nor client —, never served under the `^/api/cv` prefix (`ROLE_TRUSTED`), and never carry the
     professional chronology — it re-identifies even without names, even reduced to durations and sectors
     (ADR 0003 D5 amended, 2026-09-13).
   - **`ROLE_TRUSTED` is granted nominatively** by a `ROLE_SUPER` account via the invitation flow, never
     through a shared or published credential; `ROLE_SUPER` inherits it through `role_hierarchy` (the CLI,
     `app:user:create`, never grants `ROLE_TRUSTED` alone: `--role ROLE_SUPER` for the bootstrap, or a
     base-tier account).
   - **Never name a real account in this repo** (issue #78, pt 5): a valid username in a public repository is
     half a credential, `login_throttling` or not.
   - The About page is **public in full, on purpose** (audit C3): what it publishes is decided at authoring
     time. Don't reintroduce a conditional filter there (see `AboutContentResource`'s docblock).
   Mechanics: `docs/adr/0003`, `docs/adr/0001` (amended, invitations), `.claude/rules/security-authentication.md`
   (base access), `.claude/rules/frontend.md` (its expiry on the frontend).

## Backoffice (`ROLE_SUPER`)

Content management for all of the bounded contexts (see `.claude/rules/`), plus user administration, gated end-to-end behind `ROLE_SUPER`
(the default role every account also has is `ROLE_USER`, cf. `CpgUser::getRoles()` — never sufficient here):

- **Non-negotiable rule for every new endpoint — "never send what the caller isn't entitled to"**: the API must
  never return protected data to an unauthenticated or unauthorized caller, *even when the frontend does not
  display it*. Hiding a field client-side is presentation, never protection — anyone can call the endpoint
  directly. Concretely: a new route is protected by default; making it public is a deliberate act.
  `tests/Security/ApiRouteExposureTest.php` enforces this automatically — it walks the router and asserts that
  **every** `/api` route outside its `PUBLIC_PATHS` allow-list answers 401/403 to an anonymous caller, that no
  `/api/backoffice*` path can ever be allow-listed, and that the whole backoffice answers 403 to an
  authenticated account lacking `ROLE_SUPER` (authenticated ≠ authorized). Adding a public endpoint therefore
  means adding an entry to `PUBLIC_PATHS` **with a written justification**; if you can't justify it, it isn't
  public. `tests/Security/RouterScopeTest.php` closes the boundary on the other side: any route compiled
  outside `/api` must be justified in its own `NON_API_PATHS` allow-list and protected by its own firewall
  and `access_control` rule, or the suite turns red. The same test carries `BASE_TIER_PATHS` (issue #78, invariant n°6): with a base-tier token
  (`POST /api/account/base-access`, `ROLE_USER`) **every** route outside `PUBLIC_PATHS ∪ BASE_TIER_PATHS`
  must answer 403, and every listed entry must actually open — so a new `ROLE_USER` content route needs its
  own justified entry there, and a `ROLE_USER` rule on an identifying route turns the suite red. Never
  weaken or delete that test to make a new route pass.
  **Every one of these guards reads the `test` container only, so never declare security or a route for a
  single environment** (issue #659; preprod and prod run `APP_ENV=prod`): `SecurityConfigEnvironmentParityTest`,
  `RouteEnvironmentParityTest` and, for the security services' compiled definitions (issue #698),
  `SecurityServicesEnvironmentParityTest` enforce it — details in `.claude/rules/backoffice-api.md`, « Authorization ».
