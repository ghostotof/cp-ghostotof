---
paths:
  - "backend/src/Mcp/Infrastructure/Session/**"
  - "backend/tests/Mcp/Infrastructure/Session/**"
  - "backend/tests/Mcp/Fixtures/RecordingSessionStore.php"
  - "backend/config/packages/mcp.yaml"
  - "backend/config/packages/cache.yaml"
  - "backend/src/Mcp/Presentation/Controller/McpEndpointController.php"
  - "backend/tests/Mcp/Presentation/McpEndpointTest.php"
  - "backend/tests/Mcp/Presentation/McpEndpointGetTest.php"
  - "backend/src/Mcp/Presentation/Tool/GetNominativeCorpusTool.php"
  - "backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php"
  - "k8s/base/cache-prune-cronjob.yaml"
---

# Backend — `Mcp`: sessions (the bounded store, an unreachable store, the binding to an MCP connection)

> Split out of `.claude/rules/mcp.md` by #731 (49 793 bytes, 207 short of `tools/check-claude-rules.sh`'s 50 000 bytes),
> its « Sessions » bullet moved here word for word, then amended by the same issue (an oversized session's id does not
> exist; the bound on the queued answer removed, the binding kept as defence in depth); `mcp.md` keeps everything
> else and its index lists every MCP rule. Decisions:
> `docs/adr/0006-serveur-mcp.md`, `docs/adr/0005-etat-hors-du-pod.md`; spec `.claude/specs/0007-mcp-server.md`. The
> index of every rule is at the top of `.claude/CLAUDE.md`.

