---
paths:
  - "backend/src/Mcp/Connection/Application/OwnMcpConnection*.php"
  - "backend/src/Mcp/Connection/Application/AccountMcpConnectionsRevoker.php"
  - "backend/src/Mcp/Connection/Presentation/Controller/OwnMcpConnectionsController.php"
  - "backend/src/Mcp/Connection/Domain/Exception/McpConnectionNotFoundException.php"
  - "backend/src/Mcp/Connection/Infrastructure/Doctrine/RevocationOutsideTransactionException.php"
  - "backend/src/Shared/Application/Security/AccountMcpConnectionsRevokerInterface.php"
  - "backend/src/Shared/Application/Security/AccountAccessWithdrawal.php"
  - "backend/src/Shared/Application/Security/RevokedAccountMcpConnections.php"
  - "backend/src/Security/User/Application/CpgUserAdministrator.php"
  - "backend/src/Security/User/Application/CpgUserRoleAdministrator.php"
  - "backend/src/Security/User/Application/PasswordSetupService.php"
  - "backend/migrations/Version20261028120000.php"
  - "backend/migrations/Version20261028140000.php"
  - "backend/tests/Migrations/Version20261028120000Test.php"
  - "backend/tests/Migrations/Version20261028140000Test.php"
  - "backend/tests/Mcp/Connection/AccountWideRevocationTest.php"
  - "backend/tests/Mcp/Connection/AbandonedGrantsTest.php"
  - "backend/tests/Mcp/Connection/OwnMcpConnectionRevocationConcurrencyTest.php"
  - "backend/tests/Mcp/Connection/Fixtures/revoke-own-mcp-connection.php"
  - "backend/tests/Mcp/Connection/Application/OwnMcpConnectionsTest.php"
  - "backend/tests/Mcp/Connection/Application/AccountMcpConnectionsRevokerTest.php"
  - "backend/tests/Mcp/Connection/Presentation/Controller/OwnMcpConnectionsControllerTest.php"
  - "frontend/src/presentation/pages/McpConnectionsPage.vue"
  - "frontend/src/presentation/layout/AccountMenu.vue"
  - "frontend/src/presentation/format/instant.ts"
  - "frontend/src/application/mcp/useMcpConnections.ts"
  - "frontend/src/infrastructure/mcp/HttpMcpConnectionsRepository.ts"
  - "frontend/src/domain/mcp/**/*McpConnection*"
  - "frontend/tests/**/*McpConnections*"
  - "frontend/tests/presentation/layout/AccountMenu.spec.ts"
  - "frontend/tests/presentation/format/instant.spec.ts"
---

# Backend and frontend — `Mcp/Connection`: the person's revocation, the account-wide revocations, the abandoned grant (#650, #730)

