---
paths:
  - "backend/src/Mcp/**"
  - "backend/tests/Mcp/**"
  - "backend/config/packages/mcp.yaml"
  - "backend/config/routes/mcp.yaml"
---

# Backend — `Mcp` (the MCP server)

> Decisions: `docs/adr/0006-serveur-mcp.md`; spec `.claude/specs/0007-mcp-server.md` (issue #379). Vocabulary:
> `CONTEXT.md`, « Accès MCP ». The index of every rule is at the top of `.claude/CLAUDE.md`.

- **What it is.** `symfony/mcp-bundle` serves one server, `portfolio`, on `/api/mcp` (Streamable HTTP),
  configured in `config/packages/mcp.yaml`. Read-only, **no model call** (ADR 0006 D1). The bundle and
  `mcp/sdk` are pinned **exact** (`0.14.1`, `0.8.1`; the bundle requires `mcp/sdk ^0.8.1`, so 0.9 is out until
  a bundle release moves) — `McpEndpointTest::testTheMcpPackagesArePinnedExactly` refuses any operator.
  `nyholm/psr7` is the PSR-17 implementation the bundle's `Http\Discovery\Psr17Factory` looks for;
  `php-http/discovery` is `false` in `allow-plugins`, so nothing gets installed behind our back.
- **A tool serves exactly what its route serves** (ADR 0006 D1). Every route-backed tool goes through
  `Application/RoutePayloadReaderInterface` (`Infrastructure/ApiPlatform/ApiPlatformRoutePayloadReader`): the
  resource's **single** `GET` operation the server may serve without an MCP connection (else
  `RouteOperationNotFoundException`; API Platform's synthesised `NotExposed` operations are not routes and are
  skipped — a collection resource has one), its provider through `api_platform.state_provider.locator`, then
  the `serializer` in `json` with the context
  `SerializerContextBuilder` builds for a request — **including `skip_null_values`**: without it a card with no
  icon came out with `"iconKey": null`, which the route omits. Never assemble a payload in a tool, never call a
  repository or a backoffice class from one. **The reader checks the tier itself**: the provider is called with
  no firewall nor `access_control` in between, so it asks `security.access_map` — the firewall's own map — for
  the route's path (`/api` + the URI template, variables substituted) and refuses, with
  `RouteAboveMcpTiersException`, an operation requiring more than `ROLE_USER`/`PUBLIC_ACCESS` or carrying a
  `security` attribute (ADR 0006 D2: without a connection, the anonymous and base tiers only). The backoffice
  is refused that way, not by its path; `ApiPlatformRoutePayloadReaderTest` pins it. Not done, accepted: a
  provider returning `null` (an item route's 404) would surface as `UnexpectedRoutePayloadException`, and URI
  variables are not checked against the route's `requirements` — no item tool exists; the first one handles
  both (a typed parameter, `null` → `ToolCallException`). Wiring defects (`RouteOperationNotFoundException`,
  `RouteAboveMcpTiersException`, `UnexpectedRoutePayloadException`) never reach the HTTP kernel: the SDK catches
  them, logs them `error` on the `mcp` channel and answers `-32603` — no `framework.exceptions` entry applies. `Presentation/Tool/RouteBackedToolResult` builds the result: the
  payload as `structuredContent` and the same JSON as the one text block (many clients pass only that block to
  the model). A route rendering an object → that object; a collection → `{"items": [...]}` (ADR 0006 D1, the
  MCP spec wants an object before the 2026-07-28 revision). Each tool's functional test compares its output
  with the route's body **in the same test**, in every language the route takes, and checks the
  `structuredContent` against the declared `outputSchema` with the SDK's `SchemaValidator`.
- **Tools** are services `#[McpTool]` on `__invoke()`, under `Presentation/Tool/` — the `registry` of
  `mcp.yaml` lists that namespace only, and **the bundle refuses a pattern that matches no service** (the
  server does not boot): a `prompts` pattern enters with the first prompt. `readOnlyHint: true`. A language
  parameter is typed `Locale $locale = Locale::FR` (the SDK derives the `enum` of the input schema), so an
  unknown language is refused by the SDK **before** the tool, as a JSON-RPC `-32602`. A domain « not found »
  the route answers with a 404 becomes a `Mcp\Exception\ToolCallException` with a literal English message
  (`isError: true`), never an unhandled exception (`-32603`, which tells the model nothing). Descriptions,
  titles, schemas and `instructions` are read by any client, i.e. at the anonymous tier: **they never name
  the author** (ADR 0006 D8), they say « the site's author ».
- **The `mcp` firewall never reads a cookie** (ADR 0006 D4). Declared before `api`, `^/api/mcp$`, stateless,
  no authenticator (phase 2 will add the MCP-connection Bearer one, and only it). That is what makes
  `/api/mcp`'s entry in `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` safe: a cross-site request carrying a
  trusted person's `BEARER` borrows no identity there. `McpEndpointTest::testATrustedBearerCookieIsNeitherRefusedByTheCsrfGuardNorRead`
  pins both halves (each one's removal turns it red, measured). `/api/mcp` is in `ApiRouteExposureTest`'s
  `PUBLIC_PATHS`; `AccessControlAnchoringTest` pins the firewall's bounds.
- **Host check**: `http.allowed_hosts` is `'%env(key:host:url:APP_FRONTEND_BASE_URL)%'` — the site's public
  host, already set per overlay (same host as the API behind the Ingress), `localhost` in dev and test; no
  variable of its own. The SDK's middleware answers 403 on a foreign `Host`, or on a foreign `Origin` when one
  is sent.
- **Sessions** live in the DBAL pool `cache.mcp_sessions` (`cache.yaml`, table `cache_items`; lifetime written
  once, `session.ttl` in `mcp.yaml`, 1 h), through the PSR-16 service `app.mcp.session_cache` (`psr/simple-cache`
  is a direct dependency for it): never the bundle's default `file` store under `var/cache` (read-only pod, two
  replicas, ADR 0005). The values carry the cache MAC like every DBAL pool (#468), and `cache:pool:prune` purges
  them. **A session is bounded** (`Infrastructure/Session/BoundedSessionStore`, an `#[AsDecorator]` of
  `mcp.server.portfolio.session.store`, `MAX_BYTES` 512 KiB): the SDK copies into it what the client declares
  (`clientInfo`, `capabilities` and their free `experimental` fields, « responses » under ids of the client's
  choosing) and rewrites the whole row on every request — unbounded, an anonymous client filled `cache_items`,
  hence PostgreSQL's disk (security review of #640, rated high). Over the bound the session is destroyed and the
  write refused (`warning`, size only, never the id); the SDK then answers the request without a session id and
  writes back a small empty session while draining its outgoing queue — an orphan row, whose **count** is the
  nginx `mcp` zone's business (task 5, #644), not the bound's. **The bound must fit the largest legitimate
  answer**: the SDK queues a tool's response in the session before sending it, so every answer goes through
  `cache_items` — phase 2's nominative corpus included (measure it there). Each MCP request costs three
  round trips on the session row (`has`, `get`, `set`) and two writes per `tools/call` (save, then the queue
  drained): ADR 0006 D6's « no write per call » holds for the authentication, not for the session.
  `Psr16SessionStore` swallows every exception and returns `false`; the decorator logs that `warning` too.
- **`GET /api/mcp` answers an empty SSE stream, not 405** (`Presentation/Controller/McpEndpointController`, an
  `#[AsDecorator]` of `mcp.server.portfolio.controller`): `retry: 300000`, then closed. The SDK 0.8.1 answers
  405 (spec-compliant), which PhpStorm's AI Assistant takes for a failure. A finished `Response`, **never a
  `StreamedResponse` held open**: under PHP-FPM a held stream keeps one worker per connected client, and the
  server sends no notification anyway. POST, DELETE and OPTIONS go to the bundle. The GET bypasses the SDK's
  middlewares, so it applies the host check itself, with the SDK's rules (raw `Origin` if any, else the raw
  `Host`, port ignored) — `McpEndpointTest::testGetFromAForeignHostIsRefused`. Accepted, like the SDK: the
  `Origin` check ignores scheme and port (`http://<site>:8443` passes), a page served on the site's own name.
- **Tests** speak MCP through `tests/Mcp/Support/SpeaksMcp` (`initializeMcpSession()`, `mcpCall()`, JSON or
  SSE answers alike, the last answering event of a stream). A test that opens sessions deletes the
  `mcp-portfolio-` rows of `cache_items` in its `tearDown`: they live an hour in the test database. Only the
  handshake-era revision (`2025-06-18`) is exercised; the SDK also serves the sessionless 2026-07-28 revision on
  the same endpoint, untested so far (phase 2's deferred 401 must recognise the protected tool in both forms).
- **Open, owned elsewhere** (security review of #640): the SDK logs raw JSON-RPC messages, arguments and
  session ids at `info`/`debug` on the `mcp` channel, and client-chosen strings (unknown method, tool name) at
  `warning`/`error` — task 4 (#643) caps that channel and gives the call log a channel of its own; batches of up
  to 100 calls per HTTP request, the request body size and the number of sessions per IP — task 5 (#644).