- **Sessions** live in the DBAL pool `cache.mcp_sessions` (`cache.yaml`, table `cache_items`; lifetime written
  once, `session.ttl` in `mcp.yaml`, 1 h), through the PSR-16 service `app.mcp.session_cache` (`psr/simple-cache`
  is a direct dependency for it): never the bundle's default `file` store under `var/cache` (read-only pod, two
  replicas, ADR 0005). The values carry the cache MAC like every DBAL pool (#468), and `cache:pool:prune` purges
  them, **every hour** since #644 (`k8s/base/cache-prune-cronjob.yaml`, `McpSessionsPrunedHourlyTest`): an expired
  session lived up to 25 h with the daily purge only, now 2 h at most. **A session is bounded** (`Infrastructure/Session/BoundedSessionStore`, an `#[AsDecorator]` of
  `mcp.server.portfolio.session.store`, `MAX_BYTES` 512 KiB): the SDK copies into it what the client declares
  (`clientInfo`, `capabilities` and their free `experimental` fields, « responses » under ids of the client's
  choosing in 0.8.1 — since 0.9 only the answer to a pending request the server sent —, the last request's `_meta`) and rewrites the whole row on every request — since #644 the first two are
  refused before the SDK (`InboundMessageRefusalListener`, `mcp.md`, « Six inbound messages »), the `_meta` is bounded by nginx's 16 KiB body and
  replaced by each request, not accumulated; unbounded, an anonymous client filled `cache_items`,
  hence PostgreSQL's disk (security review of #640, rated high). Over the bound the session is destroyed and the
  write refused (`warning`, size only, never the id), and **the id stays gone for the rest of the request**
  (#641): in SDK 0.8.1, draining its outgoing queue, the SDK re-read the session and wrote it back, empty, under the
  same id — which stayed valid, so the next request ran on an emptied session (no `clientInfo`, no negotiated
  version) instead of the 404 that makes a client re-initialise. The decorator remembers the destroyed id
  (`ResetInterface`) and refuses it on `exists`/`read`/`write`. **The answer arrives, and the id it carries does not
  exist** (#731, decided 2026-11-06: follow the SDK): since SDK 0.9 a response is handed to the transport before the
  session is saved (`Protocol::handleMessages()`), so the request whose session crosses the bound is answered — an
  oversized `initialize` included, served with the `Mcp-Session-Id` of a session that is not in the store — and the
  next request under that id gets 404, POST and GET alike, never a served stream
  (`McpEndpointTest::testAnOversizedSessionIsNeitherKeptNorReusable`, which also pins the `mcp` channel of that
  `initialize`: the bound's one `warning`, sizes only, and no « Failed to save session » — `Session::save()` returns
  `false` without throwing when the store refuses the write). In 0.8.1 the answer, queued in the refused session, was
  lost: no JSON-RPC answer, then a 404. **The memory of destroyed ids is kept, though the endpoint no longer shows it**
  (measured 2026-11-10: removed, `McpEndpointTest` stays green): after the refused `save()` the SDK only re-reads the
  session to drain the queue of server-initiated messages (`Protocol::consumeOutgoingMessages()`), writes it back
  only when that queue is not empty, and a destroyed session reads as absent. The rule holds in the decorator, not
  in the SDK's call order, which a bump may change:
  `BoundedSessionStoreTest::testAnIdDestroyedOverTheBoundStaysGoneForTheRestOfTheRequest` (red without the memory,
  measured). The **number** of sessions per IP is
  the nginx `mcpsession` zone's business (#644, 10 creations per minute), not the bound's. **The bound no longer has to fit an answer**
  (#731): since SDK 0.9 a `POST`'s response stays with the transport and never enters the session, whose outgoing
  queue only holds messages the server initiates, never a response (what feeds it: « bound to the first MCP
  connection », below) — this server sends none. In 0.8.1 the SDK queued a tool's response in
  the session before sending it, so every answer went through `cache_items`, the nominative corpus included: the
  tool measured its queued answer, refused a corpus over half of `MAX_BYTES` with a link to the CV page, and a test
  kept every route-backed answer far below the bound. All of it is removed (decided 2026-11-06; the business effect
  confirmed by the author on 2026-11-10: **a very large nominative corpus is served whole**, never replaced by the
  link). The premise is pinned, not read in the SDK:
  `GetNominativeCorpusToolTest::testTheAnswerNeverTransitsThroughTheSession` reads every `INSERT`/`UPDATE` of
  `cache_items` during the `tools/call` (Doctrine's `debug` lines, their parameters through `print_r` — the value is
  binary) and finds the corpus in none, the binding in the session's own;
  `testACorpusLargerThanTheSessionBoundIsServedInFull` serves a corpus heavier than `MAX_BYTES`, audited, no
  `warning`, the session serving the next request. An SDK bump that queued answers again turns both red: the bound
  and the refusal are then to be decided again. What still bounds the corpus is upstream: `MAX_CHARACTERS` for the
  CV's text only, the anonymous CV and the case studies have no length bound. A `tools/call` on a known
  session costs four round trips on the session row, **three reads and one write**, in this order (measured
  2026-11-10): the reception check's read, `save()`'s own existence check — a read too, in the decorator —, the
  write (one `INSERT … ON CONFLICT DO UPDATE`), the drain's read; in 0.8.1 the drain wrote the emptied queue back, a
  second write. Pinned by `McpEndpointTest::testAToolCallCostsAFixedNumberOfSessionStatements`, which reads
  Doctrine's `debug` lines of the call and requires every statement on `cache_items` to name the session: red with
  the decorator's read cache removed (five reads) and with the SDK's drain writing an empty queue back (two writes,
  four reads), both measured. It is a cost per MCP request that an SDK bump can change — the test says so, and this
  sentence and ADR 0006 D6 (its precision of 2026-11-10, which states this cost and names the test) follow it; a
  session lock (#762) would add one existence check on reception. D6's « no write per call » holds for the
  authentication, with one session write per request.
  `Psr16SessionStore` swallows every exception of `read`, `write` and `destroy` and returns `false` (its `exists()`
  throws, « an unreachable store » below); the decorator logs that `warning` too, on a
  refused write and on a session **present but unreadable** (the cache MAC refused after an `APP_SECRET` rotation,
  #641). **Such a session does not exist** (#673, the author's decision of 2026-10-21): the SDK served it empty, then
  wrote it back empty under the same id, and the client never re-initialised. `Protocol` checks every received id
  with `exists()` before reading it, so the decorator's `exists()` **reads** the session — one query in the common
  case —, keeps what it read for the `read()` that follows (dropped by a `write`, a `destroy` and `reset()`), and on
  a failed read asks the inner store whether the row is there: absent (expired), `false` silently; present, the
  `warning` and `false` — the SDK answers 404, like the GET. A new session never goes through `exists()` (the SDK
  reads it in `save()` on every `initialize`, `false`), so it costs nothing more (#663).
  `McpEndpointTest::testAPostOnAnUnreadableSessionIs404` corrupts a real row. **An unreachable store is not an absent
  session** (#731, decided 2026-11-06: follow the SDK): since SDK 0.9 `Psr16SessionStore::exists()` throws
  `SessionStoreException` when its cache's `has()` fails; the decorator lets it through, neither caught nor logged
  (`BoundedSessionStoreTest::testAnUnreachableStoreIsNotAnAbsentSession`), and on a cache whose `has()` throws it is a
  **503** (behind the DBAL pool, never: below), a JSON-RPC `-32603`
  « Session store is unavailable. » with an `error` line of that message on the `mcp` channel — the SDK's at the POST
  (`Protocol::resolveSession()`), the controller's at the GET —, never a 404: the session may exist, the client retries
  instead of re-initialising. **Measured, accepted by the author on 2026-11-10 (#770)**: behind the DBAL pool Symfony's adapter catches the failed
  query itself (`AbstractAdapterTrait::hasItem()`: a `warning` of the `cache` channel naming the key, then `false`), so
  an unreachable PostgreSQL still reads « absent », 404 at both; the 503 needs a cache whose `has()` throws — never
  reached in production, tracked by #770. **That
  `warning` names the key without the session id** (#731, decided 2026-11-10): the key is `mcp-portfolio-<session id>`
  and `main` takes the `cache` channel in production, so `Infrastructure/Log/SessionKeyCacheChannelRedactor`, a
  processor of that channel, withholds the id on every such line — read, existence check, write, deletion — and
  keeps the prefix (`.claude/rules/mcp-journaux.md`, « The session id never leaves through the `cache` channel »). **A session is bound to the first MCP
  connection that uses it** (#648, the author's decision of 2026-10-24), **kept as defence in depth** (#731, decided
  2026-11-06). Its original reason held in 0.8.1: the SDK queued a tool's response in the session, then drained the
  session's **whole** outgoing queue to any `POST` carrying its id (`Protocol::consumeOutgoingMessages()`, from
  `StreamableHttpTransport::createJsonResponse()`), so a concurrent anonymous `POST` holding the id would have
  received the corpus of the authenticated call. Since 0.9 no answer goes through the session (above). What the
  binding still keeps, read in the pinned SDK (`composer.json`): what the connection declared (`client_info`, the
  last request's `_meta`, the negotiated version); the day a tool asks the client something (elicitation, sampling),
  **the pending requests** the SDK stores in the session (`Protocol::registerRequest()`, `_mcp.pending_requests`) and
  **the client's answers to them** (`handleResponse()`, `_mcp.responses`, read back by `checkResponse()`), which no
  other connection may read nor answer in its place — the request itself would **not** go through the session's
  queue: suspended by a tool's fiber during a `POST`, it goes to a queue of that transport alone
  (`Protocol::queueFiberOutgoing()`), which only that `POST`'s response drains; and the session's queue, which
  `consumeOutgoingMessages()` still drains **whole** to whoever carries the id, now only fed by
  `Protocol::sendRequest()` / `sendNotification()` called outside a tool's fiber (in the SDK, the resource
  subscription notifications) and by `handleFiberYield()` once the transport is gone. None of these paths is taken
  today: the server sends no request nor notification, has no resource nor subscription, and refuses a client's
  responses before the SDK (`mcp.md`, « Six inbound messages »). The
  decorator reads the request's connection in the token storage (`McpConnectionPrincipal`, set by the `mcp` firewall
  before the SDK) and writes the binding **in the same write** as the session: the stored value is
  `mcp-connection:<connection UUID>\n` then the SDK's session, never decoded (the SDK's starts with `{` or `[`, so an
  unbound row reads as before; a malformed header is an unreadable session). Bound, the session **does not exist** for
  any other request, anonymous or another connection: `exists()` is `false` before the SDK reads or drains anything
  (404) — the GET's too —, a read returns nothing, a write is refused, a `DELETE` destroys nothing (the SDK answers 200,
  as to an unknown id; one read more); a `warning` without any id. Not used by a connection yet, a session is served as
  before, and the first one to use it binds it (an anonymous `initialize`, then the OAuth flow). On clients: one that
  stops presenting its token, or comes back under a new connection, gets a 404 and re-initialises; a refresh keeps the
  connection, hence the session. Accepted, the SDK's own race (no session lock, #762), **narrowed since 0.9**:
  `Session::save()` calls the existence check before writing, and the decorator's check reads the row and refreshes
  its binding (the reload that follows, onto which `save()` applies its changed keys, is served from what the check
  just read, no second query), so a request **whose save starts after** another connection bound the session writes
  nothing (`save()` returns `false`; in 0.8.1 it wrote the session back once, with what it had read on receipt). The
  window left runs from that read to the write, inside `save()`: bound in that interval,
  the session is written back unbound **or bound to the request's own connection** (the last writer wins, the first
  connection then gets a 404), or destroyed when the write crosses `MAX_BYTES` — what is lost is session state, like
  any concurrent update, never an answer, and the session's queue is only read under the binding
  the store holds then (the drain re-reads the store and refuses a foreign binding); likewise a connection that
  knows the id of a session opened anonymously
  can take it, its anonymous holder then getting a 404 (ids are the server's UUID v4s); and one refused request may
  write two `warning`s, in this order: `save()`'s existence check, then the drain's read (0.8.1: the drain's read,
  then its refused write), measured by `BoundedSessionStoreTest::testARequestOvertakenByAnotherConnectionWritesNothing`
  (the binding precedes the save; one falling inside the window is not played) — security review of #648. `MAX_BYTES` counts the
  SDK's session, not the header. The sessionless 2026-07-28 revision is not concerned: `StatelessProtocol` serves each request on an in-memory session, never on this store.
  `BoundedSessionStoreTest` replays a `tools/call`'s sequence with the SDK's `Session`
  (`testEveryWriteOfABoundSessionCarriesItsBinding`: the reception check, the request's `_meta` set, `save()`, the
  drain's read — one write per request, each starting with the binding), the drain of a non-empty session queue
  (by its connection only, written back bound) and the overtaken request above;
  `GetNominativeCorpusToolTest` plays an anonymous `ping`, GET and `DELETE`, another connection, a session opened
  anonymously and reads the `cache_items` row.
