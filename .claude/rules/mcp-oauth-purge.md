---
paths:
  - "backend/src/Mcp/Connection/Application/McpConnectionPurger*.php"
  - "backend/src/Mcp/Connection/Application/McpConnectionPurgeResult.php"
  - "backend/src/Mcp/Connection/Infrastructure/Doctrine/DeletesDeadTokens.php"
  - "backend/src/Mcp/Connection/Presentation/Command/PurgeMcpConnectionsCommand.php"
  - "backend/tests/Mcp/Connection/Application/McpConnectionPurgerTest.php"
  - "backend/tests/Mcp/Connection/McpConnectionPurgeLockOrderTest.php"
  - "backend/tests/Mcp/Connection/Fixtures/purge-mcp-connections.php"
  - "backend/tests/Mcp/Connection/Presentation/Command/PurgeMcpConnectionsCommandTest.php"
  - "backend/tests/Mcp/Connection/Infrastructure/Kubernetes/McpConnectionsPurgedDailyTest.php"
  - "k8s/base/messenger-purge-cronjob.yaml"
---

# Backend — `Mcp/Connection`: the daily purge of MCP connections and their tokens (#649, #726)

> Decisions: `docs/adr/0006-serveur-mcp.md`, D3 and D6; spec `.claude/specs/0007-mcp-server.md`, phase 2 (issue
> #649, task 9: expiry marked, old revoked connections, then dead tokens, deleted; issue #726, D4: the marking and the
> deletions in two transactions). Split out of `.claude/rules/mcp-oauth-cycle-de-vie.md` by #726 (49 958 bytes, a
> sentence short of `tools/check-claude-rules.sh`'s 50 000 bytes), its « The daily purge » bullet moved here word
> for word; that rule keeps the lifetimes, the expiry (`isExpiredAt()`, the purge's only judge), the revocations
> and their lock orders, and points here. Vocabulary: `CONTEXT.md`, « Accès MCP ». The index of every rule is at
> the top of `.claude/CLAUDE.md`.

- **The daily purge** (task 9 of #649): `app:mcp:purge` (`Presentation/Command/PurgeMcpConnectionsCommand`, a thin
  wrapper) → `Application/McpConnectionPurgerInterface::purge(RetentionPeriod)` → `McpConnectionPurgeResult`, run every
  night by the housekeeping CronJob, third line after the invitations (`k8s/base/messenger-purge-cronjob.yaml`, under
  `set -e`, `PGOPTIONS=-c lock_timeout=5s`; `McpConnectionsPurgedDailyTest` pins the line and that no other manifest
  runs it). **Two transactions** (#726): the marking, (1)–(3), COMMIT, then its audits; the deletions, (4)–(5), in the
  token endpoint's order — connection rows first, tokens after (the reverse deadlocked in `40P01`, task 6). The
  candidates' lock lasts only their judgement. (1) `findActiveCandidatesForExpiry($idleBefore, $maximumBefore)`, a
  **wide** SQL pre-selection (`revoked_at IS NULL AND (COALESCE(last_used_at, granted_at) <= :idleBefore OR granted_at
  <= :maximumBefore)`, both bounds `now − duration + P1D`, `new DateInterval`, computed once in
  `McpConnectionPurger::preselectionBounds()`, typed `DATETIMETZ_IMMUTABLE`), **locked
  `FOR UPDATE`** on the connection rows only (no join), `HINT_REFRESH`, by id, through `RereadUnderLock::rowsMatching()`
  (`.claude/rules/backend-architecture.md`; nothing detached): a refresh in flight commits first and its new
  `last_used_at` is judged, instead of a stale row revoked a few milliseconds after a legitimate use; (2) each candidate judged by `isExpiredAt(now, lifetimes)`, **the only definition**;
  (3) `revokeExpired(list<Uuid>, $at)`, one statement like `revokeActive()` (`… WHERE revoked.id IN (SELECT c.id FROM
  mcp_connection c WHERE c.id IN (:ids) AND c.revoked_at IS NULL ORDER BY c.id FOR NO KEY UPDATE) … RETURNING`, the
  ids converted through `UuidType`), cause `expired`; (4) `deleteRevokedBefore($threshold)`, `revoked_at < :threshold`, strict,
  cascading — **before any token deletion**: run last, it locked old revoked connections after (5) had locked token
  rows, the order a cascading account deletion (`cpg_user` → `mcp_connection` → tokens) crosses (final review of
  #649); the cascade carries what tokens are left, normally none; (5) `deleteDead($now)` on codes, **access tokens,
  then refresh tokens**, dead = `expires_at <= :now` **or** a revoked connection (one DQL `DELETE … OR connection_id IN
  (SELECT … revoked_at IS NOT NULL)`, written once in `Infrastructure/Doctrine/DeletesDeadTokens`, locking no
  connection row) — a rotated refresh token or an exchanged code of an **active** connection stays until its expiry,
  its row recognising a reuse or a replay; those of a connection marked in (3) go the same night. A before R is the
  order of the revocation endpoint, league's refresh grant and the cascade (lot B: R then A left the cycle told in
  « Revoking a refresh token at the revocation endpoint »; deleting an A rewrites every R that outlives it whatever the
  order, a live R whose access token expired included — the *extra* cost is the SET NULL rewrite of dead R rows the
  next statement deletes, measured by no counter: `McpConnectionPurgerTest` counts the same two, two, two). **Pinned in real processes** (`McpConnectionPurgeLockOrderTest`, child
  `Fixtures/purge-mcp-connections.php`, `.claude/rules/backend-outillage.md`): the test holds the other side's first
  row, waits for the purge to block, asks for the second — an old revoked connection then its access token for (4)
  before (5), the connection, its access token, then its refresh token for A before R, each inverted order a `40P01`;
  and the lock's duration: a live candidate (last used twelve hours before its idle deadline, derived from the
  configuration's `connection_idle`) is free `FOR UPDATE NOWAIT` while the purge waits in (5) — `55P03` under one
  transaction, measured, the other two green. **Its precondition reads the purge's bounds at their source**
  (`assertALiveCandidate()`: the candidate pre-selected by `McpConnectionPurger::preselectionBounds()`, the one
  computation of `now − duration + PRESELECTION_MARGIN` that `purge()` calls too, #726; and not yet expired by
  `isExpiredAt()`): a copied margin would let a change of the purge's make the `NOWAIT` free for nothing, green. **Accepted, unpinned**: no
  concurrency test proves the `FOR UPDATE` of (1) itself (the sequential suite stays green without it). **Accepted limit**: a cycle between `mcp_connection` rows
  alone — candidates (1, by id) or old revoked rows (4) against a cascade taking an account's connections in its
  plan's order; PostgreSQL aborts one, the Job retries (`backoffLimit: 2`). **Closed** (#726, final review): the marking
  against a code exchange's supersession of two candidates of one pair — the three revocations lock their rows by id
  too (`McpConnectionRepository::revokeWhere()`, `.claude/rules/mcp-oauth-cycle-de-vie.md`, « A new grant supersedes
  the previous one ») — **and the code's own connection, which the exchange locks first, is never a candidate**
  (#726, CR1): `ConnectionLifetimes::fromConfiguration()` refuses an idle or maximum duration up to `P1D` plus the
  code's duration plus an elapsed hour (the clock changes) and minute (truncation, clock skew)
  (`.claude/rules/mcp-oauth-cycle-de-vie.md`, « The durations live in configuration »); below it, a fresh connection
  could be a candidate, the marking holding another one the exchange waited on while wanting the one the exchange held,
  `40P01`. Closed by construction under that refusal,
  every second of both clock changes included (measured, same bullet). **After the marking's COMMIT**, one
  `mcpConnectionExpired()` line per connection marked (actor `system`); a rolled-back marking audits nothing
  (`testARolledBackPurgeAuditsNothing`, red with the audit moved inside, measured); failed deletions leave the marking
  and its lines, the next night deletes (`testTheMarkingIsAuditedEvenWhenTheDeletionsRollBack`). **An audit failure
  never changes the outcome** (#725's rule, applied to the purge by #726, audit B4, like
  `TokenEndpoint::auditSuperseded()`, both through `Application/FailSafeAudit`, `.claude/rules/mcp-oauth-audits.md`): each
  line is tried on its own, a failure logs `Expired connection not audited.` at
  `critical` on the main channel (`['exceptionClass' => …]` only, never a message that could quote the connection), and
  the other lines and the deletions run whatever happened — the revocation took place
  (`testAnAuditFailureSkipsNeitherTheOtherLinesNorTheDeletions`: the first line fails, the second is written, the dead
  token deleted, the result returned; the exception escapes `purge()` without the `try`, measured). Every instant is
  `ClockInterface::now()` (Paris). **Retention**: `app.mcp.revoked_connection_retention` (`'6 months'`, every
  environment), the default of `--older-than`, read by `RetentionPeriod` (a date or an unreadable expression:
  `Command::INVALID`); under one day, `thresholdAtLeastOneDayBefore()` refuses it before any write
  (`InvalidRetentionPeriodException::shorterThanOneDay()`, shared with the invitation purge), `INVALID` too. **No
  `--dry-run`** (decision 6): it deletes nothing a person could regret — the invitation purge deletes accounts. The
  output names connections by UUID only, with the four counters, a success even at zero. **Volumes**: the token tables
  stay bounded by this purge; no index on `expires_at`, to revisit if the volume asks. `McpConnectionPurgerTest` (frozen
  clock across the autumn change; `testThePurgeAndIsExpiredAtAgreeAtTheBoundary`: each threshold and one second after,
  marked ⇔ `isExpiredAt()` re-read — red when every candidate is revoked unjudged, measured),
  `PurgeMcpConnectionsCommandTest`.
