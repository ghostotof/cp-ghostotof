---
paths:
  - "backend/src/Mcp/Connection/Application/OwnMcpConnection*.php"
  - "backend/src/Mcp/Connection/Application/AccountMcpConnectionsRevoker.php"
  - "backend/src/Mcp/Connection/Application/RevokedConnectionAudit.php"
  - "backend/src/Mcp/Connection/Application/CodeExchangeSupersession.php"
  - "backend/src/Mcp/Connection/Domain/Exception/ConsentAccountChangedException.php"
  - "backend/src/Mcp/Connection/Presentation/Controller/OwnMcpConnectionsController.php"
  - "backend/src/Mcp/Connection/Domain/Exception/McpConnectionNotFoundException.php"
  - "backend/src/Mcp/Connection/Infrastructure/Doctrine/RevocationOutsideTransactionException.php"
  - "backend/src/Shared/Application/Security/AccountMcpConnectionsRevokerInterface.php"
  - "backend/src/Shared/Application/Security/AccountAccessWithdrawal.php"
  - "backend/src/Shared/Application/Security/RevokedAccountMcpConnections.php"
  - "backend/src/Security/User/Application/CpgUserAdministrator.php"
  - "backend/src/Security/User/Application/CpgUserRoleAdministrator.php"
  - "backend/src/Security/User/Application/PasswordSetupService.php"
  - "backend/src/Security/User/Application/RoleChangeOutcome.php"
  - "backend/src/Security/User/Application/DeadlockRetryInterface.php"
  - "backend/src/Security/User/Infrastructure/Doctrine/DoctrineDeadlockRetry.php"
  - "backend/src/Mcp/Connection/Application/ReadSnapshotInterface.php"
  - "backend/src/Mcp/Connection/Infrastructure/Doctrine/DoctrineReadSnapshot.php"
  - "backend/src/Mcp/Connection/Infrastructure/Doctrine/ReadSnapshotInsideTransactionException.php"
  - "backend/migrations/Version20261028120000.php"
  - "backend/migrations/Version20261028140000.php"
  - "backend/migrations/Version20261028160000.php"
  - "backend/migrations/Version20261028180000.php"
  - "backend/migrations/Version20261028200000.php"
  - "backend/tests/Migrations/Version20261028120000Test.php"
  - "backend/tests/Migrations/Version20261028140000Test.php"
  - "backend/tests/Migrations/Version20261028160000Test.php"
  - "backend/tests/Migrations/Version20261028180000Test.php"
  - "backend/tests/Migrations/Version20261028200000Test.php"
  - "backend/tests/Mcp/Connection/Infrastructure/Doctrine/DoctrineReadSnapshotTest.php"
  - "backend/tests/Security/User/Application/DeadlockRetryIntegrationTest.php"
  - "backend/tests/Security/User/Infrastructure/Doctrine/DoctrineDeadlockRetryTest.php"
  - "backend/tests/Mcp/Connection/AccountWideRevocationTest.php"
  - "backend/tests/Mcp/Connection/AbandonedGrantsTest.php"
  - "backend/tests/Mcp/Connection/AccountWideRevocationDeadlockTest.php"
  - "backend/tests/Mcp/Connection/CodeExchangedAtTest.php"
  - "backend/tests/Mcp/Connection/Fixtures/change-account-password.php"
  - "backend/tests/Mcp/Connection/Application/RevokedConnectionAuditTest.php"
  - "backend/tests/Mcp/Connection/Application/CodeExchangeSupersessionTest.php"
  - "backend/tests/Mcp/Connection/Application/ConsentServiceTest.php"
  - "backend/tests/Mcp/Connection/Presentation/Controller/ConsentControllerTest.php"
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
  - "frontend/tests/support/mcpConnectionList.ts"
  - "frontend/tests/presentation/layout/AccountMenu.spec.ts"
  - "frontend/tests/presentation/format/instant.spec.ts"
---

# Backend and frontend — `Mcp/Connection`: the person's revocation, the account-wide revocations, the abandoned grant, the consent bound to its account (#650, #730)

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

