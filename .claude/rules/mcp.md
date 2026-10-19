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
  resource's **single** public `GET` operation (outside `/backoffice`, else `RouteOperationNotFoundException`),
  its provider through `api_platform.state_provider.locator`, then the `serializer` in `json` with the context
  `SerializerContextBuilder` builds for a request — **including `skip_null_values`**: without it a card with no
  icon came out with `"iconKey": null`, which the route omits. Never assemble a payload in a tool, never call a
  repository or a backoffice class from one. `Presentation/Tool/RouteBackedToolResult` builds the result: the
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
- **Sessions** live in the DBAL pool `cache.mcp_sessions` (`cache.yaml`, table `cache_items`, 1 h), through the
  PSR-16 service `app.mcp.session_cache` (`psr/simple-cache` is a direct dependency for it): never the
  bundle's default `file` store under `var/cache` (read-only pod, two replicas, ADR 0005). The values carry the
  cache MAC like every DBAL pool (#468), and `cache:pool:prune` purges them.
- **`GET /api/mcp` answers an empty SSE stream, not 405** (`Presentation/Controller/McpEndpointController`, an
  `#[AsDecorator]` of `mcp.server.portfolio.controller`): `retry: 300000`, then closed. The SDK 0.8.1 answers
  405 (spec-compliant), which PhpStorm's AI Assistant takes for a failure. A finished `Response`, **never a
  `StreamedResponse` held open**: under PHP-FPM a held stream keeps one worker per connected client, and the
  server sends no notification anyway. POST, DELETE and OPTIONS go to the bundle.
- **Tests** speak MCP through `tests/Mcp/Support/SpeaksMcp` (`initializeMcpSession()`, `mcpCall()`, JSON or
  SSE answers alike).
