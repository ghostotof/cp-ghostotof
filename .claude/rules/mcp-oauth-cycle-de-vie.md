---
paths:
  - "backend/src/Mcp/Connection/**"
  - "backend/tests/Mcp/Connection/**"
  - "backend/config/packages/security.yaml"
  - "k8s/base/ingress-oauth-metadata.yaml"
  - "frontend/src/presentation/pages/OAuthConsentPage.vue"
  - "frontend/src/application/mcp/useOAuthConsent.ts"
  - "frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts"
  - "frontend/src/domain/mcp/**/*Consent*"
  - "frontend/src/domain/mcp/services/consentParams.ts"
  - "frontend/src/domain/mcp/errors/ReauthenticationRequiredError.ts"
  - "frontend/src/presentation/ui/useDeliberateActivation.ts"
  - "frontend/src/presentation/router/reauthentication.ts"
  - "frontend/tests/**/*OAuthConsent*"
  - "frontend/tests/domain/mcp/services/consentParams.spec.ts"
  - "k8s/base/messenger-purge-cronjob.yaml"
---

# Backend — `Mcp/Connection`: lifetime of a connection and its tokens (#649)

> Decisions: `docs/adr/0006-serveur-mcp.md`, D3 and D6; spec `.claude/specs/0007-mcp-server.md`, phase 2 (issue
> #649: lifetimes, rotation, reuse, expiry, purge). Split out of `.claude/rules/mcp-oauth.md` from the start
> (34 KB, which #649 to #652 would push past `tools/check-claude-rules.sh`'s 50 000 bytes); that rule keeps the
> authorization server itself and points here. Vocabulary: `CONTEXT.md`, « Accès MCP ». The index of every rule
> is at the top of `.claude/CLAUDE.md`.

- **The durations live in configuration, nowhere else** (`Domain/ValueObject/ConnectionLifetimes`). Five
  parameters of `config/services.yaml`, ISO 8601 intervals: `app.mcp.lifetime.authorization_code` (`PT10M`),
  `access_token` (`PT1H`), `refresh_token` (`P30D`, rotated at each use), `connection_idle` (`P30D` without use)
  and `connection_maximum` (`P90D` after the grant, whatever the use). The service is built by
  `ConnectionLifetimes::fromConfiguration()` (a factory: private constructor), which refuses, with
  `InvalidConnectionLifetimeException` naming the parameter (`codeLifetime`, `accessLifetime`, `refreshLifetime`,
  `idleLifetime`, `maximumLifetime`: names that are no secret's, so `SecretParametersTest` asks for no
  `#[SensitiveParameter]`) and never the value, an unreadable interval, a zero one, an idle delay beyond the maximum and an access token outliving its refresh token — compared on one fixed
  instant (`2026-01-01T00:00:00+00:00`), so the verdict never depends on the day the container is built. Each
  getter returns a **copy** (`DateInterval` is mutable). `AuthorizationServerFactory` reads league's three
  durations from it and `McpBearerAuthenticator` hands it to `McpAccessToken::refusalAt()`: no constant keeps a
  copy (`McpConnection::IDLE_LIFETIME`/`MAX_LIFETIME` and the factory's `*_TTL` are gone).
  **One value for every environment, never a `%env()%`**: `SecurityServicesEnvironmentParityTest` compares every
  `App\` definition across environments (#659, `.claude/rules/backoffice-api.md`, « Authorization »), and a token
  lifetime of prod alone would escape every test. `ConnectionLifetimesWiringTest` reads the container's service
  and an exchanged code's `expires_in`; `ConnectionLifetimesTest` the refusals.
- **A connection's expiry is defined by two deadlines, once, and is monotone.** `ConnectionLifetimes::idleDeadline($lastActivity)`
  and `maximumDeadline($grantedAt)` add the duration (`add(new DateInterval(…))`, never `modify()`, #445);
  `McpConnection::isExpiredAt($now, $lifetimes)` is `$now >= idleDeadline(lastUsedAt ?? grantedAt) || $now >=
  maximumDeadline(grantedAt)` — exclusive, dead at the second of the deadline. **The only definition of expiry**:
  the purge pre-selects its candidates in SQL, then judges each row with `isExpiredAt()` in PHP. **Deadlines,
  never thresholds** (`$now` minus the duration, the first form, rejected in task 1's review): a deadline depends
  on the connection alone, so once dead a connection stays dead; a threshold moved back an hour while `$now`
  crossed the repeated autumn hour, and a connection granted `2026-09-25T02:30+02:00` was dead at `00:45Z`, alive
  at `01:15Z`, dead at `01:45Z` — and a refresh in that hour would have rewritten `last_used_at`, granting thirty
  more days. Days are calendar days of **`$now`'s zone**: `isExpiredAt()` moves both instants there before adding,
  because Doctrine hydrates a `datetimetz_immutable` with a fixed offset (`+02:00`), on which 30 days last 720
  hours — a connection read from the database would die an hour before the same one in memory; the clock always
  gives Paris, so the deadline stays fixed. In Paris 30 days across the autumn change last 721 hours, the
  deadline at the same wall time; a wall time the spring change skips (`02:30` on 2026-03-29) is pushed past the
  gap (`03:30` CEST): one hour more, never less. `ConnectionLifetimesTest` (deadlines, the skipped hour),
  `McpConnectionExpiryTest` (boundary second by second, the fixed offset, the review's reproduction, a 15-minute
  sweep across both changes asserting monotonicity).
- **A connection is revoked once, with its cause, by the repository only.** `McpConnection` has no mutator of
  `revokedAt` nor of `revocationCause` (`Domain/ValueObject/RevocationCause`: `client`, `refresh-token-reuse`,
  `code-replay`, `superseded`, `expired`; #650 adds `person`). `McpConnectionRepositoryInterface::revokeActive($id,
  $cause, $at)` is **one native SQL statement through the EntityManager's DBAL connection, never the unit of work**:
  `UPDATE mcp_connection c SET revoked_at, revocation_cause FROM mcp_client cl WHERE c.id = :id AND c.revoked_at IS
  NULL … RETURNING c.id, c.account_id, cl.client_id` (`:at` typed `Types::DATETIMETZ_IMMUTABLE`, `:id`
  `UuidType::NAME`). Why: league detects a reused refresh token or a replayed code **inside** `TokenEndpoint`'s
  transaction, whose `wrapInTransaction()` rolls back **and closes the EntityManager** on the refusal — the
  revocation must be written after that, and a closed EntityManager keeps its connection
  (`testRevokeActiveWorksOnAClosedEntityManager`). The database decides: the first revocation keeps its instant and
  cause, a second one (or an unknown id) returns `null`; it returns a `RevokedConnection` (an `AccountReference`:
  the account's UUID, the client's **public** `client_id`, the cause) read from the written row, never from an
  instance loaded earlier — which is **not** refreshed, like the tokens' `revokeByHash()`. The database refuses a
  half revocation, an instant without a cause or the reverse (`chk_mcp_connection_revocation_cause`,
  `CHECK ((revoked_at IS NULL) = (revocation_cause IS NULL))`, SQLSTATE 23514 — DBAL 4.5 has no dedicated
  exception class, the tests read the SQLSTATE and the constraint's name). **Revoking a connection does not touch
  its codes' and tokens' rows** (decision 6 of #649): every reader judges the connection (`isRevoked()`), and a
  token's `revoked_at` keeps its one meaning — a code's single use, a refresh token's rotation —, which is what
  makes a rotated refresh token's reuse recognisable. The partial index `idx_mcp_connection_active_pair`
  (`account_id, mcp_client_id WHERE revoked_at IS NULL`, declared on the entity too so that `migrations:diff` stays
  empty) serves the search for a pair's active grant, which a new grant supersedes. `McpConnectionRepositoryTest`.
- **Two application paths revoke a connection, chosen by the transaction.** Outside a transaction, after a
  rolled-back one: **`McpConnectionRevokerInterface::revoke($id, $cause)`** — the clock's instant, the repository's
  revocation, then, only when it took effect, the audit line `mcp-connection-revoked` (`userId`, `connectionId`,
  `clientId`, `cause`; `.claude/rules/security-authentication.md`), **after** the write; one statement, callable
  after a rolled-back exchange (the token endpoint's reuse and replay, below). Inside a transaction: **the
  dedicated repository method** — `revokeActive()` (the revocation endpoint's, below), `revokeOtherActiveOf()` (the
  superseding grant's), `revokeExpired()` (the purge's) — in the transaction, which returns what it revoked
  (`?RevokedConnection` or a `list<RevokedConnection>`), and the audit written by the caller **after the COMMIT** —
  the revoker there would audit before it, and a ROLLBACK would leave a line for a revocation that never happened.
  Either way, no line for a connection the statement did not revoke (unknown or already revoked). `expired` is audited through
  `SecurityAuditLoggerInterface::mcpConnectionExpired()` (actor `system`, the purge being a scheduled command), any
  other cause through `mcpConnectionRevoked()` (actor read from the token storage). `McpConnectionRevokerTest`
  (audited once with what the repository returned, never when it returned `null`).
- **The refresh is judged under the lock of its connection** (task 3 of #649, `Infrastructure/League/RefreshTokenRepository::isRefreshTokenRevoked()`
  — league's name; what it judges is everything that makes a refresh token unacceptable). League calls it from
  `validateOldRefreshToken()`, inside `TokenEndpoint`'s transaction, **after** binding the token to the client
  (« Token is not linked to client »: a refresh token presented under another `client_id` never reaches this
  repository, so it never revokes its real client's connection —
  `RefreshRotationTest::testARefreshTokenPresentedByAnotherClientLeavesTheConnectionAlone`) and after its own
  `expire_time < time()` check, kept as a second net. In order: the token by its hash (unknown → dead); **its
  connection re-read `FOR UPDATE`** through `McpConnectionRepositoryInterface::lockForIssuance()` —
  `RereadUnderLock::row(…, forUpdate: true)`, the project's one re-read under a lock, held until the COMMIT or
  ROLLBACK, bounded by FPM's `lock_timeout=5s` (gone → dead) —; **the token re-read under the lock**
  (`findOneByHash()` carries `HINT_REFRESH` on the refresh-token and code repositories: without it Doctrine hands
  back the managed instance unrefreshed, and a rotation a concurrent request committed during the wait would stay
  unseen; the sequential tests cannot tell — measured: `forUpdate: false` leaves every one of them green —,
  `TokenEndpointConcurrencyTest::testTwoSimultaneousRefreshesOfOneTokenRevokeTheConnection` can (task 5: two real
  processes present one refresh token, the second released at the first's first flush — the last use, written
  under the lock — and blocked on the `FOR UPDATE` until the COMMIT; it must then be refused « Token has been
  revoked », the connection revoked `refresh-token-reuse`, one new pair in rows; under the mutation it is served a
  second pair, red, see `.claude/rules/backend-outillage.md`, « Concurrency tests »)); the connection revoked →
  dead, no second revocation, no audit; **the token
  revoked on an active connection → `RotatedRefreshTokenReusedException`** (a `CompromisedMcpConnectionException`,
  `Domain/Exception`: `connectionId()`, `revocationCause()`, a constant message, never the token), judged
  **before** the deadlines so that a rotated token coming back on an expired, not yet purged connection is a reuse
  too (`testAReusedTokenOnAnExpiredConnectionIsStillAReuse`); the token past its `expiresAt` (`<= now`, exclusive
  like the access token's), the connection past `isExpiredAt()`, the account below the trusted tier → dead, **nothing
  written**: a client refreshing in a loop accumulates no token row (`testAnExpiredConnectionCannotRefresh`,
  `testAConnectionPastItsMaximumCannotRefresh`, `testAnAccountNoLongerTrustedCannotRefresh` count the rows before
  and after; the connection is not revoked, the purge will mark it); else `McpConnection::recordUseAt($now)` and
  `save()` when it wrote. **Every judgement reads `ClockInterface::now()`** (Paris), the token's expiry included:
  the answer to the « two clocks » review — league's `time()` is a second net, identical in production, never the
  judge here (`LeagueRepositoriesTest::testARefreshTokenExpiryIsJudgedOnTheApplicationClock`: a token still valid
  for `time()`, dead for a `MockClock` at its deadline, alive one second before).
- **A compromised connection is revoked after the ROLLBACK, by the token endpoint.** The exception leaves league
  through `wrapInTransaction()`, which rolls back (nothing of the exchange survives: no token issued, the old pair
  still unrevoked in rows, the last use unwritten) and closes the EntityManager; `TokenEndpoint::respond()` catches
  `CompromisedMcpConnectionException` **outside** the transaction, calls `McpConnectionRevokerInterface::revoke()`
  (native SQL, works on the closed manager; the audit line `mcp-connection-revoked` with `cause`), then throws
  league's own refusal word for word, chosen by cause in one `match` (`RefreshTokenReuse` →
  `invalidRefreshToken('Token has been revoked')`, `CodeReplay` → `invalidGrant('Authorization code has been
  revoked')`, the three other causes — which never arise at the token endpoint — a bare `invalidGrant()`): an
  attacker learns nothing the refusal did not say before. **Accepted, documented, never worked around**: a client
  that replays its refresh after a lost answer (the network cut after the COMMIT) presents a rotated token, loses
  its connection and must reconnect — the price OAuth 2.1's rotation accepts
  (`RefreshRotationTest::testReusingARotatedRefreshTokenRevokesTheConnection`, which also reads the audit line:
  `userId`, `connectionId`, the public `clientId`, `cause`, no token value). Revoking the connection leaves its
  token rows as they are (decision 6): `OAuthFlowTest` sees the successor still unrevoked in rows and worthless at
  the endpoint.
- **Revoking a refresh token at the revocation endpoint revokes its connection** (task 6 of #649, decision 3: RFC
  7009 §2.1 — revoking a refresh token may revoke the whole authorization it carries), cause `client`.
  `LeagueRevocationEndpoint::revokeRefreshToken()` revokes the connection, through `revokeActive()`, the token and its
  access token in **one transaction**, which returns the `?RevokedConnection`; the audit line is written after the
  COMMIT, only when this revocation took effect (a connection already revoked keeps its first instant, cause and
  line). **The lock order is the token endpoint's**: the connection first, re-read `FOR UPDATE`
  (`lockForIssuance()`), then `revokeActive()`, then the refresh token and the access token (`revokeByHash()`). The
  refresh takes C, then A, then R; a revocation taking R and A before C deadlocked with a refresh of the same token
  held between its lock and its rotation — measured in real processes, the revocation aborted in `40P01` (a 500), the
  refresh served and the connection **left active** with the new pair
  (`RevocationEndpointConcurrencyTest::testARevocationCrossingTheRotationOfItsTokenRevokesTheConnection`: red under
  the old order, green since — the revocation waits on C, then revokes). **The cause is decided under the lock**, on
  the refresh token re-read there (`HINT_REFRESH`): already revoked while the connection is active, it was rotated,
  and handing it back is the same signal as presenting it at the token endpoint — cause `refresh-token-reuse`
  (`RevocationControllerTest::testRevokingAnAlreadyRotatedRefreshTokenIsAuditedAsReuse`; the concurrency test ends
  there too, the rotation committed during the wait); otherwise `client`. Only a refresh token issued to the client
  presenting it (`issuedTo()`): another client's token, unknown or unreadable, is the same 200 with nothing revoked. **Revoking an access token alone leaves the connection active**
  (§2.1 only allows cutting the rest). The intended consequence: a revoked refresh token presented on a **still
  active** connection can only come from a rotation, so its reuse is recognised without ambiguity; presented after
  the client revoked it, it meets a revoked connection — a plain `invalid_grant`, no second line, the cause stays
  `client`. `RevocationControllerTest::testRevokingARefreshTokenRevokesItsConnection` (the audit line read on the
  revocation's request, none on the later refresh), `testRevokingAnAccessTokenRevokesItsRowOnly` and
  `testATokenIssuedToAnotherClientIsLeftAlone` (the connection active, no line).
- **A replayed code is judged under the same lock** (task 4 of #649, `Infrastructure/League/AuthCodeRepository::isAuthCodeRevoked()`,
  written on the refresh's model; OAuth 2.1 §4.1.2). League's code grant calls it from `validateAuthorizationCode()`,
  inside `TokenEndpoint`'s transaction, after authenticating the client, decrypting the payload and judging the
  code's expiry **on the encrypted payload alone** (`expire_time` against `time()`, the real clock) — so **a code
  replayed after its ten minutes is a plain `invalid_grant`, with no revocation, and no `MockClock` reaches it**:
  accepted limit, the tokens issued by the code already live under rotation by then. In order: the code by its hash
  (unknown → dead); **its connection re-read `FOR UPDATE`** (`lockForIssuance()`, the same lock as the refresh's, so
  two simultaneous exchanges of one code serialize —
  `TokenEndpointConcurrencyTest::testTwoSimultaneousExchangesOfOneCodeRevokeTheConnection` proves it in real
  processes, the second released at the first's first flush, the new access token, and refused « Authorization code
  has been revoked » once the first committed, the connection revoked `code-replay`, one pair in rows; under
  `forUpdate: false` both are served, red); **the code re-read
  under the lock** (`HINT_REFRESH`); the connection revoked → dead, no second revocation, no audit
  (`CodeReplayTest::testAReplayOnARevokedConnectionWritesNoSecondLine`: a third exchange leaves the first revocation's
  instant and cause, and the journal's single line); **the code revoked on an active connection →
  `AuthorizationCodeReplayedException`** (a `CompromisedMcpConnectionException`, cause `code-replay`) — a code is only
  ever revoked by its exchange, which league does **last**, after both tokens (a failed exchange leaves it exchangeable,
  `TokenControllerTest::testAFailedExchangeLeavesNoTokenAndTheCodeExchangeable`), so a revoked code coming back has been
  exchanged, and `TokenEndpoint` revokes the connection after the ROLLBACK, then answers
  `invalidGrant('Authorization code has been revoked')` word for word; the tokens the code issued die with the
  connection — the refresh token received at the first exchange is refused with nothing written
  (`testExchangingACodeTwiceRevokesTheConnection`: the audit line is read on the request that writes it, and
  **every later refusal is read in turn and must write none** — the `security_audit` `TestHandler` holds the last
  request's records only, since MonologBundle tags every logger `kernel.reset` and the services resetter runs
  between two requests of a `KernelBrowser` whether the kernel reboots or not, `disableReboot()` included,
  measured in task 4: a line counted « after the last request » would always read zero). **The exception is a
  `RuntimeException`, never a `LogicException` nor an `InvalidArgumentException`**: league's code grant wraps its
  validation in a `catch` of each (`AuthCodeGrant::respondToAccessTokenRequest()`, 9.4.1) — an `InvalidArgumentException`
  becomes `invalidGrant('Cannot validate the provided authorization code')`, a `LogicException` `invalidRequest('code',
  'Issue decrypting the authorization code')` — as a `DomainException` (a `LogicException`) the compromise never left
  league, answered that `invalid_request`, and the connection stayed active (measured;
  `CompromisedMcpConnectionExceptionTest` reads the ancestors by reflection, a typed `assertNotInstanceOf` being
  « always true » for PHPStan). **League binds the code to the client after the
  repository**, unlike the refresh grant (`client_id !== $client->getIdentifier()` comes after `isAuthCodeRevoked()`): a
  code replayed under another registered `client_id` revokes its real client's connection too — **accepted and pinned**
  (`testAReplayUnderAnotherClientRevokesTheConnectionToo`): a public client's `client_id` is public (it is in the
  authorization request), whoever replays a code can name the right one, a code that comes back has leaked whatever the
  name it comes back under, and the refusal is the one league gave before the revocation, word for word; the audit line
  names the connection's client, never the replayer's. A code presented **first** by another client is refused by
  league (« not issued to this client ») after a repository call that writes nothing: the code stays exchangeable by
  its client, the connection intact, no line (`testACodePresentedFirstByAnotherClientConsumesNothing`).
  `LeagueRepositoriesTest` judges a code under a rolled-back transaction, like a refresh token (the lock requires one).
- **A new grant supersedes the previous one** (task 7 of #649, decision 2). `ConsentService::grant()`, in its
  transaction, right after adding the new connection, calls
  `McpConnectionRepositoryInterface::revokeOtherActiveOf($kept, RevocationCause::Superseded, $now)` — the second
  path above, inside a transaction: **one native SQL statement** like `revokeActive()` (`UPDATE mcp_connection c …
  FROM mcp_client cl WHERE c.account_id = :account AND c.mcp_client_id = :client AND c.id <> :kept AND c.revoked_at
  IS NULL … RETURNING c.id, c.account_id, cl.client_id`, the row mapping written once for both), served by the
  partial index `idx_mcp_connection_active_pair`; it returns a `list<RevokedConnection>`. After the COMMIT,
  `mcp-connection-granted` first, then one `mcp-connection-revoked` per superseded connection (cause `superseded`,
  the person as actor); a rolled-back grant audits nothing, a denial revokes nothing (`ConsentServiceTest`). One
  account and one client hold one active connection: a client re-authorised, or a decision sent again after a 5xx,
  leaves only the newest — its code is the one the client receives; the old connection's refresh and access tokens
  are refused with nothing written (the connection is judged, decision 6), its rows untouched.
  `ConsentControllerTest::testASecondGrantSupersedesTheFirstConnection` (audit order on the grant's request, the old
  refresh refused `invalid_grant` with no second line, the new code exchanged),
  `testAGrantForAnotherClientLeavesTheFirstAlone`, `testARetriedApprovalLeavesOneActiveConnection`;
  `McpConnectionRepositoryTest` (the kept one, another client's, another account's and an already revoked one
  untouched). **The price, decided**: two workstations using the same pre-registered client do not stay connected
  together — the second consent cuts the first. **Lock order**: the statement locks only the **other** connections'
  rows, never a token row, so it inverts nothing of the token endpoint's order (connection, then tokens); a refresh
  holding a superseded connection's row `FOR UPDATE` makes the consent wait for its COMMIT (bounded by FPM's
  `lock_timeout`), after which PostgreSQL re-evaluates `revoked_at IS NULL` on the committed row. **Accepted
  limit**: two simultaneous approvals of the same pair (a double click, which `useDeliberateActivation` makes
  unlikely) may leave two active connections — neither transaction sees the other's uncommitted row; the extra one
  has never been used and expires after `connection_idle`. A partial unique constraint was rejected: it would turn
  that double click into a 500. Tests that need two active connections of one account use another client
  (`HoldsAnMcpConnection::holdAnotherMcpConnection()`, `OTHER_CLIENT_ID`).
- **The last use is written at the refresh only, at most once per elapsed hour** (`McpConnection::USAGE_WRITE_INTERVAL`,
  `PT1H`, `recordUseAt($at)`: writes and returns `true` when no use is noted or when the interval has elapsed since
  the previous one — `add(new DateInterval(…))`, elapsed time, #445: across the repeated autumn hour the two
  instants of one hour read the same wall time, `02:30`,
  `McpConnectionExpiryTest::testTheLastUseIsWrittenAtMostOncePerElapsedHour`). Never on a call of `/api/mcp`
  (ADR 0006 D6, `testNoRequestWritesOutsideTheMcpSessions` stays green). The price: the idle deadline counts from a
  use up to an hour old. `RefreshRotationTest::testARefreshRecordsTheLastUseAtMostOncePerHour` (null after the
  exchange, set by the first refresh, left alone 30 minutes later, rewritten 61 minutes later).
- **A known token refused is audited** (task 8 of #649). `McpBearerAuthenticator` writes
  `SecurityAuditLoggerInterface::mcpAccessTokenRefused()` — event `mcp-access-token-refused`, `userId` (the
  connection's account, `McpConnection::account()`, an `AccountReference`), `connectionId`, the public `clientId`,
  `cause` = the `BearerRefusal` value; actor `anonymous`, the refusal precedes any authentication — right before
  throwing `McpBearerRefusedException` for a refusal of `McpAccessToken::refusalAt()` or of `TrustedTier`. **Never for
  `Malformed` nor `Unknown`**: no connection is known there, and a flood of garbage tokens must write no audit line
  (`testAMalformedOrUnknownTokenIsNotAudited`). **Volume**: a healthy client sees its access token expire once an
  hour (`access_token`, `PT1H`), then refreshes — at most one line per connection and per hour in normal use; more
  means a client that does not refresh, or a connection used after its revocation or expiry. **No query more**: the
  line goes to the Monolog channel, and the connection, its account and its client are those of the token's single
  join (`testARefusedKnownTokenCostsNoExtraQuery`: the refused call costs exactly the accepted call's
  authentication query, judged on the token and on the tier). Never the token nor its hash in the line
  (`testAKnownRefusedTokenIsAudited`, one case per cause, the common 401 unchanged).
- **The daily purge** (task 9 of #649): `app:mcp:purge` (`Presentation/Command/PurgeMcpConnectionsCommand`, a thin
  wrapper) → `Application/McpConnectionPurgerInterface::purge(RetentionPeriod)` → `McpConnectionPurgeResult`, run every
  night by the housekeeping CronJob, third line after the invitations (`k8s/base/messenger-purge-cronjob.yaml`, under
  `set -e`, `PGOPTIONS=-c lock_timeout=5s`; `McpConnectionsPurgedDailyTest` pins the line and that no other manifest
  runs it). **The order, in one transaction, is the token endpoint's** — every connection row first, tokens after (the
  reverse deadlocked in `40P01`, task 6): (1) `findActiveCandidatesForExpiry($idleBefore, $maximumBefore)`, a **wide** SQL
  pre-selection (`revoked_at IS NULL AND (COALESCE(last_used_at, granted_at) <= :idleBefore OR granted_at <=
  :maximumBefore)`, both bounds `now − duration + P1D`, computed in PHP with `new DateInterval`, typed
  `DATETIMETZ_IMMUTABLE`), **locked `FOR UPDATE`** on the connection rows only (no join: the account's and client's rows
  stay unlocked), `HINT_REFRESH`, ordered by id — a refresh in flight commits first and its new `last_used_at` is
  judged, instead of a stale row revoked a few milliseconds after a legitimate use; (2) each candidate judged by
  `isExpiredAt(now, lifetimes)`, **the only definition**, the SQL never decides; (3) `revokeExpired(list<Uuid>, $at)`,
  one statement like `revokeActive()` (`WHERE c.id IN (:ids) AND c.revoked_at IS NULL … RETURNING`), cause `expired`;
  (4) `deleteRevokedBefore($threshold)`, `revoked_at < :threshold`, strict, cascading — **before any token deletion**:
  a `DELETE` locks the rows it removes, and run last it locked old revoked connections after (5) had locked token rows
  of other connections, the order a cascading account deletion (`cpg_user` → `mcp_connection` → tokens) crosses
  (final review of #649); the cascade carries what tokens are left, normally none — those of a revoked connection are
  dead and went the night after its revocation, so the counters are unchanged; (5) `deleteDead($now)` on codes, refresh
  tokens, then access tokens (fewer `ON DELETE SET NULL` rewrites): `expires_at <= :now` **or** a revoked connection
  (`DELETE … USING mcp_connection`, which locks no connection row) — a rotated refresh token or an exchanged code of an
  **active** connection stays until its expiry, its row being what recognises a reuse or a replay; the tokens of a
  connection marked in (3) go in the same run. **Accepted limit**: no order of steps covers a cycle between
  `mcp_connection` rows alone — the purge locks its candidates (1, by id) then its old revoked rows (4), a deletion
  cascade takes the account's connections in physical order; PostgreSQL aborts one of the two (`40P01`) and the Job
  retries (`backoffLimit: 2`). **After the COMMIT**, one `mcpConnectionExpired()` line per connection marked (actor `system`); a rolled-back
  purge audits nothing (`testARolledBackPurgeAuditsNothing`, red with the audit moved inside, measured). Every instant is
  `ClockInterface::now()` (Paris). **Retention**: `app.mcp.revoked_connection_retention` (`'6 months'`, one value for every
  environment), the default of `--older-than`, read by `RetentionPeriod` (a date or an unreadable expression:
  `Command::INVALID`); under one day, `McpPurgeRetentionTooShortException::belowOneDay()` before any write, `INVALID` too.
  **No `--dry-run`** (decision 6): it only deletes dead tokens and connections dead for months, nothing a person could
  regret — the invitation purge has one because it deletes accounts. The output names connections by UUID only, with the
  four counters, a success even at zero. **Volumes**: the token tables stay bounded by this purge (an access token lives
  an hour, a refresh token is rotated at each use); no index on `expires_at` (sequential scans of bounded tables), to
  revisit if the volume asks — the partial index of task 2 serves the grant. **Accepted, unpinned**: no concurrency test
  proves the `FOR UPDATE` of (1) (the sequential suite stays green without it). `McpConnectionPurgerTest` (frozen clock
  across the autumn change; `testThePurgeAndIsExpiredAtAgreeAtTheBoundary`: last use at the idle threshold and one second
  after, grant at the maximum threshold and one second after, marked ⇔ `isExpiredAt()` re-read from the database — red
  when every candidate is revoked unjudged, measured), `PurgeMcpConnectionsCommandTest`.
- **The trusted tier has one judge**, `Infrastructure/Security/TrustedTier::reaches(CpgUser)`: the stored roles
  through `RoleHierarchyInterface`, the name `McpConnectionPrincipal::TRUSTED_TIER` — used by
  `McpBearerAuthenticator` on every request of `/api/mcp` (behaviour unchanged, `McpBearerAuthenticatorTest`) and
  by the refresh. A new site that judges the tier goes through it.
