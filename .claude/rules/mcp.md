---
paths:
  - "backend/src/Mcp/**"
  - "backend/tests/Mcp/**"
  - "backend/config/packages/mcp.yaml"
  - "backend/config/routes/mcp.yaml"
---

# Backend — `Mcp` (the MCP server)

> Decisions: `docs/adr/0006-serveur-mcp.md`; spec `.claude/specs/0007-mcp-server.md` (issue #379). Vocabulary:
> `CONTEXT.md`, « Accès MCP ». Since #673 this file keeps only part of the MCP rules, see the
> index below. The index of every rule is at the top of `.claude/CLAUDE.md`.

**Where the rest lives** (split by #673, past `tools/check-claude-rules.sh`'s 50 000 bytes). A reference elsewhere to
`.claude/rules/mcp.md`, « <bullet> » points at one of these:

| Rule | Bullets |
|---|---|
| this file | the bundle and its pins, route-backed tools, the catalogue, `BackedByRoute`, tools, the `mcp` firewall, capabilities, host check, sessions, `GET /api/mcp`, tests, inbound refusals, no stream, rate per IP |
| `mcp-prompt-d8.md` | the prompt `present_for_role` (`PromptRole`, the nonce, the cleaning), the D8 guard (`AuthorNeverNamedTest`, the secret `MCP_D8_AUTHOR_NAME`) |
| `mcp-journaux.md` | the SDK's `mcp` channel capped and redacted, the call log `mcp_usage` (`McpCallLog`, `ModernExchange`, `TruncatedIp`, the fallback line) |

- **What it is.** `symfony/mcp-bundle` serves one server, `portfolio`, on `/api/mcp` (Streamable HTTP),
  configured in `config/packages/mcp.yaml`. **The path is written once**, `Infrastructure/Http/McpEndpoint::PATH`
  (#673): `mcp.yaml` reads it (`!php/const`), and so do `InboundMessageRefusalListener`, `McpCallLog` and the CSRF
  guard's exclusion; `McpEndpointPathTest` compares what PHP cannot read — the route, the `mcp` firewall's pattern,
  the sidecar's `location =`, the frontend's `MCP_ENDPOINT_PATH`. Not read, accepted: the dev nginx conf (mirrored
  by `tools/check-backend-nginx-rate-limits.sh`), the smoke scripts (`tools/`, not mounted; a preprod fails), and the
  tests' own `'/api/mcp'` (`SpeaksMcp::MCP_ENDPOINT`, `Request::create()`): an oracle kept apart from the constant.
  Read-only, **no model call** (ADR 0006 D1). The bundle and
  `mcp/sdk` are pinned **exact** (`0.14.1`, `0.8.1`; the bundle requires `mcp/sdk ^0.8.1`, so 0.9 is out until
  a bundle release moves) — `McpEndpointTest::testTheMcpPackagesArePinnedExactly` refuses any operator.
  `nyholm/psr7` is the PSR-17 implementation the bundle's `Http\Discovery\Psr17Factory` looks for;
  `php-http/discovery` is `false` in `allow-plugins`, so nothing gets installed behind our back. That package is
  the bundle's, never in our `require`: `src/` takes the service `mcp.psr17_factory` by its PSR-17 interfaces
  (one parameter typed `ResponseFactoryInterface&StreamFactoryInterface`), never by its class (#663, `McpEndpointTest`).
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
  both (a typed parameter, `null` → `ToolCallException`). Since #641 the path read in the `AccessMap` is **the router's**,
  generated under the operation's name (its route name at API Platform), base URL stripped — never `/api` +
  the template, which ignored a `routePrefix` and failed open; an operation with no route is refused
  (`UnroutedOperationException`). The read runs on a synthetic request (`127.0.0.1`, `localhost`, GET), so
  `AccessMapStaysPathOnlyTest` refuses any `access_control` rule conditioned on anything but its path (`ips`,
  `host`, `port`, `methods`, `allow_if`). The provider is called with no request nor token:
  `McpExposureSymmetryTest` refuses a served provider depending on `RequestStack`, `Security`, the token
  storage or the authorization checker. Not covered, accepted: a property guarded by
  `#[ApiProperty(security: …)]` would be omitted (no token), an inequality with the route, never a leak — none
  exists today. Wiring defects (`RouteOperationNotFoundException`,
  `RouteAboveMcpTiersException`, `UnexpectedRoutePayloadException`) never reach the HTTP kernel: the SDK catches
  them, logs them `error` on the `mcp` channel and answers `-32603` — no `framework.exceptions` entry applies. `Presentation/Tool/JsonToolResult` builds the result (every tool's, `get_contact_info`'s included): the
  payload as `structuredContent` and the same JSON as the one text block (many clients pass only that block to
  the model). A route rendering an object → that object; a collection → `{"items": [...]}` (ADR 0006 D1, the
  MCP spec wants an object before the 2026-07-28 revision). Each tool's functional test compares its output
  with the route's body **in the same test**, in every language the route takes, and checks the
  `structuredContent` against the declared `outputSchema` with the SDK's `SchemaValidator`. **One JSON
  nuance, accepted** (#641): the route's Serializer writes an integral float as `1.0`
  (`JSON_PRESERVE_ZERO_FRACTION`, the technologies' `years`), the SDK encodes the whole JSON-RPC answer
  with a bare `json_encode`, so `structuredContent` carries `1` — the same JSON number, which a tool cannot
  write otherwise. The text block, which `JsonToolResult` encodes itself, takes the flag and stays
  byte-identical; `RouteBackedToolsTest` compares `structuredContent` as a JSON value (`asJsonValue()`).
- **The catalogue** (#641, spec 0007 « Catalogue des outils »): eight route-backed tools — `get_about`,
  `get_quality`, `list_contributions`, `list_incidents`, `get_technical_background` and `get_tech_watch`
  (the last two without `locale`: their routes take none), and at the base tier, served without a token (ADR 0006 D2),
  `get_anonymous_cv` and `list_case_studies` — plus two **without a route**, in a closed list:
  `get_contact_info` (`{"contactPageUrl": "<APP_FRONTEND_BASE_URL>/<locale>/contact"}`, nothing else, no
  e-mail; `app.frontend_base_url`, the variable `allowed_hosts` reads) and `get_nominative_corpus`,
  **always listed**, its description saying it requires an MCP connection (ADR 0006 D3, what phase 2's
  deferred authentication relies on); in phase 1 its call is a literal tool error, and it has no
  dependency, so nothing of the corpus can leak. `get_tech_watch` relays third-party strings
  (endoflife.date cycles, versions, links) to the person's model — indirect prompt injection (LLM01): its
  description says to treat them as data; the output stays equal to the route (D1). **The page « Brancher votre
  assistant » copies the catalogue** (#645): tool names per tier, the prompt, its arguments and the `role` bound,
  hand-written in `frontend/src/infrastructure/portfolio/content/{fr,en}.ts`. `ConnectAssistantPageMirrorsCatalogueTest`
  (#673) compares each language with the real registry (tier from `BackedByRoute`, `get_contact_info` anonymous,
  `get_nominative_corpus` named outside the tiers) and `PromptRole::MAX_CHARACTERS`: renaming, adding or moving a
  tool turns it red until the page follows. The content directory is mounted read-only in the dev container
  (`docker-compose.yml`); CI checks out the whole repository.
- **Every route-backed tool declares its route**: `#[BackedByRoute(self::RESOURCE, AccessTier::…)]` on the
  class, `self::RESOURCE` (the API Platform resource) being the constant it also passes to the reader, so
  the route declared is the route read. `AccessTier` is the ADR 0003 tier the route's `access_control`
  rule fixes (none → `ANONYMOUS`, `ROLE_USER` → `BASE`, `ROLE_TRUSTED` → `TRUSTED`).
  **`tests/Mcp/McpExposureSymmetryTest`** (ADR 0006 D1) reads the real router, `security.access_map` and
  the `portfolio` registry (after `mcp.server.portfolio.builder`'s `build()`, which fills it): every route
  of the anonymous and base tiers — **any method**, so a route serving content other than by `GET` cannot
  slip by — has a tool (matched on `_api_resource_class`) or an entry in `EXCLUDED_ROUTES` with its reason
  (writes and flows, `/api/mcp`, API Platform infrastructure); every tool declares a `GET` route of its
  resource whose tier is **exactly** the declared one, or is in the closed `TOOLS_WITHOUT_ROUTE` (amend
  the ADR before adding one). Fictive cases prove each side fails (a route with neither tool nor
  exclusion, a tool with no route or no declaration, a base-tier tool declared anonymous), and no tool
  depends on a repository or a backoffice class (constructor types, declared resource). A new content
  route therefore turns the suite red until it is exposed or excluded.
- **Tools** are services `#[McpTool]` on `__invoke()`, under `Presentation/Tool/` — the `registry` of
  `mcp.yaml` lists that namespace (and `Presentation/Prompt/` for prompts) only, and **the bundle refuses a
  pattern that matches no service** (the server does not boot). `readOnlyHint: true`. A language
  parameter is a **string**, never an enum (decided 2026-10-19): `#[Schema(description: ToolLocale::DESCRIPTION)]
  string $locale = ToolLocale::DEFAULT`, parsed by `ToolLocale::parse()`, which turns an unknown value into a
  tool error the model can correct (MCP 2025-11-25) — with a PHP enum type the SDK derived an `enum` and
  refused the value before the tool, as a JSON-RPC `-32602`. A domain « not found »
  the route answers with a 404 becomes a `Mcp\Exception\ToolCallException` with a literal English message
  (`isError: true`), never an unhandled exception (`-32603`, which tells the model nothing). Descriptions,
  titles, schemas, `instructions` and `serverInfo` are read by any client, i.e. at the anonymous tier: **they
  never name the author** (ADR 0006 D8), they say « the site's author ». The only exceptions are listed under
  D8 in the ADR, each decided by the author (today: the server name `cp-ghostotof`, for this server only);
  a new one goes into that list first, then into `AuthorNeverNamedTest::ADMITTED`.
- **The `mcp` firewall never reads a cookie** (ADR 0006 D4). Declared before `api`, `^/api/mcp$`, stateless,
  no authenticator (phase 2 will add the MCP-connection Bearer one, and only it). That is what makes
  `/api/mcp`'s entry in `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` safe: a cross-site request carrying a
  trusted person's `BEARER` borrows no identity there. `McpEndpointTest::testATrustedBearerCookieIsNeitherRefusedByTheCsrfGuardNorRead`
  pins both halves (each one's removal turns it red, measured). `/api/mcp` is in `ApiRouteExposureTest`'s
  `PUBLIC_PATHS`; `AccessControlAnchoringTest` pins the firewall's bounds.
- **The capabilities are written, never detected** (#675): `Infrastructure/DependencyInjection/TruthfulCapabilitiesPass`
  (registered in `Kernel::build()`) adds `setCapabilities()` to `mcp.server.portfolio.builder` — tools, prompts,
  completions, **no `listChanged`**, no resources, **no `logging`** (nothing sends the client a `notifications/message`;
  `logging/setLevel`, deprecated since 2026-07-28, is still answered: the SDK's handlers never read the announce) —
  for both eras (`initialize` and `server/discover`); the enabled extensions are folded in by `Builder::build()`.
  Detected, the SDK 0.8.1 announced `listChanged` for tools, prompts and resources that do not exist as soon as an
  event dispatcher is set — the bundle always sets Symfony's, which the call log listens to (#643) —, with no bus
  (`subscriptions.bus: none`) to carry a single notification: a compliant client opened a `subscriptions/listen`
  for nothing. The catalogue only changes with a deployment. A compiler pass because bundle 0.14.1 exposes no
  capability in its configuration; written by hand because the registry is only filled by `build()`, after the
  call. `tests/Mcp/AnnouncedCapabilitiesTest` pins the announce in both eras and matches each announced kind
  against the real registry: a resource (#654) added without announcing it turns it red. Announcing a
  notification again means a bus first, then lifting the `listen` refusal (below), in the same change. After
  changing `Kernel.php` locally, `rm -rf var/cache/test`: the test container is not rebuilt on its own.
- **Host check**: `http.allowed_hosts` is `'%env(key:host:url:APP_FRONTEND_BASE_URL)%'` — the site's public
  host, already set per overlay (same host as the API behind the Ingress), `localhost` in dev and test; no
  variable of its own. The SDK's middleware answers 403 on a foreign `Host`, or on a foreign `Origin` when one
  is sent.
- **Sessions** live in the DBAL pool `cache.mcp_sessions` (`cache.yaml`, table `cache_items`; lifetime written
  once, `session.ttl` in `mcp.yaml`, 1 h), through the PSR-16 service `app.mcp.session_cache` (`psr/simple-cache`
  is a direct dependency for it): never the bundle's default `file` store under `var/cache` (read-only pod, two
  replicas, ADR 0005). The values carry the cache MAC like every DBAL pool (#468), and `cache:pool:prune` purges
  them, **every hour** since #644 (`k8s/base/cache-prune-cronjob.yaml`, `McpSessionsPrunedHourlyTest`): an expired
  session lived up to 25 h with the daily purge only, now 2 h at most. **A session is bounded** (`Infrastructure/Session/BoundedSessionStore`, an `#[AsDecorator]` of
  `mcp.server.portfolio.session.store`, `MAX_BYTES` 512 KiB): the SDK copies into it what the client declares
  (`clientInfo`, `capabilities` and their free `experimental` fields, « responses » under ids of the client's
  choosing, the last request's `_meta`) and rewrites the whole row on every request — since #644 the first two are
  refused before the SDK (`InboundMessageRefusalListener`, below), the `_meta` is bounded by nginx's 16 KiB body and
  replaced by each request, not accumulated; unbounded, an anonymous client filled `cache_items`,
  hence PostgreSQL's disk (security review of #640, rated high). Over the bound the session is destroyed and the
  write refused (`warning`, size only, never the id), and **the id stays gone for the rest of the request**
  (#641): draining its outgoing queue, the SDK re-reads the session and writes it back, empty, under the same
  id — which stayed valid, so the next request ran on an emptied session (no `clientInfo`, no negotiated
  version) instead of the 404 that makes a client re-initialise. The decorator remembers the destroyed id
  (`ResetInterface`) and refuses it on `exists`/`read`/`write`; the answer queued for that request is lost
  (it could not fit), the client sees no JSON-RPC answer, then a 404. The **number** of sessions per IP is
  the nginx `mcpsession` zone's business (#644, 10 creations per minute), not the bound's. **The bound must fit the largest legitimate
  answer**: the SDK queues a tool's response in the session before sending it, so every answer goes through
  `cache_items` — phase 2's nominative corpus included (measure it there). **Guarded since #644**:
  `RouteBackedToolsTest::testTheAnswerQueuedInTheSessionStaysFarBelowTheSessionBound` measures, per route-backed tool on
  the seeds, what is queued — the SDK's JSON answer, JSON-encoded again by `Session::save()` — and keeps it under an eighth
  of `MAX_BYTES` and under 3 × the route body + 1 KiB (a ratio independent of the content: an SDK change of encoding
  moves it). Measured on production on 2026-10-20 with the same computation: 2.1 to 2.8 × the route body, 21.6 KB at most
  (`get_anonymous_cv` fr, 10.2 KB body), 4 % of the bound; the seeds give the same order (12 KB for `list_incidents`). Each MCP request costs three
  round trips on the session row (`has`, `get`, `set`) and two writes per `tools/call` (save, then the queue
  drained): ADR 0006 D6's « no write per call » holds for the authentication, not for the session.
  `Psr16SessionStore` swallows every exception and returns `false`; the decorator logs that `warning` too, on a
  refused write and on a session **present but unreadable** (`read()` false while `exists()` is true — the cache
  MAC refused after an `APP_SECRET` rotation, #641): the SDK then starts from an empty session. That second
  `exists()` is run **only for an id received** — one the SDK's own `exists()` found earlier in the request
  (`Protocol` checks a received id before reading it) —, and is still re-run then (expired in between: absent,
  not unreadable): a new session reads `false` in `save()` on every `initialize`, and the check cost it one query
  for nothing (#663). The memory of received ids is cleared by `reset()`, like the destroyed ones.
- **`GET /api/mcp` answers an empty SSE stream, not 405** (`Presentation/Controller/McpEndpointController`, an
  `#[AsDecorator]` of `mcp.server.portfolio.controller`): `retry: 300000`, then closed. The SDK 0.8.1 answers
  405 (spec-compliant), which PhpStorm's AI Assistant takes for a failure. A finished `Response`, **never a
  `StreamedResponse` held open**: under PHP-FPM a held stream keeps one worker per connected client, and the
  server sends no notification anyway. POST, DELETE and OPTIONS go to the bundle; `HEAD`, which the router
  matches to the GET route, gets the GET's answer without a body (it reached the SDK's 405 before #641).
  **The GET goes through the very middlewares of the POST** (#641): `mcp.server.portfolio.middleware_factory`
  (the SDK's DNS-rebinding protection and CORS, built by the bundle from `allowed_hosts`) run by the SDK's
  `MiddlewareRequestHandler` — never a copy of their rules: T1's copy kept one host where `allowed_hosts` is a
  list, and split `[::1]:8080` into `[`. Then the transport's version rule, `StreamableHttpTransport::handshakeMiddleware()`
  (`ProtocolVersionMiddleware`: a revision it does not negotiate, `2026-07-28` included, is a 400, as the SDK answers a
  body-less GET; #673). Then it answers as the transport does for a session: 400 for a
  malformed or repeated `Mcp-Session-Id`, 404 for an unknown or expired one, **or one present but unreadable** (#673,
  decided 2026-10-21: the cache MAC refused after an `APP_SECRET` rotation; read, not only found — the POST, which the
  SDK serves on an empty session then, differs, accepted: such a session is dead either way) (the client re-initialises; a GET
  without the header is still served, and so is one whose header is empty or `0`, which the transport reads
  as absent — #663), 406 without `Accept: text/event-stream`. `McpEndpointGetTest` pins each
  case, the host rules on a several-host list included. Accepted, like the SDK: the `Origin` check ignores
  scheme and port (`http://<site>:8443` passes), a page served on the site's own name.
- **Tests** speak MCP through `tests/Mcp/Support/SpeaksMcp` (`initializeMcpSession()`, `mcpCall()`, JSON or
  SSE answers alike, the last answering event of a stream; `callMcpTool()`, `listedMcpTool()`, `routeBody()`).
  A test that opens sessions calls `forgetMcpSessions()` in its `tearDown`: the `mcp-portfolio-` rows of
  `cache_items` live an hour in the test database. A new route-backed tool adds its rows to
  `RouteBackedToolsTest`'s providers (equality per language, closed schema, empty `{"items": []}`, unknown
  language), where a base-tier route is read with a base-access token, the cookies then dropped so that
  the tool is called without one. The tools are exercised in the
  handshake-era revision (`2025-06-18`); the SDK also serves the sessionless 2026-07-28 revision on
  the same endpoint, exercised by #672's tests (no session written, `initialize` refused), by #664's call-log
  tests (`postModernMcp()`, `MCP_MODERN_REVISION`) and the preprod smoke (phase 2's deferred 401 must recognise
  the protected tool in both forms).
- **Six inbound messages are refused before the SDK** (#644, #664, #675): `Infrastructure/Http/InboundMessageRefusalListener`
  (`kernel.request`, priority 15, `POST` on the canonical path `/api/mcp`, so `/api/%6Dcp` too — and a POST stays
  one: `framework.allowed_http_method_override: []` since the audit of #675, where Symfony turned a POST carrying
  `X-HTTP-Method-Override: DELETE` into a DELETE these refusals did not judge, `HttpMethodOverrideTest`) decodes once a body
  starting, after JSON blanks, with `[` or `{`, and answers **400** `application/json`, a JSON-RPC `-32600` without
  `id` — the form of the SDK transport's own refusals; `listen` excepted, (6) below —, the reason and its message in the enum
  `InboundMessageRefusal`: (1) **a batch of several messages**, with the SDK's own predicate (`json_decode` assoc,
  then any PHP list is a batch — **an object keyed `"0"`, `"1"`… too**, which passed while only the bracket was read,
  100 `tools/call` served, measured in the reviews of #644): the SDK 0.8.1 accepts up to 100 per request, hard-coded
  (`MessageFactory::DEFAULT_MAX_BATCH_SIZE`, built by the `Builder`, not configurable through the bundle), and batches
  came with revision 2025-03-26 and left the protocol at the next, 2025-06-18 — of the four handshake revisions SDK
  0.8.1 negotiates (2024-11-05 to 2025-11-25) only 2025-03-26 knows them, and the sessionless 2026-07-28, served too,
  has none; (2) **an `initialize` whose
  `clientInfo` + `capabilities`** weigh more than `MAX_DECLARATION_BYTES` (4 096) **as the session stores them**
  (`declarationBytes()`: `json_encode` without flags, six bytes per accented character) — real clients declare under
  1 KiB; accepted: a client sending a large icon as a data URI is refused; **`params._meta` is not counted, accepted**
  (#672, the author's decision of 2026-10-20 recalled): the SDK keeps only the **last** request's `_meta` in the
  session (`Protocol::SESSION_ACTIVE_REQUEST_META`, `set` on every request), so it is replaced, never accumulated,
  and nginx's 16 KiB body bounds it — a tighter bound of its own would leave the session-bound tests below
  without an HTTP path. `McpEndpointTest::testEachRequestMetaReplacesThePreviousOneInTheSession` pins the
  replacement (three 10 KiB `_meta`s, the row must not grow by half of one; an SDK mutated to accumulate them
  turns it red, measured): if an SDK bump makes it accumulate, the decision is to be taken again;
  (3) **a client response**, by the SDK's criterion in its order (`MessageFactory::createMessage()`): `error`, then `result`, by `isset`, **before** `method` —
  a request carrying either is a response too (#671: requiring no `method` let
  `{"method":"ping","id":"a1","result":{}}` through, stored under `responses.a1`). A `null` value does not count (the SDK
  serves the request); a falsy non-null one (`false`, `0`, `""`) does (the SDK then refuses it as invalid, with its id;
  refused here first, without). `InboundMessageClassificationTest` holds a hand-written table — the message, what the
  real `MessageFactory` makes of it, whether the listener refuses it — and fails when the SDK classifies otherwise:
  correct the SDK column, then the table's invariant (refuse every `Response`/`Error`, never a request) forces the
  listener to follow. The SDK stored such responses under ids of the client's choosing, accumulating ~30 KB per post
  up to the session bound (audit of #644, measured) — this server sends the client no request (no sampling,
  elicitation nor roots); the day a tool asks one, this refusal must learn to recognise the expected answer;
  (4) **a `params._meta` that is not an object** (#664, audit): the SDK's factory stores a notification's into an
  `?array` property, and a scalar threw a `TypeError` — an `error` line of the `mcp` channel at will in the
  handshake era, the whole call line lost in the sessionless one; (5) **a sessionless `clientInfo` the SDK cannot
  read** (#664, audit), judged by `RequestMeta::fromParams()` itself: `StatelessProtocol::handle()` only catches
  its `MissingRequestMetaException` (an incomplete envelope, a 400 it answers itself, left to it), and a name that
  is not a string threw out of it — a 500 and a `critical` line at will; (6) **`subscriptions/listen`** (#675, the
  author's decision of 2026-10-21), on the method alone (`StatelessProtocol::LISTEN_METHOD`), whatever the era —
  the SDK's classifier picks it from the body, under any session header too. The SDK held the stream 30 s
  (measured 30.07 s in dev; `Builder::setSubscriptionLifetime()` is only wired by the bundle with a bus, and `0`
  there means forever), one php-fpm worker for the whole of it: fourteen `listen`s from one IP took a pod's pool.
  The server sends and announces no notification (above), so a compliant client has nothing to listen to.
  Rejected: a short lifetime (a compliant client re-subscribing after each graceful close would spend the `mcp`
  zone's 60 requests a minute and meet 429s on its real calls), and the refusal alone with `listChanged` still
  announced (a feature announced, then refused). **Its form is not the others'** (`InboundMessageRefusal::error()`):
  a well-formed request whose method is not served, so `-32601` « Method not found » **with its id** (none for a
  notification), as the SDK answers a method it does not serve — to it, the client should stop asking; a client
  reading `-32600` without id took it for a malformed message it could retry, into the `mcp` zone's 429s (audit
  of #675). A 400, never the sessionless SDK's 404: in a session a 404 tells the client its session expired. In a
  session the SDK already answered `-32600` « Unknown method » at once (200). Under the modern envelope its call
  line names `subscriptions/listen` with `-32601`, where the stream was a `success` of 30 s; in a session it leaves
  the fallback line (`McpCallLogTest`).
  A one-message batch is served, its message judged like a single one; unreadable JSON is left to the SDK (`-32700`).
  The refusal precedes the session and the `Host` check (a foreign host gets this 400, not the SDK's 403: nothing is
  served either way); `McpCallLog` writes its fallback line (`POST`, 400, `protocol-error`; a call line under the
  modern envelope, above). Its messages are read by
  `AuthorNeverNamedTest`. **No body bound in the application**: `SdkChannelRecordsTest` sends 100 KB strings through
  the SDK on purpose, and the bound is nginx's (`.claude/rules/deploiement-nginx.md`, chunked included).
  `InboundMessageRefusalTest` pins every case; `McpEndpointTest`'s session-bound cases go through `_meta` since.
- **No answer is a stream** (#678, audit C5 of #675): under PHP-FPM a stream holds a worker. `tests/Mcp/NoAnswerIsAStreamTest`
  sends, in both eras, as a client accepting `text/event-stream` with a `progressToken`, every request the SDK can
  build (`MessageFactory::REGISTERED_MESSAGES` and `StatelessProtocol`'s `*_METHOD`, read by reflection, against its
  closed `params()` — an SDK bump adding one turns it red until judged; `server/discover` must announce no extension,
  whose methods escape that reading), each listed tool, prompt and resource included, and
  wants JSON (a `result` for `tools/call`/`prompts/get`). Measured red: a tool calling `ClientGateway::progress()`, and
  the `listen` refusal lifted. Not covered: a tool notifying only for some arguments.
- **Rate, body and sessions per IP** (#644): the nginx `mcp` zone and the location's 16 KiB body bound
  (`.claude/rules/deploiement-nginx.md`). The `mcpsession` zone counts every `POST` whose `Mcp-Session-Id` is not
  a well-formed UUID (#672, an allow-list: PHP's PSR-7 bridge trims tabs and drops a header with a control
  character before `StreamableHttpTransport` tests the value's truthiness, so nginx cannot list the forms PHP
  reads as « no session ») and exempts the sessionless 2026-07-28 revision, served on an in-memory session —
  `McpEndpointTest` pins that an `initialize` under that header opens no session, that the revision writes none,
  and that the conf exempts exactly `ProtocolVersion::modernVersions()`. The `mcpconn` zone bounds simultaneous
  connections (2 per IP and per pod, of a pod's eight workers): set against `subscriptions/listen`'s 30 s (#672),
  **kept at 2 by decision** (#675, 2026-10-21) once `listen` is refused, as defence in depth — one IP never holds
  more than two workers of a pod, whatever a request becomes (a slow tool, a future stream). A shared egress IP
  (hosted connectors: claude.ai and ChatGPT call from shared IPs, so their users could throttle one another) meets
  the `mcp` zone first: measured after phase 1's release (#673, point 2, release checklist in `tasks/todo.md`), and
  both bounds again against the real clients in phase 2 (`.claude/rules/deploiement-nginx.md`).
