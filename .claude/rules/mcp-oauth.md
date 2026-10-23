---
paths:
  - "backend/src/Mcp/Connection/**"
  - "backend/tests/Mcp/Connection/**"
  - "backend/config/packages/security.yaml"
  - "k8s/base/ingress-oauth-metadata.yaml"
  - "frontend/src/presentation/pages/OAuthConsentPage.vue"
  - "frontend/src/application/mcp/useOAuthConsent.ts"
  - "frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts"
  - "frontend/src/domain/mcp/**/*Consent*"
  - "frontend/src/domain/mcp/services/consentParams.ts"
  - "frontend/tests/**/*OAuthConsent*"
  - "frontend/tests/domain/mcp/services/consentParams.spec.ts"
---

# Backend — `Mcp/Connection` (the OAuth 2.1 authorization server, phase 2)

> Decisions: `docs/adr/0006-serveur-mcp.md`, D3 (amended 2026-10-22 and 2026-10-23) and D4; spec
> `.claude/specs/0007-mcp-server.md`, « Connexion MCP et serveur d'autorisation (phase 2) » (issue #647).
> Split out of `.claude/rules/mcp.md` from the start (its 31 KB plus this would have neared
> `tools/check-claude-rules.sh`'s 50 000 bytes, and #648 to #652 add to it). Vocabulary:
> `CONTEXT.md`, « Accès MCP » (**connexion MCP**, **client MCP**). The index of every rule is at the top
> of `.claude/CLAUDE.md`.

- **What it is, and why without the bundle.** `league/oauth2-server` **`9.4.1`**, pinned exact (like the
  MCP packages, ADR 0006 D7), **never `league/oauth2-server-bundle`** (ADR 0006 D3, amended 2026-10-23):
  its RSA key pair, session-based authorize controller, clear-text Doctrine persistence and authenticator
  all went against this server. `Infrastructure/League/AuthorizationServerFactory` builds
  `League\OAuth2\Server\AuthorizationServer`, declared in `config/services.yaml` by that factory (a test
  alias, `test.oauth.authorization_server`, reads the very definition). **Order matters**: the default
  scope and `revokeRefreshTokens(true)` are **copied** into each grant by `enableGrantType()`, so they are
  set first. Durations live there only: code `PT10M`, access token `PT1H`, refresh token `P30D` (rotation,
  reuse and expiry of the connection are #649's). Layers: `Domain/` (entities `McpClient`,
  `McpConnection`, `McpAuthorizationCode`, `McpAccessToken`, `McpRefreshToken`, the value object
  `RedirectUri`, `TokenHashShape`), `Application/` (validator, consent, token endpoint, URLs, the pure
  helpers `QueryParameterPollution`, `FormEncodedRequest`, `IssParameter`, `InvalidTarget`),
  `Infrastructure/League/` (league's repositories and entities, the grant, the key), `Infrastructure/Doctrine/`,
  `Presentation/` (thin controllers, `app:mcp:client:register`). Every exception carries the `…Exception`
  suffix, like all of `src/`.
- **League's « user » is the MCP connection, never the account** (`ConnectionAsUser`, its RFC 4122 UUID):
  every code and token hangs on a connection and dies with it; the account appears in no payload handed to
  the client. `ConnectionOfToken` (written once for the three repositories) refuses, as a named server
  fault, an unknown connection, one of another client, a revoked one (`LeagueRepositoriesTest`).
- **Opaque access tokens, every secret stored as its SHA-256 only.** `OpaqueAccessToken`: the string the
  client gets **is** league's 80-hex identifier, nothing signed, nothing readable — `/api/mcp`'s
  authenticator (#648) will find the row by its hash and re-read the connection in one query
  (ADR 0006 D4). `NoSigningKey` is the « private key » league demands at construction: league 9.4.1 only
  passes it on, and every read throws (`AccessTokensAreNotSignedException`, `NoSigningKeyTest`) — never an
  empty or fallback key. Codes, access and refresh tokens are stored as `TokenIdentifierHash::of()`
  (`hash('sha256', …)`, written once for the three repositories: a hash computed otherwise at read time
  would never find its token), `CHAR(64)` unique columns whose shape `TokenHashShape` enforces
  (`MalformedTokenHashException`). **No token, code nor token identifier in clear** in the database, a log
  or the audit log: `LeagueRepositoriesTest` (stored as the hash only), `OAuthFlowTest::testNoTokenIsStoredInClear`
  (the whole flow, every table read), `McpTokenEntitiesTest`. Timestamps `datetimetz_immutable`, keys
  `Uuid::v7()`. `mcp_connection.mcp_client_id` (never `client_id`, which is the client's public OAuth id in
  `mcp_client`) cascades on delete — **accepted**: removing a client (backoffice, #652) cuts its
  connections; the grant stays in the audit log (`McpConnectionRepositoryTest`).
- **The encryption key of codes and refresh tokens** (league's `CryptTrait`, defuse) is
  `OAuthEncryptionKey`, derived from `APP_SECRET` under `cp-ghostotof/oauth-encryption/v1`
  (`.claude/rules/backend-integrite.md`), handed to league as a defuse `Key` (a string would be read as a
  password, a PBKDF2 per encryption), derived at each `key()`, never at construction (#474).
  `RefreshTokenPayloadReader` decrypts with league's own code, the key set only for the read. **Rotating
  `APP_SECRET` or the context makes every code in flight and every refresh token handed out unreadable**:
  each MCP client reconnects (`k8s/README.md`, « Cas d'`APP_SECRET` »).
- **PKCE `S256` is decided in one place, `LoopbackAwareAuthCodeGrant::validateAuthorizationRequest()`**:
  league admits `plain` and reads a missing method as `plain`; a request without `code_challenge`, or whose
  `code_challenge_method` is not exactly `S256`, is an `invalid_request` redirected to the client (with
  `state`) — but only after the client and its redirect were validated in league's order, never towards an
  unvalidated URI (`AuthorizationServerTest`, `AuthorizeControllerTest::testARequestWithoutS256PkceIsRefusedBackToTheClient`).
  Never re-check it elsewhere. At the token endpoint, a missing `code_verifier` is league's
  `invalid_request` (OAuth 2.1), a wrong one `invalid_grant` (`TokenControllerTest`).
- **Redirect matching is owned by `Domain/ValueObject/RedirectUri`, through `McpClient::allowsRedirect()`**;
  the grant's `validateRedirectUri()` delegates and never rewrites it. `https`: strict string equality;
  `http`: loopback only (`localhost`, `127.0.0.1`, `[::1]`), compared **without the port** (RFC 8252 §7.3:
  a native client opens an ephemeral port); a requested URI not written in League\Uri's normal form (but
  for ASCII case of scheme and host), with a fragment, user info, or a port outside 1–65535 matches
  nothing. A registered one must be normalised, carry no fragment nor `iss` (the server appends its own).
  Refused → league's `invalid_client`, **never a redirect**. The validator returns what league
  **retained** (trimmed), never the raw value. `RedirectUriTest`, `McpClientTest`, `AuthorizationServerTest`.
- **The authorization request is judged in three steps** (`Application/AuthorizationRequestValidator`):
  before league, without redirect (400 JSON) — a repeated or PHP-rewritten parameter
  (`QueryParameterPollution`, below), an expected parameter that is not a string, a blank `client_id` or
  `redirect_uri` (league would fall back to `PHP_AUTH_USER`, or to the single registered redirect without
  its port), a `state` over 1024 bytes (`STATE_MAX_LENGTH`, never echoed); then league (client, redirect,
  PKCE, scope); then **`resource` (RFC 8707), required and equal to `<public base>/api/mcp`**, else
  `invalid_target` sent back on the validated redirect with `state`. At the token endpoint `resource` may
  be omitted but, present, must be the same (`invalid_target`, JSON); every access token carries that
  audience whatever was asked (`AccessTokenRepository`). `AuthorizeControllerTest`, `TokenControllerTest`.
- **Parameter pollution is refused, never corrected** (RFC 6749 §3.1, §3.2): PHP keeps the **last**
  occurrence, `URLSearchParams.get()` the **first**, and PHP rewrites `client.id`, `client id`, `client[id`
  into `client_id`. `QueryParameterPollution::refuse()` reads the raw query **or the raw form body** and
  refuses a watched name that is repeated or reached only through a rewrite — at the authorize endpoint and,
  beyond the ticket, at the token and revocation endpoints (`QueryParameterPollutionTest`, the controller
  tests' `testARepeated…`). **The redirect to the consent page is rebuilt from the validated parameters
  only** (`AuthorizationRequestSummary::consentQuery()`, fixed order), never the received `QUERY_STRING`.
- **Token and revocation take `application/x-www-form-urlencoded` only** (`FormEncodedRequest`): the PSR-7
  bridge also parses a JSON body into parameters, which league then served while the pollution check, reading
  the raw body as a form, saw nothing. Another media type is an `invalid_request`; league gets the
  parameters `parse_str()` drew from **that** raw body (`php://input`), never `$_POST` — so
  `enable_post_data_reading` is irrelevant to them (`.claude/rules/images-docker.md`). The revocation
  authenticates the client (`client_id`, public, known) **before** reading the token; a token unknown,
  unreadable or of another client is a 200 without effect (RFC 7009 §2.2); a refresh token takes its
  access token along, in one transaction; the connection is **not** revoked (#649, #650).
  `token_type_hint` is ignored. **Accepted**: the response time differs with the token's fate (one query,
  one decryption), status and body do not. **Known, #649's**: league's races (tokens issued before the code
  is revoked, a refresh token revoked after being checked); a code exchanged twice in sequence is refused.
- **OAuth answers are league's (RFC 6749 §5.2), rendered by the controller, never thrown**:
  `ApiProblemResponseListener` / `ApiJsonErrorFormatListener` would rewrite them as problem+json, which
  OAuth clients do not read. **What the controllers render is `Cache-Control: no-store`**: the authorize
  redirects and refusals, the token and revocation answers, league's errors, the consent's 200s (the summary
  carries `state` and the PKCE challenge, the decision the code). The consent's 400/401/403/422
  problem+json come from the shared listeners, the firewall and the CSRF guard, without it — accepted: they
  carry no `state`, challenge nor code (a constant message, or the names of the refused fields). The PSR-7 bridge is the MCP
  bundle's (`mcp.psr_http_factory`, `mcp.http_foundation_factory`, `mcp.psr17_factory`, by interface).
  The consent's refusal of a revalidated request is its 400 (`AuthorizationRequestRejectedException`,
  `log_level: info`).
- **`iss` (RFC 9207) on every redirect to a client**, refusals included, written once (`IssParameter`):
  the authorize endpoint's redirected refusals and both consent outcomes. The metadata announce it
  (`authorization_response_iss_parameter_supported`), so a compliant client refuses a response without it.
- **Firewalls.** `oauth` (`^/api/oauth/(authorize|token|revoke)$`, stateless, **no authenticator, no
  cookie read**, before `api`): a `BEARER` changes nothing (`AuthorizeControllerTest::testABearerCookieGetsExactlyTheAnonymousAnswer`;
  token and revoke `testABearerCookieWithoutTheXsrfHeaderIsNeitherRefusedNorRead`). That is what makes
  `/api/oauth/token` and `/api/oauth/revoke`'s exclusion from the CSRF guard safe
  (`.claude/rules/security-authentication.md`); exchanging a code needs its PKCE verifier, revoking a token
  needs the token. The three are in `ApiRouteExposureTest::PUBLIC_PATHS` and
  `McpExposureSymmetryTest::EXCLUDED_ROUTES` with their reasons; `AccessControlAnchoringTest` pins the
  bounds (a neighbour such as `/api/oauth/authorizex` falls back into `api`). No `%env()%` in
  `security.yaml`, nothing per environment (#659).
- **Consent: `GET|POST /api/oauth/consent`, `ROLE_TRUSTED`** (`access_control ^/api/oauth/consent(/|$)`,
  before the `ROLE_USER` rules; `ROLE_SUPER` through the hierarchy), under the `api` firewall (the person's
  cookie) and the CSRF guard for the POST, JSON only (`MapRequestPayload(acceptFormat: 'json')`). **One
  judge**: `ConsentService` revalidates the request through the same validator on both calls, keeping
  nothing in between; a refusal there is a 400 problem+json, never a redirect. The approval writes the
  connection and, through league, its code in one transaction (`TransactionInterface`), then the audit
  line `mcp-connection-granted` **after** the COMMIT (`ConsentServiceTest`); a denial creates and logs
  nothing and sends league's `access_denied`. The POST answers a 200 JSON `redirectUri`, not a 302
  (`fetch()` would follow it). `ConsentControllerTest` (base tier and non-trusted 403, anonymous 401 on the GET and
  the CSRF guard's 403 on the POST, CSRF 403 at the trusted tier, 422 without `approved`). **Accepted limits**: the POST revalidates the body without binding it
  to the GET that was shown — same-origin script could grant for another registered redirect of the same
  client (a signed GET→POST ticket would close it, not done); `ConsentService` itself checks no tier
  (`access_control` does, and #648's authenticator re-checks the tier on every request).
- **Metadata outside `/api`**, at the canonical URLs (RFC 9728 §3.1, RFC 8414 §3.1), built from
  `Application/OAuthEndpoints` — the single source of every public URL: issuer `<base>/api/oauth`,
  resource `<base>/api/mcp`, the three endpoints, both documents, the consent page (always on
  `app.frontend_base_url`). The base is `app.public_api_base_url` (`APP_PUBLIC_API_BASE_URL`, empty outside
  dev, falling back to `APP_FRONTEND_BASE_URL`: in preprod and prod the API is served on the frontend's
  origin; `docker-compose.override.yml` sets it in dev), trailing slash stripped — clients compare issuer and
  resource byte for byte (`OAuthEndpointsTest`). Each document has its own firewall
  (`oauth_resource_metadata`, `oauth_server_metadata`, `$`-anchored, no authenticator), its `PUBLIC_ACCESS`
  rule and its justified entry in `RouterScopeTest::NON_API_PATHS`; public JSON cached an hour
  (`MetadataDocumentResponseFactory`). What is announced is what the server does, nothing more
  (`OAuthServerMetadata`): `S256` only, `none` auth, `response_modes_supported: ["query"]` (omitted, RFC 8414
  defaults to query **and** fragment), no `registration_endpoint`, **no OIDC document**.
  **Two announcements ahead of the code, accepted** (phase 2 only ships whole): `client_id_metadata_document_supported: true`
  until CIMD (#651) lands, and `bearer_methods_supported: ["header"]` while `/api/mcp` accepts no token
  until its authenticator (#648) lands. `OAuthMetadataTest` pins both documents exactly, their routed paths, a POST 405, the
  `BEARER` changing nothing, no `openid-configuration`.
- **A dedicated Ingress carries the two documents**, `k8s/base/ingress-oauth-metadata.yaml`: two `Exact`
  paths to `backend:80` (an `Exact` beats the main Ingress's `/` prefix), **no annotation, no `tls`** —
  ingress-nginx applies `auth-*` annotations to every path of one object, and preprod's Basic Auth sits on
  the main one; a client cannot answer a Basic Auth. Each overlay patches the host only (the main Ingress's,
  whose certificate covers it). `/api/oauth/*` and `/api/mcp` stay behind preprod's Basic Auth until phase 2's delivery (#653).
  `OAuthMetadataIngressTest` pins paths against `OAuthEndpoints` and the router, no annotation nor
  certificate, and that no overlay patches anything else. **The frontend answers a real 404** under
  `/.well-known/` for those paths and the RFC roots and `openid-configuration` (never the SPA's 200,
  which stops a client's discovery): `tools/check-frontend-image-headers.sh`. `tools/audit-prod.sh` §6ter
  reads both documents **without** preprod's Basic Auth (`CURL_OPTS_ANONYMOUS`, the property to prove), the
  exact `issuer`/`resource`, and `openid-configuration`'s 404.
- **Rate**: the nginx `oauth` zone on `^~ /api/oauth/` (`.claude/rules/deploiement-nginx.md`), one counter
  per IP and per pod for authorize, consent, token and revoke together; the documents stay under `publicapi`.
- **Clients are registered by `app:mcp:client:register` only** (`McpClientRegistrar` behind its
  interface; no dynamic registration, no `registration_endpoint`; CIMD is #651, the backoffice #652):
  public clients, no secret, redirects judged by `RedirectUri` before saving, repeated ones kept once, a
  concurrent duplicate named (`McpClientAlreadyRegisteredException`). The client's name is **declared**,
  shown as such on the consent page.
- **The consent page** (`/<locale>/oauth/consent`, `requiresAuth`, `ROLE_TRUSTED`/`ROLE_SUPER`, `noindex`;
  an anonymous visitor goes through login, whose `redirect` keeps the query). `consentParamsFromQuery()`
  refuses a repeated or valueless parameter (no call); the page sends back what it received, computes
  nothing. It follows the returned URL with **`window.location.replace`**, never `assign` (the page must
  not stay in history: « Back » would offer to grant the same request again), and only an `http:`/`https:`
  URL whose **`hostname`** (not `host`: the backend's `redirectHost` has no port, a loopback redirect has an
  ephemeral one) is the host shown — defence in depth, the server already validated it. 401 **and 403**
  (a CSRF refusal included) read as an expired session (`markSessionExpired`, like `useAssistant`):
  accepted, a re-login gives fresh cookies. 422 is terminal (`malformed-decision`), a 400 whose `type` is the
  rejection is `invalid`. `OAuthConsentPage.spec.ts`, `useOAuthConsent.spec.ts`,
  `HttpOAuthConsentRepository.spec.ts`, `consentParams.spec.ts`.
- **The whole flow** is driven end to end by `tests/Mcp/Connection/OAuthFlowTest` (trait
  `DrivesTheOAuthFlow`: a registered client, authorize, consent as a trusted account, exchange, refresh,
  revoke, every table read for a token in clear).
