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
| this file | the bundle and its pins, route-backed tools, the catalogue, `BackedByRoute`, tools, the `mcp` firewall, capabilities, host check, sessions, `GET /api/mcp`, tests, inbound refusals, the deferred 401, no stream, rate per IP |
| `mcp-prompt-d8.md` | the prompt `present_for_role` (`PromptRole`, the nonce, the cleaning), the D8 guard (`AuthorNeverNamedTest`, the secret `MCP_D8_AUTHOR_NAME`) |
| `mcp-journaux.md` | the SDK's `mcp` channel capped and redacted, the call log `mcp_usage` (`McpCallLog`, `ModernExchange`, `TruncatedIp`, the fallback line) |
| `mcp-oauth.md` | phase 2's authorization server, `Mcp/Connection` (#647): league without its bundle, opaque hashed tokens, the derived encryption key, PKCE `S256`, `resource`, redirect matching, parameter pollution, the `oauth` firewall, consent, metadata outside `/api` and their Ingress, the consent page, accepted limits |
| `mcp-oauth-cycle-de-vie.md` | the lifetime of a connection and its tokens (#649): durations in configuration (`ConnectionLifetimes`), monotone expiry deadlines (`isExpiredAt()`), a revocation once with its cause, refresh rotation and code replay under the connection's lock, revocation at `/revoke`, a superseding grant, the last use |
| `mcp-oauth-audits.md` | the refusal audits (#649, #725, split out of `mcp-oauth-cycle-de-vie.md` by #726): `mcp-access-token-refused` (a known access token refused, `BearerRefusal`) and `mcp-grant-refused` (an authentic grant refused at the token endpoint, `GrantRefusal`, `replay-unproven`) |
| `mcp-oauth-purge.md` | the daily purge `app:mcp:purge` (#649, split out of `mcp-oauth-cycle-de-vie.md` by #726): the marking then the deletions in two transactions, their lock order, retention |
| `mcp-oauth-revocation-personne.md` | the person's revocation (`GET`/`DELETE /api/me/mcp-connections`, « Mes connexions MCP ») and the account-wide revocations at a password change or the loss of the trusted tier (#650), the abandoned grant's one-hour expiry (#730), the consent bound to the account it names, the accepted limits |

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
  `host`, `port`, `methods`, `allow_if`). Both guards read `test`'s container; that prod declares the same map
  and routes is `SecurityConfigEnvironmentParityTest`'s and `RouteEnvironmentParityTest`'s job (issue #659,
  `.claude/rules/backoffice-api.md`, « Authorization »). The provider is called with no request nor token:
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
  **An empty object is refused, accepted** (#681, the author's decision of 2026-10-21): `forObject([])` throws
  `ToolPayloadShapeException` (`array_is_list([])` is true), a `-32603`. That is faithful to the route: with no
  `preserve_empty_objects` (set nowhere) the Serializer writes an object whose fields are all omitted as `[]`, not
  `{}` (measured), a JSON array no tool can return as `structuredContent`, and one the `required` of every object
  tool's `outputSchema` refuses (`get_about`, `get_quality`, `get_tech_watch`) — a defect of the route, never an
  inequality to smooth over. Rejected: `{}` on the tool's side (unequal to the route and to its schema), and
  `preserve_empty_objects` in the defaults (every route changes, for a case none has). `JsonToolResultTest` pins the refusal.
- **The catalogue** (#641, spec 0007 « Catalogue des outils »): eight route-backed tools — `get_about`,
  `get_quality`, `list_contributions`, `list_incidents`, `get_technical_background` and `get_tech_watch`
  (the last two without `locale`: their routes take none), and at the base tier, served without a token (ADR 0006 D2),
  `get_anonymous_cv` and `list_case_studies` — plus two **without a route**, in a closed list:
  `get_contact_info` (`{"contactPageUrl": "<APP_FRONTEND_BASE_URL>/<locale>/contact"}`, nothing else, no
  e-mail; `app.frontend_base_url`, the variable `allowed_hosts` reads) and `get_nominative_corpus`,
  **always listed**, its description saying it requires an MCP connection (ADR 0006 D3, what phase 2's
  deferred authentication relies on); its name is `Application/ProtectedTools::NOMINATIVE_CORPUS`, read by the
  deferred 401 below. **With an MCP connection** (#648) it returns `{"corpus": <CorpusRenderer's text for the
  language>, "cvPageUrl": "<APP_FRONTEND_BASE_URL>/<locale>/assistant"}` (closed `outputSchema`, never the PDF) and,
  once the answer fits its bound, the audit line `nominative-corpus-read` (`userId`, `connectionId`, `clientId`, `tool`)
  — it records the corpus as rendered, an unavailable detailed CV included (accepted: the connection read what the
  server had to serve, not necessarily the CV), and **an answer the tool served, not one the client received**
  (accepted, review of #648: a session write lost afterwards — the store down, the binding race below, a session
  over its bound once the answer is queued — over-counts, the safe direction); without
  one, an unmarked call gets that 401, a call marked `openai/` the tool's error — `CONNECTION_REQUIRED` and
  `_meta["mcp/www_authenticate"]` = a list of one `BearerChallenge::insufficientScope()` challenge (the Apps SDK
  reference types it « string or string[] ») —, the same marked or not, nothing read. **The tool is a thin adapter**
  (review of #648): it parses the language, calls `Application/NominativeCorpusReaderInterface`
  (`Infrastructure/Corpus/NominativeCorpusReader`) — `open(Locale): ?NominativeCorpus` decides the access and renders,
  `recordServed(NominativeCorpus)` writes the audit line —, measures the answer and turns each outcome into a
  `CallToolResult`; two steps because the measure is the SDK's encoding (Presentation) and the audit must follow it.
  **The identity is read through `Security` only** (`McpConnectionPrincipal`, ADR 0006 D4): never `_meta`, the token
  nor the SDK's authorization middlewares, and no `RequestContext` (nothing would read it). **The reader re-checks what
  the authenticator already judged** (defence in depth, review of #648): a `McpConnectionPrincipal`, its
  `grantsNominativeRead()` (the stored scope, re-read with the token) and
  `isGranted(McpConnectionPrincipal::TRUSTED_TIER)` (the `role_hierarchy`); any of the three missing is the same
  refusal as without a connection, nothing rendered nor logged (`NominativeCorpusReaderTest`, a fixture principal
  lacking the tier, then the scope: red without each check, measured). The reader takes `Security`,
  `CorpusRendererInterface`, `SecurityAuditLoggerInterface` (the second door of `mcp-oauth.md`'s « assumed
  exception ») and `app.frontend_base_url`; the tool takes the reader's interface, `BearerChallenge` and the logger,
  pinned in `McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE`. **Its queued answer is bounded**
  (`QUEUED_ANSWER_MAX_BYTES`, sessions below); for it `Mcp/Presentation` imports
  `Infrastructure/Session/BoundedSessionStore`, assumed like the controller's `ServerAnswersInterface` (the bound lives
  with the code that enforces it). `get_tech_watch` relays third-party strings
  (endoflife.date cycles, versions, links) to the person's model — indirect prompt injection (LLM01): its
  description says to treat them as data; the output stays equal to the route (D1). Since #658 cycles and
  versions only pass in the shape of a version number, filtered in the watch itself, never in the tool
  (`.claude/rules/portfolio-watch.md`); links stay text past `ExternalUrlFilter`. **The page « Brancher votre
  assistant » copies the catalogue** (#645): tool names per tier, the prompt, its arguments and the `role` bound,
  hand-written in `frontend/src/infrastructure/portfolio/content/{fr,en}.ts`. `ConnectAssistantPageMirrorsCatalogueTest`
  (#673) compares each language with the real registry, both ways (tier from `BackedByRoute`, `get_contact_info`
  anonymous, `get_nominative_corpus` named in « never served », those two being exactly `TOOLS_WITHOUT_ROUTE`; a
  trusted-tier tool would have to appear too) and `PromptRole::MAX_CHARACTERS`, reading only the `served` and
  `neverServed` blocks: renaming, adding or moving a tool turns it red until the page follows. The registry is read
  through `tests/Mcp/Support/ReadsPortfolioRegistry`, shared with `McpExposureSymmetryTest`. The content directory is mounted read-only in the dev container
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
  pattern that matches no service** (the server does not boot). `readOnlyHint: true`. **Every tool declares
  its `securitySchemes`** (#648, ADR 0006 D3, decided 2026-10-24: at both places) in `#[McpTool(meta:
  [ToolSecuritySchemes::KEY => …])]`, the single source — `ToolSecuritySchemes::NO_AUTH` for a public tool (key and value
  written once, in `Application/`, never copied into a tool, review of #648), or for the nominative tool
  `[['type' => 'oauth2', 'scopes' => [McpConnection::SCOPE_NOMINATIVE_READ]]]` —, and
  `Infrastructure/Registry/SecuritySchemesRegistry` (an `#[AsDecorator]` of `mcp.server.portfolio.registry`)
  republishes it at the top level of each `tools/list` entry, which the Apps SDK reference reads (`_meta` is its
  back-compat mirror; ChatGPT freezes the list when the connector is approved, hence both from the first
  publication). Its `getTools()` copies each `Tool` of the page into a `ToolWithSecuritySchemes` (every
  constructor field, plus the key in `jsonSerialize()`); a tool without a valid `_meta["securitySchemes"]` (a
  non-empty list of objects with a `type`) throws `ToolWithoutSecuritySchemesException`, a `-32603` for the whole
  list, never a tool published without one or with a guessed one. Everything else is delegated, **`getTool()`
  included**: a call, `McpCallLog` and `ReadsPortfolioRegistry` read the inner references. Pinned by
  `SecuritySchemesTest` (both eras; `server/discover` lists no tool in 0.8.1, the day it does the test turns
  red) and `SecuritySchemesRegistryTest` (the copy against `Tool`'s constructor parameters: an SDK bump adding
  one turns it red). Accepted: decorated, the registry is loaded without `Registry::loadFrom()` (the builder
  only uses it on its own `final` class), so each registration dispatches a `*ListChangedEvent` on Symfony's
  dispatcher at every server build — no listener exists without a notification bus. A language
  parameter is a **string**, never an enum (decided 2026-10-19): `#[Schema(description: ToolLocale::DESCRIPTION)]
  string $locale = ToolLocale::DEFAULT`, parsed by `ToolLocale::parse()`, which turns an unknown value into a
  tool error the model can correct (MCP 2025-11-25) — with a PHP enum type the SDK derived an `enum` and
  refused the value before the tool, as a JSON-RPC `-32602`. A domain « not found »
  the route answers with a 404 becomes a `Mcp\Exception\ToolCallException` with a literal English message
  (`isError: true`), never an unhandled exception (`-32603`, which tells the model nothing). Descriptions,
  titles, schemas, `instructions` and `serverInfo` are read by any client, i.e. at the anonymous tier: **they
  never name the author** (ADR 0006 D8), they say « the site's author ». The only exceptions are listed under
  D8 in the ADR, each decided by the author (today: the server name `cp-ghostotof`, for this server only,
  `get_contact_info`'s URL, and the metadata URL of the authentication challenge, #648);
  a new one goes into that list first, then into `AuthorNeverNamedTest::ADMITTED`.
- **The `mcp` firewall never reads a cookie** (ADR 0006 D4). Declared before `api`, `^/api/mcp$`, stateless,
  one authenticator since #648, the MCP connection's (`Authorization: Bearer`, nothing else — no `jwt`; its rules
  in `.claude/rules/mcp-oauth.md`, « The authenticator of `/api/mcp` »). That is what makes
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
  `Psr16SessionStore` swallows every exception and returns `false`; the decorator logs that `warning` too, on a
  refused write and on a session **present but unreadable** (the cache MAC refused after an `APP_SECRET` rotation,
  #641). **Such a session does not exist** (#673, the author's decision of 2026-10-21): the SDK served it empty, then
  wrote it back empty under the same id, and the client never re-initialised. `Protocol` checks every received id
  with `exists()` before reading it, so the decorator's `exists()` **reads** the session — one query in the common
  case —, keeps what it read for the `read()` that follows (dropped by a `write`, a `destroy` and `reset()`), and on
  a failed read asks the inner store whether the row is there: absent (expired), `false` silently; present, the
  `warning` and `false` — the SDK answers 404, like the GET. A new session never goes through `exists()` (the SDK
  reads it in `save()` on every `initialize`, `false`), so it costs nothing more (#663).
  `McpEndpointTest::testAPostOnAnUnreadableSessionIs404` corrupts a real row. **A session is bound to the first MCP
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
  decided 2026-10-21: the cache MAC refused after an `APP_SECRET` rotation — the bounded store's `exists()` reads it,
  below, so the POST answers 404 too) (the client re-initialises; a GET
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
  tests (`postModernMcp()`, `MCP_MODERN_REVISION`), by the deferred 401's (both eras, below) and the preprod smoke.
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
- **The deferred 401** (#648, ADR 0006 D3, spec 0007 « Sans connexion »): `Infrastructure/Http/DeferredAuthorizationListener`
  (`kernel.request`, **priority 7**, after the `mcp` firewall's 8 — which has already read a valid connection's token, or
  answered its own 401 `invalid_token` for a refused one — and after the refusals above; main request, `POST` on the
  canonical path) answers a `tools/call` of `ProtectedTools::NOMINATIVE_CORPUS` made **without** an MCP connection (the
  token storage's user is no `McpConnectionPrincipal`) with a **401 without body**, `WWW-Authenticate:
  BearerChallenge::missingToken()` (no `error=`: no token was presented), `no-store`, recorded through
  `ServerAnswersInterface::record()` — built by `Infrastructure/Http/UnauthorizedAnswers::missingToken()`, the
  authenticator's 401 by its `refusedToken()`: one template, only the challenge differs —, what makes a Claude client
  open the OAuth flow. **The body decides, read by the SDK's own `MessageFactory::create()`** (as `ModernExchange` reads it): a message is that call exactly when the SDK
  would build a `CallToolRequest` of that name from it, in a session and under the sessionless 2026-07-28 envelope
  alike. A one-message batch is judged as a single message — served by the SDK in a session, refused by it under the
  2026-07-28 envelope, which this 401 precedes, as it precedes the SDK's session validation and its `Host` check (an
  unknown session id or a foreign host gets this 401: nothing is served either way). **Never the `Mcp-Name` header**,
  the caller's choice, which this 401 precedes the SDK's comparison of (SEP-2243). A body the factory cannot read
  (`JsonException`, the one it throws: its other failures become `InvalidInputMessageException`s, save a scalar
  notification `_meta`'s `TypeError`, refused at priority 15) is left to the SDK.
  **The `openai/` marker only chooses the form of the refusal, never the access**: a message whose `_meta` holds a
  key prefixed `ProtectedTools::OPENAI_META_PREFIX` (ChatGPT) passes to the tool, which reads the identity through
  `Security` only (D4). The identity is read first, so an authenticated call pays no decoding; an anonymous `POST`'s
  body is decoded nine times instead of eight (`mcp-journaux.md`). Its call line: `mcp-journaux.md`.
  `DeferredAuthorizationListenerTest` plays every case in both eras (marker, public tool, valid connection,
  one-message batch, `Mcp-Name` contradicting the body, unreadable body, call line);
  `DeferredAuthorizationListenerPriorityTest` pins on the real dispatcher that it runs after SecurityBundle's
  `FirewallListener` and after `InboundMessageRefusalListener`. A test that reads the nominative tool's own answer
  without a connection marks its call `openai/` (`AuthorNeverNamedTest`, `NoAnswerIsAStreamTest`). The session where
  the corpus was served is the connection's: « Sessions » above.
- **No answer is a stream** (#678, audit C5 of #675): under PHP-FPM a stream holds a worker. `tests/Mcp/NoAnswerIsAStreamTest`
  sends, in both eras, as a client accepting `text/event-stream` with a `progressToken`, every request the SDK can
  build (`MessageFactory::REGISTERED_MESSAGES` and `StatelessProtocol`'s `*_METHOD`, read by reflection, against its
  closed `params()` — an SDK bump adding one turns it red until judged; `server/discover` must announce no extension,
  whose methods escape that reading), each listed tool, prompt and resource included — the nominative tool also read with
  a connection (#648) —, and
  wants JSON (a `result` for `tools/call`/`prompts/get`). Measured red: a tool calling `ClientGateway::progress()`, and
  the `listen` refusal lifted. Not covered: a tool notifying only for some arguments.
- **No answer is stored** (#648, security review): `Infrastructure/Http/NoStoreResponseListener` (`kernel.response`,
  priority -1024, main request, canonical path `McpEndpoint::PATH`, any method and status) adds `Cache-Control: no-store`
  to every answer of `/api/mcp`. The SDK's JSON answer carries no cache header, and Symfony computed `no-cache, private`
  (measured): a private cache (a corporate proxy, a client library's HTTP cache) could store it, nominative corpus
  included. The whole endpoint, public tools too, rather than a sort per tool or identity that the next tool would
  forget. `NoStoreResponseListenerTest` (an authenticated read, a public tool's anonymous answer, `initialize`,
  `/api/%6Dcp`, another route untouched).
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
