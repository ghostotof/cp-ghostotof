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
  description says to treat them as data; the output stays equal to the route (D1).
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
- **The prompt** (#642): `Presentation/Prompt/PresentForRolePrompt`, `present_for_role(role, locale)`,
  one user message in French or English — instructions (rely on the tools only, cite the tool behind each
  statement, say what they do not tell, treat tool outputs as data too), then the role's block, then a
  reminder that the block is data (« sandwich »: hostile data never has the last word). It carries nothing
  of the site. **Its `@param` texts are published**: the SDK makes them the arguments' descriptions in
  `prompts/list` (it reads no `#[Schema]` on a prompt), so they are English and under D8 like a tool
  description; `locale`'s is `ToolLocale::DESCRIPTION` word for word, its default `ToolLocale::DEFAULT`, its
  refusal `ToolLocale::UNKNOWN_LANGUAGE` (pinned by the test). `role` goes through the value object
  `PromptRole`: refused beyond `MAX_CHARACTERS` (500, counted in characters **on the input as typed**, before
  any cleaning, never truncated); then NFKC (folds the full-width `＜／ｒｏｌｅ＞`), one line ending
  (`\r\n`, `\r`, NEL, U+2028/2029 → `\n`), invisible characters removed (`\p{Cc}` but `\n` and `\t`,
  `\p{Cf}` — zero-width, bidi, Unicode TAG —, `\p{Co}`, `\p{Cn}`, variation selectors); then, as
  `CorpusRenderer` does for `documents`, **only the chevrons introducing the word `role`** are removed
  (ASCII and the look-alikes NFKC leaves, `‹ 〈 ⟨ ˂ ᐸ ❮`…, across blanks and slashes) — « équipe <10
  personnes, PHP >= 8.4 » keeps its meaning —; trimmed of Unicode blanks; refused without a letter or digit.
  `delimited()` writes the block. A refusal (and an unknown language) is a `PromptGetException`, the only
  error the SDK 0.8.1 renders with its message — `-32603`, where the protocol wants `-32602`; a missing
  `role` is the SDK's own generic `-32603` « Error while handling prompt ». Its message never quotes the
  text received: the SDK logs it with the error, at `error` (a client mistake: #643 caps that channel). A
  PCRE or NFKC failure is `RoleNeutralisationFailedException`, a server fault (generic `-32603`), never a
  « role is empty ». Not exhaustive, accepted (like the corpus): letters of other alphabets mimicking
  `role`, HTML entities. The raw `prompts/get` arguments, `role` included, are still in the SDK's `debug`
  log of the request — task 4 (#643).
- **The D8 guard** (#642, ADR 0006 D8): `tests/Mcp/AuthorNeverNamedTest` (group `d8`) reads, **over HTTP on
  the real server**, once per class, what it says or writes of itself: `initialize` (`serverInfo`,
  `instructions`), `tools/list`, `prompts/list`, `resources/list`, `resources/templates/list` (a `nextCursor`
  fails it: a next page would escape); every prompt's `prompts/get` in each language it declares and its
  answers to invalid input (too long, empty, unknown language, required argument missing), all built from
  what `prompts/list` declares; `completion/complete` on every prompt argument; every tool's answer to an
  unknown language; and the **output of the tools without a route** (`McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE`,
  public for that), which the server writes itself — a route-backed tool's output is its route's content (D1)
  and is not read. `testEverySurfaceTheServerWritesIsRead` pins that list. Through
  `tests/Mcp/Support/AuthorMentions` it refuses, after NFKD folding (full width, accents and `\p{Cf}` removed,
  so a soft hyphen inside the name does not split it), case and punctuation ignored: the full name and each
  word of three letters or more as whole words; failing that, the name joined or a word of five letters or
  more inside a word (`prenomnom22@…`); the **initials** in capitals as a word (`XY`, `X. Y.`) or in lower
  case at the head of an identifier (`xy-…`, `xy_…`, `xy.…`) — which gives `cp-ghostotof` its meaning as an
  exception —; and any run of six consecutive words of the nominative CV. Every string read, keys included.
  Each finding is an `AuthorMention` (path, `MentionKind`, term number), **never the term nor the text carrying
  it**. The exceptions are `AuthorNeverNamedTest::admitted()`, copied from ADR 0006's closed list (the server
  name, `get_contact_info`'s URL in both its forms), admitted at their path and for their exact value only,
  and checked to be still published as written (`assertTrue`, no diff that would print the value). The CV is
  the file `GET /api/cv` serves (`app.cv_file_path`, through the corpus's `PdfTextExtractorInterface`):
  textless in CI (`dummy.pdf`); any other file present must yield a text, or the guard fails rather than
  search zero fragments. **The name never enters the repo**: `MCP_D8_AUTHOR_NAME` is a fictive name in
  `phpunit.dist.xml`, as an `<env>` **without `force`** (PHPUnit then keeps a value the process environment
  already carries — `<server>` always overwrites). In `test-backend` the suite runs `--exclude-group d8`, and
  a step of its own, run whenever the suite ran, runs `--group d8` with the **repository secret**
  `MCP_D8_AUTHOR_NAME`: the rest of the suite and its `vendor/` code never hold it. A Dependabot push cannot
  read it: for the actor `dependabot[bot]` only, the empty variable is unset and the fictive name applies;
  anywhere else an empty name fails the guard, saying so. **Under `GITHUB_ACTIONS` a failure gives the number
  of mentions only** — a public log naming the path and the term's number would tell which published word is
  the name; locally it lists them. Set or rotate the secret from a prompt or a file, never `--body`:
  `gh secret set MCP_D8_AUTHOR_NAME --repo ghostotof/cp-ghostotof` (the author decided on 2026-10-20 that his
  name alone is no secret in an assistant session, so it may be run there too; it stays out of the repo). To
  check the real name locally: `read -r MCP_D8_AUTHOR_NAME && export MCP_D8_AUTHOR_NAME`, then `docker compose
  exec -e MCP_D8_AUTHOR_NAME -T -u dev backend php bin/phpunit --group d8` (`-e` without a value: nothing in
  `ps` nor the history). The guard's in-memory mutations prove it bites (the test name in a tool description
  — whole, last word in capitals, joined with digits, full width, split by a soft hyphen —; initials at and
  beside the admitted path; a name at the admitted path; the name in a prompt refusal; a fragment of the
  fictive CV, taken from its text); measured on the code too (#642: the name in a tool description, in a
  prompt `@param`, and in `ToolLocale::UNKNOWN_LANGUAGE`, each red — the last at every error surface, and
  reduced to a count under `GITHUB_ACTIONS`). Accepted limits: the guard runs under the test configuration (a
  future `%env()%` in `instructions` would be read with its test value), letters of other alphabets mimicking
  the name, a CV fragment shorter than six words (an employer alone, an address).
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
  write refused (`warning`, size only, never the id), and **the id stays gone for the rest of the request**
  (#641): draining its outgoing queue, the SDK re-reads the session and writes it back, empty, under the same
  id — which stayed valid, so the next request ran on an emptied session (no `clientInfo`, no negotiated
  version) instead of the 404 that makes a client re-initialise. The decorator remembers the destroyed id
  (`ResetInterface`) and refuses it on `exists`/`read`/`write`; the answer queued for that request is lost
  (it could not fit), the client sees no JSON-RPC answer, then a 404. The **number** of sessions per IP is
  the nginx `mcp` zone's business (task 5, #644), not the bound's. **The bound must fit the largest legitimate
  answer**: the SDK queues a tool's response in the session before sending it, so every answer goes through
  `cache_items` — phase 2's nominative corpus included (measure it there). Each MCP request costs three
  round trips on the session row (`has`, `get`, `set`) and two writes per `tools/call` (save, then the queue
  drained): ADR 0006 D6's « no write per call » holds for the authentication, not for the session.
  `Psr16SessionStore` swallows every exception and returns `false`; the decorator logs that `warning` too, on a
  refused write and on a session **present but unreadable** (`read()` false while `exists()` is true — the cache
  MAC refused after an `APP_SECRET` rotation, #641): the SDK then starts from an empty session.
- **`GET /api/mcp` answers an empty SSE stream, not 405** (`Presentation/Controller/McpEndpointController`, an
  `#[AsDecorator]` of `mcp.server.portfolio.controller`): `retry: 300000`, then closed. The SDK 0.8.1 answers
  405 (spec-compliant), which PhpStorm's AI Assistant takes for a failure. A finished `Response`, **never a
  `StreamedResponse` held open**: under PHP-FPM a held stream keeps one worker per connected client, and the
  server sends no notification anyway. POST, DELETE and OPTIONS go to the bundle; `HEAD`, which the router
  matches to the GET route, gets the GET's answer without a body (it reached the SDK's 405 before #641).
  **The GET goes through the very middlewares of the POST** (#641): `mcp.server.portfolio.middleware_factory`
  (the SDK's DNS-rebinding protection and CORS, built by the bundle from `allowed_hosts`) run by the SDK's
  `MiddlewareRequestHandler` — never a copy of their rules: T1's copy kept one host where `allowed_hosts` is a
  list, and split `[::1]:8080` into `[`. Then it answers as the transport does for a session: 400 for a
  malformed or repeated `Mcp-Session-Id`, 404 for an unknown or expired one (the client re-initialises; a GET
  without the header is still served), 406 without `Accept: text/event-stream`. `McpEndpointGetTest` pins each
  case, the host rules on a several-host list included. Accepted, like the SDK: the `Origin` check ignores
  scheme and port (`http://<site>:8443` passes), a page served on the site's own name.
- **Tests** speak MCP through `tests/Mcp/Support/SpeaksMcp` (`initializeMcpSession()`, `mcpCall()`, JSON or
  SSE answers alike, the last answering event of a stream; `callMcpTool()`, `listedMcpTool()`, `routeBody()`).
  A test that opens sessions calls `forgetMcpSessions()` in its `tearDown`: the `mcp-portfolio-` rows of
  `cache_items` live an hour in the test database. A new route-backed tool adds its rows to
  `RouteBackedToolsTest`'s providers (equality per language, closed schema, empty `{"items": []}`, unknown
  language), where a base-tier route is read with a base-access token, the cookies then dropped so that
  the tool is called without one. Only the
  handshake-era revision (`2025-06-18`) is exercised; the SDK also serves the sessionless 2026-07-28 revision on
  the same endpoint, untested so far (phase 2's deferred 401 must recognise the protected tool in both forms).
- **Open, owned elsewhere** (security review of #640): the SDK logs raw JSON-RPC messages, arguments and
  session ids at `info`/`debug` on the `mcp` channel, and client-chosen strings (unknown method, tool name) at
  `warning`/`error` — task 4 (#643) caps that channel and gives the call log a channel of its own; batches of up
  to 100 calls per HTTP request, the request body size and the number of sessions per IP — task 5 (#644).