> Decisions: `docs/adr/0006-serveur-mcp.md`, D3 and D4; spec `.claude/specs/0007-mcp-server.md`, phase 2 (task 11:
> « Mes connexions MCP », user stories 34 and 35; plan archived with the spec, `tasks/plan-t11-mes-connexions.md`
> until then). Vocabulary: `CONTEXT.md`, « Accès MCP ». Written beside `.claude/rules/mcp-oauth-cycle-de-vie.md`
> (the lifetimes, the expiry, the repository's revocation and its lock order), which was too close to
> `tools/check-claude-rules.sh`'s 50 000 bytes to take this: that rule keeps what is common to every revocation and
> points here for the three causes #650 added. The index of every rule is at the top of `.claude/CLAUDE.md`.

## Three new causes, three writers, one statement

`RevocationCause` gained `person`, `password-changed` and `trusted-tier-withdrawn` (≤ 32 characters, kebab). **The
database admits them before the code writes them** (`Version20261028120000` widens
`chk_mcp_connection_revocation_cause_value` to eight values; expand/contract, #175; `Version20261028120000Test` the
honest `down()`, `McpConnectionRepositoryTest` every case of the enum against the migrated schema). All three go
through `McpConnectionRepository::revokeWhere()` — the one statement of the five revocations, rows locked in id order
`FOR NO KEY UPDATE`, one `RETURNING` row per connection **this call** revoked — and **none through
`McpConnectionRevokerInterface::revoke()`**, typed by the compromise (#726, CR6): a repository method of its own per
path, never a generic cause on the revoker.

| Cause | Writer | Repository method | Transaction | Audit actor |
|---|---|---|---|---|
| `person` | `Application/OwnMcpConnections::revoke()` | `revokeActiveOfAccount($id, $account, …)` | none: the statement is the transaction | the person (token storage) |
| `password-changed` | `CpgUserAdministrator::changePassword()`; `PasswordSetupService::complete()` when the link **replaces** a password | `revokeAllActiveOfAccount($account, …)` through the port | the caller's, required | the administrator; `anonymous` for the link |
| `trusted-tier-withdrawn` | `CpgUserRoleAdministrator::setSuperAdmin()` on a demotion that loses `ROLE_TRUSTED` | same | the caller's, required | the administrator |

Every audit line (`mcp-connection-revoked`, `.claude/rules/security-authentication.md`) is written **after the
COMMIT**, once per connection **the call revoked**, none for an unknown or already revoked one, through
`Application/FailSafeAudit` (an audit failure never changes the answer: `critical`, class only, #725).

## The person's revocation: `GET` and `DELETE /api/me/mcp-connections`

- **Under `/api/me/`**, so behind the existing `access_control ^/api/me(/|$)` (`ROLE_TRUSTED`, `ROLE_SUPER` by the
  hierarchy): no new rule, no `PUBLIC_PATHS` nor `BASE_TIER_PATHS` entry, nothing for the environment-parity tests
  (`ApiRouteExposureTest` asks for the 401 and the base tier's 403 alone). `OwnMcpConnectionsController` is thin, a
  hand-written JSON response like `ConsentController` (no API Platform), `Cache-Control: no-store` — the list says
  which clients read the nominative CV on the person's behalf. The account is `#[CurrentUser]`, never an id the
  client sends. The `DELETE` is a cookie request: the CSRF double-submit applies, no exclusion.
- **`GET`: one query** (`findAllOfAccount()`: connections with their client joined, `granted_at DESC, id DESC`, no
  lock), **every connection of the account** — active, expired, and revoked ones kept six months by the purge (the
  author's decision of 2026-10-28). The entry: `id`, `client{id,name}`, `grantedAt`, `lastUsedAt`, `expiresAt`,
  `state`, `revokedAt`, `revocationCause`. No token, code nor hash.
- **The state has one rule** (`OwnMcpConnections::stateOf()`, `OwnMcpConnectionState`): `revoked` when `revoked_at` is
  set and the cause is not `expired`; `expired` when the cause is `expired` **or** `isExpiredAt($now)` is true (never
  `revoked_at` alone, which does not say why); `active` otherwise. A connection revoked then past its deadline stays
  `revoked`. **The displayed deadline is the judged one**: `McpConnection::deadline($zone, $lifetimes)` is the
  earliest of the idle, maximum and — until the code is exchanged — unexchanged deadlines, and `isExpiredAt()` is
  `$now >= deadline()`. Two computations could drift apart; there is one.
- **`DELETE`: 204, effect at the client's next request** (`testRevokingOwnConnectionCutsTheNextNominativeCall`). One
  `UPDATE … WHERE c.id = :id AND c.account_id = :account`: the filter by account is what guarantees a person revokes
  her own, with no read first. When it returns `null`, `belongsToAccount()` tells **her already revoked connection**
  (204, nothing written, the first revocation keeps its instant and cause — idempotent DELETE) from **another
  account's or an unknown one** (`McpConnectionNotFoundException`, one 404 problem+json with a constant `detail`, so
  the API is no oracle on a UUID's existence; `log_level: info`). An id in capitals or malformed never reaches the
  service: `Requirement::UUID`, the router's 404. **An expired connection the purge has not marked yet is not
  revoked**: the person revokes it `person`, not `expired` — the cause says who cut.
- **Lock order**: the connection alone, `FOR NO KEY UPDATE`, by id — the token endpoint, the purge and the
  supersession take a connection first, none after something else, so no cycle. A revocation crossing a refresh
  waits for its COMMIT then revokes (the refresh's `last_used_at` intact); crossing the purge's marking it answers
  204 and writes and audits nothing, the cause staying `expired`
  (`OwnMcpConnectionRevocationConcurrencyTest`, real processes, `.claude/rules/backend-outillage.md`).

## The account-wide revocations: a port of `Shared`, called inside the lock

`Security/User` must name no class of `Mcp` (`.claude/rules/security-authentication.md`), and `Mcp` opens no third
door towards `Security`: `Shared/Application/Security/AccountMcpConnectionsRevokerInterface` is the port,
`Mcp/Connection/Application/AccountMcpConnectionsRevoker` its implementation. Two calls, in two moments:

1. `revokeAllOf(AccountReference, AccountAccessWithdrawal): RevokedAccountMcpConnections` — **inside** the closure of
   `AccountLockInterface::whileLocked()` / `AccountAdministrationLockInterface::whileAdministering()`. The new password
   (or the role) and the cut connections commit together or not at all: a revocation that fails undoes the password
   (`testARefusedRevocationLeavesThePasswordUnchanged`, `…TheRolesUnchanged`, `…TheActivationUndone`). It audits
   nothing and returns what it revoked. Outside a transaction the repository refuses before writing
   (`RevocationOutsideTransactionException`, a `LogicException`, the account's UUID only in the message).
2. `auditRevoked(RevokedAccountMcpConnections)` — **after** the lock returns, so after the COMMIT, one line per
   connection.

`AccountAccessWithdrawal` (`PasswordChanged`, `TrustedTierWithdrawn`) is a **closed set**, not a cause: the
implementation maps it to a `RevocationCause` with an exhaustive `match`, so a reason added without its cause fails
PHPStan. Rejected: a Symfony event after the COMMIT (not atomic: a failure leaves a changed password and live
connections); an event inside the transaction (implicit order, audit before the COMMIT).

**Lock order**: the account row (`FOR UPDATE`, taken by the lock), then its connections by id. No path takes a
connection then the account (the token endpoint reads the account without a lock, the consent takes a `FOR KEY SHARE`
of it before inserting, the cascading deletion follows the same direction).

**Who calls it, and when it does not**:

- `CpgUserAdministrator::changePassword()` (the backoffice `PUT`): always. The line order is `password-changed`, then
  one `mcp-connection-revoked` per connection.
- `PasswordSetupService::complete()` — an invitation link: **only when it replaces a password**. `activate()` reads
  `hasPassword()` **before** `setPassword()` on the account re-read under the lock. A pending account to which a
  `ROLE_SUPER` set a password (#460) keeps `activatedAt` null yet signs in, can hold `ROLE_TRUSTED` and grant
  connections: its next link replaces that password. A link on an account with no password *sets* the first one:
  nothing to revoke, no call (the plan's first reading, « it only touches an account without a password », was
  wrong; the author corrected it on 2026-10-28). The actor of these lines is `anonymous` (public flow);
  `accountActivated` comes first.
- `CpgUserRoleAdministrator::setSuperAdmin()`: on a **demotion whose written list lacks `ROLE_TRUSTED`**. The test
  reads the list the code **writes** (`rolesAfterDemotion()`: `ROLE_TRUSTED` kept only if the account has an e-mail,
  ADR 0003 D1), never `getRoles()` (which adds `ROLE_USER`, #560) nor the state before. A demoted account that keeps
  its tier, a promotion and the idempotent no-op revoke nothing and write no line. The closure returns
  `[changed, ?RevokedAccountMcpConnections]`; `role-changed` is audited first, then the connections. This is the only
  application path that withdraws the tier; account deletion already cuts connections by cascade (its audit is #652).
- Not a writer: `CpgUserRepository::upgradePassword()` (rehash of the same secret at login) and `CpgUserRegistrar`
  (creates an account, changes none).

## The abandoned grant (#730): a connection whose code is never exchanged expires after an hour

Supersession happens at a code's first exchange (#726, D2), so an approval whose code is never exchanged left a
connection nobody replaced nor used, alive for the idle delay (30 days), and they piled up.

- **A third deadline, until the first exchange.** `mcp_connection.code_exchanged_at` (`datetimetz_immutable`,
  nullable; `Version20261028140000`), written by `CodeExchangeSupersession::supersedeOthersOf()` on the instance league
  locked, in the exchange's transaction, **before** the others are revoked (same trigger, « first successful
  exchange »; lock order unchanged); `McpConnection::recordCodeExchangeAt()` writes once (`??=`). While it is null,
  `deadline()` is also bounded by `ConnectionLifetimes::unexchangedDeadline($grantedAt)`. A refresh does not pass
  through there. A column rather than `last_used_at` written at the exchange: that one is written at the refresh only
  (`RefreshRotationTest` pins it), and an `EXISTS` over the tokens does not survive the purge of dead tokens.
- **The sixth duration**, `app.mcp.lifetime.connection_unexchanged` (`PT1H`, one value for every environment).
  `ConnectionLifetimes::fromConfiguration()` refuses it, naming the parameter `unexchangedLifetime`: **elapsed time
  only** (a year, month or day component — `notElapsedTime`), **not beyond the code's duration plus twice
  `SECOND_TRUNCATION_AND_SKEW_MARGIN`** (`notBeyondCodeWindow`), or **reaching `connection_idle`**
  (`notBelowIdle`).
- **The purge's third pre-selection bound** (`PreselectionBounds::unexchangedBefore()`; `findActiveCandidatesForExpiry()`
  now takes the `PreselectionBounds` whole): `granted_at <= :unexchangedBefore AND code_exchanged_at IS NULL`, the bound
  being `now − connection_unexchanged + SECOND_TRUNCATION_AND_SKEW_MARGIN` (`.claude/rules/mcp-oauth-purge.md`).
  **CR1 of #726 holds**: a connection whose code is still exchangeable is never a candidate (candidate ⇒ age ≥ duration − 1 min
  > code + 1 min), so the purge never wants the connection the exchange locked first.
  `AbandonedGrantsTest` plays it through HTTP, the exchange and `app:mcp:purge`: three abandoned approvals, listed
  `expired`, marked `expired` by the purge (actor `system`); an exchanged connection outlives the hour; a code still
  exchangeable is never pre-selected.
- **The migration backfills**: a connection with a `last_used_at`, an access token or a refresh token is taken as
  exchanged (`code_exchanged_at = granted_at`, an approximation read as null-or-not only). Without it every existing
  row would read as unexchanged and expire an hour after its grant. Both migrations run **before** the rollout.

## The consent page names the account

`GET /api/oauth/consent` also returns `account.username`, read from `#[CurrentUser]` — the account whose cookie
arrives with **this** request, not the state a page kept (another tab may have switched account). The route is already
`ROLE_TRUSTED` and the identifier is the caller's own; it enters no log. The page (`OAuthConsentPage.vue`) shows it in a
section of its own and offers « Changer de compte » (a `RouterLink` to the login with `redirect` and `reauth=1`, the
mechanism of #647), outside the decision buttons' container.

## Frontend

- **Slice**: `domain/mcp` (`McpConnection` entity, `McpConnectionsRepository`, two errors) → `infrastructure/mcp/
  HttpMcpConnectionsRepository` → `application/mcp/useMcpConnections` (status `loading|ready|error`, revocation
  outcome `revoked|already-gone|failed`; the list is **re-read from the server** after a revocation, the frontend
  recomputes no state nor deadline; a 401/403 marks the session expired like `useAssistant`) → `presentation/pages/
  McpConnectionsPage.vue`, provided in `main.ts`. Dates: `presentation/format/instant.ts`, `Europe/Paris`.
- **The page** `/<locale>/mcp-connections`: `requiresAuth`, `roles: [ROLE_TRUSTED, ROLE_SUPER]`, `noindex` (the guard is
  a courtesy, `/api/me` is the authority). One table (`<caption>`, `th scope`), the page title its only heading;
  « Révoquer » only on `active` rows, its accessible name carrying the client and the grant date; `window.confirm()`
  naming the client (the backoffice convention); the only `role="status"` of the page announces the outcome, a failure
  is a `role="alert"`; after a revocation focus lands on the `<h1>`. A cause missing from `CAUSE_LABEL_KEYS` (a newer
  backend) reads as the generic label: causes are mapped explicitly, never built by string concatenation.
- **Desktop account menu**: `presentation/layout/AccountMenu.vue`, a WAI-ARIA **disclosure** (button with
  `aria-expanded` and `aria-controls`, a list of ordinary links), not a `role="menu"`. It closes on a second click,
  Escape (focus returns to the button), a click outside, a navigation and the choice of an entry. It groups the actions
  of the **trusted tier** that used to sit flat in `AppHeader` (assistant, administration or CV download, logout) plus
  « Mes connexions MCP ». Under 768 px the existing icon menu stays, with one more link.
  **The base tier keeps its flat actions on desktop** — the author's decision of 2026-10-28: the menu is the trusted
  tier's. Not checked in a browser yet (the task-10 control: tab order, visible focus, contrast, 768 and 1024 px widths;
  it needs a trusted-tier session) — to do and to note in the PR (`.claude/rules/frontend-a11y.md`).

## Accepted limits (the author's decisions of 2026-10-28; revisit through a new issue)

1. **The session cookie survives a password change.** The JWT is not revocable (ADR 0003, one hour): a stolen cookie
   can still grant a connection within ten minutes of its `iat` **after** the change. The revocation cuts what exists,
   not what is born later.
2. **The `POST /api/oauth/consent` grants to the account of the cookie at sending**, not the one the `GET` displayed
   (outside #650). The displayed name is information, not a binding of the decision.
3. **An approval in flight crossing the loss of the tier** can insert a connection right after the revocation. The
   authenticator and the refresh judge the tier again and refuse it at use; it is simply not marked revoked, and
   would live again if the tier came back within its lifetime.
4. **The migration's backfill cannot tell** an exchanged, never-refreshed connection whose tokens were purged from an
   abandoned one: impossible with the shipped durations (token lifetimes outlast the connection's idle deadline).
5. **The pre-selection margin of #730 only widens the pre-selection.** Zero would be the deadline itself and the purge
   would stay correct: `isExpiredAt()` decides, never the SQL; `ConnectionLifetimesTest` pins the value.
6. **`CpgUserRepository::upgradePassword()` revokes nothing**, by design: same secret.
7. **Roles edited by hand in SQL are not covered.** Judged at use.

## Tests

`OwnMcpConnectionsControllerTest` (list, shape, one query, no token in the body, base tier 403, anonymous 401, 204
idempotent, indistinguishable 404, CSRF, audit once, audit failure leaves the 204), `OwnMcpConnectionsTest`,
`AccountMcpConnectionsRevokerTest`, `AccountWideRevocationTest` (the three writers, atomicity, no line for nothing to
revoke), `OwnMcpConnectionRevocationConcurrencyTest`, `AbandonedGrantsTest`, `ConnectionLifetimesTest`,
`McpConnectionExpiryTest`, `Version20261028120000Test`, `Version20261028140000Test`; frontend `McpConnectionsPage.spec.ts`,
`useMcpConnections.spec.ts`, `HttpMcpConnectionsRepository.spec.ts`, `AccountMenu.spec.ts`, `AppHeader.spec.ts`,
`instant.spec.ts`, `adminGuard.spec.ts`, `OAuthConsentPage.spec.ts`.
