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
  - "frontend/src/domain/mcp/errors/ReauthenticationRequiredError.ts"
  - "frontend/src/presentation/ui/useDeliberateActivation.ts"
  - "frontend/src/presentation/router/reauthentication.ts"
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
  set first. Its durations (code, access token, refresh token) are read from `ConnectionLifetimes`, in
  configuration since #649: where they live, why without `%env()%`, the connection's expiry, rotation and
  reuse are in `.claude/rules/mcp-oauth-cycle-de-vie.md`. Layers: `Domain/` (entities `McpClient`,
  `McpConnection`, `McpAuthorizationCode`, `McpAccessToken`, `McpRefreshToken`, the value objects
  `RedirectUri`, `McpClientId`, `McpClientName`, `TokenHashShape`), `Application/` (validator, consent, token
  endpoint, URLs, the port `AuthenticationInstantInterface`, the pure helpers `QueryParameterPollution`,
  `FormEncodedRequest`, `IssParameter`, `InvalidTarget`), `Infrastructure/League/` (league's repositories and
  entities, the two grants, the key), `Infrastructure/Doctrine/`, `Infrastructure/Security/`
  (`JwtAuthenticationInstant`), `Presentation/` (thin controllers, `app:mcp:client:register`). Every exception
  carries the `…Exception` suffix, like all of `src/`.
