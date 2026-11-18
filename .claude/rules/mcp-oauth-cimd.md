---
paths:
  - "backend/src/Mcp/Connection/**/Cimd/**"
  - "backend/tests/Mcp/Connection/**/Cimd/**"
  - "backend/src/Mcp/Connection/Domain/ValueObject/CimdClientId.php"
  - "backend/src/Mcp/Connection/Domain/ValueObject/AllowedClientDomains.php"
  - "backend/src/Mcp/Connection/Domain/ValueObject/ClientMetadataDocument.php"
  - "backend/src/Mcp/Connection/Domain/ValueObject/ClientMetadataRefusal.php"
  - "backend/src/Mcp/Connection/Domain/ValueObject/ClientRegistration.php"
  - "backend/src/Mcp/Connection/Domain/Exception/ClientMetadataRefusedException.php"
  - "backend/tests/Mcp/Connection/Cimd*Test.php"
---

# Backend — `Mcp/Connection`, CIMD (Client ID Metadata Documents, #651)

> Split out of `mcp-oauth.md`'s territory by #651 (spec 0007, Task 12). The authorization server, the consent
> and the registered clients are in `.claude/rules/mcp-oauth.md`; the connection's lifetime in
> `mcp-oauth-cycle-de-vie.md`; the refusal audits of the token endpoint in `mcp-oauth-audits.md`. The standard:
> `draft-ietf-oauth-client-id-metadata-document-03` (named by the metadata's
> `client_id_metadata_document_supported: true`). The plan and its decisions (D-A to D-H) are archived with
> the spec once T12 closes.

A CIMD client has no row in `mcp_client` before its first approval: its `client_id` **is the https URL of a
JSON document** it publishes (`https://claude.ai/…`), and the document declares its name and its redirect
URIs. The server fetches it, **at the authorization point only**, and judges the request by it.

## Who judges what (D-A)

- **The authorization endpoint judges the document.** `AuthorizationRequestValidator::admitted()` asks
  `CimdClientAdmissionInterface::admit()`; the answer is a client built from the document, never from a row.
- **`/token`, the refresh and `/revoke` judge the row** written at the approval (`registration = 'cimd'`).
  They make **no outbound call**, ever: a token endpoint that fetched a URL on behalf of whoever posts to it
  would be an SSRF and a latency oracle. A document that is later removed or changed therefore stops
  nothing already granted (accepted, see the limits below).
- `ClientRegistration` (`preregistered` | `cimd`) is the column `mcp_client.registration` (default
  `preregistered`, migration `Version20261113120000`). `McpClient::discovered()` builds the in-memory client
  of a document; `McpClient::cimdHost()` also answers for a *pre-registered* row whose id has the shape of
  a CIMD URL, so **every caller filters on `registration()`** first.
- **A pre-registered row always wins** (D-C). `CimdClientAdmission::admit()`, in its real order:
  1. `CimdClientId::tryFrom()`: not a CIMD URL → `null`, league judges by the rows.
  2. A row exists in mode `preregistered` → `null`: league judges it, **nothing is fetched**.
  3. The host is not in the allowed list → `ClientMetadataRefusedException::because(DomainNotAllowed)`, **before
     any fetch and without audit**. Since the list is read before anything else, **removing a domain stops
     new authorizations of its clients at once, even those with a `cimd` row** (it stops nothing granted:
     accepted).
  4. `CimdClientResolverInterface::resolve()`; a refusal other than `DomainNotAllowed` is audited
     (`mcp-client-metadata-refused`, through `FailSafeAudit`) then rethrown unchanged.
  5. Returns `McpClient::discovered($document, $clock->now())`.
