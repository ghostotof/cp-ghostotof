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
  `#[SensitiveParameter]`) and never the value, an unreadable interval, a zero one, **a connection idle or maximum
  duration under one day** (`ConnectionLifetimes::MINIMUM_CONNECTION_LIFETIME`, `P1D`, lot B of the #649 reviews: it
  is the purge's pre-selection margin, read there as `PRESELECTION_MARGIN` — shorter, the bound `now − duration +
  margin` would lie in the future and the purge would lock every active connection `FOR UPDATE` each night; a second
  less than `P1D` refused `belowOneDay`), **a connection idle or maximum duration up to that margin plus the code's
  duration plus an elapsed hour and minute, included** (#726, CR1, `notBeyondFreshConnectionWindow`: with a `PT10M`
  code, `P1DT1H11M` refused, `P1DT1H11M1S` accepted) — below, a connection whose code is still exchangeable can be a
  purge candidate, and the marking may want it, which the exchange holds first, while holding another the exchange
  waits on, `40P01`; above, the purge never wants it and every other lock on both sides follows the ids: **closed by
  construction**. The hour (`CLOCK_CHANGE_MARGIN`): the purge's bound counts a calendar day of 23 or 25 hours —
  without it, measured second by second, `P1DT10M1S` let a fresh connection be pre-selected (3 599 s of overlap) on
  2026-03-29, 2026-03-30 and 2026-10-26, replayed by `testAFreshConnectionIsNeverPreselectedAcrossTheClockChanges`.
  The minute (`SECOND_TRUNCATION_AND_SKEW_MARGIN`): `granted_at` truncated to the second, league's `expire_time`
  computed after it and accepted up to 0.999 s past, clock skew between nodes. Judged per parameter, idle first,
  after positivity and before the two orderings), an
  idle delay beyond the maximum and an access token outliving its refresh token — compared on one fixed
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
  `UPDATE mcp_connection revoked SET revoked_at, revocation_cause FROM mcp_client cl WHERE revoked.id IN (SELECT c.id
  FROM mcp_connection c WHERE c.id = :id AND c.revoked_at IS NULL ORDER BY c.id FOR NO KEY UPDATE) AND revoked.revoked_at IS
  NULL … RETURNING revoked.id, revoked.account_id, cl.client_id` — **the rows locked in id order** by the sub-query
  (#726, final review: a bare `UPDATE` locks them in the order its plan reads them, the heap's or an index's; the purge
  locks by id) in **`FOR NO KEY UPDATE`**, the strength the `UPDATE` itself takes (#726, T6: no key column rewritten;
  `FOR UPDATE` also blocked the `FOR KEY SHARE` an insertion referencing the connection takes, and still conflicts with
  it, so the order and the exclusion between revocations are unchanged); a row revoked during the wait is neither
  returned nor rewritten, and stays locked until the end of the transaction; the one
  shape of the three revocations (`:at` typed `Types::DATETIMETZ_IMMUTABLE`, `:id`
  `UuidType::NAME`). Why: league detects a reused refresh token or a replayed code **inside** `TokenEndpoint`'s
  transaction, whose `wrapInTransaction()` rolls back **and closes the EntityManager** on the refusal — the
  revocation must be written after that, and a closed EntityManager keeps its connection
  (`testRevokeActiveWorksOnAClosedEntityManager`). The database decides: the first revocation keeps its instant and
  cause, a second one (or an unknown id) returns `null`; it returns a `RevokedConnection` (a
  `Shared/Domain/Security/McpConnectionReference`: the account's UUID, the connection's, the client's **public**
  `client_id` — plus `cause()`; four accessors, no public property) read from the written row, never from an
  instance loaded earlier — which is **not** refreshed, like the tokens' `revokeByHash()`. The database refuses a
  half revocation, an instant without a cause or the reverse (`chk_mcp_connection_revocation_cause`,
  `CHECK ((revoked_at IS NULL) = (revocation_cause IS NULL))`, SQLSTATE 23514 — DBAL 4.5 has no dedicated
  exception class, the tests read the SQLSTATE and the constraint's name) **and a cause outside `RevocationCause`**
  (`chk_mcp_connection_revocation_cause_value`, `Version20261027120000`, lot B of the reviews: the column is
  hydrated as an enum, so an unknown value written in SQL made every read of the connection throw `ValueError`, a
  500 where the authenticator answers 401; `McpConnectionRepositoryTest` writes each case of the enum, then an
  unknown one, `Version20261027120000Test` the honest `down()`). **#650's `person` cause widens that `CHECK` by a
  new migration before the code writes it** — expand/contract (#175): a value added to the enum alone turns the
  repository test red, and the old constraint would refuse the first `person` revocation in production. **Revoking a connection does not touch
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
  token endpoint's, at a code's first exchange), `revokeExpired()` (the purge's) — in the transaction, which returns what it revoked
  (`?RevokedConnection` or a `list<RevokedConnection>`), and the audit written by the caller **after the COMMIT** —
  the revoker there would audit before it, and a ROLLBACK would leave a line for a revocation that never happened.
  Either way, no line for a connection the statement did not revoke (unknown or already revoked). `expired` is audited through
  `SecurityAuditLoggerInterface::mcpConnectionExpired()` (actor `system`, the purge being a scheduled command),
  `superseded` through `mcpConnectionSuperseded($revoked, $supersededBy)` (#726, below), any other cause through
  `mcpConnectionRevoked()` (actor read from the token storage, as for `superseded`). **The revoker never writes
  `expired`** (lot C of the reviews: its expiry branch had no caller): asked for it, it throws
  `Application/RevocationCauseReservedToThePurgeException` before any write — an expiry audited there would carry
  the request's actor instead of `system`; **nor `superseded`** (#726): `RevocationCauseReservedToTheExchangeException`,
  its line would lack `supersededBy`. `McpConnectionRevokerTest` (audited once with what the repository
  returned, never when it returned `null`; `expired` and `superseded` refused, nothing written nor audited).
- **The refresh is judged under the lock of its connection** (task 3 of #649, `Infrastructure/League/RefreshTokenRepository::isRefreshTokenRevoked()`
  — league's name; what it judges is everything that makes a refresh token unacceptable). League calls it from
  `validateOldRefreshToken()`, inside `TokenEndpoint`'s transaction, **after** binding the token to the client
  (« Token is not linked to client »: a refresh token presented under another `client_id` never reaches this
  repository, so it never revokes its real client's connection —
  `RefreshRotationTest::testARefreshTokenPresentedByAnotherClientLeavesTheConnectionAlone`) and after its own
  `expire_time < time()` check, kept as a second net. **Steps one to five are the code's too, written once**
  (lot C of the reviews): `Infrastructure/League/TokenUnderConnectionLock::judge($found, $reread, $compromised)`
  — the token found by the caller, its re-read and its compromise passed as closures — returns the locked
  connection and the re-read token (`LockedToken`) or `null` for dead; each repository keeps its own lookup, re-read
  and exception, and the refresh its later steps. In order: the token by its hash (unknown → dead); **its
  connection re-read `FOR UPDATE`** through `McpConnectionRepositoryInterface::lockForUpdate()` —
  `RereadUnderLock::row(…, forUpdate: true)`, the project's one re-read under a lock, held until the COMMIT or
  ROLLBACK, bounded by FPM's `lock_timeout=5s` (gone → dead) —; **the token re-read under the lock**
  (`McpRefreshTokenRepositoryInterface::rereadUnderConnectionLock($id)`, and the code repository's twin —
  `RereadUnderLock::row(…, forUpdate: false)` since lot B of the reviews, the lock being the connection's: `HINT_REFRESH`,
  without which Doctrine hands back the managed instance unrefreshed, and a rotation a concurrent request committed
  during the wait would stay unseen; a token purged during the wait comes back `null`, its stale instance detached
  (#560), and the judgement says dead. `findOneByHash()` carries **no** `HINT_REFRESH` any more on those two
  repositories: the re-read under a lock is written once, in `RereadUnderLock`, never a copy
  (`.claude/rules/backend-architecture.md`). The sequential tests cannot tell — measured: `forUpdate: false` leaves every one of them green —,
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
  `CompromisedMcpConnectionException` **outside** the transaction and — for every `RotatedRefreshTokenReusedException`,
  for an `AuthorizationCodeReplayedException` only when `ReplayProofInterface` proves the PKCE verifier's possession
  (#726, « A replayed code is judged under the same lock »; unproven, it audits `replay-unproven` and throws the same
  refusal, revoking nothing) — calls `McpConnectionRevokerInterface::revoke()`
  (native SQL, works on the closed manager; the audit line `mcp-connection-revoked` with `cause`), then throws
  league's own refusal word for word, chosen by the exception's **type** in one `match` (lot C of the reviews: no
  arm left for the causes that never arise there) — `RotatedRefreshTokenReusedException` →
  `invalidRefreshToken('Token has been revoked')`, `AuthorizationCodeReplayedException` → `invalidGrant('Authorization
  code has been revoked')`; the parent is `@phpstan-sealed` on those two, so PHPStan judges the `match`
  exhaustive (an arm removed: `match.unhandled`, measured) —: an attacker learns nothing the refusal did not say
  before. **Accepted, documented, never worked around**: a client
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
  line). The access token's hash is read **before** the transaction, on the instance
  `McpRefreshTokenRepository::findOneByHash()` loads with the refresh token (a `leftJoin`, lot C of the reviews): left a
  lazy proxy, it was initialised there, and the purge deleting A in between made it throw `EntityNotFoundException`, a
  500, where the cascade's NULL reads `null`; loaded, the hash stays readable and revoking a row already gone touches
  nothing (`McpRefreshTokenRepositoryTest`, red without the join, measured). **The lock order is C → A → R**: the connection first, re-read `FOR UPDATE` (`lockForUpdate()`), then
  `revokeActive()`, then the access token and **then** the refresh token (`revokeByHash()`). Two reasons, not the
  token endpoint's own order (which fixes C before the tokens only). **C before the tokens** is the token endpoint's:
  the refresh takes C, then A, then R; a revocation taking R and A before C deadlocked with a refresh of the same
  token held between its lock and its rotation — measured in real processes, the revocation aborted in `40P01` (a
  500), the refresh served and the connection **left active** with the new pair
  (`RevocationEndpointConcurrencyTest::testARevocationCrossingTheRotationOfItsTokenRevokesTheConnection`: red under
  the old order, green since — the revocation waits on C, then revokes). **A before R** is the order of whoever
  touches both rows, and they are three: league's refresh grant revokes A then R (`RefreshTokenGrant`); the nightly
  purge's `McpAccessTokenRepository::deleteDead()` deletes A, whose FK `mcp_refresh_token.access_token_id … ON DELETE
  SET NULL` then updates R (its cascade); and the purge's own `DELETE`s run A then R (lot B of the reviews — delivered
  R then A, « fewer SET NULL rewrites », they left a residual cycle: a `/revoke` of a refresh token on an **already
  revoked** connection whose dead tokens were not purged yet held A and waited on R while the purge held R, its
  refresh-token `DELETE`, and waited on A, `40P01`; deleting an A rewrites every R that outlives it in either order, a
  live R whose access token expired included — the *extra* cost of A then R over R then A is the SET NULL rewrite on
  the dead R rows the next statement deletes, nothing more; pinned in real processes by
  `McpConnectionPurgeLockOrderTest`, lot C). Revoking R before A (the first delivery of #649) deadlocked with that purge:
  the revocation held R and waited on A, the purge held A (its `DELETE`) and waited on R (the cascade's SET NULL) —
  `40P01`; the purge does not hold C for a non-candidate (active) connection, so C guards nothing here. When the
  revocation was the victim, it answered a 500, the connection stayed active and no audit line was written while the
  client believed it had logged out (`RevocationEndpointPurgeDeadlockTest::testARevocationCrossingThePurgeOfItsAccessTokenRevokesTheConnection`:
  the test holds A and then takes R in the purge's order while the real revocation child runs — red under the old
  order, green since). **The cause is decided under the lock**, on
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
  (unknown → dead); **its connection re-read `FOR UPDATE`** (`lockForUpdate()`, the same lock as the refresh's, so
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
  exchanged. **It is raised whatever the request**: league judges the revocation before the client binding and
  before PKCE, so at that point nothing proves the replay comes from the code's holder. **`TokenEndpoint` revokes
  only on a PKCE proof** (#726, the author's decision of 2026-10-27, lifting #649's accepted limit 3):
  `Application/ReplayProofInterface::provesPossession($request)` (`Infrastructure/League/PkceReplayProof`) is true
  only when the body's `code` and `code_verifier` are strings, `GrantPayloadReader::s256ChallengeOf()` reads the
  payload's `code_challenge` under a `code_challenge_method` exactly `S256` (a non-empty string, `null` otherwise),
  and league's own `S256Verifier` (`hash_equals()`) verifies the verifier against it — never a copy of the rule.
  **Without the proof** (verifier absent, wrong, the public challenge itself, a code without an `S256` challenge):
  the same refusal word for word, **no revocation**, no `mcp-connection-revoked`, and one `mcp-grant-refused` line,
  cause `replay-unproven` (`GrantRefusal::ReplayUnproven`, passed by the caller: league's hint alone would say
  `code-refused`) — the signal of an interception: whoever reads a code on the redirect holds the code, not the
  verifier, which the legitimate client only ever sends to the token endpoint (RFC 7636 §1). The connection lives,
  its refresh token is served (`CodeReplayTest::testAReplayWithoutTheVerifierLeavesTheConnectionActive`,
  `testAReplayWithAWrongVerifierLeavesTheConnectionActive`; `PkceReplayProofTest`, the real flow's code and forged
  payloads re-encrypted under the server's key). **Measured**: `provesPossession()` always `true` turns those two
  red (`code-replay` written); always `false`, the proven replays (`CodeReplayTest`'s other three,
  `GrantRefusalAuditTest::testACompromiseIsNotAuditedTwice`, `TokenEndpointConcurrencyTest`'s code exchange, whose two
  requests present the right verifier); the method check out of `s256ChallengeOf()`, `PkceReplayProofTest`'s `plain`,
  absent and `s256` cases. **With the proof**, `TokenEndpoint` revokes the connection after the
  ROLLBACK, then answers `invalidGrant('Authorization code has been revoked')` word for word; the tokens the code
  issued die with the connection — the refresh token received at the first exchange is refused with nothing written
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
  code replayed under another registered `client_id` **with the right verifier** revokes its real client's connection
  too — **accepted and pinned** (`testAReplayUnderAnotherClientRevokesTheConnectionToo`): the verifier is the proof, a
  public client's `client_id` is none (it is in the authorization request, whoever replays a code can name the right
  one), and the refusal is the one league gave before the revocation, word for word; the audit line names the
  connection's client, never the replayer's. **The proof judges the verifier alone** (#726, audit I2):
  at the token endpoint league compares the presented `redirect_uri` only with the code's own, after the repository
  (`validateAuthorizationCode()`; never with the client's registered list, and `TokenEndpoint` adds no check), so a
  proven replay revokes under another `client_id` **or another `redirect_uri`, registered or not**, alike
  (`testAReplayUnderAnotherRedirectRevokesTheConnectionToo`, both cases; red under a proof also requiring the code's
  `redirect_uri`, measured). A code
  presented **first** by another client is refused by
  league (« not issued to this client ») after a repository call that writes nothing: the code stays exchangeable by
  its client, the connection intact, no line (`testACodePresentedFirstByAnotherClientConsumesNothing`).
  `LeagueRepositoriesTest` judges a code under a rolled-back transaction, like a refresh token (the lock requires one).
- **A new grant supersedes the previous one at the first exchange of its code** (task 7 of #649, decision 2; moved
  from the approval by #726, D2, the author's decision of 2026-10-27). `ConsentService::grant()` revokes nothing: its
  transaction adds the connection and returns league's code, then `mcp-connection-granted` alone after the COMMIT.
  `TokenEndpoint::respond()`, in its transaction, once league answered, calls
  `Application/CodeExchangeSupersessionInterface::supersedeOthersOf($request): ?Supersession` (`Infrastructure/League/LeagueCodeExchangeSupersession`:
  `null` but for `grant_type=authorization_code` with a code naming a known connection; the code's connection through `GrantPayloadReader::connectionIdOf()`
  and `findOneById()` — the managed instance `isAuthCodeRevoked()` locked `FOR UPDATE` —, then
  `McpConnectionRepositoryInterface::revokeOtherActiveOf($kept, RevocationCause::Superseded, $now)`) — the second path
  above: **one native SQL statement** like `revokeActive()` (its sub-query choosing `c.account_id = :account AND
  c.mcp_client_id = :client AND c.id <> :kept AND c.revoked_at IS NULL`, locked `ORDER BY c.id`; statement and row mapping
  written once for the three revocations, `McpConnectionRepository::revokeWhere()`, only the `WHERE` differing, one of
  its three private `WHERE_*` constants — `@param self::WHERE_*`, no caller composes SQL, lot C), served by
  `idx_mcp_connection_active_pair`. Its `Application/Supersession` — the kept connection's UUID and the
  `list<RevokedConnection>` — leaves the transaction with league's response (`Application/IssuedTokens`); after the
  COMMIT, one `mcp-connection-revoked` per superseded connection through `mcpConnectionSuperseded()` (cause
  `superseded`, actor `anonymous`: the `oauth` firewall authenticates nobody), **naming the connection that replaces
  it**, `supersededBy` (the kept one's RFC 4122 UUID, #726 audit B3: written on the exchange's request, no longer the
  consent's, nothing else ties the cut to the grant that caused it; `ConsentControllerTest` reads it,
  `SecurityAuditLoggerTest` the exact keys), `mcp-oauth.md`'s second door; its
  failure leaves the answer unchanged (#725's rule: `critical`, class only):
  `TokenEndpointSupersessionAuditTest::testAnAuditFailureAfterTheCommitLeavesTheTokensIssued`,
  `testARolledBackExchangeAuditsNoSupersededConnection` (red with the audit inside, measured). A rolled-back
  exchange revokes nothing, a refresh supersedes nothing. So: an abandoned grant (code never exchanged, failed redirect)
  leaves the previous connection alive — **re-authorizing cuts the old connection only at the new code's exchange**
  (#726, audit B1): until the person can revoke one herself (#650, « Mes connexions MCP »), an abandoned flow keeps a
  possibly stolen connection alive, so **#650 is a prerequisite to opening phase 2 publicly** (the author's decision
  of 2026-10-27); a decision sent again after a 5xx, or a double click, leaves two active connections until the first exchange, which leaves one; **the first code exchanged, older or newer, supersedes the others**, so an older code exchanged after a newer one is
  dead (its connection superseded: `invalid_grant` « Authorization code has been revoked », audited `code-refused`,
  nothing revoked); a superseded connection's tokens are refused with nothing written (decision 6), its rows untouched.
  `ConsentControllerTest::testASecondGrantSupersedesTheFirstAtItsExchange` (the approval audits the grant only, the
  revocation read on the exchange's request, the old refresh `invalid_grant` with no second line),
  `testAnAbandonedSecondGrantLeavesTheFirstConnection` (D2's reproduction), `testAGrantForAnotherClientLeavesTheFirstAlone`,
  `testARetriedApprovalLeavesOneActiveConnectionOnceExchanged`, `testAnOlderCodeExchangedAfterANewerOneIsRefused`,
  `testARefreshSupersedesNothing`; `ConsentServiceTest::testAnApprovalRevokesNothing`; `McpConnectionRepositoryTest`
  (the kept one, another client's, another account's and an already revoked one untouched). **The price, decided**:
  two workstations using the same pre-registered client do not stay connected together — the second exchange cuts the
  first. **Lock order**: the code's connection (league, `FOR UPDATE`), its tokens, then the **other** connections of
  the pair **by id** (the sub-query; #726: in the plan's order, heap or index, two of them both purge candidates crossed the purge's
  marking, `FOR UPDATE … ORDER BY id`, in `40P01` — `TokenEndpointConcurrencyTest::testASupersessionLocksTheOtherConnectionsInIdOrder`,
  the lower one's row rewritten so that the heap contradicts the ids, `ctid` checked — a HOT update, so only the heap
  order is contradicted and an index scan would keep the ids' order: the test discriminates under today's sequential
  scan only —: held by the test, the exchange
  blocks on it, the higher one free `NOWAIT`; measured `55P03` without the `ORDER BY`); a refresh takes its connection then its tokens only — no cycle: a refresh holding a
  superseded connection makes the exchange wait for its COMMIT (FPM's `lock_timeout`), then PostgreSQL re-evaluates
  `revoked_at IS NULL` on the committed row
  (`TokenEndpointConcurrencyTest::testAnExchangeCrossingARefreshOfTheSupersededConnectionRevokesIt`, real processes,
  the exchange released at the refresh's first flush: `issued 200`, C1 revoked `superseded`, no `40P01`; measured red
  with the `UPDATE` in `SKIP LOCKED`, C1 left active). **Accepted, unpinned** (#726, audit I7): the
  supersession's `UPDATE` (holding the code's connection, wanting the pair's others) can cross a cascading account
  deletion (`cpg_user` → `mcp_connection`, in its plan's order; the id order closes nothing there, the exchange holding its
  own connection first) — `40P01`, harmless: an aborted exchange wrote nothing and
  the account goes; nothing retries a `40P01`, so an aborted deletion — the backoffice's
  `CpgUserAdministrator::delete()`, the only path that can reach an account holding a connection (the pending-invitation
  purge removes accounts awaiting password setup, without a password, which cannot sign in, and
  `CpgUserInviter::invite()`'s removal on a full queue only reaches the account it just created, without a password) — answers a 500, deletes and audits nothing, and is asked again by hand.
  **Accepted limit, new** (#726): two simultaneous exchanges of
  **two** codes of one pair (two grants of one account to one client, same millisecond) each hold
  their connection first and wait for the other's (the id order of the `UPDATE` cannot close it) — PostgreSQL aborts one (`40P01`, a 500), the other passes and revokes the
  first's connection, whose code is then dead. #649's double-click limit is gone, save a client exchanging no code (the
  unused connection expires after `connection_idle`). A partial unique constraint was rejected: it would turn that
  double click into a 500. Tests that need two active connections of one account use another client
  (`HoldsAnMcpConnection::holdAnotherMcpConnection()`, `OTHER_CLIENT_ID`).
- **The last use is written at the refresh only, at most once per elapsed hour** (`McpConnection::USAGE_WRITE_INTERVAL`,
  `PT1H`, `recordUseAt($at)`: writes and returns `true` when no use is noted or when the interval has elapsed since
  the previous one — `add(new DateInterval(…))`, elapsed time, #445: across the repeated autumn hour the two
  instants of one hour read the same wall time, `02:30`,
  `McpConnectionExpiryTest::testTheLastUseIsWrittenAtMostOncePerElapsedHour`). Never on a call of `/api/mcp`
  (ADR 0006 D6, `testNoRequestWritesOutsideTheMcpSessions` stays green). The price: the idle deadline counts from a
  use up to an hour old. `RefreshRotationTest::testARefreshRecordsTheLastUseAtMostOncePerHour` (null after the
  exchange, set by the first refresh, left alone 30 minutes later, rewritten 61 minutes later).
- **The refusal audits** (`mcp-access-token-refused`, `mcp-grant-refused`): `.claude/rules/mcp-oauth-audits.md`, split out by #726.
- **The daily purge** (`app:mcp:purge`): `.claude/rules/mcp-oauth-purge.md`, split out by #726.
- **The trusted tier has one judge**, `Infrastructure/Security/TrustedTier::reaches(CpgUser)`: the stored roles
  through `RoleHierarchyInterface`, the name `McpConnectionPrincipal::TRUSTED_TIER` — used by
  `McpBearerAuthenticator` on every request of `/api/mcp` (behaviour unchanged, `McpBearerAuthenticatorTest`) and
  by the refresh. A new site that judges the tier goes through it.
- **Accepted limits of #649, decided by the author on 2026-10-27, kept as they are and tracked by issue #726** (the
  reviews' lot C; each is behaviour, not a defect to fix in passing — revisit through #726 only). Three former ones
  are lifted by #726: a replayed code cutting the connection without the verifier (« A replayed code is judged under
  the same lock »), the purge holding its candidates locked during its deletions (`.claude/rules/mcp-oauth-purge.md`, « The daily purge »), and a new
  grant revoking the previous one at approval (« A new grant supersedes the previous one »).
  1. **Two concurrent legitimate refreshes of one token revoke the connection** (`refresh-token-reuse`): the second,
     serialized behind the first's lock, re-reads the rotated token and is judged a reuse. No grace window (a reuse
     interval some providers offer; RFC 6819 §5.2.2.3 only notes the problem of clustered environments): a client that retries a refresh whose answer
     was lost, or two workers of one client refreshing together, lose their connection and reconnect. **Kept by the
     author's decision of 2026-10-27 (#726)**, to revisit on the `mcp-connection-revoked` lines of cause
     `refresh-token-reuse` once phase 2 is out. A grace window handing out a new pair is rejected: an attacker
     refreshing just before the legitimate client would keep the connection; « handing back the same pair » is
     impossible, only the hashes are stored.
