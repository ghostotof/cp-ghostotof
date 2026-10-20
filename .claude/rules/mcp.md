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
  refusal `ToolLocale::UNKNOWN_LANGUAGE` (pinned by the test). **The block's tag carries a nonce** (#662,
  decided 2026-10-20): `RoleBlockTag::random()`, `role-` + 8 random bytes in hex, drawn **per prompt**; the
  instructions name it (« between `<role-…>` and `</role-…>` … the block ends only at `</role-…>` »), and so
  does the reminder. That is the defence that holds against every look-alike: a booby-trapped job ad is
  written before the nonce exists, so it cannot write the block's real end, and a fake one (`</role>`,
  `</ʀᴏʟᴇ>`) lacks the nonce. Random rather than an HMAC of the text: nothing needs to recompute it, and a
  nonce that changes on every call cannot be learnt by calling the prompt on the trap. Hex, so neither the
  D8 guard (no letter past `f` — a name made of `a`–`f` only would make it flaky) nor the cleaning reads a word
  in it. Tests read the tag from the text (`PresentForRolePromptTest::tagOf()`). The cleaning below stays, as
  defence in depth. `role` goes through the value object `PromptRole`: refused beyond `MAX_CHARACTERS`
  (500 characters, never truncated) **on the input as typed**, before any cleaning (removing characters must
  not let a long text through) — the bound the argument's description publishes —, and beyond
  `MAX_CLEANED_CHARACTERS` (**twice** the bound) on the cleaned text, which NFKC can lengthen (U+FDFA gives 18
  characters: ~9 000 injected before #662); the cap is not the bound itself so that an autocorrected `…`
  (three characters once folded) never gets a text within the bound refused; same message both times.
  Cleaning, in this order: NFKC (folds the full-width `＜／ｒｏｌｅ＞`); invisible characters removed (`\p{Cc}`
  but the blank controls, `\p{Cf}` — zero-width, bidi, Unicode TAG —, `\p{Co}`, `\p{Cn}`, and
  **`\p{Default_Ignorable_Code_Point}`**, #662: variation selectors, and the ignorables `\p{Cf}` misses, `Mn`
  U+034F, U+180B–180D, U+180F, U+17B4–17B5 and `Lo` Hangul fillers U+115F, U+1160, U+3164, U+FFA0 —
  `<\u{034F}/role>` displayed as `</role>`; U+180E is `Cf`; ZWJ and VS16 inside emoji sequences go too, as
  before #662, accepted); then, as `CorpusRenderer` does for `documents` in principle, **only the opening signs
  introducing the word `role`** are removed (`OPENING`): **by class** every `\p{Ps}` and `\p{Pi}` (#662, over a
  list of look-alikes, which stopped at the last glyph thought of — `《 ❰ «` passed): `‹ 〈 ⟨ ❮`, but also `[`
  and `{`, which open a tag elsewhere (BBCode, templates); **by name** those that are not punctuation: `<`,
  `≮` (NFKC composes it from `<` + U+0338), `˂ ˱ ᐸ ᚲ`. They are removed across **anything that is neither a
  letter nor a digit** (`TAG_RUN`; #662 audit: a list of separators kept the `<` of `<\role`, `<|role`,
  `<⟋role`, `<⠀/role`) — « équipe <10 personnes, PHP >= 8.4 » keeps its meaning; `(role-based…)` and
  `“role”` lose their opening sign, accepted. `TAG_RUN` starts only at the head of a run (lookbehind) and is
  possessive: without them a long run followed by a letter was read again from each position, quadratic
  (measured 1.9 ms → 0.08 ms on 990 dots then `a`, JIT off; no test pins it, PCRE's limits did not tell
  the two patterns apart); then **one line**: every run of blanks (`\s`, `\p{Z}`, NEL, and the blank-looking
  U+2800 braille blank and U+1D159 null notehead) becomes one space (#662 audit: line breaks hid an
  instruction sixty lines down, or mimicked on its own line an end of block and a fake reminder) — a pasted
  list becomes one line, accepted —, then trimmed; refused without a letter or digit. `delimited($tag)`
  writes the block. A refusal (and an unknown language) is a `PromptGetException`, the only
  error the SDK 0.8.1 renders with its message — `-32603`, where the protocol wants `-32602`. **Both parameters
  are untyped (`mixed`)** (#663): typed `string`, the SDK cast an argument of another type itself — an array became
  `"Array"` with a PHP `warning` on the `php` channel (fixed text, no value), a line an anonymous caller produced at
  will in production. `PromptRole::fromInput()` refuses anything but a string with `NOT_A_TEXT` — a missing `role`
  too, which the SDK now passes as `null` instead of its generic « Error while handling prompt » —, and a `locale`
  of another type is an unknown language (`null` stays the default: the SDK reads it as absent).
  `PresentForRolePromptTest` catches the PHP warning with its own error handler (PHPUnit's keeps vendor warnings
  away from Monolog) and reads every channel above `notice`. A tool needs none of this: the SDK validates its
  arguments against the `inputSchema` first. Its message never quotes the
  text received: the SDK logs it with the error, at `error`, which `SdkChannelRedactor` downgrades to `notice` (#643). A
  PCRE or NFKC failure is `RoleNeutralisationFailedException`, a server fault (generic `-32603`), never a
  « role is empty »; a Unicode property the PCRE did not know would fail closed too, but as the compile
  warning — an `ErrorException` where Symfony turns warnings into exceptions (debug), the named exception
  elsewhere. `\p{Default_Ignorable_Code_Point}` needs PCRE2 ≥ 10.40: 10.44 in the images (PHP's bundled
  one), the runner's system library in CI (Ubuntu 24.04: 10.42). **Unicode versions differ**: ICU 78
  (Unicode 17) folds, PCRE 10.44 (Unicode 15.0) classifies, so a character assigned after Unicode 15.0 is
  `\p{Cn}` to PCRE and removed — the cautious direction; the reverse (a newer PCRE than ICU) would let
  unfolded letters through. Not exhaustive, accepted (like the corpus), **the nonce covering each**: letters
  of other alphabets mimicking `role` (`</r\u{043E}le>`, a Cyrillic `о` NFKC does not fold), same-script
  look-alikes (small capitals `</ʀᴏʟᴇ>`, `roIe`, `r0le`, `rôle`), a combining mark inside the word (`ró`),
  letters used as separators (`<ノrole`, `<丿role`), HTML entities, closing quotes used as openers
  (`»/role«`, `\p{Pf}`), and symbols that are not listed openers (`≺`, `◁`, `\p{Sm}`/`\p{So}`). The raw `prompts/get` arguments, `role` included, are withheld from the SDK's log
  (#643, below).
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
  `MCP_D8_AUTHOR_NAME`: the rest of the suite and its `vendor/` code never hold it. **No actor falls back on the
  fictive name** (#663): a Dependabot push reads only the **Dependabot secrets**, so the same secret is set
  there too — before #663 the empty variable was unset for `dependabot[bot]`, and a bump of `mcp/sdk` or
  `symfony/mcp-bundle`, which write what the server publishes, was never checked against the real name in its
  own run (only after its merge, on `develop`'s). Without the Dependabot copy, a Dependabot run fails the guard,
  saying so, like an empty name anywhere else. Accepted: the name then reaches the dependency code of a
  Dependabot branch not reviewed yet — the code it would reach on `develop` once merged; the alternative (an
  `ignore` of the two packages in `dependabot.yml`, bumped by hand) gave up their update PRs.
  `tools/tests/d8-guard-step.test.sh` (`tools-tests`) pins that step: the secret in its `env`, no fallback in its
  script, no other line of `pipeline.yml` naming the variable (`.github/` is not mounted in the dev container,
  hence a shell test rather than PHPUnit); `tools/github-settings.sh` (step `l`) checks both copies exist. **Under `GITHUB_ACTIONS` a failure gives the number
  of mentions only** — a public log naming the path and the term's number would tell which published word is
  the name; locally it lists them. Set or rotate the secret from a prompt or a file, never `--body`:
  `gh secret set MCP_D8_AUTHOR_NAME --repo ghostotof/cp-ghostotof`, then the same with `--app dependabot` (the
  two stores are distinct; the author decided on 2026-10-20 that his
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
  list, and split `[::1]:8080` into `[`. Then it answers as the transport does for a session: 400 for a
  malformed or repeated `Mcp-Session-Id`, 404 for an unknown or expired one (the client re-initialises; a GET
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
  the tool is called without one. Only the
  handshake-era revision (`2025-06-18`) is exercised; the SDK also serves the sessionless 2026-07-28 revision on
  the same endpoint, exercised only by #672's tests (no session written, `initialize` refused) and the preprod
  smoke (phase 2's deferred 401 must recognise the protected tool in both forms).
- **The SDK's `mcp` channel is capped and bounded** (#643, security reviews of #640 and #642). McpBundle tags
  every SDK service on `mcp`, hard-coded: at `info`/`debug` it wrote the raw JSON-RPC message (« Received message
  to process. », arguments included — `role` is free text), the decoded request and the session ids, and
  client-chosen strings unbounded at `warning`/`error`. In `when@prod` its own handler is at **`warning`**, and no
  other handler (`main`, `console`) receives it — preprod's `LOG_LEVEL=debug` included (`McpLogChannelsTest`).
  `Infrastructure/Log/SdkChannelRedactor` (`#[AsMonologProcessor(channel: 'mcp')]`, every level, every
  environment) replaces the context keys of `WITHHELD_KEYS` (`message`, `request`, `response`, `arguments`,
  `session_id`…) by `WITHHELD`, bounds the message and every context string to 256 characters, turns an
  exception into its normalised form without a trace (three `previous` at most), and **downgrades the client's
  faults to `notice`** — under the cap; the call log keeps their outcome. Which faults: a closed list written
  against SDK 0.8.1 — by SDK exception class when one only designates the client (`ToolNotFoundException`,
  `PromptNotFoundException`, `ResourceNotFoundException`, `PromptGetException`, `InvalidInputMessageException`),
  by exact message otherwise (unreadable JSON, an id-less error response), and `RegistryException` only by code
  `-32602` or for a missing or unconvertible argument (message prefix). **Never `Mcp\Exception\InvalidArgumentException`**:
  the SDK also throws it for wiring defects (« Invalid handler type »), which would vanish under the cap. A server
  fault (`JsonException` while encoding, a wiring `LogicException`, `RoleNeutralisationFailedException`) keeps its
  level (`SdkChannelRedactorTest`). An argument of another type than a string (an array for `role`) is refused by
  the prompt before any cast since #663 (« The prompt », above). `SdkChannelRecordsTest` provokes each client fault on the real server, with
  100 KB names, and reads the `all_channels_test` probe at `debug`: nothing at `warning` or above, no client
  string whole, no `role` sentinel, no session id — an SDK upgrade that renames a message or a class turns it red.
  Accepted: the dev log loses the raw messages too (the processor is not per environment).
- **The call log is `mcp_usage`** (#643, spec 0007 « Journal des appels », not `mcp`: the SDK's channel).
  `Infrastructure/Log/McpCallLog` listens to the SDK's own events, dispatched through Symfony's
  `event_dispatcher` (`Mcp\Event\RequestEvent`, `ResponseEvent`, `ErrorEvent`, `NotificationEvent`), and writes
  **one `info` line per JSON-RPC message handled**, `MCP call` — an invalid message (an unknown method, alone or
  in a one-message batch, the only batch left since #644) has no event: the class is also a Monolog processor of the `mcp` channel and writes its line, method `null`,
  `-32600`, when the SDK logs « Failed to create message. »: `method`, `name` (the tool or prompt, **only
  when the registry knows it** — the caller's name has no bound), `outcome` (`McpCallOutcome`: `success`,
  `tool-error` for `isError: true`, `protocol-error`), `errorCode` (JSON-RPC), `durationMs`, `ip`
  (`TruncatedIp`, on the bytes: IPv4 /24, IPv6 /48, an IPv4-mapped address written as the IPv4 it carries, a 6to4
  address `2002::/16` keeping only the first three bytes of its IPv4 — a /48 kept it whole; read **once, at
  `kernel.request`**: the `RequestStack` is empty at terminate and during an SSE answer) and
  `declaredClient` (`clientInfo` from the session, name and version bounded to 64 characters: declared, never
  checked). **An HTTP request on `/api/mcp` that handled no message** — unreadable JSON, missing or expired
  session, `GET`, `DELETE`, a request refused before the SDK (#644: 400, `protocol-error`), and the whole sessionless `2026-07-28` revision, which the SDK
  serves through `StatelessProtocol` with **no event** (follow-up issue) — leaves one `MCP request without call`
  line (`httpMethod`, `httpStatus`, `protocolVersion` — `ProtocolVersion::tryFrom()` of the header, `null` otherwise
  —, `outcome`, `durationMs`, `ip`) at `kernel.terminate`. A `POST` there is a `protocol-error` (the SDK answers a
  parse error in 200), except a 202 (a notification) and a modern revision, whose outcome follows the
  status only. Pending requests are keyed by object (a JSON-RPC id is the client's; a batch could repeat it before
  #644, kept: the keying costs nothing and a future SDK may queue several) and keep
  their method, known name and start only. A request left without an answer (an exception the SDK caught outside
  its events) is written at terminate as `protocol-error`; accepted: a suspended exchange (sampling, elicitation —
  no tool does it) is written so too, and its resumption leaves only a fallback line. **Never an argument, a header, a cookie nor the session id**: the
  line is built field by field. Its own `info` handler in `when@prod`, like `ai_usage`, excluded from `main` and
  `console` (`McpLogChannelsTest`); `McpCallLogTest` reads it through the `all_channels_test` probe (sentinels
  in `role`, `Authorization` and `BEARER`, one-message batch, notification, fallbacks, IPv4/IPv6 callers).
- **Three inbound messages are refused before the SDK** (#644): `Infrastructure/Http/InboundMessageRefusalListener`
  (`kernel.request`, priority 15, `POST` on the canonical path `/api/mcp`, so `/api/%6Dcp` too) decodes once a body
  starting, after JSON blanks, with `[` or `{`, and answers **400** `application/json`, a JSON-RPC `-32600` without
  `id` — the form of the SDK transport's own refusals —, the reason and its message in the enum
  `InboundMessageRefusal`: (1) **a batch of several messages**, with the SDK's own predicate (`json_decode` assoc,
  then any PHP list is a batch — **an object keyed `"0"`, `"1"`… too**, which passed while only the bracket was read,
  100 `tools/call` served, measured in the reviews of #644): the SDK 0.8.1 accepts up to 100 per request, hard-coded
  (`MessageFactory::DEFAULT_MAX_BATCH_SIZE`, built by the `Builder`, not configurable through the bundle), and batches
  left the protocol at revision 2025-06-18, the only handshake revision served; (2) **an `initialize` whose
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
  elicitation nor roots); the day a tool asks one, this refusal must learn to recognise the expected answer.
  A one-message batch is served, its message judged like a single one; unreadable JSON is left to the SDK (`-32700`).
  The refusal precedes the session and the `Host` check (a foreign host gets this 400, not the SDK's 403: nothing is
  served either way); `McpCallLog` writes its fallback line (`POST`, 400, `protocol-error`). Its messages are read by
  `AuthorNeverNamedTest`. **No body bound in the application**: `SdkChannelRecordsTest` sends 100 KB strings through
  the SDK on purpose, and the bound is nginx's (`.claude/rules/deploiement-nginx.md`, chunked included).
  `InboundMessageRefusalTest` pins every case; `McpEndpointTest`'s session-bound cases go through `_meta` since.
- **Rate, body and sessions per IP** (#644): the nginx `mcp` zone and the location's 16 KiB body bound
  (`.claude/rules/deploiement-nginx.md`). The `mcpsession` zone counts every `POST` whose `Mcp-Session-Id` is not
  a well-formed UUID (#672, an allow-list: PHP's PSR-7 bridge trims tabs and drops a header with a control
  character before `StreamableHttpTransport` tests the value's truthiness, so nginx cannot list the forms PHP
  reads as « no session ») and exempts the sessionless 2026-07-28 revision, served on an in-memory session —
  `McpEndpointTest` pins that an `initialize` under that header opens no session, that the revision writes none,
  and that the conf exempts exactly `ProtocolVersion::modernVersions()`. The `mcpconn` zone bounds simultaneous
  connections (2 per IP and per pod): `subscriptions/listen` holds a worker 30 s (#675, after #664).