- **An assumed exception to the context boundaries: `Mcp/Connection` depends on `Security` directly.** It is
  the first context outside `Security` whose entity references `CpgUser` (`McpConnection::$account`, a
  `ManyToOne` on `account_id`, `ON DELETE CASCADE`) and whose service depends on
  `SecurityAuditLoggerInterface` (`ConsentService`, the `mcp-connection-granted` line). Elsewhere a context
  designates an account through `Shared/Domain/Security/AccountReference` (its UUID, `Ai`'s quotas) and never
  imports `Security`. Here the spec requires the foreign key — removing the account must kill its connections in
  the database, not wait for a purge — and Doctrine maps it on the entity class only; and the grant belongs to
  the security audit log, whose single writer is that interface. Rejected: a bare UUID column with no foreign
  key (a deleted account would leave live connections until #648's authenticator stumbled on them), and a copy
  of the audit logger (a second writer of `security_audit`). A new dependency of this context on `Security`
  goes through the same two doors, nothing else. **`Mcp/Infrastructure` uses the second door too** (#648):
  `Corpus/NominativeCorpusReader`, behind `GetNominativeCorpusTool`, writes `nominative-corpus-read` through
  `SecurityAuditLoggerInterface`, for the same reason (the single writer of `security_audit`); it designates the account
  through the firewall's `McpConnectionPrincipal`, an `AccountReference`, never a `CpgUser`. The trusted tier's role is
  no third door either: `McpConnectionPrincipal::TRUSTED_TIER`, a copy that `McpConnectionPrincipalTest` compares with
  `CpgUser::ROLE_TRUSTED`. The consent's recency rule (below) is no third
  one: it reads
  the token Symfony's firewall authenticated and Lexik's manager (vendor), never a class of `App\Security`.
- **League's « user » is the MCP connection, never the account** (`ConnectionAsUser`, its RFC 4122 UUID):
  every code and token hangs on a connection and dies with it; the account appears in no payload handed to
  the client. The `mcp` firewall's user is the connection too (`McpConnectionUser`, below). `ConnectionOfToken`
  (written once for the three repositories) refuses, as a named server
  fault, an unknown connection, one of another client, a revoked one (`LeagueRepositoriesTest`).
- **Opaque access tokens, every secret stored as its SHA-256 only.** `OpaqueAccessToken`: the string the
  client gets **is** league's 80-hex identifier, nothing signed, nothing readable — `/api/mcp`'s
  authenticator (#648) finds the row by its hash and re-reads the connection in one query
  (ADR 0006 D4, below). `NoSigningKey` is the « private key » league demands at construction: league 9.4.1 only
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
  `GrantPayloadReader` decrypts with league's own code, the key set on it only for the read.
  **Accepted limit, never promised away**: once handed over, the derived key lives in league's own private
  properties — `AuthorizationServer`, both grants, the response type, through `CryptTrait` — for the
  service's whole life, which a `dump()` of those objects would show; nothing of the project dumps them
  (`.claude/rules/backend-integrite.md`, « `APP_SECRET` never keys an HMAC directly »). No docblock claims the
  key never sits in a property. **Rotating
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
  be omitted but, present, must be the same (`invalid_target`, JSON) — **the client authenticated first**, as
  at the revocation: a foreign `resource` from an unknown client, or with no `client_id` in the body (a public
  client's `none` method), is `invalid_client`, never an `invalid_target` judging an unauthenticated request; an
  absent or own `resource` leaves league to authenticate the client. **Since #725 the token endpoint requires the
  `client_id` in the body whatever the `resource`** (`TokenEndpoint::refuseClientOutsideTheBody()`, `invalid_client`
  before league): league also read it from HTTP Basic, where the audit of a refused grant did not see who presented
  it (`.claude/rules/mcp-oauth-cycle-de-vie.md`, « Every refusal of an authentic grant is audited »). A client
  identifier holding a `:` never passed in Basic anyway — league splits the user at the first colon. Every access token carries that audience whatever was asked
  (`AccessTokenRepository`). **The scope summarised, sent back to the page and stored on the connection is the
  set granted**, never the raw repetition (`scope=nominative:read%20nominative:read` gives `nominative:read`,
  `ConsentControllerTest::testARepeatedScopeIsSummarisedAndStoredOnce`). `AuthorizeControllerTest`,
  `TokenControllerTest`.
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
  `enable_post_data_reading` is irrelevant to them (`.claude/rules/images-docker.md`); `FormEncodedRequestTest`
  hands it a parsed body that differs from the raw one (another client, one more parameter, empty, absent) and
  wants the raw one's (red without the swap, measured). **A read value starting or ending with a blank is an
  `invalid_request`** (#725, refused, never corrected): league trims every parameter (`AbstractGrant::parseParam()`)
  before reading it, any other reader of the same body — the audit of a refusal — would read it untrimmed
  (`FormEncodedRequestTest`, the blanks of `trim()` one by one). **A `client_secret` from a public client is ignored, not
  refused**: league 9.4.1 consults `ClientRepository::validateClient()` only for a confidential client
  (`AbstractGrant::validateClient()`), so a superfluous secret, in the body or in HTTP Basic, changes nothing,
  and its repetition is not watched (`TokenControllerTest::testASuperfluousClientSecretIsIgnored`: a league bump
  that starts refusing it turns it red). `validateClient()` stays written, the interface demands it, and refuses
  a secret for whoever calls it some day (`LeagueRepositoriesTest`). The revocation
  authenticates the client (`client_id`, public, known) **before** reading the token; a token unknown,
  unreadable or of another client is a 200 without effect (RFC 7009 §2.2). **Revoking a refresh token kills its
  access token** too, in one transaction (§2.1's SHOULD); **revoking an access token leaves its refresh token
  alive** (§2.1 only says MAY): a client handing back its access token alone can still refresh
  (`RevocationControllerTest::testRevokingAnAccessTokenRevokesItsRowOnly`). **Revoking a refresh
  token revokes its connection** too, cause `client` (#649): `.claude/rules/mcp-oauth-cycle-de-vie.md`, « Revoking a
  refresh token at the revocation endpoint revokes its connection »; revoking a connection from the site is #650.
  `token_type_hint` is ignored. **Accepted**: the response time differs with the token's fate (one query,
  one decryption), status and body do not. **The token endpoint is atomic** (`TokenEndpoint`): league runs
  inside `TransactionInterface` — it writes in several steps (the exchange: access token, refresh token, then
  the code's revocation; the refresh: the old access and refresh tokens revoked **before** the new ones are
  issued), so a failure midway rolls everything back: no orphan access token beside a code still
  exchangeable, no client deprived of its old refresh token without a new one
  (`TokenControllerTest::testAFailedExchange…`, `testAFailedRefresh…`, a database failure forced by a trigger
  refusing `mcp_refresh_token` inserts, red without the transaction, measured). `DoctrineTransaction`'s
  `wrapInTransaction()` closes the EntityManager on any exception, an ordinary `invalid_grant` included:
  harmless, the controller only renders afterwards. **A code presented as `refresh_token` is an
  `invalid_grant`** (`PayloadCheckedRefreshTokenGrant`): codes and refresh tokens share league's key, and a
  code's payload (no `refresh_token_id`) reached league's read as a `TypeError`, a 500 at will; the payload's
  exact shape is checked before league's validation, which stays whole behind (an undecryptable one is left
  to league's own refusal; a valid one is decrypted twice, accepted). The reverse league refuses itself
  (`auth_code_id` absent). **League's races are closed under the connection's row lock** (#649): a refresh is
  judged `FOR UPDATE` on its connection, inside the token endpoint's transaction, and a rotated refresh token
  presented again revokes the connection — the lock, the order of judgement and the two clocks are in
  `.claude/rules/mcp-oauth-cycle-de-vie.md`, « The refresh is judged under the lock of its connection ». A code
  exchanged twice is judged the same way, and its replay revokes the connection too — « A replayed code is judged
  under the same lock », in the same rule.
- **OAuth answers are league's (RFC 6749 §5.2), rendered by the controller, never thrown**:
  `ApiProblemResponseListener` / `ApiJsonErrorFormatListener` would rewrite them as problem+json, which
  OAuth clients do not read. **What the controllers render is `Cache-Control: no-store`**: the authorize
  redirects and refusals, the token and revocation answers, league's errors, the consent's 200s (the summary
  carries `state` and the PKCE challenge, the decision the code). The consent's 400/401/403/422
  problem+json come from the shared listeners, the firewall and the CSRF guard, without it — accepted: they
  carry no `state`, challenge nor code (a constant message, or the names of the refused fields). The PSR-7 bridge is the MCP
  bundle's (`mcp.psr_http_factory`, `mcp.http_foundation_factory`, `mcp.psr17_factory`, by interface).
  The consent's refusal of a revalidated request is its 400 (`AuthorizationRequestRejectedException`,
  `log_level: info`). **The authorize endpoint's refusals carry no `WWW-Authenticate`**: league sets
  `Basic realm="OAuth"` on an `invalid_client` whenever the request has an `Authorization` header (RFC 6749
  §5.2, meant for the token endpoint), and a browser behind preprod's Basic Auth sends one on every request —
  it would open a credentials prompt on the authorize endpoint
  (`AuthorizeControllerTest::testARefusalNeverAsksTheBrowserForCredentials`). The token endpoint keeps it.
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
- **The authenticator of `/api/mcp`** (#648, ADR 0006 D4): `Infrastructure/Security/McpBearerAuthenticator`, the
  `mcp` firewall's only one (`custom_authenticators`, provider `mcp_connections` = `McpConnectionUserProvider`, which
  loads and refreshes nothing — the badge carries its loader, the firewall is stateless — and never names the
  identifier it was asked). **Only `Authorization` is read, scheme `Bearer`** case-insensitive (RFC 7235), followed by
  a space or alone; never a cookie. **Any other scheme leaves the request anonymous** — preprod's Ingress Basic Auth
  sends `Authorization: Basic …` on every request, never a 401 for it
  (`testABasicAuthorizationHeaderLeavesTheRequestAnonymous`); so does a scheme glued to the token or followed by a
  tab (`Bearer<token>`, `Bearer\t<token>`, `Bearerxyz`: `testAnotherSchemeLeavesTheRequestAnonymous`, a valid token
  then not read). **Several `Authorization` headers**: Symfony reads the first; nginx may join them into one,
  comma-separated, before PHP-FPM — `Bearer <token>, Basic …` is then refused `Malformed` (a 401), `Basic …, Bearer
  <token>` left anonymous, depending on the order. Accepted: the first header decides, never the second.
  The judgement, first cause wins (`Domain/ValueObject/BearerRefusal`): the shape `^[0-9a-f]{80}\z` **before any
  query** (`Malformed`: `Bearer` alone, a second space, 79/81 characters, upper case, 10 KiB — zero SQL, measured with
  a positive witness); then `TokenIdentifierHash::of()` and **one query** joining token, connection, account and client
  (`findOneByHash()`, `Unknown`); then `McpAccessToken::refusalAt(now, OAuthEndpoints::resource())` — token revoked,
  expired (league never checks an opaque token's expiry), foreign audience byte for byte, connection revoked,
  expired, without `nominative:read` in its stored scope (`InsufficientScope`, word for word, final review of
  #648; **accepted**, security review of #648: it answers the common 401 `invalid_token`, not RFC 6750 §3.1's 403
  `insufficient_scope`, so a client would refresh into the same scope — unreachable while league grants
  `nominative:read` only; to decide before any second scope) —; then `ROLE_TRUSTED` reached through
  `RoleHierarchyInterface` from the **stored** roles (`AccountNotTrusted`: a `ROLE_SUPER` without stored
  `ROLE_TRUSTED` passes, the base tier does not), judged by `Infrastructure/Security/TrustedTier::reaches()`, the
  one judge of the tier, shared with the refresh (#649, `.claude/rules/mcp-oauth-cycle-de-vie.md`). The role name
  is `McpConnectionPrincipal::TRUSTED_TIER`, not `CpgUser::ROLE_TRUSTED`, so the context's dependency on `Security`
  keeps its two doors (`McpConnectionPrincipalTest` compares the two). **No write per call** (no `last_used_at`, #649 writes it at refresh; `testNoRequestWritesOutsideTheMcpSessions`), and
  exactly **one query more** than the same anonymous call (`testAnAuthenticatedCallCostsExactlyOneQueryMore`). Nothing
  loads the account before that query: a join does not refresh an instance the EntityManager already manages.
  **A presented and refused token is a 401 on every request** of `/api/mcp` (the author's decision of 2026-10-24):
  `initialize`, a public or the nominative tool, either era, `openai/` marker or not (RFC 6750 §3.1: it makes the
  client refresh) — **save what is answered before the firewall** (priority 8): the refusals before the SDK
  (`InboundMessageRefusalListener`, 15, a 400) and the router's 404/405 (`RouterListener`, 32) keep their own answer.
  Empty body, `WWW-Authenticate: <BearerChallenge::refusedToken()>`, `no-store`, the same for every
  cause, recorded through `ServerAnswersInterface::record()` (the call log reads it as the server's answer), built by
  `Mcp/Infrastructure/Http/UnauthorizedAnswers::refusedToken()`, shared with the deferred 401. The cause
  is named only in `McpBearerRefusedException`'s constant message (`MCP access token refused: <cause>`, never the
  token, its hash nor a UUID), logged by the `security` channel's « Authenticator failed. », and — for a **known**
  token (every cause but `Malformed` and `Unknown`) — in the audit line `mcp-access-token-refused` (#649,
  `.claude/rules/mcp-oauth-cycle-de-vie.md`, « A known token refused is audited »); the `AuthenticatorManager`
  catches it, its `log_level: info` is never used (like `SubmittedPasswordTooLongException`). On success the request
  goes on as `McpConnectionUser` (`Application/McpConnectionPrincipal`, an `AccountReference`): identifier = the
  **connection**'s UUID, roles = the account's stored roles, `accountId()`, `connectionId()`, `clientId()` (public
  OAuth id); the `security` channel's « Authenticator successful! » designates it by `userId`
  (`AccountIdentifierReachesNoLogTest`). **The challenge is written once**, `Application/BearerChallenge`:
  `missingToken()`, `refusedToken()` (`, error="invalid_token"`), `insufficientScope($description)` (the description
  checked against RFC 6749 A.2's set, `InvalidChallengeDescriptionException` otherwise). Tests hold a connection
  through the real flow, `tests/Mcp/Support/HoldsAnMcpConnection` (`holdMcpConnection()`, `bearer()`, SQL alterations
  of the account, connection and token rows); `McpBearerAuthenticatorTest` reads each refusal's cause in the log.
- **Consent: `GET|POST /api/oauth/consent`, `ROLE_TRUSTED`** (`access_control ^/api/oauth/consent(/|$)`,
  before the `ROLE_USER` rules; `ROLE_SUPER` through the hierarchy), under the `api` firewall (the person's
  cookie) and the CSRF guard for the POST, JSON only (`MapRequestPayload(acceptFormat: 'json')`). **One
  judge**: `ConsentService` revalidates the request through the same validator on both calls, keeping
  nothing in between; a refusal there is a 400 problem+json, never a redirect. The approval writes the
  connection and, through league, its code in one transaction (`TransactionInterface`), then the audit
  line `mcp-connection-granted` **after** the COMMIT (`ConsentServiceTest`); **the active grant of the same account
  and client is superseded at the first exchange of the new code**, never at the approval (#726, D2): in the token
  endpoint's transaction, cause `superseded`, one `mcp-connection-revoked` line per superseded connection after its
  COMMIT (#649: `.claude/rules/mcp-oauth-cycle-de-vie.md`, « A new grant supersedes the previous one »); a denial creates, revokes and logs nothing and sends league's `access_denied`. The POST answers a 200 JSON `redirectUri`, not a 302
  (`fetch()` would follow it). `ConsentControllerTest` (base tier and non-trusted 403, anonymous 401 on the GET and
  the CSRF guard's 403 on the POST, CSRF 403 at the trusted tier, 422 without `approved`). The account is read
  by `#[CurrentUser] CpgUser`, checked rather than cast. **An approval requires a recent authentication**
  (F1 of #647's reviews, the author's decision): the person's `BEARER` lives an hour and is not revocable
  (ADR 0003), so a stolen cookie must not open a thirty-day connection. `ConsentService::MAX_AUTHENTICATION_AGE`
  (`PT10M`, a class constant, the same in every environment — never a per-environment security setting, #659)
  bounds the age of the authentication instant, read by `AuthenticationInstantInterface`
  (`Infrastructure/Security/JwtAuthenticationInstant`: the `iat` of the JWT the `api` firewall authenticated,
  re-read through Lexik's `parse()`, signature checked — never a value the client sends); past it, or with no
  instant readable, `ReauthenticationRequiredException`: 401 problem+json `/errors/reauthentication-required`,
  static `detail`, `log_level: info`, nothing written nor audited. Judged **after** the revalidation (an invalid
  request stays its 400). The summary and the denial need no recent authentication: neither opens anything,
  and a person saying no need not sign in again. **The site has no JWT refresh**: a token is only issued at
  login (and by the base access, which never reaches the consent), so `iat` is the instant the password was
  typed; a refresh added some day must carry the original instant in a claim of its own, or this rule protects
  nothing. Boundary pinned in `ConsentServiceTest` (exactly ten minutes granted, one second more refused,
  unknown instant refused), the HTTP path in `ConsentControllerTest` (a JWT signed by the site's key, `iat`
  eleven minutes back: 401 typed, no connection, code nor audit line; five minutes back: granted). **Accepted
  limits**: the POST revalidates the body without binding it
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
  **One announcement ahead of the code, accepted** (phase 2 only ships whole):
  `client_id_metadata_document_supported: true` until CIMD (#651) lands; `bearer_methods_supported: ["header"]` is
  true since #648's authenticator. `OAuthMetadataTest` pins both documents exactly, their routed paths, a POST 405,
  the `BEARER` changing nothing, no `openid-configuration`.
- **A dedicated Ingress carries the two documents**, `k8s/base/ingress-oauth-metadata.yaml`: two `Exact`
  paths to `backend:80` (an `Exact` beats the main Ingress's `/` prefix), **no annotation, no `tls`** —
  ingress-nginx applies `auth-*` annotations to every path of one object, and preprod's Basic Auth sits on
  the main one; a client cannot answer a Basic Auth. Each overlay patches the host only (the main Ingress's,
  whose certificate covers it). `/api/oauth/*` and `/api/mcp` stay behind preprod's Basic Auth until phase 2's
  delivery (#653).
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
  public clients, no secret, a concurrent duplicate named (`McpClientAlreadyRegisteredException`). **The
  client's invariants live in the Domain**, shared by the command and the future backoffice (#652):
  `McpClientId` (1–255 characters of `[A-Za-z0-9._:/-]`, `InvalidMcpClientIdException`), `McpClientName` (1–100
  characters once trimmed of Unicode blanks, valid UTF-8, no control, format, line or paragraph separator,
  `InvalidMcpClientNameException`), and `McpClient::register()` takes only those value objects and a
  `list<RedirectUri>`, a repeated redirect kept once. Messages never quote the value. The columns take the
  domain's bounds (`client_id VARCHAR(255)`, `name VARCHAR(100)`, `McpClient::*_MAX_LENGTH` = the value
  objects' `MAX_LENGTH`); `ClientRepository` and the token endpoint search no identifier outside
  `McpClientId`'s shape. Tests build clients through `tests/Mcp/Connection/RegisteredMcpClient`. A
  pre-registered client's name is the one the site's administrator typed, and the consent page says so — never
  « declared by the client, not verified »; a CIMD client (#651) will declare its own, with its own wording.
- **The consent page** (`/<locale>/oauth/consent`, `requiresAuth`, `ROLE_TRUSTED`/`ROLE_SUPER`, `noindex`;
  an anonymous visitor goes through login, whose `redirect` keeps the query). `consentParamsFromQuery()`
  refuses a repeated or valueless parameter (no call); the page sends back what it received, computes
  nothing. It follows the returned URL with **`window.location.replace`**, never `assign` (the page must
  not stay in history: « Back » would offer to grant the same request again), and only an `http:`/`https:`
  URL whose **`hostname`** (not `host`: the backend's `redirectHost` has no port, a loopback redirect has an
  ephemeral one) is the host shown — defence in depth, the server already validated it. 401 **and 403**
  (a CSRF refusal included) read as an expired session (`markSessionExpired`, like `useAssistant`):
  accepted, a re-login gives fresh cookies. 422 is terminal (`malformed-decision`), a 400 whose `type` is the
  rejection is `invalid`. **A 401 whose `type` is `reauthentication-required`** (only on the approval) is
  its own failure, terminal, the session **not** marked expired: the page says why and links to the login
  with `redirect` (the full path) and **`reauth=1`** (`presentation/router/reauthentication.ts`, one value only),
  which the login route's `beforeEnter` honours for a person already signed in — it sends them home
  otherwise; LoginPage announces why it opened, and its open-redirect guard on `redirect` is unchanged
  (`LoginPage.spec.ts`, `adminGuard.spec.ts`). **Double-clickjacking** (`presentation/ui/useDeliberateActivation`):
  Authorize and Deny are enabled only once the page has been visible (`document.visibilityState`) and
  focused (`document.hasFocus()`) for `ACTIVATION_DELAY_MS` (600 ms), **counted from when the buttons appear**
  (`useDeliberateActivation(showRequest)`), never from the page's mount — they only render once
  `GET /api/oauth/consent` answers, and a load longer than the delay made them appear already active (round 2
  of the reviews; `OAuthConsentPage.spec.ts`, « résumé reçu après le délai »); the delay restarts on each
  appearance, on refocus and when the page becomes visible again; hidden, blurred or with the buttons gone,
  they are disabled; a pointer click (`detail` > 0) whose `pointerdown` (captured on the
  document) came before the activation is ignored, a keyboard click depends on the activation alone.
  `frame-ancestors 'none'` already covers framing; this covers a window brought in front between two clicks.
  The page spec runs it under fake timers (`setTimeout` only, `flushPromises` keeps `setImmediate`; axe needs
  the real ones back). `OAuthConsentPage.spec.ts`, `useOAuthConsent.spec.ts`,
  `HttpOAuthConsentRepository.spec.ts`, `consentParams.spec.ts`.
- **The whole flow** is driven end to end by `tests/Mcp/Connection/OAuthFlowTest` (trait
  `DrivesTheOAuthFlow`: a registered client, authorize, consent as a trusted account, exchange, refresh,
  revoke, every table read for a token in clear). `testAnAwkwardStateAndARegisteredQueryReachTheClientIntact`
  sends a `state` holding `+`, `&`, `=`, `%`, a space, `#` and non-ASCII characters, on a registered `https`
  redirect carrying its own query (`?tenant=a`): the callback keeps that query first, then league's `code` and
  `state` (byte-exact), then `iss` last. The page's leg is played with the values it would send (the query read
  decoded, sent back to the `GET`, then camelCase in the `POST`), PHPUnit running no frontend.
