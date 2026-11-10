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
> exist); `mcp.md` keeps everything else and its index lists every MCP rule. Decisions:
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
  choosing, the last request's `_meta`) and rewrites the whole row on every request — since #644 the first two are
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
  the nginx `mcpsession` zone's business (#644, 10 creations per minute), not the bound's. **The bound must fit the largest legitimate
  answer**: the SDK queues a tool's response in the session before sending it, so every answer goes through
  `cache_items` — phase 2's nominative corpus included, **measured in #648**: the SDK encodes the answer without
  flags (`\uXXXX`, 12 bytes for a 4-byte character), the corpus is in it twice (`structuredContent` and the text
  block), and `Session::save()` encodes it again; `MAX_CHARACTERS` bounds the CV's text only, the anonymous CV and the
  case studies have no length bound. Queued, the fictive CV gives 2 880 bytes, 30 000 ASCII characters 60 806, 30 000
  of 2 or 3 bytes 420 806, 30 000 of 4 bytes **841 076 — session destroyed**. Decided by the author (2026-10-24), corrected by the
  review of task 5: the tool bounds **the queued answer itself**, `GetNominativeCorpusTool::QUEUED_ANSWER_MAX_BYTES` =
  `MAX_BYTES / 2` (half left to the rest of the session), measured by `Presentation/Tool/QueuedAnswer::bytes()` (the
  `CallToolResult` as returned, wrapped in the SDK's `Response`, `json_encode`d as `Protocol::queueOutgoing()` does,
  then again as `Session::save()` does) — a bound on the payload was not enough: against the flag-less payload the
  queue weighs 2 × (ASCII), ~2.3 × (accents, emoji), 4 × (`/`, line feed) and 6 × (`"`, `\`), up to 12 queued bytes
  per byte of text. Over it, a tool error giving `cvPageUrl` (download the CV from the site), a `warning` with the sizes
  only, no audit line. The session bound, `MAX_CHARACTERS` and the career assistant's corpus are unchanged.
  `GetNominativeCorpusToolTest::testTheToolMeasuresWhatTheSdkQueues` compares the measure with the real queue (two SDK
  internals no API publishes: an SDK bump changing either turns it red), and
  `testAQueuedAnswerAtTheBoundFitsInTheSession` pins the relation with `MAX_BYTES` on the worst composition (a corpus of
  `"`) in a real session. **Guarded since #644**:
  `RouteBackedToolsTest::testTheAnswerQueuedInTheSessionStaysFarBelowTheSessionBound` measures, per route-backed tool on
  the seeds, what is queued — the SDK's JSON answer, JSON-encoded again by `Session::save()` — and keeps it under an eighth
  of `MAX_BYTES` and under 3 × the route body + 1 KiB (a ratio independent of the content: an SDK change of encoding
  moves it). Measured on production on 2026-10-20 with the same computation: 2.1 to 2.8 × the route body, 21.6 KB at most
  (`get_anonymous_cv` fr, 10.2 KB body), 4 % of the bound; the seeds give the same order (12 KB for `list_incidents`). Each MCP request costs three
  round trips on the session row (`has`, `get`, `set`) and two writes per `tools/call` (save, then the queue
  drained): ADR 0006 D6's « no write per call » holds for the authentication, not for the session.
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
  (`BoundedSessionStoreTest::testAnUnreachableStoreIsNotAnAbsentSession`), and it is a **503**, a JSON-RPC `-32603`
  « Session store is unavailable. » with an `error` line of that message on the `mcp` channel — the SDK's at the POST
  (`Protocol::resolveSession()`), the controller's at the GET —, never a 404: the session may exist, the client retries
  instead of re-initialising. **Measured, not decided**: behind the DBAL pool Symfony's adapter catches the failed
  query itself (`AbstractAdapterTrait::hasItem()`: a `warning` of the `cache` channel naming the key, then `false`), so
  an unreachable PostgreSQL still reads « absent », 404 at both; the 503 needs a cache whose `has()` throws. **A session is bound to the first MCP
  connection that uses it** (#648, the author's decision of 2026-10-24): the SDK drains a session's **whole** outgoing
  queue to any `POST` carrying its id (`Protocol::consumeOutgoingMessages()`, from `StreamableHttpTransport::createJsonResponse()`),
  so a concurrent anonymous `POST` holding the id would have received the corpus queued by the authenticated call. The
  decorator reads the request's connection in the token storage (`McpConnectionPrincipal`, set by the `mcp` firewall
  before the SDK) and writes the binding **in the same write** as the session: the stored value is
  `mcp-connection:<connection UUID>\n` then the SDK's session, never decoded (the SDK's starts with `{` or `[`, so an
  unbound row reads as before; a malformed header is an unreadable session). Bound, the session **does not exist** for
  any other request, anonymous or another connection: `exists()` is `false` before the SDK reads or drains anything
  (404) — the GET's too —, a read returns nothing, a write is refused, a `DELETE` destroys nothing (the SDK answers 200,
  as to an unknown id; one read more); a `warning` without any id. Not used by a connection yet, a session is served as
  before, and the first one to use it binds it (an anonymous `initialize`, then the OAuth flow). On clients: one that
  stops presenting its token, or comes back under a new connection, gets a 404 and re-initialises; a refresh keeps the
  connection, hence the session. Accepted, the SDK's own race (no lock): a request that read the session before it was
  bound may write it back once, with what it had read, unbound **or bound to its own connection** (the last writer
  wins, the first connection then gets a 404), or destroy it when its write crosses `MAX_BYTES` — never the
  connection's answer, then lost like any concurrent update, and never another connection's corpus (the drain re-reads
  the store and refuses a foreign binding); likewise a connection that knows the id of a session opened anonymously
  can take it, its anonymous holder then getting a 404 (ids are the server's UUID v4s); and one refused request may
  write two `warning`s (the drain's read, then the refused write) — security review of #648. `MAX_BYTES` counts the
  SDK's session, not the header. The sessionless 2026-07-28 revision is not concerned: `StatelessProtocol` serves each request on an in-memory session, never on this store.
  `BoundedSessionStoreTest` replays the SDK's sequence with its `Session` (every write bound, the full queue included);
  `GetNominativeCorpusToolTest` plays an anonymous `ping`, GET and `DELETE`, another connection, a session opened
  anonymously and reads the `cache_items` row.
