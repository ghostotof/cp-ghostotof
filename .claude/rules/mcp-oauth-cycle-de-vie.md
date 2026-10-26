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
  - "k8s/base/messenger-purge-cronjob.yaml"
---

# Backend — `Mcp/Connection`: lifetime of a connection and its tokens (#649)

> Decisions: `docs/adr/0006-serveur-mcp.md`, D3 and D6; spec `.claude/specs/0007-mcp-server.md`, phase 2 (issue
> #649: lifetimes, rotation, reuse, expiry, purge). Split out of `.claude/rules/mcp-oauth.md` from the start
> (34 KB, which #649 to #652 would push past `tools/check-claude-rules.sh`'s 50 000 bytes); that rule keeps the
> authorization server itself and points here. Vocabulary: `CONTEXT.md`, « Accès MCP ». The index of every rule
> is at the top of `.claude/CLAUDE.md`.

- **The durations live in configuration, nowhere else** (`Domain/ValueObject/ConnectionLifetimes`). Five
  parameters of `config/services.yaml`, ISO 8601 intervals: `app.mcp.lifetime.authorization_code` (`PT10M`),
  `access_token` (`PT1H`), `refresh_token` (`P30D`, rotated at each use), `connection_idle` (`P30D` without use)
  and `connection_maximum` (`P90D` after the grant, whatever the use). The service is built by
  `ConnectionLifetimes::fromConfiguration()` (a factory: private constructor), which refuses, with
  `InvalidConnectionLifetimeException` naming the parameter and never the value, an unreadable interval, a null
  one, an idle delay beyond the maximum and an access token outliving its refresh token — compared on one fixed
  instant (`2026-01-01T00:00:00+00:00`), so the verdict never depends on the day the container is built. Each
  getter returns a **copy** (`DateInterval` is mutable). `AuthorizationServerFactory` reads league's three
  durations from it and `McpBearerAuthenticator` hands it to `McpAccessToken::refusalAt()`: no constant keeps a
  copy (`McpConnection::IDLE_LIFETIME`/`MAX_LIFETIME` and the factory's `*_TTL` are gone).
  **One value for every environment, never a `%env()%`**: `SecurityServicesEnvironmentParityTest` compares every
  `App\` definition across environments (#659, `.claude/rules/backoffice-api.md`, « Authorization »), and a token
  lifetime of prod alone would escape every test. `ConnectionLifetimesWiringTest` reads the container's service
  and an exchanged code's `expires_in`; `ConnectionLifetimesTest` the refusals.
- **A connection's expiry is defined by two thresholds, once.** `ConnectionLifetimes::idleThreshold($now)` and
  `maximumThreshold($now)` are `$now` minus the duration; `McpConnection::isExpiredAt($now, $lifetimes)` is
  `(lastUsedAt ?? grantedAt) <= idleThreshold || grantedAt <= maximumThreshold` — exclusive, dead at the second
  of the threshold. The usage is compared with a threshold rather than `$now` with a deadline because that is the
  form the purge reads in SQL (a column against a parameter typed `Types::DATETIMETZ_IMMUTABLE`), on the very same
  thresholds: the purge and the authenticator judge the same second. Days are calendar days of `$now`'s zone,
  subtracted by `sub(new DateInterval(…))`, never `modify()` (#445): 30 days in Paris across the autumn change
  last 721 hours, the threshold falls at the same wall time (`ConnectionLifetimesTest`, `McpConnectionExpiryTest`,
  boundary second by second).