Every audit line (`mcp-connection-revoked`, `.claude/rules/security-authentication-audit.md`) is written **after the
COMMIT**, once per connection **the call revoked**, none for an unknown or already revoked one, by
**`Application/RevokedConnectionAudit::record($connection, $cause)`**, the one writer of that line for these three
causes (#650, R5a: `OwnMcpConnections` and `AccountMcpConnectionsRevoker` repeated it): the call to the security log
under `FailSafeAudit` (an audit failure never changes the answer: `critical`, class only, constant message
`Revoked connection not audited.`, #725). It sits beside `FailSafeAudit` and its two callers, the same layer, so no
dependency is new (the second door from `Mcp/Connection` to `Security`, `.claude/rules/mcp-oauth.md`, is already
theirs). The expiry (actor `system`), the supersession (`supersededBy`) and a compromise keep their own audit method.
`RevokedConnectionAuditTest`.

## The person's revocation: `GET` and `DELETE /api/me/mcp-connections`

- **Under `/api/me/`**, so behind the existing `access_control ^/api/me(/|$)` (`ROLE_TRUSTED`, `ROLE_SUPER` by the
  hierarchy): no new rule, no `PUBLIC_PATHS` nor `BASE_TIER_PATHS` entry, nothing for the environment-parity tests
  (`ApiRouteExposureTest` asks for the 401 and the base tier's 403 alone). `OwnMcpConnectionsController` is thin, a
  hand-written JSON response like `ConsentController` (no API Platform), `Cache-Control: no-store` — the list says
  which clients read the nominative CV on the person's behalf. The account is `#[CurrentUser]`, never an id the
  client sends. The `DELETE` is a cookie request: the CSRF double-submit applies, no exclusion.
- **`GET`: two reads in one snapshot, no lock, no N+1** (`OwnMcpConnections::of()`, R3, CR2, CR3):
  `findNotRevokedOfAccount()` — **every** connection of the account not revoked (active, and expired not yet marked) —
  and `findTerminatedOfAccount($id, $limit)` (`revoked_at IS NOT NULL`: the revoked and the expired the purge marked,
  kept six months), both on the client-joined base `ofAccount()`, **which sorts nothing**: each half writes its own
  order, because the order decides *which* rows a limit keeps. **The terminated are chosen by `revoked_at DESC, id
  DESC`** (the most recently ended — a `LIMIT` over the grant date kept the wrong fifty for a person who revokes an
  old connection), the non-revoked by `granted_at DESC, id DESC` (all returned, the order is presentation). **The
  display is per grant**: the service merges the two halves in PHP and sorts them `granted_at DESC, id DESC`, the
  order the page always showed — the end instant decides what is kept, not how it is shown. **Both reads run inside
  `ReadSnapshotInterface::read()`** (`Mcp/Connection/Application`, implemented by
  `Infrastructure/Doctrine/DoctrineReadSnapshot`): a `REPEATABLE READ READ ONLY` transaction, `SET TRANSACTION`
  before the first query (DBAL 4 opens its transaction without options). Without it, in `READ COMMITTED`, a
  revocation committed between the two statements made a connection vanish from the list (seen by the first half as
  active and not by the second as terminated, or the reverse — measured, red). Read only, so no row lock (a refresh or
  the purge never waits for a list) and no serialization failure (`40001` is born of an update of a row modified since
  the snapshot, and this makes none). **It refuses a transaction already open**
  (`ReadSnapshotInsideTransactionException`, a `LogicException` with no HTTP mapping): nested, DBAL would open a
  savepoint and the isolation level could no longer be chosen. Rejected: `TransactionInterface` (it writes, flushes
  the unit of work and closes the EntityManager on failure); one query (a `LIMIT` in a DQL sub-query is impossible,
  native SQL plus a mapping is more code than the port). `DoctrineReadSnapshotTest`, `OwnMcpConnectionsTest`.
  **The terminated are bounded to
  `OwnMcpConnections::TERMINATED_LIMIT = 50`** — the one place the number is written, the page cites none. `of()` asks
  for **limit + 1**: the extra row says « there are more » without a `COUNT`; the answer is `OwnMcpConnectionList{connections,
  terminatedTruncated}`. **The non-revoked are unbounded
  because they are bounded by nature**: an abandoned approval expires in an hour and the purge marks it each night, a
  first exchange supersedes the pair's others, clients are a closed list registered by CLI. No index migration:
  `account_id` suffices (EXPLAIN: 0.24 ms and 1.0 ms for the two halves, 3 005 rows of the account among 63 000 — 3 000
  terminated plus the other accounts' rows). The entry: `id`, `client{id,name}`, `grantedAt`, `codeExchanged` (boolean,
  not the instant: #730's backfill only approximates it), `lastUsedAt`, `expiresAt`, `state`, `revokedAt`,
  `revocationCause`; **`expiresAt` is `null` exactly for `revoked`** (an `expired` keeps the date that expired it;
  the page decides nothing about hiding a date). No token, code nor hash. The author's decision of 2026-10-28
  first listed every connection; R3 bounded the history (#650, F6).
- **The state has one rule** (`OwnMcpConnections::stateOf()`, `OwnMcpConnectionState`): `revoked` when `revoked_at` is
  set and the cause is not `expired`; `expired` when the cause is `expired` **or** `isExpiredAt($now)` is true (never
  `revoked_at` alone, which does not say why); `active` otherwise. A connection revoked then past its deadline stays
  `revoked`. **The displayed deadline is the judged one**: `McpConnection::deadline($zone, $lifetimes)` is the
  earliest of the idle, maximum and — until the code is exchanged — unexchanged deadlines, and `isExpiredAt()` is
  `$now >= deadline()`. Two computations could drift apart; there is one — and `viewOf()` **computes it once per
  row** and hands it to `stateOf()` (`$now >= $deadline`, `isExpiredAt()`'s comparison). A revoked then expired
  connection stays `revoked` (`testARevokedThenExpiredConnectionStaysRevoked`).
- **`DELETE`: 204, effect at the client's next request** (`testRevokingOwnConnectionCutsTheNextNominativeCall`). One
  `UPDATE … WHERE c.id = :id AND c.account_id = :account`: the filter by account is what guarantees a person revokes
  her own, with no read first. When it returns `null`, `belongsToAccount()` tells **her already revoked connection**
  (204, nothing written, the first revocation keeps its instant and cause — idempotent DELETE) from **another
  account's or an unknown one** (`McpConnectionNotFoundException`, one 404 problem+json with a constant `detail`, so
  the API is no oracle on a UUID's existence; `log_level: info`). An id in capitals or malformed never reaches the
  service: `Requirement::UUID`, the router's 404. **An expired connection the purge has not marked yet is not
  revoked**: the person revokes it `person`, not `expired` — the cause says who cut.
- **Lock order** (the person's statement **alone**): the connection, `FOR NO KEY UPDATE`, by id; it holds nothing
  while waiting for a row, so no cycle can contain it. (The account-wide revocations below do not share that: they
  hold the account.) A revocation crossing a refresh
  waits for its COMMIT then revokes (the refresh's `last_used_at` intact); crossing the purge's marking it answers
  204 and writes and audits nothing, the cause staying `expired`
  (`OwnMcpConnectionRevocationConcurrencyTest`, real processes, `.claude/rules/backend-outillage.md`).

## The account-wide revocations: a port of `Shared`, called inside the lock

`Security/User` must name no class of `Mcp` (`.claude/rules/security-authentication-audit.md`), and `Mcp` opens no third
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
of it before inserting, the cascading deletion follows the same direction). **That does not make a cycle impossible**:
the account-wide revocation can cross the first exchange of a new grant's code — accepted limit 4 (F1). The
« no cycle » of the person's `DELETE` above holds for that statement alone.

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
  `RoleChangeOutcome` (`Security/User/Application`, readonly: `unchanged()`, `changed(?RevokedAccountMcpConnections)`;
  the closure's body is the private `writeRole()`), not a tuple read by position; `role-changed` is audited first, then the connections. This is the only
  application path that withdraws the tier; account deletion already cuts connections by cascade (its audit is #652).
- Not a writer: `CpgUserRepository::upgradePassword()` (rehash of the same secret at login) and `CpgUserRegistrar`
  (creates an account, changes none).

**What the revocation does not cover, and R1 closes**: it cuts what exists, not what a **stolen session cookie** grants
afterwards. So the same two password writers also advance the **credentials version**:
`CpgUser::replacePassword($hash)` writes the password **and** `credentials_version` (`Version20261028180000`,
`INT NOT NULL DEFAULT 0`, fingerprint n° 39 of `verify.sql`) in the lock's closure, and the JWT carries a copy
(`cv`). The consent refuses a session whose copy differs (`.claude/rules/mcp-oauth.md`) and, since R1b, the whole `api`
firewall does (`.claude/rules/security-authentication-sessions.md`): the account-wide revocation and the cookie's
refusal together close the window. **R1 first dated the change** (`credentials_changed_at`, `Version20261028160000`,
nullable, no backfill: writing `now()` would have voided every open session without any password having changed;
fingerprint n° 38) and compared it with the JWT's `iat`; S1 replaced the date by the version (a login that read the old
hash before the change's COMMIT but signed later passed, two pods' clocks refused a legitimate sign-in), and S4 removed
the then-unread column (`Version20261028200000`, `DROP COLUMN`, fingerprint n° 40; `down()` recreates it nullable and
empty). **Added then dropped in the same deployment**: the column was never delivered (neither `main` nor `develop`
holds `Version20261028160000`; the production code names it nowhere), so the expand/contract rule « never drop a column
in the version that stops reading it » (#175) protects nothing here; for a delivered column it would take two versions.
`Version20261028160000` is untouched (already run on dev and test databases) and **its test rebuilds the state before
the drop inside its rolled-back transaction** — it recreates the column as `Version20261028200000::down()` does, then
replays its own `down()`/`up()`: a migration test whose column a later migration removed starts from that state.
`AccountWideRevocationTest`, `CpgUserTest`, `CpgUserRepositoryTest`, `Version20261028180000Test`, `Version20261028200000Test`.

## The abandoned grant (#730): a connection whose code is never exchanged expires after an hour

Supersession happens at a code's first exchange (#726, D2), so an approval whose code is never exchanged left a
connection nobody replaced nor used, alive for the idle delay (30 days), and they piled up.

- **A third deadline, until the first exchange.** `mcp_connection.code_exchanged_at` (`datetimetz_immutable`,
  nullable; `Version20261028140000`), written by **the revocation of the code**: league revokes the code **last**, after
  both tokens, so `AuthCodeRepository::revokeAuthCode()` calls `McpConnectionRepositoryInterface::recordCodeExchangeOf($codeHash,
  $at)` right after `revokeByHash()` — one statement, `UPDATE mcp_connection SET code_exchanged_at = :at WHERE
  code_exchanged_at IS NULL AND id IN (SELECT connection_id FROM mcp_authorization_code WHERE code_hash = :h)`: the
  connection is found by the code's row, the first exchange is the only one noted, and it happens **before** the token
  endpoint supersedes the pair's others (lock order unchanged). It was first written by `CodeExchangeSupersession`
  (#730) and moved by R4 (CR3): there it depended on decrypting the code and re-reading the connection, and the early
  return of an unreadable code skipped it with the supersession — the connection that served stayed « never
  exchanged » and expired in an hour, silently. `CodeExchangedAtTest` (the read port replaced by a mute one: tokens
  issued, column written; red before the code), `AbandonedGrantsTest`. `McpConnection::recordCodeExchangeAt()` (`??=`)
  survives as the in-memory twin that builds an exchanged state in tests. **A supersession skipped is signalled**
  (R5a): after a successful exchange, a code naming no connection, or a connection the database no longer knows, is held
  impossible; should it happen the pair's older connection stays alive unannounced, so `CodeExchangeSupersession` logs
  `critical` (two constant messages, no context) and leaves the answer unchanged (two tests). While the column is null,
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
  row would read as unexchanged and expire an hour after its grant. All **five** migrations of #650 and #730 run
  **before** the rollout, in order: `Version20261028120000`, `Version20261028140000`, `Version20261028160000`,
  `Version20261028180000`, `Version20261028200000` (fingerprint n° 40 the last). `credentials_changed_at` is added by the
  third and dropped by the fifth, in the same deployment.
  **The migration-to-rollout window exposes nothing for this delivery** (settled in review, the migration is not
  editable): a connection exchanged by the *old* code between the migration and the end of the rollout would stay null
  and expire in an hour — but neither `main` nor `develop` holds any `backend/src/Mcp/Connection` code, so no old code
  can exchange a code in that window, and the phase 2 is not out. **For a later column of this kind**: expand/contract in
  two versions (the first writes the column, the second reads it), or an idempotent `UPDATE` run again after the
  rollout. A catch-up at the refresh does not work — the refresh comes after the one-hour deadline.

## The consent page names the account, and the decision is bound to it

`GET /api/oauth/consent` also returns `account{id, username}`, read from `#[CurrentUser]` — the account whose cookie
arrives with **this** request, not the state a page kept (another tab may have switched account). The route is already
`ROLE_TRUSTED` and the identifier is the caller's own; it enters no log. The page (`OAuthConsentPage.vue`) shows the
name in a section of its own and offers « Changer de compte » (a `RouterLink` to the login with `redirect` and
`reauth=1`, the mechanism of #647), outside the decision buttons' container.

**The `POST` is bound to that account** (R2, F5; it lifted the limit the first delivery had accepted: « the POST grants
to the cookie's account »). The body carries **`accountId`**, the `account.id` the summary showed (the UUID, which is
never reused and tells the page nothing it lacks); `ConsentRequest` requires it — `Assert\NotBlank` and `Assert\Uuid`,
a missing, empty or malformed one is a **422** (`NotBlank` because `Uuid` and `Length` let the empty string through,
and `toDecision()` could not read it: a 500 instead of a 422). **No tolerance of an absent `accountId`**: an old
frontend left open across a deployment re-reads the request from its assistant, and an optional guard is defeated by
omitting the field. `ConsentService::decide()` compares with `Uuid::equals()` (not strings: capitals are the same
account, RFC 9562 §4) and a difference is `ConsentAccountChangedException`, a **409 `/errors/consent-account-changed`**,
constant `detail` (no account in the body), `log_level: info`, nothing written or audited. **Approval and denial are
both bound**, one rule: a denial creates nothing, so binding it protects nothing, but it costs a click and spares an
exception to explain. **The order of the checks** (`decide()`): the request's revalidation (an invalid request stays
its 400, which a re-read does not mend); the account shown (409); the session predating the password change (R1, the
401 `reauthentication-required`); then, for an approval only, the authentication age. `ConsentServiceTest`,
`ConsentControllerTest` (`testAnApprovalShownToAnotherAccountIsRefusedAndGrantsNothing`, `…ADenialShownToAnotherAccountIsRefusedToo`),
`ConsentRequestTest`.

The page: `ConsentAccountChangedError` (the 409 recognised by its `type`, never the `detail`) →
`useOAuthConsent` `failure: 'account-changed'`, **terminal** (the request is no longer shown) and the session **not
marked expired** (it still holds for the site); a `role="alert"` and a « Relire la demande » button call **`reload()`**,
which reads the request again under the current account. `OAuthConsentPage.spec.ts`, `useOAuthConsent.spec.ts`,
`HttpOAuthConsentRepository.spec.ts`.

## Frontend

- **Slice**: `domain/mcp` (`McpConnection` entity, `McpConnectionsRepository`, two errors) → `infrastructure/mcp/
  HttpMcpConnectionsRepository` → `application/mcp/useMcpConnections` (status `loading|ready|error`, revocation
  outcome `revoked|already-gone|failed`; the list is **re-read from the server** after a revocation, the frontend
  recomputes no state nor deadline; a 401/403 marks the session expired like `useAssistant`, and `sessionExpired` tells
  the page; `load()` keeps only the last read launched) → `presentation/pages/McpConnectionsPage.vue`, provided in
  `main.ts`. Dates: `presentation/format/instant.ts`, `Europe/Paris`. What the slice shares with the rest of the
  frontend (the sign-in-again link, `expired=1`, the list contract, the two apps' `accountId` contract, the
  labels « Jamais aboutie » / « Pas encore renouvelée » / « Sans échéance »): `.claude/rules/frontend.md`.
- **The page** `/<locale>/mcp-connections`: `requiresAuth`, `roles: [ROLE_TRUSTED, ROLE_SUPER]`, `noindex` (the guard is
  a courtesy, `/api/me` is the authority). One table (`<caption>`, `th scope`), the page title its only heading;
  « Révoquer » only on `active` rows, its accessible name carrying the client and the grant date; `window.confirm()`
  naming the client (the backoffice convention); the only `role="status"` of the page announces the outcome, a failure
  is a `role="alert"`; after a revocation focus lands on the `<h1>`. A cause missing from `CAUSE_LABEL_KEYS` (a newer
  backend) reads as the generic label: causes are mapped explicitly, never built by string concatenation.
- **Desktop account menu**: `presentation/layout/AccountMenu.vue`, a WAI-ARIA **disclosure** (button with
  `aria-expanded` and `aria-controls`, a list of ordinary links), not a `role="menu"`. It closes on a second click,
  Escape (focus returns to the button), a click outside, a navigation and the choice of an entry; its list is hidden
  by `v-show`, so `aria-controls` names an element that exists while closed (`.claude/rules/frontend-a11y.md`). It groups the actions
  of the **trusted tier** that used to sit flat in `AppHeader` (assistant, administration or CV download, logout) plus
  « Mes connexions MCP ». Under 768 px the existing icon menu stays, with one more link.
  **The base tier keeps its flat actions on desktop** — the author's decision of 2026-10-28: the menu is the trusted
  tier's. Not checked in a browser yet (the task-10 control: tab order, visible focus, contrast, 768 and 1024 px widths;
  it needs a trusted-tier session) — to do and to note in the PR (`.claude/rules/frontend-a11y.md`).

## Accepted limits (the author's decisions of 2026-10-28; revisit through a new issue)

The first delivery listed three; review R1, R1b and R2 **lifted two of them** and R3 and R4 added two.

1. ~~**The session cookie survives a password change.**~~ **Lifted** (R1 for the consent, R1b for the whole API): a session
   opened before the last password change is refused (`.claude/rules/security-authentication-sessions.md`). What stays,
   written there: the JWT is otherwise not revocable (ADR 0003). The « same second » limit of the first form is gone
   with the date (S1).
2. ~~**The `POST /api/oauth/consent` grants to the account of the cookie at sending.**~~ **Lifted** (R2): the decision
   is bound to the account the summary named, a 409 otherwise.
3. **An approval in flight crossing the loss of the tier** can insert a connection right after the revocation. The
   authenticator and the refresh judge the tier again and refuse it at use; it is simply not marked revoked, and
   would live again if the tier came back within its lifetime.
4. **An account-wide revocation crossing the first exchange of a new grant can deadlock; the revocation side is
   retried once, the exchange side is not** (#650, F1, the author's decisions of 2026-10-28; S3 of the second review,
   CR4; revisit through a new issue). An account holds C1 (an older active connection) and C2 (a new grant to the same
   client; UUID v7, so C1 < C2). The first exchange of C2's code locks C2 (league, `FOR UPDATE`) and then wants C1
   (`revokeOtherActiveOf()`); `revokeAllActiveOfAccount()` (password change, loss of the trusted tier, a setup link
   replacing a password) holds the account and locks the connections by id: C1, then C2. If they cross, each waits for
   what the other holds, PostgreSQL aborts one (`40P01`) and the other proceeds. **Both transactions stay atomic.**
   **The revocation side is retried once** (`DeadlockRetryInterface`, S3/CR4: the whole locked transaction is replayed on
   a reset EntityManager, the account or token re-read, the hash not recomputed, the audit written once after the COMMIT
   that succeeded); it then ends after the exchange that won (C1 `superseded`, C2 `password-changed`, one
   credentials-version increment, one `mcp-connection-revoked` line — C2's — and one `password-changed`, one
   `deadlock-retried` log line). A second deadlock surfaces (a 500, as before). Without the retry the administrator
   remedying a compromised password got a 500, the password unchanged and the connections alive: the remedy failed
   silently. **The exchange side is not retried** (league, out of scope): the OAuth client gets a 500 from the token
   endpoint, outside the OAuth format (no OAuth layer catches a `DriverException`), its code is left unexchanged
   (not revoked, `code_exchanged_at` null) and it presents it again; the revocation then ends with C1 and C2 both
   `password-changed`, two audit lines, no retry. No `NOWAIT`, no pair lock before league. **Where the retry lives**: a
   port `Security/User/Application/DeadlockRetryInterface::retryOnce(Closure(bool $isRetry): T)` and its adapter
   `Infrastructure/Doctrine/DoctrineDeadlockRetry`, asked for by each service
   (`CpgUserAdministrator::changePassword()`, `CpgUserRoleAdministrator::setSuperAdmin()`,
   `PasswordSetupService::complete()`), **never inside the account locks** nor `PostgresLockedTransaction`: their other
   callers are not replayable (the invitation handler sends a link, the purge counts what it deletes). Only
   `DeadlockException` is retried (any other exception, `DriverException` included, surfaces on the first attempt);
   `ManagerRegistry::resetManager()` runs before the second attempt, **always** (the cancelled transaction had written,
   so the skeleton closed the EntityManager, #549; the manager is a lazy object replaced in place); no loop (a cycle
   that forms three times in a row is a defect to see, not a chance to absorb) and no wait (PostgreSQL has already broken
   the cycle). The second attempt re-reads what the first held: `changePassword()` the account (`findOneById`, 404 if it
   vanished, no audit), `complete()` the token by its hash (a link consumed meanwhile is refused as everywhere),
   `setSuperAdmin()` nothing (the administration lock re-reads target and actor by id). The retry logs a `warning`,
   `event` `deadlock-retried`, the SQLSTATE only (the driver's message copies the statement). Pinned in real
   processes by `AccountWideRevocationDeadlockTest`, one case per victim (`deadlock_timeout=100ms` on the child meant to
   lose; the child prints `retried <n>`, the retries it absorbed; mutations: `SKIP LOCKED` goes red at the wait,
   `ORDER BY c.id DESC` goes red because the cycle disappears), the retry itself by `DoctrineDeadlockRetryTest` (port
   alone) and `DeadlockRetryIntegrationTest` (the three services on the real database); mutations measured: the retry
   removed turns 13 tests red, `resetManager()` removed 6 (`EntityManagerClosed`), a retry on any `Throwable` 9.
5. **Approvals in a burst are not capped on the Symfony side.** The non-revoked half of the list is unbounded because
   bounded by nature (above); a burst of approvals not yet exchanged would lengthen it until the nightly purge marks
   them. Only nginx's rate limit (per IP and per pod, shared with authorize, token and revoke) slows it.
6. **The migration's backfill cannot tell** an exchanged, never-refreshed connection whose tokens were purged from an
   abandoned one: impossible with the shipped durations (token lifetimes outlast the connection's idle deadline).
7. **The pre-selection margin of #730 only widens the pre-selection.** Zero would be the deadline itself and the purge
   would stay correct: `isExpiredAt()` decides, never the SQL; `ConnectionLifetimesTest` pins the value.
8. **`CpgUserRepository::upgradePassword()` revokes nothing**, by design: same secret.
9. **Roles edited by hand in SQL are not covered.** Judged at use.

## Tests

`OwnMcpConnectionsControllerTest` (list, shape, `terminatedTruncated`, two reads, no token in the body, base tier 403,
anonymous 401, 204 idempotent, indistinguishable 404, CSRF, audit once, audit failure leaves the 204),
`OwnMcpConnectionsTest`, `RevokedConnectionAuditTest`, `AccountMcpConnectionsRevokerTest`, `AccountWideRevocationTest`
(the three writers, atomicity, no line for nothing to revoke), `AccountWideRevocationDeadlockTest` (limit 4, one case per victim),
`OwnMcpConnectionRevocationConcurrencyTest`, `AbandonedGrantsTest`, `CodeExchangedAtTest`, `ConsentServiceTest`,
`ConsentControllerTest`, `ConsentRequestTest`, `McpConnectionRepositoryTest`, `ConnectionLifetimesTest`,
`McpConnectionExpiryTest`, `DoctrineReadSnapshotTest`, `DoctrineDeadlockRetryTest`, `DeadlockRetryIntegrationTest`, `Version20261028120000Test`, `Version20261028140000Test`, `Version20261028160000Test`, `Version20261028180000Test`, `Version20261028200000Test`;
frontend `McpConnectionsPage.spec.ts`, `useMcpConnections.spec.ts`, `HttpMcpConnectionsRepository.spec.ts`,
`AccountMenu.spec.ts`, `AppHeader.spec.ts`, `instant.spec.ts`, `adminGuard.spec.ts`, `OAuthConsentPage.spec.ts`,
`useOAuthConsent.spec.ts`, `HttpOAuthConsentRepository.spec.ts`, `LoginPage.spec.ts`. Fixture
`frontend/tests/support/mcpConnectionList.ts` builds the list the API answers.
