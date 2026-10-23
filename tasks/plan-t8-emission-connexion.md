# Spec 0007 — Tâche 8 (#647) : émission d'une connexion MCP, client pré-enregistré — plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** une personne du palier de confiance accorde une connexion MCP à un client pré-enregistré
(redirection vers le site, connexion si besoin, consentement), et le client reçoit un jeton d'accès
et un jeton de rafraîchissement — métadonnées découvrables aux URL canoniques, rien en clair en base.

**Architecture :** un sous-contexte `Mcp/Connection` porte le modèle (client, connexion, code, jetons),
des adaptateurs qui implémentent les interfaces de dépôt de `league/oauth2-server` 9.4.1 (sans le
bundle : décision du 2026-10-23, voir Tâche 11), et des contrôleurs minces. **La « personne » que
league connaît est la connexion MCP** : l'identifiant d'utilisateur que league range dans le code,
les jetons et le jeton de rafraîchissement est l'UUID de la connexion créée au consentement — c'est
ce qui rattache chaque jeton à sa connexion sans état côté serveur. Jetons d'accès **opaques**
(`bin2hex(random_bytes(40))`), stockés en SHA-256 comme `PasswordSetupToken` ; codes et jetons de
rafraîchissement chiffrés par league avec une clé Defuse **dérivée d'`APP_SECRET`** (aucune paire
de clés, aucun nouveau secret).

**Tech Stack :** PHP 8.5, Symfony 8.1, `league/oauth2-server` 9.4.1, `symfony/psr-http-message-bridge`
+ `nyholm/psr7` (déjà présents), Doctrine ORM 3, Vue 3 + TypeScript, Vitest, nginx, kustomize.