- **The validator hands the discovered client to league through a request attribute** (D-B), PSR-7 only:
  `CimdClientAdmissionInterface::REQUEST_ATTRIBUTE` (`mcp.cimd.discovered_client`), read by
  `LoopbackAwareAuthCodeGrant::getClientEntityOrFail()`. The grant honours it **only if it is an
  `McpClient` whose `getClientId()` equals the requested `client_id`**, otherwise it falls back to the rows
  (silently, accepted). A second attribute, `AUTHORIZATION_POINT_ATTRIBUTE` (`mcp.authorization_point`), is
  set by the authorization point alone; it tells the code exchange (never guarded) from the authorization.
  **An attribute is not a query parameter**: `CimdAuthorizationTest::testAQueryParameterNamedLikeTheAdmissionAttributeChangesNothing`
  proves, end to end, that `?mcp.cimd.discovered_client=…` changes nothing. An `mcp.authorization_point`
  attribute set by mistake at the token endpoint would make every `cimd` exchange be refused.
- **The approval writes the row** (D-C): `McpClientRepository::saveDiscovered()` is
  `INSERT … ON CONFLICT (client_id) DO UPDATE SET name, redirect_uris WHERE mcp_client.registration = :registration
  RETURNING id`; it returns `null` for a pre-registered row (**never overwritten**), in the **same transaction
  as the connection's `add()`**, lock order `mcp_client` then `mcp_connection`. A second approval refreshes
  the row instead of duplicating it. The `cimd` row is shared by every account that approves that client:
  each approval rewrites its name and redirect URIs (accepted, #651).
- **The grant audit always writes the public `client_id`** (D-H): the URL for a CIMD client.

## The allowed list (`app.mcp.cimd.allowed_domains`)

`backend/config/services.yaml`, versioned, **never `%env()%`**, one value for every environment
(`claude.ai`, `chatgpt.com`). `AllowedClientDomains::fromList()` refuses a malformed entry
(`CimdClientId::HOST_PATTERN`: lowercase DNS name, two labels at least, no scheme, port or path); matching is
**exact equality, never an implicit subdomain**; an **empty list switches CIMD off**.

**The verified domain shown on the consent page is the host alone**, and that is safe only because the list
names hosts **whose every document belongs to one publisher**. **Never add a host shared between several
accounts** (a user-content host, a code-hosting or a storage domain): anyone could then publish a document
under the trusted name. This is the rule that makes the consent's « verified domain » true; a doubt means
the host stays out.

`AllowedClientDomains`, the fetcher client, the fetcher and the resolver port are `public: true` in
`services.yaml` for `CimdWiringTest`; never make them public under `when@test` only
(`SecurityServicesEnvironmentParityTest`, `.claude/rules/backoffice-api.md`).
`app.mcp.cimd.transport` and `app.mcp.cimd.http_client` carry **`autoconfigure: false`**. Only the
transport is `LoggerAwareInterface` (`CurlHttpClient`; `NoPrivateNetworkHttpClient` implements `HttpClientInterface`
and `ResetInterface` alone, read in vendor): autoconfiguration would call `setLogger(@logger)` on it, after which it
logs `Request: "GET <full URL>"` at `info` — the anonymous caller's `client_id`, where only the host may be logged.
On the decorator it is a precaution, should a vendor release make it `LoggerAware`. `kernel.reset` is set back by
hand on both (the decorator's `reset()` also forwards to the transport, reset twice — harmless); `CimdWiringTest::testTheMetadataTransportHasNoLogger` reads the compiled
object. A new `LoggerAwareInterface` service on the outbound path needs the same.

## Five layers against SSRF, and a resolution of our own (D-D)

The `client_id` is attacker-chosen text that makes the server open a connection. In order:

1. **Shape** — `CimdClientId`: `https` exactly, host a lowercase DNS name of two labels or more, no port, no
   userinfo, a non-empty path with no empty, `.` or `..` segment; `McpClientId`'s shape first; **no
   normalization of any kind** (what is judged is what is fetched and what is stored). A `client_id` with a
   port, or an IPv6 literal host, is refused. Then **the list** (above).
2. **Resolution is ours**: `HostAddressResolverInterface`, rooted name (`host.`), **A records only**
   (`NativeHostAddressResolver` = `dns_get_record($host.'.', DNS_A)`, its warnings neutralised by
   `set_error_handler`). No resolution is left to curl.
3. **`PublicAddressPolicy`**: **every** address must be public; `SUBNETS` = Symfony's
   `IpUtils::PRIVATE_SUBNETS` + multicast + the special ranges it leaves out. An IPv4 carried by an IPv6
   address is refused **whatever that IPv4 is**, by range, without decoding it: mapped `::ffff:0:0/96`, compatible
   `::/96`, NAT64 `64:ff9b::/96` and `64:ff9b:1::/48`, 6to4 `2002::/16`, Teredo `2001::/32` (inside the refused
   IETF block `2001::/23`). A string that is not an IP address (a name, `0x7f.1`, a trailing blank) is not public.
4. **Pinning**: the verified address goes to curl through `CURLOPT_RESOLVE`, so the connection goes where
   the check looked (no second resolution to rebind).
5. **A net, not a second judge**: `NoPrivateNetworkHttpClient` (service `app.mcp.cimd.http_client`) over
   its **own transport** `app.mcp.cimd.transport` (a `CurlHttpClient`: a shared handle would share its DNS
   cache and connections), with `PublicAddressPolicy::SUBNETS`. Whatever it blocks reads **`unreachable`**.

A refusal of any layer is a `ClientMetadataRefusal` (D-G, below). The fetcher also checks the list (**three
places read it**: the admission, the resolver, the fetcher: accepted, each is cheap and each is the guard of
its own entry point).

## Bounded reading, and what is read (D-E)

`HttpClientMetadataFetcher`: **no redirect** (`max_redirects: 0`, a 3xx = `redirected`), **no proxy**
(`no_proxy: '*'`), `Accept-Encoding: identity` (a compressed answer is refused as `unexpected-content-type`),
`Content-Type` must be `application/json`, `TIMEOUT` 2.0 s, `MAX_DURATION` 3.0 s, `MAX_CONNECT_DURATION`
1.0 s, `MAX_BYTES` **5120, counted on the stream** (`stream()`, `cancel()`; a `Content-Length` above is refused
at once, the body never read), `User-Agent: cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)`.
Statuses: 200 ok, 3xx `redirected`, 429 and 5xx `upstream-error`, anything else `unexpected-status`. **Any
`TransportExceptionInterface` is `unreachable`, its message never read nor chained** (it carries the URL): the
refusal's `previous` is `ClientMetadataTransportFailedException`, a constant message naming the transport
exception's class alone (`TimeoutException`…), with no `previous` of its own. The curl code is lost, Symfony
exposing it only in the message.
`max-age` is read for the cache (an integer of 1 to 9 digits; `s-maxage` is not read).

`ClientMetadataDocument::fromJson()` (depth 8; other fields ignored) requires: `client_id` equal **to the
character** to the URL fetched; **neither `client_secret` nor `client_secret_expires_at`**;
`token_endpoint_auth_method` **declared and equal to `none`**; `grant_types` and `response_types`, if
declared, containing `authorization_code` / `code`; an admissible name (`McpClientName`); **1 to 10
redirect URIs** (`MAX_REDIRECT_URIS`), each a valid `RedirectUri`. One bad redirect refuses the whole document.
**Outside loopback, a redirect URI's host is the `client_id`'s host, by exact equality** (no implicit subdomain,
no path prefix; `RedirectUri::host()` against `CimdClientId::host()`), **with no explicit port** (`RedirectUri::port()`:
`CimdClientId` refuses any port so that the target stays 443, a `:8443` would send the code to another service of
the host; an explicit `:443` is already refused as not normalised), else
`InvalidClientMetadataDocumentException::redirectOutsideClientHost()` → `invalid-document`. That factory exists for
the tests only: its message reaches no log, the audit carrying the host and the cause alone. Loopback is `http` only
(`isLoopback()`): an `https` redirect to `127.0.0.1` or `localhost` is judged like any other, hence refused (not
pinned by a test) (#651, security review): the allowed list judges the `client_id`'s host alone, so a path of a
listed host serving a third party's JSON would otherwise send the code elsewhere under « verified domain ».
A loopback redirect (Claude Code) stays admitted. Never relax this to a subdomain or a second host without a
real document that needs it.

## Cache and quota (D-F)

- **Cache**: pool `cache.mcp_client_metadata` (`cache.adapter.doctrine_dbal`, table `cache_items`, authenticated
  values, `.claude/rules/backend-integrite.md`), key `ClientIdFingerprint::of()` (SHA-256 hex of the
  `client_id`; a frozen value, not a secret). **The value is the raw body, revalidated on every read**: never a
  deserialized object. **No error and no invalid document is ever cached** (draft §6.2). TTL = `max-age`
  clamped to `MIN_TTL` 300 s / `MAX_TTL` 3600 s; `no-store`, `no-cache` and `max-age=0` read as absent.
  `CimdClientResolver::resolve()` reads **the list, then the store, then the quota, then fetches, validates
  (`InvalidDocument`), saves**: the list before the store, otherwise a removed domain would stay admitted
  until `MAX_TTL`.
- **Quota** (`rate_limiter.yaml`, `SymfonyClientMetadataFetchQuota`), **consumed on a cache miss only, never
  for a host outside the list**:
  - `mcp_client_metadata`: sliding window 10 per 10 min **per IP** (`FetchQuotaKey`: an IPv6 by its /64, an
    IPv4 carried by an IPv6 counted as that IPv4, `unknown` when unreadable);
  - `mcp_client_metadata_global`: 100 per 10 min, constant key `global`, **looked at** (`consume(0)`) before
    the caller's quota and **consumed after**;
  - `mcp_client_metadata_known_client`: 10 per 10 min, key = the fingerprint; the lane of clients already
    seen (`McpClientRepositoryInterface::isDiscovered()`, `consumeForKnownClient()`); **it never refuses on
    its own**: when full it falls back to the global ceiling.

## Refusals and their traces (D-G)

- **Definitive** (the document is wrong): `invalid_client`, 401, no redirect, like an unknown client.
  **Transient**: `temporarily_unavailable`, **429** for the quota, **503** for the host or the network, no
  redirect either. `OAuthServerException` carries the `ClientMetadataRefusedException` as `previous` (the
  controller reads `Retry-After` there); **the cause is never rendered to the client**.
- `ClientMetadataRefusal` (the causes): `domain-not-allowed`, `throttled`, `unresolvable`, `address-blocked`,
  `unreachable`, `redirected`, `unexpected-status`, `upstream-error`, `unexpected-content-type`, `too-large`,
  `invalid-document`. **Transient** (`isTransient()`): `throttled`, `unresolvable`, `unreachable`,
  `upstream-error`.
- **Audit** `mcp-client-metadata-refused` (`SecurityAuditLogger::mcpClientMetadataRefused($clientHost,
  $cause)`, `.claude/rules/security-authentication-audit.md`): **the host only**, never the URL, the
  `client_id` nor the body; `domain-not-allowed` is **never audited** (the host of an unlisted `client_id` is chosen
  freely by an anonymous caller: writing it would hand anyone a pen on our log lines); the method trusts its caller, which audits a host of the list only. Written through
  `FailSafeAudit`: an audit failure never replaces the refusal (before it, a failing audit made a 500).
- The consent page has no audit of its own for a transient refusal: the trace is at its source.

## The consent page, for a `cimd` client

`GET /api/oauth/consent` returns `registration` and `domain` (`verifiedDomain()`, the host alone). The page
shows the verified domain (`oauthConsent.client.domainLabel` + `domainNotice`), the **address of the document**
(`consent-client-document`), the **declared** name (`consent-client-name`, `declaredNameNotice`: « declared by
the client, not verified ») and `registration.cimd`. All of it interpolated as text, **never `v-html`**.
The 429/503 of the revalidation is in `.claude/rules/mcp-oauth.md`, « The consent page ».

## Tests: no network, no DNS

**No test reaches the network or a name server.** The resolver port is replaced by
`tests/Mcp/Connection/Support/FixedHostAddressResolver` (a host → addresses table, keeps the hosts asked), the
HTTP client by a `MockHttpClient` wrapped in the real `NoPrivateNetworkHttpClient`
(`CimdAuthorizationTest::serve()`), documents by `tests/Mcp/Connection/Fixtures/ClaudeCodeMetadata`.
`CimdWiringTest` pins the wiring (own transport, always curl, `NativeHostAddressResolver` behind the port).
**What the doubles do not model**: `MockResponse` has no real `cancel()`, no curl `timeout` / `max_duration`
and no `CURLOPT_RESOLVE`; so the byte count, the pinning and the timeouts are tested as *our* arguments and
reasoning, not as curl's behaviour. **`NativeHostAddressResolver` has no test**: it is proved on preprod in T14.

## Accepted limits (#651)

- A document removed or changed stops no connection already granted; removing a domain stops new
  authorizations only (the list is read first, above).
- The quota is per address (an IPv6 by its /64); the other limiters count the whole IPv6 (#775).
- **The global ceiling can be exhausted** by ten sources, and then refuses the first registration of an
  unknown client; the known-client counter can be exhausted but never refuses alone (a per-client lock was
  weighed and dropped).
- Races between the look (`consume(0)`) and the consumption, for the known-client counter and the ceiling.
- The `global` key is a hot spot (about 3 ms per fetch); a caller refused by its own quota still touches it.
- No negative cache; no single request per cache miss (two callers may fetch at once);
  `no-store` / `no-cache` / `max-age=0` read as absent (floor 300 s).
- The **quota key** reads an embedded IPv4 through `Shared/Infrastructure/Network/EmbeddedIpv4` (mapped,
  `64:ff9b::/96`, 6to4, compatible): it recognises neither `64:ff9b:1::/48`, nor a network's own NAT64 prefix,
  nor Teredo (those callers are counted by their /64). The address *policy* above refuses all of them.
- A transport block reads `unreachable`, not `address-blocked`.
- The native resolver has **no delay of its own**: `dns_get_record` follows `resolv.conf`, outside the 3 s
  (a `dnsConfig` decision is T14's).
- A document without `token_endpoint_auth_method` is refused; an IPv6-only host and a `client_id` with a port
  are refused; the name shown in « Mes connexions MCP » is the one of the last approval.
- An anonymous caller triggers a fetch without sending a PKCE challenge: checking `code_challenge` before the
  admission would be free (to propose again).
- A timing oracle (F7): a cached client answers at once, a cache miss costs a fetch of up to 3 s, so an anonymous
  caller can measure whether a `client_id` was seen recently. Accepted: the identifier is public and confined to the
  allowed list, and the cache is what bounds the outbound calls.
- The `Retry-After` of a 429 (I1) is the one of the limiter that refused: it may tell the shared ceiling
  (`mcp_client_metadata_global`) is saturated rather than the caller's own quota. Accepted: it reveals a load, never a
  person, and a precise delay is what makes the 429 retryable.
- A definitive refusal answers `invalid_client` (401), an unreachable or failing host 503 (I2): the caller learns
  whether the server reached the host. Accepted: the host is in the closed list, and merging both would hide a
  transient outage behind a definitive refusal.
- The transport exception is never chained under the refusal (I4, closed by #651's security review): its message
  carries the document's URL, which an anonymous caller chose, and the kernel logs the consent's 503
  (`ConsentRevalidationUnavailableException`) at `warning` with its whole chain. `ClientMetadataTransportFailedException`
  (constant message, the class only) stands in for it; the audit record never carried it (`clientHost` only).
