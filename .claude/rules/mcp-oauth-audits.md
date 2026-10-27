---
paths:
  - "backend/src/Mcp/Connection/Application/GrantRefusalAuditInterface.php"
  - "backend/src/Mcp/Connection/Application/TokenEndpoint.php"
  - "backend/src/Mcp/Connection/Application/FormEncodedRequest.php"
  - "backend/src/Mcp/Connection/Application/FailSafeAudit.php"
  - "backend/src/Mcp/Connection/Domain/ValueObject/BearerRefusal.php"
  - "backend/src/Mcp/Connection/Domain/ValueObject/GrantRefusal.php"
  - "backend/src/Mcp/Connection/Infrastructure/League/LeagueGrantRefusal*.php"
  - "backend/src/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticator.php"
  - "backend/tests/Mcp/Connection/GrantRefusalAuditTest.php"
  - "backend/tests/Mcp/Connection/Application/FailSafeAuditTest.php"
  - "backend/tests/Mcp/Connection/Infrastructure/League/LeagueGrantRefusal*Test.php"
  - "backend/tests/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticatorTest.php"
---

# Backend — `Mcp/Connection`: the refusal audits `mcp-access-token-refused` and `mcp-grant-refused` (#649, #725, #726)

> Decisions: `docs/adr/0006-serveur-mcp.md`, D3 and D6; spec `.claude/specs/0007-mcp-server.md`, phase 2 (issue
> #649, task 8: a known access token refused; issue #725: every refusal of an authentic grant at the token endpoint;
> issue #726: the unproven replay). Split out of `.claude/rules/mcp-oauth-cycle-de-vie.md` by #726 (49 035 bytes,
> past which the ten corrections of its task 9 would have pushed `tools/check-claude-rules.sh`'s 50 000 bytes), its
> two audit bullets moved here (#726), then kept up to date; that rule keeps the lifetimes, the expiry, the revocations and their
> lock orders, and points here — a bullet quoted below by its title alone (« A replayed code is judged under the
> same lock ») is one of that rule's. Vocabulary: `CONTEXT.md`, « Accès MCP ». The index of every rule is at the top
> of `.claude/CLAUDE.md`.

- **A known token refused is audited** (task 8 of #649). `McpBearerAuthenticator` writes
  `SecurityAuditLoggerInterface::mcpAccessTokenRefused()` — event `mcp-access-token-refused`, `userId` (the
  connection's account), `connectionId`, the public `clientId` (the three read on the connection itself, a
  `McpConnectionReference`),
  `cause` = the `BearerRefusal` value; actor `anonymous`, the refusal precedes any authentication — right before
  throwing `McpBearerRefusedException` for a refusal of `McpAccessToken::refusalAt()` or of `TrustedTier`. **Never for
  `Malformed` nor `Unknown`**: no connection is known there, and a flood of garbage tokens must write no audit line
  (`testAMalformedOrUnknownTokenIsNotAudited`). **Volume**: a healthy client sees its access token expire once an
  hour (`access_token`, `PT1H`), then refreshes — at most one line per connection and per hour in normal use; more
  means a client that does not refresh, or a connection used after its revocation or expiry — and that bound holds
  for **normal use only**: a known refused token replayed on purpose writes one line per request, bounded by nothing
  but the nginx `mcp` zone (60 a minute per IP and per pod, `burst=20 nodelay`: up to 80 the first minute), accepted since the line names a connection that exists and
  a client that keeps presenting it. **No query more**: the
  line goes to the Monolog channel, and the connection, its account and its client are those of the token's single
  join (`testARefusedKnownTokenCostsNoExtraQuery`: the refused call costs exactly the accepted call's
  authentication query, judged on the token and on the tier). Never the token nor its hash in the line
  (`testAKnownRefusedTokenIsAudited`, one case per cause, the common 401 unchanged).
- **Every refusal of an authentic grant is audited at the token endpoint** (#725, the author's decisions of
  2026-10-27: which refusals, where, what bound, then the four of the step-07 reviews). **The criterion is the access
  token's**: a code or refresh token the server **decrypts** is one it issued (only it holds the key,
  `Infrastructure/League/GrantPayloadReader::connectionIdOf()`, the payload's `user_id` as RFC 4122), so it designates a
  connection and only whoever holds a real one writes a line. Event `mcp-grant-refused`: `userId`, `connectionId`,
  `clientId` (the connection's, through `McpConnectionRepositoryInterface::referenceOf()`, native SQL — the token
  endpoint's `wrapInTransaction()` closed the EntityManager), `cause` (`Domain/ValueObject/GrantRefusal`), `grantType`,
  `presentingClientId` (the body's `client_id`, always a registered client as league read it — what makes a code
  presented by another client readable); actor `anonymous`. **Audited, with their cause**: PKCE `verifier-missing`,
  `verifier-malformed`, `verifier-mismatch` (a wrong verifier on a live code: the interception signal, judged after the
  repository); `code-expired`, `token-expired` (league's clock, on the payload — a code replayed after its ten minutes,
  never recognised as a replay, the accepted limit of « A replayed code is judged under the same lock », now leaves a
  trace); `code-refused` (dead under the lock: row purged, connection revoked) and `token-refused` (the same, plus the
  token or the connection past its deadline on the application's clock, the account no longer trusted) — never a
  compromise; `replay-unproven` (#726: an exchanged code replayed on an active connection without the proof of its PKCE
  verifier — no revocation, « A replayed code is judged under the same lock »; named by `TokenEndpoint`, which calls
  `GrantRefusalAuditInterface::recordUnprovenReplay($request, $compromise->connectionId())` — a dedicated variant (#726,
  CR4): the connection is the one the compromise already designates under league's lock, so the code is **not
  decrypted again**, and `record($request, $refusal)` keeps league's hint alone, which says `code-refused` here;
  `LeagueGrantRefusalAuditTest` pins the line on the passed connection, that an unreadable code changes nothing there
  (while `record()` writes nothing for it), and `record()`'s classification unchanged); `foreign-client`, `redirect-mismatch`, `not-a-code`, `not-a-refresh-token`, `invalid-scope` (at a refresh,
  or at an exchange whose payload names a scope gone since), `foreign-resource` (`invalid_target` with an authentic
  grant), and `unrecognised` for any other league refusal of an authentic grant, so none is lost. **Not audited, and
  why**: the authorize endpoint's refusals (unknown client, unregistered `redirect_uri`, PKCE method — no connection
  exists yet, nothing to designate, and any caller can produce them); an unknown client (`invalid_client`, refused for
  itself before the grant); an unreadable grant or one without a connection UUID; a connection no longer in the
  database (nobody to designate); a grant type carrying no artifact; a **5xx** (`LeagueGrantRefusalAudit::SERVER_FAULT`:
  league failing, sometimes *after* validating the grant — a unique-identifier collision, a `server_error` — where a
  line would accuse a legitimate account of a valid code); and **a compromise** — reuse and proven replay already
  write `mcp-connection-revoked`; the audit is a sibling `catch` of `TokenEndpoint::respond()`, never reached by the
  compromise's own refusal — **save an unproven replay** (#726), audited `replay-unproven` from the compromise's own
  `catch`, since it revokes nothing and writes no other line. **Two doors closed before league, by decision** (the security review measured both
  escaping the audit): `FormEncodedRequest` refuses, `invalid_request`, any read value starting or ending with a blank
  of `trim()` — league trims every parameter (`AbstractGrant::parseParam()`), so a code wrapped in a space reached
  league while the audit, reading the raw body, decrypted nothing, and a `client_id` followed by 50 000 tabs entered the
  journal as such; refused, never corrected, like parameter pollution. And `TokenEndpoint` requires the `client_id`
  **in the body** (the `none` method, the only one the metadata announce; `invalid_client` otherwise): league also
  read it from HTTP Basic, where the audit did not see who presented the grant. A body those two refuse is not audited:
  its parameters do not read unambiguously. **An audit failure never changes the answer** (decided):
  `TokenEndpoint::audit()` (and its unproven-replay twin) goes through `Application/FailSafeAudit::attempt($write,
  $message)` — written once (#726, S1) for its four sites: `TokenEndpoint::audit()` and
  `TokenEndpoint::auditUnprovenReplay()` (both `Grant refusal not audited.`), `CodeExchangeSupersession::auditSuperseded()`
  (`Superseded connection not audited.`, after the exchange's COMMIT) and the purge's `McpConnectionPurger::auditExpired()`
  (`Expired connection not audited.`): it catches any `Throwable` of the write and logs the caller's constant message,
  here `Grant refusal not audited.`, at `critical` on the main
  channel (`['exceptionClass' => …]`, the class only, never a message that could quote the grant;
  `FailSafeAuditTest`); league's refusal is then thrown unchanged —
  `testAnAuditFailureLeavesLeaguesRefusalUnchanged` renames `mcp_connection` for one request (500 before, measured);
  its twin `testAnUnprovenReplayAuditFailureLeavesLeaguesRefusalUnchanged` replaces `GrantRefusalAuditInterface` (league
  reads the connection under its lock before the replay is recognised, so the table cannot be hidden): « Authorization
  code has been revoked », `invalid_grant`, nothing revoked, the `critical` line (500 without the `attempt()`, measured).
  **Classified by league's hint** (`Infrastructure/League/LeagueGrantRefusals`, one table, `invalid_scope` and
  `invalid_target` by type; the project's own hint is `PayloadCheckedRefreshTokenGrant::NOT_A_REFRESH_TOKEN_HINT`, never
  copied): league names its refusals by sentence only, pinned 9.4.1; a bump changing one drops it to `unrecognised` and
  its case turns red. **Hook, decided**: `TokenEndpoint`, after the ROLLBACK, through
  `Application/GrantRefusalAuditInterface` (`Infrastructure/League/LeagueGrantRefusalAudit`) — rejected: league's events
  (only `client.authentication.failed` and `refresh_token.client.failed`, nothing for PKCE, expiry or redirect) and a
  witness filled by the repositories (blind to the refusals league makes before them). **Cost**: one decryption more
  than league's on **every** 4xx refusal of a known client carrying a grant, authentic or not (decrypting is how it is
  told), and one query for an authentic one — save the unproven replay, which decrypts nothing more (its query, the
  connection's reference, stays: the line names the account and the client). Every body parameter is read through
  `FormEncodedRequest::stringParameter()` (#726, CR7). **Volume, decided**: one line per refused request, bounded by the nginx
  `oauth` zone alone — 10 a minute per IP **and per pod**, `burst=10 nodelay`, so with production's two replicas up to
  40 the first minute, then 20 a minute per IP; a grant stays « authentic » as long as its connection row lives (six
  months after a revocation), and N addresses multiply the bound — the access token's decision; an application dedup
  was rejected (state to keep in a DBAL pool, ADR 0005, a query more per refusal, an attack burst shrunk to one line).
  **To revisit with CIMD (#651)**: a client known by its metadata URL makes `presentingClientId` an URL the caller
  chose (≤ 255 characters of `McpClientId`'s alphabet), and its own volume. `GrantRefusalAuditTest` produces each cause
  through the real league (forged payloads re-encrypted under the server's key for the expiries and a vanished
  connection, SQL alterations for every death under the lock) and, through `assertAudited()`, reads the exact keys and
  finds no grant, verifier nor hash in the journal; the unknown client, the compromise, the blank-wrapped code and the
  dead refresh tokens turn red under their mutation, measured, as do `LeagueGrantRefusalsTest`'s fallback and
  `LeagueGrantRefusalAuditTest`'s 5xx.
