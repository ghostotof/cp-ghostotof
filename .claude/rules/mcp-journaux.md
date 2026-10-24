---
paths:
  - "backend/src/Mcp/Infrastructure/Log/**"
  - "backend/tests/Mcp/Infrastructure/Log/**"
  - "backend/config/packages/monolog.yaml"
---

# Backend — `Mcp`: the SDK's channel and the call log

> Split out of `.claude/rules/mcp.md` (#673), whose index lists every MCP rule. Decisions: `docs/adr/0006-serveur-mcp.md`;
> spec `.claude/specs/0007-mcp-server.md`. The index of every rule is at the top of `.claude/CLAUDE.md`.

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
  the prompt before any cast since #663 (« The prompt », `.claude/rules/mcp-prompt-d8.md`). `SdkChannelRecordsTest` provokes each client fault on the real server, with
  100 KB names, and reads the `all_channels_test` probe at `debug`: nothing at `warning` or above, no client
  string whole, no `role` sentinel, no session id — an SDK upgrade that renames a message or a class turns it red.
  Accepted: the dev log loses the raw messages too (the processor is not per environment).
- **The call log is `mcp_usage`** (#643, spec 0007 « Journal des appels », not `mcp`: the SDK's channel).
  `Infrastructure/Log/McpCallLog` listens to the SDK's own events, dispatched through Symfony's
  `event_dispatcher` (`Mcp\Event\RequestEvent`, `ResponseEvent`, `ErrorEvent`, `NotificationEvent`), and writes
  **one `info` line per JSON-RPC message handled**, `MCP call` — an invalid message (an unknown method, alone or
  in a one-message batch, the only batch left since #644) has no event: the class is also a Monolog processor of the `mcp` channel and writes its line, method `null`,
  `-32600`, when the SDK logs « Failed to create message. » — at `warning`, which matters: Monolog runs a channel's
  processors only when one of its handlers takes the level, and in production the `mcp` handler, at `warning`, is the
  only one; below it the line would fall back to `MCP request without call` (#673, point 7,
  `McpCallLogTest::testTheSdkLogsAnInvalidMessageAtALevelTheProductionHandlerTakes` reads the SDK's level before the
  redactor lowers it, against the production handler; measured red with that handler at `error`): `method`, `name` (the tool or prompt, **only
  when the registry knows it** — the caller's name has no bound), `outcome` (`McpCallOutcome`: `success`,
  `tool-error` for `isError: true`, `protocol-error`), `errorCode` (JSON-RPC), `durationMs`, `ip`
  (`TruncatedIp`: IPv4 /24, IPv6 /48 through `IpUtils::anonymize()` since #673, after recognising on the bytes the two
  forms it misses — an IPv4-mapped address written as the IPv4 it carries (Symfony keeps the IPv6 form), a 6to4
  address `2002::/16` keeping only the first three bytes of its IPv4 (a /48 kept it whole), the translation prefix
  `64:ff9b::/96` (an IPv6 server reached by IPv4 clients through NAT64/SIIT) keeping the /24 of its IPv4 (a /48 erased
  it); the deprecated
  IPv4-compatible `::a.b.c.d` is truncated to /24 as Symfony does, where a /48 left `::`; read **once, at
  `kernel.request`**: the `RequestStack` is empty at terminate and during an SSE answer) and
  `declaredClient` (`clientInfo` from the session, name and version bounded to 64 characters, control and format
  characters removed — U+202E reverses what follows in `kubectl logs`, #664: declared, never
  checked). **The sessionless `2026-07-28` revision has the same `MCP call` line** (#664, the author's decision of
  2026-10-20: serve it and log it, rather than refuse it through `protocol_versions`, which would pin the handshake
  to one revision). The SDK serves it through `StatelessProtocol`, **`final`, built inside `Builder::build()`,
  with no event**: nothing to listen to nor decorate, so `Infrastructure/Log/ModernExchange` reads it at
  `kernel.terminate` from the HTTP request and response. The era is **the SDK's decision**, its own
  `InboundClassifier` on the body and on the headers **as the SDK receives them**, converted by the bundle's
  PSR-7 bridge (`mcp.psr_http_factory`, which trims blanks and drops a header carrying a control character) —
  never the header alone (an unknown revision in `_meta` is served, and refused, by the modern leg; a modern
  header without the envelope is refused before any era and keeps the fallback line). **Only the server's own answer
  counts** (#678, audit C9 of #675): the response the MCP controller or `InboundMessageRefusalListener` returned,
  recognised **by its object** in `Infrastructure/Http/ServerAnswers` (behind `ServerAnswersInterface`, a `WeakMap` both
  fill through `record()`) —
  a 404, 405 or 400 of the framework under `/api` is JSON too (`ApiJsonErrorFormatListener`), and got a call line of
  the method sent; by object rather than by a request attribute, so that a response a later listener replaces is no
  longer the server's. Accepted, so: a 5xx raised by the controller, or a served answer a `kernel.response` listener
  replaces (none in `src/`), keeps the fallback line and loses the method and tool name. `McpCallLogTest` raises a framework 404 from a test listener (and a router 400 on an
  unreadable `X-Forwarded-Host`, which the PSR-7 bridge also refuses): both keep the fallback line. Then **only a
  protocol answer counts** — JSON, an SSE stream, an acknowledgement without body (which Symfony types `text/html`): an `Origin` or
  `Host` the SDK refuses at its edge, before classifying (a `text/plain` 403), handled no message and keeps the
  fallback line, as in the other era; accepted: the SDK's 4 MiB refusal (413) is JSON, read as a protocol error of
  the method sent — nginx's 16 KiB comes first. The method is the one **the SDK's `MessageFactory` recognises** (the class of the
  typed request or notification), plus the two the stateless protocol serves before it, by its constants
  (`server/discover`, `subscriptions/listen`); any other is the caller's, unbounded, and written `null` — so is a
  known method whose message is malformed, as in the other era, and one the factory throws on (a `TypeError` on a
  scalar `_meta`, which the SDK never meets: it acknowledges a notification without building it). The factory is
  built **without the extension messages** `Builder::build()` adds: bundle 0.14.1 enables none, and
  `testTheServerEnablesNoExtension` (`server/discover` announces no `extensions`) turns red the day one is — the
  reading must then get the server's factory. Whatever the reading throws leaves the fallback line, `reset()` in a
  `finally` (`McpCallLogTerminateTest`). The outcome is read in the JSON answer (`error`
  and its `code`, `result.isError`), else from the status: a 202/400 without body (a notification), an SSE stream
  (`listen`, refused before the SDK since #675; a tool that notifies — none does) whose `getContent()` is `false`.
  **`durationMs` is the whole HTTP request's** (firewall, listeners, sending the answer), where the other era measures the
  message's handling: a duration statistic keeps the two eras apart. `declaredClient` is the `clientInfo` of the
  request's own `_meta`, bounded the same way.
  `McpCallLogTest` drives the real server in that revision (success, tool error, unknown tool and method,
  removed method, `server/discover`, prompt, notification, unsupported revision, batch, foreign `Origin`, scalar
  `_meta`, malformed and bounded client) through `SpeaksMcp::postModernMcp()`; `ModernExchangeTest` covers what it
  cannot (the 30-second `listen` stream, an edge refusal, headers trimmed or dropped by the bridge).
  **Each POST's body is decoded eight times, accepted** (#681, the author's decision of 2026-10-21): four during the
  request — `InboundMessageRefusalListener`, the bundle's PSR-7 bridge (`PsrHttpFactory` `json_decode`s a JSON body
  for `getParsedBody()`), the SDK's classifier and its `MessageFactory` — and four at `kernel.terminate` for this
  reading, on every POST the server answered, whatever its era — the bridge again, the classifier, `decodedObject()`,
  the factory —, five of them ours. Measured on a 16 KiB body (nginx's bound): ~11 µs a decoding for one long string,
  ~265 µs for dense tokens (the worst case), so ~65 µs to ~1.3 ms of ours per POST, half of it after the answer is
  sent (a worker held, no client waiting), under the `mcp` zone's 60 requests a minute per IP. Rejected: memoising
  the bridge's request per HTTP request (a `WeakMap` decorator of `mcp.psr_http_factory`) and handing the
  listener's decoded body to the log — two decodings of eight saved, ~530 µs at worst, for two more pieces and a
  contract between the listener and the log; the classifier and the factory stay the SDK's own, which decide the era.
  **The deferred 401** (#648, `mcp.md`) is the server's own answer too: in a session it leaves the fallback line
  (`POST`, 401, `protocol-error`); under the modern envelope a call line `tools/call`, `protocol-error`, no `errorCode`,
  no `httpStatus` and no `name` — the registry a name is checked against is only filled by the server's build, which
  this 401 precedes —, accepted: a refused token's 401 has the same line, and only that one has its « Authenticator
  failed. » on the `security` channel; nginx's access log carries the status (`DeferredAuthorizationListenerTest`).
  **An HTTP request on `/api/mcp` that handled no message** — unreadable JSON, missing or expired
  session, `GET`, `DELETE`, a request refused before the SDK (#644: 400, `protocol-error`; under the modern
  envelope it has a call line instead, the refusal being the server's own answer, and `ModernExchange` reading what
  the classifier says: the
  method the SDK's factory recognises — `null` for a client response, `initialize` for an oversized declaration —
  and the refusal's `-32600`), an edge refusal or one by the SDK's classifier, a response the server did not return (a framework 4xx) —
  leaves one `MCP request without call`
  line (`httpMethod`, `httpStatus`, `protocolVersion` — `ProtocolVersion::tryFrom()` of the header, `null` otherwise
  —, `outcome`, `durationMs`, `ip`) at `kernel.terminate`. A `POST` there is a `protocol-error` (the SDK answers a
  parse error in 200), except a 202. Pending requests are keyed by object (a JSON-RPC id is the client's; a batch could repeat it before
  #644, kept: the keying costs nothing and a future SDK may queue several) and keep
  their method, known name and start only. A request left without an answer (an exception the SDK caught outside
  its events) is written at terminate as `protocol-error`; accepted: a suspended exchange (sampling, elicitation —
  no tool does it) is written so too, and its resumption leaves only a fallback line. **Never an argument, a header, a cookie nor the session id**: the
  line is built field by field. Its own `info` handler in `when@prod`, like `ai_usage`, excluded from `main` and
  `console` (`McpLogChannelsTest`); `McpCallLogTest` reads it through the `all_channels_test` probe (sentinels
  in `role`, `Authorization` and `BEARER`, one-message batch, notification, fallbacks, IPv4/IPv6 callers).