**Spec :** `.claude/specs/0007-mcp-server.md` (« Connexion MCP et serveur d'autorisation », phase 2)
· ADR 0006 D3/D4 · issue #647 · `docs/research/2026-10-22-mcp-decouverte-oauth.md` (section 3).

## Global Constraints

- `league/oauth2-server` **`9.4.1`**, version exacte (ADR 0006 D7, même règle que le SDK MCP).
- Un seul scope : **`nominative:read`**. Clients **publics** seulement (aucun secret), PKCE **S256
  obligatoire** — `plain` et l'absence de méthode sont refusés.
- `resource` (RFC 8707) **obligatoire** à l'autorisation et égal à `<base publique>/api/mcp` ; au point
  de jeton, refusé s'il est présent et différent (`invalid_target`).
- Émetteur `<base publique>/api/oauth` ; points `/api/oauth/authorize`, `/api/oauth/token`,
  `/api/oauth/revoke` ; consentement `GET|POST /api/oauth/consent`.
- Métadonnées **hors `/api`** : `/.well-known/oauth-protected-resource/api/mcp` et
  `/.well-known/oauth-authorization-server/api/oauth`. Aucun document OIDC, aucun `registration_endpoint`.
- Durées : code 10 min (`PT10M`), jeton d'accès 1 h (`PT1H`), rafraîchissement 30 j (`P30D`).
  Rotation, réutilisation et expiration de la connexion : **tâche 10 (#649)**, pas ici.
- Empreintes : `hash('sha256', $identifiant)`, colonnes `CHAR(64)` uniques. **Aucun jeton, code ni
  identifiant de jeton en clair** en base, dans un log ou dans le journal d'audit.
- Horodatages `datetimetz_immutable`, clés `Uuid::v7()` dans le constructeur, durées par
  `add(new DateInterval(…))`.
- Aucune configuration `security` ni route propre à un environnement (#659), aucun `%env()%` dans
  `security.yaml`.
- Règles du dépôt : `declare(strict_types=1)`, `use` triés (`make back-style-fix`), une exception nommée
  par échec (jamais SPL nue), `#[SensitiveParameter]` sur tout paramètre `token|secret|key|code…`,
  regex terminées par `\z`, chemins décidés par `CanonicalPath`.
- Commandes dans le conteneur : `make back-test`, `make back-quality`, `make front-test`,
  `make front-lint`, `make front-build` ; PHPStan/Rector en `-u dev`. Jamais lire `.env.local`.
- Avant d'écrire dans une zone, lire au moins un fichier de cette zone avec `Read` (règles à portée de
  chemin) : `security-authentication.md`, `security-user.md`, `backend-architecture.md`,
  `backend-integrite.md`, `backend-conventions.md`, `backoffice-api.md`, `mcp.md`,
  `deploiement-postgres.md`, `deploiement-nginx.md`, `deploiement.md`, `frontend*.md`.

## Review Focus

1. **Une demande modifiée entre l'autorisation et le consentement** (`redirect_uri`, `resource` ou
   `client_id` réécrits dans l'URL de la page) : le consentement revalide tout et répond 400
   problem+json — jamais un code vers une redirection non enregistrée → test (tâche 6).
2. **Un paramètre en tableau** (`client_id[]=x`, `scope[]=…`) : league suppose des chaînes ; on doit
   répondre `invalid_request`, pas 500 → test (tâche 5).
3. **Un `code_challenge` sans `code_challenge_method`** : league le lit comme `plain` → refusé → test
   (tâche 5).
4. **Un code échangé deux fois** : le second échange est refusé (`invalid_grant`) → test (tâche 7).
5. **Un `state` démesuré** (URL de consentement et redirections gonflées) : au-delà de 1024 caractères,
   `invalid_request` → test (tâche 5).

---

## Fichiers

Sous `backend/src/Mcp/Connection/` (nouveau sous-contexte, glossaire « Connexion MCP ») :

| Fichier | Rôle |
|---|---|
| `Domain/Entity/McpClient.php` | client pré-enregistré : identifiant public, nom déclaré, redirections |
| `Domain/Entity/McpConnection.php` | connexion MCP : compte, client, scope, octroi, dernier usage, révocation |
| `Domain/Entity/McpAuthorizationCode.php`, `McpAccessToken.php`, `McpRefreshToken.php` | empreintes rattachées à une connexion |
| `Domain/ValueObject/RedirectUri.php` | redirection enregistrée : `https` exacte, ou boucle locale `http` comparée sans port |
| `Domain/Repository/*RepositoryInterface.php` | une interface par entité |
| `Domain/Exception/*.php` | échecs nommés (redirection invalide, client déjà enregistré, demande d'autorisation refusée) |
| `Application/OAuthEndpoints.php` | URL publiques dérivées (émetteur, ressource, points, page de consentement) |
| `Application/AuthorizationRequestValidatorInterface.php` + impl. | valide une demande, rend son résumé |
| `Application/ConsentServiceInterface.php` + impl. | accorde ou refuse, rend l'URL de retour |
| `Application/OAuthServerMetadataInterface.php` + impl. | les deux documents |
| `Application/TokenEndpointInterface.php`, `RevocationEndpointInterface.php` + impl. | jeton, révocation |
| `Infrastructure/Doctrine/*Repository.php` | dépôts Doctrine |
| `Infrastructure/League/*` | adaptateurs league : entités, dépôts, `LoopbackAwareAuthCodeGrant`, `NoSigningKey`, `OAuthEncryptionKey`, `AuthorizationServerFactory`, `RefreshTokenPayloadReader` |
| `Presentation/Controller/*Controller.php` | métadonnées, autorisation, consentement, jeton, révocation |
| `Presentation/Command/RegisterMcpClientCommand.php` | `app:mcp:client:register` |

Ailleurs : `backend/config/packages/security.yaml`, `backend/config/services.yaml`,
`backend/config/packages/framework.yaml` (niveaux de log), `backend/migrations/`,
`k8s/base/postgres-roles/verify.sql`, `docker/nginx/default.conf`, `k8s/base/backend-nginx.conf`,
`tools/check-backend-nginx-rate-limits.sh`, `k8s/base/ingress-oauth-metadata.yaml`,
`k8s/overlays/{preprod,prod}/kustomization.yaml`, `tools/check-frontend-image-headers.sh`,
`tools/audit-prod.sh`, `docker-compose.override.yml`, le frontend (tâche 10), la documentation (tâche 11).

---

### Tâche 1 : dépendance, modèle et migration

**Files:**
- Modify: `backend/composer.json` (+ lock) — `"league/oauth2-server": "9.4.1"`, `"symfony/psr-http-message-bridge": "8.1.*"` (usage direct)
- Modify: `backend/tests/Mcp/Presentation/McpEndpointTest.php:398` — `league/oauth2-server` dans la liste des paquets pinnés exactement
- Create: les cinq entités, leurs interfaces de dépôt et dépôts Doctrine (tableau ci-dessus)
- Create: `backend/migrations/Version20261023xxxxxx.php`
- Modify: `k8s/base/postgres-roles/verify.sql` (bloc #611 : le tuple que le test imprime)
- Test: `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepositoryTest.php`

**Interfaces:**
- Produces :
  - `McpClient::register(string $clientId, string $name, list<string> $redirectUris, DateTimeImmutable $at): self` (colonne JSON) ; `getClientId(): string`, `getName(): string`, `redirectUris(): list<string>`. `allowsRedirect()` arrive en tâche 2.
  - `McpConnection::grant(CpgUser $account, McpClient $client, string $scope, DateTimeImmutable $at): self` ; `getId(): Uuid`, `account(): CpgUser`, `client(): McpClient`, `isRevoked(): bool`. Colonnes `granted_at`, `last_used_at` (nulle, écrite en tâche 10 de la spec), `revoked_at` (nulle).
  - `McpAuthorizationCode(McpConnection, string $codeHash, DateTimeImmutable $expiresAt)`, `McpAccessToken(McpConnection, string $tokenHash, string $audience, DateTimeImmutable $expiresAt)`, `McpRefreshToken(McpConnection, string $tokenHash, ?McpAccessToken, DateTimeImmutable $expiresAt)` ; chacun `revoke(DateTimeImmutable)`, `isRevoked(): bool`.
  - Dépôts : `McpClientRepositoryInterface::findOneByClientId(string): ?McpClient`, `add(McpClient): void` ; `McpConnectionRepositoryInterface::findOneById(Uuid): ?McpConnection`, `add(McpConnection): void` ; pour chaque jeton : `add(…)`, `findOneByHash(string): ?…`, `revokeByHash(string, DateTimeImmutable): void`.
  - Tables `mcp_client` (`client_id` unique), `mcp_connection`, `mcp_authorization_code`, `mcp_access_token`, `mcp_refresh_token` (`*_hash CHAR(64)` uniques, FK `ON DELETE CASCADE` vers la connexion, `mcp_connection.account_id` en cascade vers `cpg_user`, `mcp_refresh_token.access_token_id` `ON DELETE SET NULL`).

- [ ] **Step 1 : tests rouges** — `testAConnectionSurvivesARoundTrip` (grant → add → `findOneById` rend compte, client, scope, `grantedAt` avec son décalage) ; `testATokenHashIsUnique` (deux `McpAccessToken` de même empreinte → violation nommée par `uniq_mcp_access_token_hash`) ; `testDeletingTheAccountDeletesItsConnectionsAndTokens` ; pinning : `league/oauth2-server` sans opérateur.
- [ ] **Step 2 : vérifier l'échec** — `make back-test` filtré (`--filter McpConnectionRepositoryTest`) : classes absentes.
- [ ] **Step 3 : implémenter** — `composer require league/oauth2-server:9.4.1 symfony/psr-http-message-bridge:8.1.*` dans le conteneur (`-u dev`), entités, dépôts, migration écrite à la main au format de `Version20260903160900.php` (`UUID`, `TIMESTAMP(0) WITH TIME ZONE`, docblock en français qui dit pourquoi), puis le tuple `verify.sql` que `DatabaseOwnerSettingsVerificationTest` imprime.
- [ ] **Step 4 : vérifier** — `make back-test` vert (dont `DatabaseOwnerSettingsVerificationTest`, `TimestampColumnsCarryTheirOffsetTest`).
- [ ] **Step 5 : commit** `feat(mcp): modèle de la connexion MCP et de ses jetons (#647)`.

### Tâche 2 : redirection enregistrée et commande `app:mcp:client:register`

**Files:**
- Create: `backend/src/Mcp/Connection/Domain/ValueObject/RedirectUri.php`, `Domain/Exception/InvalidRedirectUri.php`, `Domain/Exception/McpClientAlreadyRegistered.php`
- Create: `backend/src/Mcp/Connection/Presentation/Command/RegisterMcpClientCommand.php`
- Test: `backend/tests/Mcp/Connection/Domain/ValueObject/RedirectUriTest.php`, `backend/tests/Mcp/Connection/Presentation/Command/RegisterMcpClientCommandTest.php`

**Interfaces:**
- Consumes : `McpClient::register`, `McpClientRepositoryInterface` (tâche 1).
- Produces : `RedirectUri::fromString(string): self` (lève `InvalidRedirectUri::…`), `RedirectUri::matches(string $requested): bool`, `RedirectUri::isLoopback(): bool`, `__toString()` ; `McpClient::allowsRedirect(string $requested): bool` (vrai si une redirection enregistrée `matches`). La commande valide chaque redirection par `RedirectUri::fromString` avant `McpClient::register`.
  - Admis : `https://…` sans fragment ; `http://` **seulement** sur `localhost`, `127.0.0.1`, `[::1]`. Refusé : tout autre schéma (`javascript:`, schémas privés — RFC 8252 §7.1 hors périmètre), fragment, identifiants dans l'URL.
  - `matches` : boucle locale → schéma, hôte, chemin et requête égaux, **port ignoré** (RFC 8252 §7.3) ; sinon égalité stricte de la chaîne.
- Commande : `app:mcp:client:register --client-id=<id> --name=<nom> --redirect-uri=<uri> [--redirect-uri=…]` ; `client-id` : 1 à 255 caractères `[A-Za-z0-9._:/-]` ; `name` : 1 à 100 caractères ; au moins une redirection. Sortie : le client enregistré, ses redirections.

- [ ] **Step 1 : tests rouges** —
  - `RedirectUriTest` (fournisseur de données) : `http://localhost/callback` correspond à `http://localhost:53412/callback` ; `http://127.0.0.1/callback` à `http://127.0.0.1:1/callback` ; `http://[::1]/cb` à `http://[::1]:8000/cb` ; **refus** de `http://localhost:5/other`, `http://evil.example/callback`, `http://localhost.evil.example/callback`, `https://localhost/callback` contre `http://localhost/callback` ; `https://app.example/cb` ne correspond qu'à elle-même (`https://app.example:444/cb` refusé) ; `fromString` refuse `http://app.example/cb`, `javascript:alert(1)`, `https://a.example/cb#x`, `https://u:p@a.example/cb`.
  - `RegisterMcpClientCommandTest` : cas nominal (deux redirections, `Command::SUCCESS`, client relu en base) ; `client-id` déjà pris → `FAILURE` + message sans la valeur ; redirection refusée → `FAILURE` ; aucune redirection → `FAILURE`.
- [ ] **Step 2 : vérifier l'échec** — `--filter 'RedirectUriTest|RegisterMcpClientCommandTest'`.
- [ ] **Step 3 : implémenter** — analyse par `League\Uri\Uri` (dépendance de league) ; commande au format de `CreateCpgUserCommand` (`#[AsCommand]`, `SymfonyStyle`, aucune valeur client citée dans une exception).
- [ ] **Step 4 : vérifier** — tests verts.
- [ ] **Step 5 : commit** `feat(mcp): pré-enregistrer un client MCP en ligne de commande (#647)`.

### Tâche 3 : adaptateurs league et serveur d'autorisation

**Files:**
- Create: `backend/src/Mcp/Connection/Infrastructure/League/` — `OAuthClient.php` (`ClientEntityInterface`), `ConnectionAsUser.php` (`UserEntityInterface`), `NominativeReadScope.php` (`ScopeEntityInterface`), `OpaqueAccessToken.php` (`AccessTokenEntityInterface`), `RefreshToken.php`, `AuthCode.php`, `ClientRepository.php`, `ScopeRepository.php`, `AccessTokenRepository.php`, `RefreshTokenRepository.php`, `AuthCodeRepository.php`, `LoopbackAwareAuthCodeGrant.php`, `NoSigningKey.php` (`CryptKeyInterface`), `AccessTokensAreNotSigned.php`, `OAuthEncryptionKey.php`, `AuthorizationServerFactory.php`
- Create: `backend/src/Mcp/Connection/Application/OAuthEndpoints.php`
- Modify: `backend/config/services.yaml` — `app.public_api_base_url: '%env(default:app.frontend_base_url:APP_PUBLIC_API_BASE_URL)%'`, service `League\OAuth2\Server\AuthorizationServer` par `AuthorizationServerFactory::create`
- Modify: `backend/.env` — `APP_PUBLIC_API_BASE_URL=` (vide : retombe sur `APP_FRONTEND_BASE_URL`, cas de la préprod et de la prod) ; `backend/phpunit.dist.xml` — `<server name="APP_PUBLIC_API_BASE_URL" value="" force="true"/>` ; `docker-compose.override.yml` — `APP_PUBLIC_API_BASE_URL: "http://localhost:${HTTP_PORT}"` sur `backend` (en dev, l'API n'est pas sur l'origine du frontend)
- Test: `backend/tests/Mcp/Connection/Infrastructure/League/*Test.php`, `backend/tests/Mcp/Connection/Application/OAuthEndpointsTest.php`

**Interfaces:**
- Consumes : entités et dépôts (tâche 1), `RedirectUri::matches` (tâche 2).
- Produces :
  - `OAuthEndpoints` (readonly, construit depuis `app.public_api_base_url` et `app.frontend_base_url`, barre finale retirée) : `issuer(): string` (`…/api/oauth`), `resource(): string` (`…/api/mcp`), `authorizationEndpoint()`, `tokenEndpoint()`, `revocationEndpoint()`, `protectedResourceMetadataUrl(): string` (`…/.well-known/oauth-protected-resource/api/mcp`), `consentPage(string $locale): string` (`<frontend>/<locale>/oauth/consent`).
  - `OpaqueAccessToken::toString()` rend l'identifiant ; `setPrivateKey()` ne garde rien.
  - `NoSigningKey` : chaque méthode lève `AccessTokensAreNotSigned::…` (échec franc si un jour un jeton JWT était demandé).
  - `OAuthEncryptionKey::fromSecret(#[SensitiveParameter] string $secret): Defuse\Crypto\Key` — `KEY_CONTEXT = 'cp-ghostotof/oauth-encryption/v1'`, `SecretKeyDerivation::derive` puis `Key::loadFromAsciiSafeString(Encoding::saveBytesToChecksummedAsciiSafeString(Key::KEY_CURRENT_VERSION, $bytes))`.
  - `LoopbackAwareAuthCodeGrant extends AuthCodeGrant` : redéfinit `validateRedirectUri()` pour comparer par `McpClient::allowsRedirect()` (league ne traite pas `localhost` en boucle locale).
  - Dépôts league : `persistNewAccessToken` retrouve la connexion par `getUserIdentifier()` (UUID), stocke `hash('sha256', id)` et `audience = OAuthEndpoints::resource()` ; `isAccessTokenRevoked`, `isAuthCodeRevoked`, `isRefreshTokenRevoked` rendent vrai si le jeton **ou sa connexion** est révoqué ou inconnu ; `persistNew*` lève `UniqueTokenIdentifierConstraintViolationException` sur violation d'unicité ; `ClientRepository::validateClient` vrai seulement pour un client connu, sans secret, et un grant `authorization_code` ou `refresh_token` ; `ScopeRepository::finalizeScopes` ne rend jamais que `nominative:read`.
  - `AuthorizationServerFactory::create(): AuthorizationServer` — `NoSigningKey`, clé dérivée, `setDefaultScope('nominative:read')`, `LoopbackAwareAuthCodeGrant` (`PT10M`, `requireCodeChallengeForPublicClients`) + `RefreshTokenGrant` (`P30D`), jeton d'accès `PT1H`, `revokeRefreshTokens(true)`.

- [ ] **Step 1 : tests rouges** — `testAPersistedAccessTokenIsStoredAsItsHashOnly` (aucune colonne ne contient l'identifiant) ; `testATokenOfARevokedConnectionIsRevoked` ; `testTheGrantAcceptsALocalhostRedirectOnAnyPort` et `testTheGrantRefusesAnotherLoopbackPath` (par `validateAuthorizationRequest` sur une requête PSR-7) ; `testTheEncryptionKeyIsDerivedNotTheSecretItself` ; `testNoSigningKeyFailsClosed` ; `OAuthEndpointsTest` : dérivation depuis `APP_FRONTEND_BASE_URL` seul, puis avec une base d'API distincte.
- [ ] **Step 2 : vérifier l'échec**.
- [ ] **Step 3 : implémenter** (traits `EntityTrait`, `TokenEntityTrait`, `AuthCodeTrait`, `RefreshTokenTrait` de league ; secret injecté comme dans `CsrfCookieTokenSigner`).
- [ ] **Step 4 : vérifier** — tests verts, `ApplicationSecretUsesTest` et `SecretParametersTest` verts.
- [ ] **Step 5 : commit** `feat(mcp): serveur d'autorisation league branché sur la connexion MCP (#647)`.

### Tâche 4 : documents de métadonnées hors `/api`

**Files:**
- Create: `Application/OAuthServerMetadataInterface.php` + `OAuthServerMetadata.php`, `Presentation/Controller/ProtectedResourceMetadataController.php`, `Presentation/Controller/AuthorizationServerMetadataController.php`
- Modify: `backend/config/packages/security.yaml` — firewalls `oauth_resource_metadata` (`^/\.well-known/oauth-protected-resource/api/mcp$`) et `oauth_server_metadata` (`^/\.well-known/oauth-authorization-server/api/oauth$`), `stateless: true`, sans authentificateur, **avant `api`** ; deux règles `access_control` `PUBLIC_ACCESS` aux mêmes motifs
- Modify: `backend/tests/Security/RouterScopeTest.php` (`NON_API_PATHS`, deux entrées justifiées), `backend/tests/Security/AccessControlAnchoringTest.php` (lignes firewall et rôles, voisins compris : `/.well-known/oauth-authorization-server` → aucun firewall)
- Test: `backend/tests/Mcp/Connection/Presentation/Controller/OAuthMetadataTest.php`

**Interfaces:**
- Consumes : `OAuthEndpoints` (tâche 3).
- Produces : `GET` seulement, `application/json`, `Cache-Control: public, max-age=3600`.
  - Ressource protégée : `{"resource", "authorization_servers": [issuer], "scopes_supported": ["nominative:read"], "bearer_methods_supported": ["header"]}`.
  - Serveur d'autorisation : `issuer`, `authorization_endpoint`, `token_endpoint`, `revocation_endpoint`, `response_types_supported: ["code"]`, `grant_types_supported: ["authorization_code", "refresh_token"]`, `code_challenge_methods_supported: ["S256"]`, `token_endpoint_auth_methods_supported: ["none"]`, `revocation_endpoint_auth_methods_supported: ["none"]`, `scopes_supported: ["nominative:read"]`, `client_id_metadata_document_supported: true`, `authorization_response_iss_parameter_supported: true`.

- [ ] **Step 1 : tests rouges** — les deux URL rendent 200 et exactement ces clés (égalité de tableau), `issuer`/`resource` dérivés de la configuration de test ; aucun `registration_endpoint` ; `GET /.well-known/openid-configuration`, `/.well-known/openid-configuration/api/oauth`, `/api/oauth/.well-known/openid-configuration` → 404 ; `POST` sur un document → 405 ; une requête portant un cookie `BEARER` rend le même corps.
- [ ] **Step 2 : vérifier l'échec**.
- [ ] **Step 3 : implémenter**.
- [ ] **Step 4 : vérifier** — `RouterScopeTest`, `AccessControlAnchoringTest`, `SecurityConfigEnvironmentParityTest`, `RouteEnvironmentParityTest` verts.
- [ ] **Step 5 : commit** `feat(mcp): métadonnées OAuth aux URL canoniques (#647)`.

### Tâche 5 : point d'autorisation

**Files:**
- Create: `Application/AuthorizationRequestValidatorInterface.php` + `AuthorizationRequestValidator.php`, `Application/AuthorizationRequestSummary.php`, `Application/IssParameter.php`, `Presentation/Controller/AuthorizeController.php`
- Modify: `security.yaml` — firewall `oauth` (`^/api/oauth/(authorize|token|revoke)$`, `stateless: true`, sans authentificateur, avant `api`) ; `ApiRouteExposureTest::PUBLIC_PATHS` (`/api/oauth/authorize`, justifiée) ; `McpExposureSymmetryTest::EXCLUDED_ROUTES` (`'GET /api/oauth/authorize'`) ; `AccessControlAnchoringTest`
- Test: `backend/tests/Mcp/Connection/Presentation/Controller/AuthorizeControllerTest.php`

**Interfaces:**
- Consumes : `AuthorizationServer` (tâche 3), `OAuthEndpoints`.
- Produces :
  - `AuthorizationRequestValidatorInterface::validate(ServerRequestInterface): AuthorizationRequestSummary` — appelle `validateAuthorizationRequest`, puis exige : chaque paramètre attendu est une chaîne (sinon `invalid_request`), `code_challenge_method === 'S256'`, `state` ≤ 1024 caractères, `resource === OAuthEndpoints::resource()` (`invalid_target`, `OAuthServerException` construite avec la redirection une fois celle-ci validée). Lève `OAuthServerException`.
  - `AuthorizationRequestSummary` (readonly) : `clientId`, `clientName`, `redirectUri`, `redirectHost`, `scope`, `resource`, plus la `AuthorizationRequestInterface` de league.
  - `GET /api/oauth/authorize` : demande valide → **302** vers `OAuthEndpoints::consentPage($locale)` + la requête d'origine telle quelle (`$locale = $request->getPreferredLanguage(['fr', 'en'])`) ; erreur avec redirection valide → 302 vers la redirection du client avec `error`, `state` et **`iss`** ; erreur sans redirection valide (client inconnu, redirection non enregistrée) → 400/401 JSON de league, **jamais** de redirection.
  - Fonction partagée `IssParameter::append(string $location, string $issuer): string` (ajoute `iss` à la requête, en préservant les paramètres présents) — réutilisée tâches 6.

- [ ] **Step 1 : tests rouges** — demande valide → 302 vers `…/fr/oauth/consent?…` (et `/en/` avec `Accept-Language: en`) ; refus : PKCE absent (`invalid_request` en redirection, avec `iss`), `code_challenge_method=plain`, `code_challenge` sans méthode, `resource` absent ou étranger (`invalid_target` + `iss`), scope inconnu (`invalid_scope`), redirection non enregistrée (400/401, **pas** de `Location`), client inconnu (`invalid_client`, pas de `Location`), `client_id[]=x` (400, pas 500), `state` de 1025 caractères ; boucle locale : port différent accepté, autre hôte et autre chemin refusés ; cookie `BEARER` présent → même réponse qu'anonyme.
- [ ] **Step 2 : vérifier l'échec**.
- [ ] **Step 3 : implémenter** (pont PSR-7 par `PsrHttpFactory`/`HttpFoundationFactory`).
- [ ] **Step 4 : vérifier** — `ApiRouteExposureTest`, `McpExposureSymmetryTest` verts.
- [ ] **Step 5 : commit** `feat(mcp): point d'autorisation OAuth, PKCE S256 et audience exigés (#647)`.

### Tâche 6 : consentement et audit « connexion MCP accordée »

**Files:**
- Create: `Application/ConsentServiceInterface.php` + `ConsentService.php`, `Application/ConsentDecision.php` (DTO), `Domain/Exception/AuthorizationRequestRejected.php` (`ProblemExceptionInterface`, 400, `use HasProblemType`), `Presentation/Controller/ConsentController.php`
- Modify: `security.yaml` — `access_control` `^/api/oauth/consent(/|$)` → `ROLE_TRUSTED`, après `backoffice`, avant les règles `ROLE_USER` ; `framework.yaml` — `log_level: info` pour `AuthorizationRequestRejected`
- Modify: `SecurityAuditLoggerInterface` + `SecurityAuditLogger` — `mcpConnectionGranted(CpgUser $account, Uuid $connectionId, string $clientId): void` → événement `mcp-connection-granted`, contexte `userId`, `connectionId`, `clientId` ; `SecurityAuditLoggerTest` (champs, aucun jeton)
- Modify: `AccessControlAnchoringTest` (`/api/oauth/consent → ROLE_TRUSTED`)
- Test: `backend/tests/Mcp/Connection/Presentation/Controller/ConsentControllerTest.php`

**Interfaces:**
- Consumes : `AuthorizationRequestValidatorInterface`, `IssParameter` (tâche 5), dépôts (tâche 1), `AuthorizationServer`.
- Produces :
  - `ConsentDecision` : champs `responseType`, `clientId`, `redirectUri`, `scope` (nullable), `state` (nullable, ≤ 1024), `codeChallenge`, `codeChallengeMethod`, `resource` (chaînes, `Assert\Length` bornées), `approved: bool` — reçu par `#[MapRequestPayload(acceptFormat: 'json')]` ; reconstruit en requête PSR-7 pour revalidation.
  - `GET /api/oauth/consent?<paramètres de la demande>` → 200 `{"client": {"id", "name", "registration": "preregistered"}, "redirectHost", "scope", "resource"}`.
  - `POST /api/oauth/consent` (cookie + `X-XSRF-TOKEN`) → 200 `{"redirectUri": "<redirection du client>?code=…&state=…&iss=…"}` ; refus (`approved: false`) → 200 `{"redirectUri": "…?error=access_denied&state=…&iss=…"}` sans connexion créée.
  - Accord : transaction (`wrapInTransaction`) qui crée `McpConnection::grant(...)`, `setUser(new ConnectionAsUser($connection))`, `setAuthorizationApproved(true)`, `completeAuthorizationRequest` (le code est persisté par le dépôt) ; audit **après** commit.
  - Demande invalide au consentement → `AuthorizationRequestRejected` (400 problem+json), jamais de redirection.

- [ ] **Step 1 : tests rouges** — compte `ROLE_TRUSTED` : GET rend le résumé ; POST accord → 200, `redirectUri` sur la redirection enregistrée avec `code`, `state` et `iss` ; une ligne `mcp_connection` (compte, client, scope) ; audit `mcp-connection-granted` (`userId`, `connectionId`, `clientId`, aucun code) ; POST refus → `access_denied`, aucune connexion ; **jeton d'accès de base → 403** (GET et POST) ; compte `ROLE_USER` seul → 403 ; anonyme → 401/403 ; sans en-tête XSRF → 403 ; `redirect_uri` modifiée après l'autorisation → 400 problem+json sans `redirectUri` ; `ROLE_SUPER` → accordé (hiérarchie).
- [ ] **Step 2 : vérifier l'échec**.
- [ ] **Step 3 : implémenter**.
- [ ] **Step 4 : vérifier** — `ApiRouteExposureTest` (invariant 6 : 403 au jeton de base), `ExceptionLogLevelCoverageTest`, `ProblemDetailStaysStaticTest`, `SecurityAuditLoggerTest` verts.
- [ ] **Step 5 : commit** `feat(mcp): consentement à une connexion MCP, palier de confiance exigé (#647)`.

### Tâche 7 : jeton et révocation — parcours complet

**Files:**
- Create: `Application/TokenEndpointInterface.php` + impl., `Application/RevocationEndpointInterface.php` + impl., `Infrastructure/League/RefreshTokenPayloadReader.php` (`CryptTrait`), `Presentation/Controller/TokenController.php`, `Presentation/Controller/RevocationController.php`
- Modify: `CsrfCookieRequestSubscriber::EXCLUDED_PATHS` — `/api/oauth/token`, `/api/oauth/revoke`, justifiés dans le docblock (appelés hors navigateur, firewall `oauth` qui ne lit aucun cookie) ; `ApiRouteExposureTest::PUBLIC_PATHS` (deux entrées) ; `McpExposureSymmetryTest::EXCLUDED_ROUTES` (`'POST /api/oauth/token'`, `'POST /api/oauth/revoke'`)
- Test: `backend/tests/Mcp/Connection/Presentation/Controller/TokenControllerTest.php`, `RevocationControllerTest.php`, `backend/tests/Mcp/Connection/OAuthFlowTest.php`

**Interfaces:**
- Consumes : `AuthorizationServer`, dépôts, `OAuthEndpoints`.
- Produces :
  - `POST /api/oauth/token` (`application/x-www-form-urlencoded`) : `respondToAccessTokenRequest` ; `resource` présent et différent → 400 `invalid_target` ; réponse league `{"token_type": "Bearer", "expires_in": 3600, "access_token", "refresh_token"}` avec `Cache-Control: no-store` ; erreurs au format RFC 6749 (`{"error", "error_description"}`), **pas** problem+json (le contrôleur attrape `OAuthServerException` et rend sa réponse).
  - `POST /api/oauth/revoke` (RFC 7009) : `token` requis (sinon 400 `invalid_request`), `client_id` requis et connu (sinon 401 `invalid_client`) ; jeton d'accès → sa ligne révoquée ; jeton de rafraîchissement → déchiffré, sa ligne et son jeton d'accès révoqués ; jeton inconnu, illisible ou d'un autre client → **200 sans effet** (aucune fuite d'existence) ; toujours 200 corps vide sinon. La connexion n'est pas révoquée ici : c'est l'objet des tâches 10 et 11 de la spec (audit « connexion révoquée »).

- [ ] **Step 1 : tests rouges** —
  - `OAuthFlowTest::testTheWholeFlowIssuesTokens` : métadonnées (ressource → émetteur → document AS) → `GET authorize` → `GET`/`POST consent` (compte `ROLE_TRUSTED`) → `POST token` avec `code_verifier` → 200, `access_token` et `refresh_token` ; puis `grant_type=refresh_token` → nouveau couple, l'ancien jeton de rafraîchissement refusé (`invalid_grant`).
  - `testNoTokenIsStoredInClear` : après le parcours, aucune ligne des cinq tables (encodée en JSON) ne contient le code, le jeton d'accès, le jeton de rafraîchissement ni l'identifiant d'un jeton.
  - Refus : `code_verifier` faux ou absent → `invalid_grant` ; code échangé deux fois → `invalid_grant` ; `redirect_uri` différente de celle de l'autorisation → `invalid_request` ; `resource` étranger → `invalid_target` ; client inconnu → `invalid_client` ; corps multipart → (nginx en prod) ; requête portant le cookie `BEARER` sans XSRF → ni 403 CSRF, ni authentifiée.
  - Révocation : jeton d'accès révoqué (`isAccessTokenRevoked` vrai) ; jeton de rafraîchissement révoqué puis refusé au point de jeton ; jeton inconnu → 200 ; `token` absent → 400.
- [ ] **Step 2 : vérifier l'échec**.
- [ ] **Step 3 : implémenter**.
- [ ] **Step 4 : vérifier** — suite complète `make back-test` verte, `make back-quality` vert.
- [ ] **Step 5 : commit** `feat(mcp): points de jeton et de révocation OAuth (#647)`.

### Tâche 8 : zone nginx `oauth`

**Files:**
- Modify: `docker/nginx/default.conf` et `k8s/base/backend-nginx.conf` (miroirs) — `limit_req_zone $binary_remote_addr zone=oauth:1m rate=10r/m;` au niveau `http` (identique dans les deux), `location ^~ /api/oauth/ { limit_req zone=oauth burst=10 nodelay; try_files $uri /index.php$is_args$args; }`
- Modify: `tools/check-backend-nginx-rate-limits.sh` — `ZONES` : `"/api/oauth/token POST 10 oauth"` et `"/api/oauth/authorize GET 10 oauth"`, avant la ligne `publicapi`

- [ ] **Step 1 : rouge** — ajouter d'abord les lignes `ZONES` ; `make back-nginx-rate-limits` échoue (zone absente des confs).
- [ ] **Step 2 : implémenter** les deux confs à l'identique ; aucune `error_page` dans la `location` (elle perdrait le 429 problem+json hérité).
- [ ] **Step 3 : vérifier** — `make back-nginx-rate-limits` vert (les deux confs, 429 problem+json au-delà de la rafale) ; `docker compose restart web` puis `curl -s -o /dev/null -w '%{http_code}' localhost:8080/.well-known/oauth-authorization-server/api/oauth` → 200.
- [ ] **Step 4 : commit** `feat(nginx): zone oauth sur les points OAuth publics (#647)`.

### Tâche 9 : Ingress dédié et 404 du frontend sous `/.well-known/`

**Files:**
- Create: `k8s/base/ingress-oauth-metadata.yaml` — `Ingress` `cp-ghostotof-oauth-metadata`, `host: PLACEHOLDER`, deux chemins `pathType: Exact` vers `backend:80`, **sans** annotation `auth-*` ni `cert-manager`, sans `tls` (motif de `ingress-healthz.yaml`, en-tête qui dit pourquoi)
- Modify: `k8s/base/kustomization.yaml` (`resources`), `k8s/overlays/preprod/kustomization.yaml` et `k8s/overlays/prod/kustomization.yaml` — patch JSON6902 `replace /spec/rules/0/host` ciblant `cp-ghostotof-oauth-metadata`
- Create: `backend/tests/Mcp/Connection/Infrastructure/Kubernetes/OAuthMetadataIngressTest.php` (par `KubernetesManifests::base()` / `::overlay()`)
- Modify: `tools/check-frontend-image-headers.sh` — `check_path "/.well-known/oauth-authorization-server" "404" …`, `check_path "/.well-known/openid-configuration" "404" …`, `check_path "/.well-known/oauth-protected-resource/api/mcp" "404" …` (l'image seule : seul l'Ingress envoie ce chemin au backend) ; en-tête du script à jour
- Modify: `tools/audit-prod.sh` — section après 6bis : les deux documents → 200 `application/json` dont `issuer`/`resource` commencent par `https://<domaine>/` ; `/.well-known/openid-configuration` → 404

**Interfaces:** les chemins de `OAuthEndpoints` (tâche 3) sont ceux de l'Ingress ; le test les confronte.

- [ ] **Step 1 : test rouge** — `OAuthMetadataIngressTest` : l'objet existe en base, deux chemins `Exact` égaux aux chemins publiés par `OAuthEndpoints`, service `backend`, aucune annotation commençant par `nginx.ingress.kubernetes.io/auth-` ; chaque overlay patche son hôte (celui de `cp-ghostotof`).
- [ ] **Step 2 : implémenter**.
- [ ] **Step 3 : vérifier** — test vert ; `kubectl kustomize k8s/overlays/preprod | grep -B3 -A20 'cp-ghostotof-oauth-metadata'` puis la même chose en prod : hôte de l'environnement, deux chemins `Exact`, aucune annotation d'auth ; `make front-image-headers` vert ; `bash -n tools/audit-prod.sh` et `make tools-tests` (shellcheck) verts.
- [ ] **Step 4 : commit** `feat(k8s): Ingress dédié aux métadonnées OAuth, 404 du frontend vérifié (#647)`.

### Tâche 10 : page de consentement (frontend)

**Files:**
- Create: `frontend/src/domain/mcp/entities/ConsentRequest.ts`, `frontend/src/domain/mcp/repositories/OAuthConsentRepository.ts`, `frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts`, `frontend/src/application/mcp/useOAuthConsent.ts`, `frontend/src/presentation/pages/OAuthConsentPage.vue`
- Modify: `frontend/src/presentation/router/index.ts` — route `oauth-consent`, `path: 'oauth/consent'` sous `/:locale(fr|en)`, `meta: { requiresAuth: true, roles: [ROLE_TRUSTED, ROLE_SUPER], noindex: true, titleKey: 'seo.oauthConsent.title', descriptionKey: 'seo.oauthConsent.description' }` ; `frontend/src/main.ts` (`provide` du dépôt) ; `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (`oauthConsent.*`, `seo.oauthConsent.*`)
- Test: `frontend/tests/presentation/pages/OAuthConsentPage.spec.ts`, `frontend/tests/infrastructure/mcp/HttpOAuthConsentRepository.spec.ts`, ajout à `frontend/tests/presentation/router/adminGuard.spec.ts`

**Interfaces:**
- Consumes : `GET`/`POST /api/oauth/consent` (tâche 6).
- Produces :
  - `OAuthConsentRepository` : `describe(params: ConsentParams): Promise<ConsentRequest>`, `decide(params: ConsentParams, approved: boolean): Promise<string>` (l'URL de retour) ; erreurs `InvalidConsentRequestError` (400), `SessionExpiredError` (401/403 → `markSessionExpired()`).
  - `useOAuthConsent()` : machine `loading | ready | submitting | invalid | error`, `approve()`, `deny()` ; la navigation finale par `window.location.assign(url)`, **seulement** si `url` est en `http:` ou `https:`.
  - Page : `h1`, le nom du client **marqué comme déclaré**, « enregistré par l'administrateur du site », l'hôte de retour, ce qui sera ouvert (le corpus nominatif, en lecture), le fait qu'il partira vers le fournisseur d'IA de ce client ; deux boutons « Autoriser » / « Refuser » ; erreur en `role="alert"`.

- [ ] **Step 1 : tests rouges** — page en français puis en anglais (textes clés, nom déclaré, hôte) ; « Autoriser » appelle `decide(…, true)` et navigue vers l'URL rendue ; « Refuser » → `decide(…, false)` ; demande invalide → message d'erreur, aucun bouton ; URL rendue en `javascript:` → pas de navigation ; axe sans violation ; SEO `noindex` ; garde : anonyme → `login` avec `redirect` qui **garde la requête** ; compte `ROLE_USER` → `forbidden` ; dépôt HTTP : `POST` avec `credentials: 'include'` et `X-XSRF-TOKEN`.
- [ ] **Step 2 : vérifier l'échec** — `make front-test`.
- [ ] **Step 3 : implémenter** (tranche verticale comme `assistant`, aucun texte brut dans le gabarit).
- [ ] **Step 4 : vérifier** — `make front-test`, `make front-lint`, `make front-build` verts.
- [ ] **Step 5 : commit** `feat(front): page de consentement à une connexion MCP (#647)`.

### Tâche 11 : documentation et décision écrite

**Files:**
- Modify: `docs/adr/0006-serveur-mcp.md` — amendement daté du 2026-10-23 sous D3 : `league/oauth2-server` 9.4.1 **sans le bundle** (paire de clés imposée, contrôleur d'autorisation à session, persistance en clair, authentificateur écarté par D4), jetons d'accès opaques, clé de chiffrement dérivée d'`APP_SECRET` (sa rotation invalide codes et jetons de rafraîchissement en cours), « la personne de league est la connexion » ; et `APP_PUBLIC_API_BASE_URL`, vide hors dev.
- Modify: `.claude/specs/0007-mcp-server.md` — la ligne « sur `league/oauth2-server-bundle` v2 » renvoie à l'amendement ; journal des validations.
- Modify: `.claude/rules/mcp.md` — section « Serveur d'autorisation (phase 2) » : où vit quoi, les invariants (S256, `resource`, empreintes, firewall `oauth` sans cookie, consentement `ROLE_TRUSTED`, métadonnées hors `/api`, Ingress dédié, 404 du frontend) et les tests qui les gardent ; `paths:` étendus à `src/Mcp/Connection/**` et `k8s/base/ingress-oauth-metadata.yaml`.
- Modify: `.claude/rules/security-authentication.md` — deux exclusions CSRF de plus et pourquoi ; `.claude/rules/deploiement-nginx.md` — zone `oauth` (neuf zones) ; `.claude/rules/deploiement.md` — l'objet Ingress dédié.
- Modify: `docs/rgpd/registre-traitements.md` — traitement « connexions MCP » (compte, client, dates ; conservation jusqu'à la purge de la tâche 10).
- Modify: `tasks/todo.md` — T8 en cours, puis cochée à la fusion.

- [ ] **Step 1** — écrire ; `make tools-tests` (`check-claude-rules.sh` : `paths:` qui touchent un fichier suivi, < 50 000 octets).
- [ ] **Step 2 : commit** `docs(mcp): serveur d'autorisation sans le bundle, règles et registre (#647)`.

### Tâche 12 : vérification de bout en bout

- [ ] **Step 1** — `make back-quality`, `make back-test`, `make front-test`, `make front-lint`, `make front-build`, `make back-nginx-rate-limits`, `make front-image-headers` : tous verts, sorties lues.
- [ ] **Step 2 : rejeu du laboratoire contre la stack de dev** (`docs/research/2026-10-22-mcp-oauth-labo/README.md`, adapté) — dans un dossier vide hors du dépôt :
  ```sh
  docker compose exec -T -u dev backend php bin/console app:mcp:client:register \
    --client-id=claude-code-dev --name="Claude Code" \
    --redirect-uri=http://localhost/callback --redirect-uri=http://127.0.0.1/callback
  claude mcp add --transport http --scope local --client-id claude-code-dev cpg-dev http://localhost:8080/api/mcp
  claude mcp logout cpg-dev
  claude mcp login cpg-dev       # à froid : navigateur → consentement (compte ROLE_TRUSTED de dev) → jeton
  claude mcp remove cpg-dev --scope local
  ```
  Attendu : dans les logs `web`, la découverte ne demande que les deux URL canoniques, puis `authorize`, `token` ; `claude mcp login` se termine connecté ; `mcp_connection` porte une ligne. Le compte de dev n'est nommé nulle part dans le dépôt.
- [ ] **Step 3** — cocher les critères de #647 dans le corps de la PR (vers `feature/379-mcp-paliers`, base explicite).
