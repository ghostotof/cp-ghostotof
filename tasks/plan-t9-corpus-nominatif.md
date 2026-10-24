# Spec 0007 — Tâche 9 (#648) : ouverture du corpus nominatif par la connexion MCP — plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** le client MCP d'une personne du palier de confiance, muni de sa connexion MCP, lit le corpus
nominatif ; une connexion révoquée ou expirée, ou un compte privé de `ROLE_TRUSTED`, n'ouvre plus rien à
la requête suivante ; sans connexion, le refus prend la forme que le client sait suivre (401 pour Claude,
erreur d'outil `_meta` pour ChatGPT) ; chaque lecture est au journal d'audit.

**Architecture :** un authentificateur Bearer sur le firewall `mcp` relit en **une requête** le jeton opaque
(par son empreinte), sa connexion, son compte et son client, et pose un utilisateur `McpConnectionUser`
(UUID du compte, de la connexion, identifiant public du client). Un écouteur `kernel.request` placé juste
après le firewall rend le 401 différé sur l'appel de `get_nominative_corpus` sans connexion, dans les deux
ères, sauf requête marquée `openai/`. L'outil lit l'identité par `Security` seulement : avec la connexion,
il rend le corpus et écrit l'audit ; sans, l'erreur d'outil au format ChatGPT. `securitySchemes` est déclaré
sur chaque outil en `_meta` (attribut) et publié aussi au premier niveau par un décorateur du registre.

**Tech Stack :** PHP 8.5, Symfony 8.1 (SecurityBundle, authentificateur maison), `mcp/sdk` 0.8.1 +
`symfony/mcp-bundle` 0.14.1 (pinnés), Doctrine ORM 3, PHPUnit.

**Spec :** `.claude/specs/0007-mcp-server.md` (« Connexion MCP et serveur d'autorisation (phase 2) »,
puces Authentificateur, Outil nominatif, Sans connexion, Journal d'audit ; « Testing Decisions », phase 2)
· ADR 0006 D3 (amendé 2026-10-22), D4, D5, D8 · issue #648 et ses deux commentaires (#664 : les deux ères ;
reports de la tâche 8) · `docs/research/2026-10-22-mcp-autorisation-differee.md` §4.

## Décisions de l'auteur (2026-10-24, cette session)

1. **`securitySchemes` publié aux deux places** : `_meta["securitySchemes"]` (attribut) **et** le champ de
   premier niveau de chaque outil dans `tools/list` (décorateur du registre). ADR 0006 D3 amendé en tâche 6.
2. **Un jeton présenté et refusé est un 401 `error="invalid_token"` sur toute requête** de `/api/mcp`
   (initialize, outil public, outil nominatif, marqueur `openai/` ou non) — règle de la spec MCP et de la
   RFC 6750 ; c'est ce qui fait rafraîchir le client.
3. **Le défi publie l'URL des métadonnées (domaine du site) : nouvelle exception D8**, admise à son chemin
   et pour sa valeur exacte, comme l'URL de `get_contact_info`. Ligne ajoutée au tableau D8 de l'ADR en tâche 5.

## Global Constraints

- Scope unique `McpConnection::SCOPE_NOMINATIVE_READ` (`nominative:read`) ; audience exigée
  `OAuthEndpoints::resource()` (URL canonique de `/api/mcp`, octet pour octet).
- Défi : `Bearer resource_metadata="<OAuthEndpoints::protectedResourceMetadataUrl()>", scope="nominative:read"`,
  plus `, error="invalid_token"` sur un jeton refusé ; dans `_meta["mcp/www_authenticate"]`, le même défi
  plus `, error="insufficient_scope", error_description="…"`. Construit en **un seul endroit** (tâche 4).
- Expiration d'une connexion : **30 jours sans usage** (`lastUsedAt ?? grantedAt`) et **90 jours après
  l'octroi** ; jeton d'accès expiré quand `expiresAt <= now`. Bornes en constantes du domaine ici ; la
  tâche 10 (#649) les passe en configuration et écrit `lastUsedAt` au rafraîchissement.
- **Le marqueur `openai/` ne choisit que la forme du refus, jamais l'accès** (ADR 0006 D3) ; l'accès est
  décidé par l'authentificateur seul (D4). L'outil ne lit jamais `_meta.oauth` ni les middlewares
  d'autorisation du SDK.
- **Aucune écriture par appel** hors de `cache_items` (sessions MCP) : ni `last_used_at`, ni rien d'autre.
- Le firewall `mcp` ne lit **jamais de cookie** ; le seul en-tête lu est `Authorization` au schéma `Bearer`
  (insensible à la casse, RFC 7235). Un `Authorization: Basic` (préprod, Basic Auth de l'Ingress) laisse la
  requête anonyme.
- Rien en clair : ni jeton, ni empreinte, ni identifiant de connexion utilisateur dans un message
  d'exception ; un compte désigné par son UUID seulement (#363) ; `#[SensitiveParameter]` sur tout
  paramètre `token|bearer|authorization…`.
- Aucune config `security` ni route par environnement (#659) ; aucun `%env()%` dans `security.yaml`.
- Règles du dépôt : `declare(strict_types=1)`, `use` triés (`make back-style-fix`), une exception nommée par
  échec, regex en `\z`, chemins par `CanonicalPath`, imports courts, natives qualifiées seulement si
  optimisées. Jamais lire `.env.local`. PHPStan/Rector en `-u dev`.
- Commandes : `make back-test` (ou `docker compose exec -T -u dev backend php bin/phpunit --filter …`),
  `make back-quality`. La garde D8 se lance à part : `--group d8`.
- Règles à lire avec `Read` avant d'écrire : `mcp.md`, `mcp-oauth.md`, `mcp-journaux.md`,
  `mcp-prompt-d8.md`, `security-authentication.md`, `ai.md` (corpus), `backend-integrite.md`,
  `backend-conventions.md`, `backoffice-api.md` (« Authorization », niveaux de log).

## Review Focus

1. **`Authorization: Basic …`** (la préprod l'envoie sur chaque requête derrière son Basic Auth) : la
   requête reste anonyme, jamais un 401 `invalid_token` → test en tâche 3.
2. **Un Bearer de mauvaise forme** (vide, espaces, 79 ou 81 caractères, majuscules, non hexadécimal, 10 Kio)
   : 401 `invalid_token` **sans aucune requête SQL** (forme de league : 80 hexadécimaux minuscules) → test en
   tâche 3.
3. **`ROLE_SUPER` sans `ROLE_TRUSTED` stocké** : hérite du palier par la `role_hierarchy` et doit ouvrir le
   corpus ; un compte de palier de base (`ROLE_USER` seul) est refusé → tests en tâche 3.
4. **Un lot d'un message** (`[{tools/call get_nominative_corpus}]`, servi par le SDK) et un **`Mcp-Name`
   qui contredit le corps** : le 401 se décide sur le corps, lu par la fabrique du SDK → tests en tâche 4.
5. **Le corpus le plus long admis** (`PopplerPdfTextExtractor::MAX_CHARACTERS` = 30 000 caractères de texte de CV, plus les autres
   sections) doit tenir dans `BoundedSessionStore::MAX_BYTES` une fois mis en file par le SDK (ré-encodé
   JSON deux fois) : sinon la session est détruite et le client ne reçoit rien → test en tâche 5.

---

## Fichiers

| Fichier | Rôle |
|---|---|
| `backend/src/Mcp/Connection/Domain/Entity/McpConnection.php` | règle d'expiration (`isExpiredAt()`) |
| `backend/src/Mcp/Connection/Domain/Entity/McpAccessToken.php` | `refusalAt()` : ce qui refuse ce jeton à cet instant |
| `backend/src/Mcp/Connection/Domain/ValueObject/BearerRefusal.php` | enum des causes de refus |
| `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpAccessTokenRepository.php` | `findOneByHash()` joint aussi compte et client |
| `backend/src/Mcp/Connection/Application/McpConnectionPrincipal.php` | interface de l'identité qu'un outil lit par `Security` |
| `backend/src/Mcp/Connection/Application/BearerChallenge.php` | le défi, écrit une fois |
| `backend/src/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticator.php` | l'authentificateur du firewall `mcp` |
| `backend/src/Mcp/Connection/Infrastructure/Security/McpConnectionUser.php` | l'utilisateur posé, `UserInterface` + `AccountReference` + principal |
| `backend/src/Mcp/Connection/Infrastructure/Security/McpConnectionUserProvider.php` | fournisseur nominal du firewall, qui ne charge rien |
| `backend/src/Mcp/Connection/Infrastructure/Security/McpBearerRefusedException.php` | `AuthenticationException` nommée, cause sans valeur |
| `backend/src/Mcp/Application/ProtectedTools.php` | nom de l'outil nominatif, partagé par l'outil et l'écouteur |
| `backend/src/Mcp/Infrastructure/Http/DeferredAuthorizationListener.php` | le 401 différé avant le SDK |
| `backend/src/Mcp/Infrastructure/Registry/SecuritySchemesRegistry.php` + `ToolWithSecuritySchemes.php` | `securitySchemes` au premier niveau |
| `backend/src/Mcp/Presentation/Tool/*.php` | `meta: ['securitySchemes' => …]` sur chaque outil ; `GetNominativeCorpusTool` réécrit |
| `backend/src/Security/Authentication/{Application/SecurityAuditLoggerInterface,Infrastructure/Log/SecurityAuditLogger}.php` | `nominativeCorpusRead()` |
| `backend/config/packages/security.yaml` | fournisseur `mcp_connections`, `custom_authenticators` du firewall `mcp` |
| tests : `backend/tests/Mcp/Support/HoldsAnMcpConnection.php` (nouveau), `tests/Mcp/Connection/…`, `tests/Mcp/Presentation/…`, `tests/Mcp/AuthorNeverNamedTest.php`, `tests/Security/…` | |
| docs : `docs/adr/0006-serveur-mcp.md`, `.claude/rules/{mcp,mcp-oauth,mcp-prompt-d8,security-authentication}.md`, `docs/rgpd/registre-traitements.md`, `tasks/todo.md` | |

---

### Task 1 : ce qui refuse un jeton, dans le domaine

**Files:**
- Create: `backend/src/Mcp/Connection/Domain/ValueObject/BearerRefusal.php`
- Modify: `backend/src/Mcp/Connection/Domain/Entity/McpConnection.php`, `McpAccessToken.php`
- Test: `backend/tests/Mcp/Connection/Domain/Entity/McpTokenEntitiesTest.php` (étendre) ou un nouveau `McpAccessTokenRefusalTest.php`

**Interfaces:**
- Produces :
  - `enum BearerRefusal: string { TokenRevoked = 'token-revoked'; TokenExpired = 'token-expired'; ForeignAudience = 'foreign-audience'; ConnectionRevoked = 'connection-revoked'; ConnectionExpired = 'connection-expired'; AccountNotTrusted = 'account-not-trusted'; Malformed = 'malformed'; Unknown = 'unknown' }` (les deux derniers et `AccountNotTrusted` sont posés par l'authentificateur, tâche 3).
  - `McpConnection::IDLE_LIFETIME = 'P30D'`, `McpConnection::MAX_LIFETIME = 'P90D'` ; `McpConnection::isExpiredAt(DateTimeImmutable $now): bool`.
  - `McpAccessToken::refusalAt(DateTimeImmutable $now, string $expectedAudience): ?BearerRefusal` — dans l'ordre : jeton révoqué, jeton expiré, audience, connexion révoquée, connexion expirée ; `null` = recevable.

- [ ] **Step 1 : tests rouges** — `testAFreshTokenOfALiveConnectionIsAccepted` (`null`) ; `testATokenIsExpiredAtItsExactExpiry` (`expiresAt == now` → `TokenExpired`, une seconde avant → `null`) ; `testARevokedTokenIsRefusedBeforeItsExpiry` ; `testAnotherAudienceIsRefused` (une barre oblique finale de plus → `ForeignAudience`) ; `testARevokedConnectionRefusesItsLiveToken` ; `testAConnectionUnusedFor30DaysIsExpired` (`grantedAt` à now − 30 j → `ConnectionExpired`, − 30 j + 1 s → `null`) ; `testLastUseRestartsTheIdleDelay` (`lastUsedAt` à now − 1 j, `grantedAt` à now − 60 j → `null`) ; `testAConnectionIsExpired90DaysAfterItsGrantWhateverItsUse` (`grantedAt` − 90 j, `lastUsedAt` − 1 h → `ConnectionExpired`). `lastUsedAt` se pose par réflexion dans le test (aucun mutateur avant #649).
- [ ] **Step 2 : vérifier l'échec** — `--filter McpAccessTokenRefusalTest` : méthodes absentes.
- [ ] **Step 3 : implémenter** — durées par `add(new DateInterval(…))` (jamais `modify`), bornes strictes comme ci-dessus ; docblock : `lastUsedAt` n'est écrit qu'à partir de #649 (avant, une connexion meurt 30 jours après l'octroi, accepté), et les constantes y passeront en configuration.
- [ ] **Step 4 : vérifier le vert**, puis `make back-quality` sur les fichiers touchés.
- [ ] **Step 5 : commit** — `feat(mcp): ce qui refuse un jeton d'accès à un instant donné (#648)`.

### Task 2 : la relecture en une requête

**Files:**
- Modify: `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpAccessTokenRepository.php` (`findOneByHash`), son interface (docblock)
- Test: `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpAccessTokenRepositoryTest.php` (nouveau)

**Interfaces:**
- Consumes : `McpAccessTokenRepositoryInterface::findOneByHash(string $tokenHash): ?McpAccessToken`.
- Produces : la même signature, le graphe jeton → connexion → compte (`roles` compris) et → client **hydraté** par la même requête (`addSelect` + `innerJoin` explicites).

- [ ] **Step 1 : test rouge** — `testOneQueryLoadsTheTokenItsConnectionItsAccountAndItsClient` : une connexion et un jeton écrits puis `clear()` de l'EntityManager ; compter les requêtes du canal `doctrine` (`debug`, comme `CpgUserRepositoryTest::cpgUserQueries()`, avec son témoin positif) autour de `findOneByHash()` **puis** de `connection()->account()->getRoles()`, `accountId()`, `connection()->client()->getClientId()` → exactement 1. `testAnUnknownHashFindsNothing` → `null`.
- [ ] **Step 2 : vérifier l'échec** — aujourd'hui 3 requêtes (compte et client chargés à la demande).
- [ ] **Step 3 : implémenter** — `->addSelect('account', 'client')->innerJoin('connection.account', 'account')->innerJoin('connection.client', 'client')`.
- [ ] **Step 4 : vert.**
- [ ] **Step 5 : commit** — `perf(mcp): le jeton, sa connexion, son compte et son client en une requête (#648)`.

### Task 3 : l'authentificateur Bearer du firewall `mcp`

**Files:**
- Create: `Mcp/Connection/Application/McpConnectionPrincipal.php`, `Mcp/Connection/Application/BearerChallenge.php`, `Mcp/Connection/Infrastructure/Security/{McpBearerAuthenticator,McpConnectionUser,McpConnectionUserProvider,McpBearerRefusedException}.php`
- Modify: `backend/config/packages/security.yaml` (provider `mcp_connections`, `mcp.provider`, `mcp.custom_authenticators`, commentaire du firewall), `backend/config/packages/framework.yaml` si une exception nouvelle demande un `log_level`
- Create: `backend/tests/Mcp/Support/HoldsAnMcpConnection.php` (trait : une connexion accordée par le vrai parcours de `DrivesTheOAuthFlow`, rend le jeton d'accès)
- Test: `backend/tests/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticatorTest.php` (fonctionnel, `KernelBrowser` sur `/api/mcp`), `BearerChallengeTest.php` (unitaire)

**Interfaces:**
- Produces :
  - `interface McpConnectionPrincipal extends AccountReference { public function connectionId(): Uuid; public function clientId(): string; }`
  - `final readonly class McpConnectionUser implements UserInterface, McpConnectionPrincipal` — `getUserIdentifier()` = UUID RFC 4122 de la **connexion** (jamais le `username`), `getRoles()` = rôles stockés du compte (`CpgUser::getRoles()`), `accountId()`, `connectionId()`, `clientId()` (identifiant public OAuth).
  - `final readonly class BearerChallenge` (service) : `missingToken(): string`, `refusedToken(): string` (+ `error="invalid_token"`), `insufficientScope(string $description): string` (+ `error="insufficient_scope", error_description="…"`) ; valeurs entre guillemets, la description sans `"` ni `\` (RFC 7235 quoted-string : refusée sinon, exception nommée).
  - `McpBearerAuthenticator` : `supports()` vrai seulement si `Authorization` commence par `Bearer` (schéma insensible à la casse, suivi d'un espace ou seul) ; `authenticate()` — forme `^[0-9a-f]{80}\z` vérifiée **avant** toute requête (`Malformed`), puis `TokenIdentifierHash::of()`, `findOneByHash()` (`Unknown`), `refusalAt($clock->now(), $endpoints->resource())`, puis `ROLE_TRUSTED` atteint par `RoleHierarchyInterface::getReachableRoleNames()` (`AccountNotTrusted`) ; `SelfValidatingPassport(new UserBadge(<uuid connexion>, fn () => $user))`. `onAuthenticationFailure()` → `Response` 401 sans corps, `WWW-Authenticate: <refusedToken()>`, `Cache-Control: no-store`, passée par `ServerAnswersInterface::record()` (le journal d'appels la lit comme une réponse du serveur) ; `onAuthenticationSuccess()` → `null`.
  - `McpBearerRefusedException extends AuthenticationException` : `because(BearerRefusal $cause)`, message constant `MCP access token refused: <cause>` (jamais le jeton, ni l'empreinte, ni un UUID).
  - `security.yaml` : `providers.mcp_connections: { id: App\Mcp\Connection\Infrastructure\Security\McpConnectionUserProvider }` (`loadUserByIdentifier` lève `UserNotFoundException` sans citer l'identifiant, `refreshUser` `UnsupportedUserException` : le badge porte son chargeur, le firewall est sans état) ; `firewalls.mcp: { pattern: ^/api/mcp$, stateless: true, provider: mcp_connections, custom_authenticators: [App\Mcp\Connection\Infrastructure\Security\McpBearerAuthenticator] }`.

- [ ] **Step 1 : tests rouges** (`BearerChallengeTest`) — les trois défis exacts, l'URL lue d'`OAuthEndpoints` (base de test) ; une description à guillemet refusée.
- [ ] **Step 2 : tests rouges** (`McpBearerAuthenticatorTest`, appel `tools/call get_contact_info` dans une session, outil public sans base de données) :
  - `testAValidTokenAuthenticatesTheConnection` — 200 ; puis lire `security.token_storage` du conteneur après la requête : un `McpConnectionUser` dont `accountId()`, `connectionId()` et `clientId()` sont ceux du compte, de la connexion et du client ;
  - `testARevokedConnectionIsRefusedAtTheNextRequest`, `testAnAccountThatLostTrustIsRefusedAtTheNextRequest` (rôles réécrits à `[]` en SQL), `testAnExpiredConnectionIsRefusedAtTheNextRequest` (`granted_at` reculé de 91 jours en SQL) — chacun : une première requête 200, l'altération, la suivante 401 avec `error="invalid_token"` ;
  - `testAnExpiredTokenIsRefused` (`expires_at` reculé en SQL — le report de la tâche 8 : league ne regarde pas l'échéance d'un jeton opaque), `testARevokedTokenIsRefused`, `testATokenOfAnotherAudienceIsRefused` (`audience` réécrite) ;
  - `testASuperAdminWithoutStoredTrustIsAccepted` (rôles `["ROLE_SUPER"]`) ; `testABaseTierAccountIsRefused` (rôles `[]`) ;
  - Review Focus 1 — `testABasicAuthorizationHeaderLeavesTheRequestAnonymous` (200, aucun `WWW-Authenticate`) ;
  - Review Focus 2 — `testAMalformedBearerIsRefusedWithoutAnyQuery` (data provider : `Bearer`, `Bearer `, 79 et 81 hex, majuscules, `g`, 10 Kio) : 401 `invalid_token`, zéro requête SQL sur les tables `mcp_*` (canal `doctrine`, témoin positif) ;
  - `testARefusedTokenIs401OnEveryRequest` (décision 2) : `initialize`, `tools/list`, `tools/call get_about`, et sous l'enveloppe 2026-07-28 (`postModernMcp()`), avec et sans `_meta["openai/subject"]` ;
  - `testNoRequestWritesOutsideTheMcpSessions` : un `tools/call` authentifié, puis aucune ligne modifiée ailleurs que dans `cache_items` (comparer `last_used_at`, et compter les `INSERT|UPDATE|DELETE` du canal `doctrine` hors `cache_items`) ;
  - `testAnAuthenticatedCallCostsExactlyOneQueryMore` : le même `tools/call get_contact_info` (même session), anonyme puis authentifié — requêtes du canal `doctrine` : exactement +1 ;
  - `testATrustedBearerCookieWithoutAuthorizationStaysAnonymous` (cookie `BEARER` de confiance, pas d'en-tête : anonyme, rien de lu).
- [ ] **Step 3 : vérifier l'échec** — `--filter 'McpBearerAuthenticatorTest|BearerChallengeTest'` : classes absentes, puis 200 au lieu de 401.
- [ ] **Step 4 : implémenter** — selon les interfaces ; `ClockInterface` injectée ; `RoleHierarchyInterface` (`security.role_hierarchy`) ; `#[SensitiveParameter]` sur tout paramètre qui porte le jeton ou l'en-tête. Si `McpBearerRefusedException` atteint le noyau, son `log_level` (`info`) dans `framework.exceptions` ; sinon le dire dans le docblock (l'`AuthenticatorManager` la rattrape, comme `SubmittedPasswordTooLongException`).
- [ ] **Step 5 : vert**, puis `ApiRouteExposureTest`, `AccessControlAnchoringTest`, `SecurityConfigEnvironmentParityTest`, `McpEndpointTest`, `McpCallLogTest`, `AccountIdentifierReachesNoLogTest` verts ; `make back-quality`.
- [ ] **Step 6 : étendre `AccountIdentifierReachesNoLogTest`** — un `tools/call` authentifié par une connexion du compte sentinelle, puis un jeton refusé : aucun canal ne contient `sentinelle` (le canal `security` écrit `Authenticator successful!` à chaque requête authentifiée, réécrit par `SecurityChannelAccountRedactor` — vérifier qu'il désigne un `AccountReference` par son `userId`).
- [ ] **Step 7 : règles** — `.claude/rules/mcp.md` (puce firewall : l'authentificateur est là, toujours aucun cookie), `.claude/rules/mcp-oauth.md` (une puce « L'authentificateur de `/api/mcp` » : une requête, causes, Basic laissé anonyme, 401 sur toute requête — décision 2 —, `bearer_methods_supported` désormais vrai : retirer l'« annonce en avance » correspondante), `.claude/rules/security-authentication.md` (le firewall `mcp` a un authentificateur).
- [ ] **Step 8 : commit** — `feat(mcp): authentificateur Bearer de la connexion MCP sur /api/mcp (#648)`.

### Task 4 : le 401 différé avant le SDK

**Files:**
- Create: `backend/src/Mcp/Application/ProtectedTools.php`, `backend/src/Mcp/Infrastructure/Http/DeferredAuthorizationListener.php`
- Test: `backend/tests/Mcp/Infrastructure/Http/DeferredAuthorizationListenerTest.php` (fonctionnel), `DeferredAuthorizationListenerPriorityTest.php` (sur le vrai répartiteur)

**Interfaces:**
- Consumes : `BearerChallenge::missingToken()`, `McpConnectionPrincipal`, `ServerAnswersInterface::record()`, `McpEndpoint::PATH`, `CanonicalPath::of()`.
- Produces :
  - `final class ProtectedTools { public const string NOMINATIVE_CORPUS = 'get_nominative_corpus'; public const string OPENAI_META_PREFIX = 'openai/'; }`
  - `#[AsEventListener(event: RequestEvent::class, priority: 7)] final readonly class DeferredAuthorizationListener` — **après** le firewall (8), avant le contrôleur : requête principale, `POST`, chemin canonique `McpEndpoint::PATH` ; corps lu par `MessageFactory::make()->create($body)` (comme `ModernExchange::typedMessage()`, exceptions rattrapées → rien) ; si **un** des messages est un `CallToolRequest` dont `name === ProtectedTools::NOMINATIVE_CORPUS`, que l'utilisateur du `TokenStorage` n'est pas un `McpConnectionPrincipal`, et qu'aucune clé du `_meta` de ce message (`getMeta()`) ne commence par `openai/` → `Response` 401 sans corps, `WWW-Authenticate: <missingToken()>`, `Cache-Control: no-store`, passée par `ServerAnswersInterface::record()`. Jamais l'en-tête `Mcp-Name`.

- [ ] **Step 1 : tests rouges**, chacun dans une session (`2025-06-18`) **et** sous l'enveloppe `2026-07-28` (data provider sur l'ère) :
  - `testAnAnonymousCallOfTheNominativeToolIs401` — 401, défi exact (`resource_metadata` canonique, `scope`), aucun `error=`, corps vide, `no-store` ;
  - `testAMarkedAnonymousCallPassesToTheTool` — `_meta: {"openai/subject": "x"}` → pas de 401 (la réponse de l'outil est la tâche 5 ; ici, le SDK a répondu : 200) ;
  - `testAPublicToolIsNeverDeferred` — `get_about` anonyme : 200 ;
  - `testAnAuthenticatedCallIsNotDeferred` — jeton valide (`HoldsAnMcpConnection`) : pas de 401 ;
  - Review Focus 4 — `testAOneMessageBatchIsJudgedLikeASingleMessage` (`[{…}]` → 401) ; `testTheBodyDecidesNotTheMcpNameHeader` (`Mcp-Name: get_about` et corps nominatif → 401 ; `Mcp-Name: get_nominative_corpus` et corps `get_about` → 200) ;
  - `testAnUnreadableBodyIsLeftToTheSdk` (`{` → pas de 401, `-32700` du SDK) ;
  - `testThe401LeavesACallLine` — `McpCallLog` : dans une session, la ligne de repli (`httpStatus` 401, `protocol-error`) ; sous l'enveloppe moderne, une ligne d'appel `tools/call` `get_nominative_corpus`, `protocol-error`. Si l'un des deux n'écrit rien, s'arrêter et le signaler (décision de journal).
  - `DeferredAuthorizationListenerPriorityTest` : sur le vrai répartiteur (`kernel.request`), l'écouteur est appelé **après** le `FirewallListener` de SecurityBundle (priorité 8 : l'identité est posée) — l'ordre relatif est épinglé, pas la valeur 7 seule.
- [ ] **Step 2 : vérifier l'échec** — 200 partout (l'outil répond l'erreur de la phase 1).
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vert** ; `InboundMessageRefusalTest`, `McpCallLogTest`, `NoAnswerIsAStreamTest`, `McpEndpointGetTest` verts ; `make back-quality`.
- [ ] **Step 5 : règle** — `.claude/rules/mcp.md`, une puce « Le 401 différé » (priorité 7, après le firewall ; décidé sur le corps par la fabrique du SDK, jamais `Mcp-Name` ; le marqueur `openai/` ne choisit que la forme ; journal).
- [ ] **Step 6 : commit** — `feat(mcp): 401 différé sur l'outil nominatif sans connexion, dans les deux ères (#648)`.

### Task 5 : l'outil nominatif, son audit, et la garde D8

**Files:**
- Modify: `backend/src/Mcp/Presentation/Tool/GetNominativeCorpusTool.php`
- Modify: `SecurityAuditLoggerInterface.php`, `SecurityAuditLogger.php` (`nominativeCorpusRead`)
- Modify: `backend/tests/Mcp/Presentation/Tool/GetNominativeCorpusToolTest.php`, `backend/tests/Mcp/AuthorNeverNamedTest.php`, `backend/tests/Security/Authentication/Infrastructure/Log/SecurityAuditLoggerTest.php`, `backend/tests/Mcp/McpExposureSymmetryTest.php` (raison de `TOOLS_WITHOUT_ROUTE` : l'outil n'est plus « sans dépendance »), `AnnouncedCapabilitiesTest`/`NoAnswerIsAStreamTest` si un appel anonyme y casse
- Modify: `docs/adr/0006-serveur-mcp.md` (ligne D8, décision 3), `docs/rgpd/registre-traitements.md` (§3.4 destinataires, §6 événement, §8 transfert hors UE), `.claude/rules/mcp.md` (puces catalogue et outils), `.claude/rules/mcp-prompt-d8.md`, `.claude/rules/security-authentication.md` (liste des événements)

**Interfaces:**
- Consumes : `McpConnectionPrincipal`, `BearerChallenge::insufficientScope()`, `ProtectedTools`, `CorpusRendererInterface::render(Locale): string`, `JsonToolResult::forObject()`, `ToolLocale::parse()`.
- Produces :
  - `SecurityAuditLoggerInterface::nominativeCorpusRead(AccountReference $account, Uuid $connectionId, string $clientId, string $tool): void` → événement `nominative-corpus-read`, message `Nominative corpus read.`, champs `userId`, `connectionId`, `clientId`, `tool` (acteur : celui du jeton, donc l'UUID du compte par `PrincipalDesignation`).
  - `GetNominativeCorpusTool::__invoke(RequestContext $context, string $locale = ToolLocale::DEFAULT): CallToolResult` (`RequestContext` injecté par le SDK, absent de l'`inputSchema`) ; constructeur : `Security`, `CorpusRendererInterface`, `SecurityAuditLoggerInterface`, `BearerChallenge`, `#[Autowire('%app.frontend_base_url%')] string $frontendBaseUrl`. `name: ProtectedTools::NOMINATIVE_CORPUS`, `outputSchema` fermé : `corpus` et `cvPageUrl`, chaînes requises.
  - Avec un `McpConnectionPrincipal` : `JsonToolResult::forObject(['corpus' => $renderer->render($locale), 'cvPageUrl' => $frontendBaseUrl.'/'.$locale->value.'/assistant'])`, puis l'audit (après le rendu : une lecture journalisée est une lecture faite). Sans : `CallToolResult::error([new TextContent(self::CONNECTION_REQUIRED)], meta: ['mcp/www_authenticate' => [$challenge->insufficientScope(self::CONNECTION_REQUIRED_DESCRIPTION)]])`, **marqué ou non** (le 401 a déjà répondu au non marqué ; ce défaut ne s'atteint que si l'écouteur manque). Forme de la valeur de `mcp/www_authenticate` (liste de défis) à vérifier contre https://developers.openai.com/apps-sdk/reference et citée dans le docblock. Textes anglais, sans l'auteur (D8) : `CONNECTION_REQUIRED` dit de connecter son compte au site depuis son client MCP puis de rappeler l'outil.

- [ ] **Step 1 : tests rouges** (`GetNominativeCorpusToolTest`, avec le `CorpusRenderer` du CV fictif, comme les tests fonctionnels de l'assistant — `.claude/rules/ai.md`) :
  - `testAValidConnectionReadsTheCorpusInEachLanguage` (fr, en) : `structuredContent.corpus` égal à `render()` de la langue, `cvPageUrl` = `<app.frontend_base_url>/<langue>/assistant`, bloc texte identique, schéma validé par le `SchemaValidator` du SDK ; jamais de PDF ni de `resource` ;
  - `testEachReadIsAudited` — une ligne `nominative-corpus-read` par appel, `actor` et `userId` = UUID du compte, `connectionId`, `clientId` (public), `tool` = `get_nominative_corpus` ; aucune ligne sur un refus ;
  - `testAMarkedAnonymousCallGetsTheChatGptRefusal` — 200, `isError: true`, `_meta["mcp/www_authenticate"]` = défi `insufficient_scope` complet, texte `CONNECTION_REQUIRED`, rien du corpus (aucune sous-chaîne du corpus fictif) ;
  - `testTheMarkerDecidesNoAccess` (data provider : sans jeton / jeton valide × marqué / non marqué) : sans jeton, jamais le corpus (401 ou erreur) ; avec, le corpus dans les deux cas ;
  - `testAnUnknownLanguageStaysAToolError` (avec connexion) ;
  - Review Focus 5 — `testTheLongestCorpusFitsInTheSession` : un `CorpusRendererInterface` de test rendant `PopplerPdfTextExtractor::MAX_CHARACTERS` caractères de 4 octets (plus la marge des autres sections, mesurée sur le rendu fictif), appelé dans une session : la réponse arrive, et la taille mise en file (calcul de `RouteBackedToolsTest::testTheAnswerQueuedInTheSessionStaysFarBelowTheSessionBound`) reste sous `BoundedSessionStore::MAX_BYTES`. Si elle le dépasse, s'arrêter : c'est une décision (borne de session ou du corpus).
  - `SecurityAuditLoggerTest` : la nouvelle méthode dans `testNoContextValueEverCarriesAPasswordATokenOrAnEmail`.
- [ ] **Step 2 : vérifier l'échec.**
- [ ] **Step 3 : implémenter** l'audit puis l'outil.
- [ ] **Step 4 : la garde D8** (`AuthorNeverNamedTest`, décision 3) — l'appel anonyme non marqué rend désormais un 401 : `toolAnswers()` lit l'outil nominatif **marqué** `openai/` (erreur, `_meta` compris) et la surface `401 get_nominative_corpus` (l'en-tête `WWW-Authenticate`) ; jamais la sortie authentifiée (le corpus nomme l'auteur, c'est son objet). `admitted()` reçoit l'URL des métadonnées aux chemins du défi (en-tête et `_meta`), pour sa valeur exacte ; `testEverySurfaceTheServerWritesIsRead` liste les nouvelles surfaces. ADR 0006 D8 : une ligne au tableau (« Défi d'authentification… `resource_metadata` », 2026-10-24, tâche 9). Lancer `--group d8`.
- [ ] **Step 5 : vert** — `GetNominativeCorpusToolTest`, `AuthorNeverNamedTest` (`--group d8`), `McpExposureSymmetryTest`, `ConnectAssistantPageMirrorsCatalogueTest`, `SecurityAuditLog*Test`, `CorpusSourcesTest` ; `make back-quality`.
- [ ] **Step 6 : docs** — registre RGPD : §3.4 (le corpus nominatif part vers le client MCP et son fournisseur, ADR 0006 D5), §6 (`nominative-corpus-read` et ses champs), §8 (transfert hors UE : le fournisseur d'IA du client reçoit le corpus et les jetons transitent par lui — report de la tâche 8), date de mise à jour ; règles `mcp.md` (l'outil nominatif n'est plus une erreur littérale ; il dépend de `Security` et du rendu du corpus), `mcp-prompt-d8.md` (nouvelles surfaces, nouvelle exception), `security-authentication.md` (événement). `Mcp/Presentation` dépend désormais de `SecurityAuditLoggerInterface` : la même porte que `ConsentService` (`mcp-oauth.md`, « An assumed exception… ») — l'écrire.
- [ ] **Step 7 : commit** — `feat(mcp): le corpus nominatif ouvert par la connexion MCP, lecture journalisée (#648)`.

### Task 6 : `securitySchemes` sur chaque outil, aux deux places

**Files:**
- Modify: les onze outils de `backend/src/Mcp/Presentation/Tool/` (`#[McpTool(meta: [...])]`)
- Create: `backend/src/Mcp/Infrastructure/Registry/SecuritySchemesRegistry.php`, `ToolWithSecuritySchemes.php`, une exception nommée pour un outil sans schéma
- Test: `backend/tests/Mcp/Presentation/Tool/SecuritySchemesTest.php` (fonctionnel), `backend/tests/Mcp/Infrastructure/Registry/SecuritySchemesRegistryTest.php` (unitaire)
- Modify: `docs/adr/0006-serveur-mcp.md` (D3 : la place tranchée), `.claude/rules/mcp.md`

**Interfaces:**
- Produces :
  - Sur chaque outil public : `meta: ['securitySchemes' => [['type' => 'noauth']]]` ; sur l'outil nominatif : `meta: ['securitySchemes' => [['type' => 'oauth2', 'scopes' => [McpConnection::SCOPE_NOMINATIVE_READ]]]]`. Une seule source : l'attribut.
  - `#[AsDecorator('mcp.server.portfolio.registry')] final class SecuritySchemesRegistry implements RegistryInterface` — délègue les 24 méthodes ; `getTools()` remplace chaque `Tool` de la page par un `ToolWithSecuritySchemes` qui recopie tous ses champs et ajoute `securitySchemes` au premier niveau de `jsonSerialize()`, valeur lue dans son `_meta["securitySchemes"]` ; un outil sans cette clé → exception nommée (serveur, `-32603`), jamais un outil publié sans schéma. `getTool()` et l'appel restent ceux du registre décoré.
  - `ToolWithSecuritySchemes extends Mcp\Schema\Tool` (non `final` en 0.8.1).

- [ ] **Step 1 : tests rouges** — `SecuritySchemesTest::testEveryListedToolDeclaresItsSchemesAtBothPlaces` (dans les deux ères : `tools/list` puis `server/discover` s'il liste les outils) : pour chaque outil, `securitySchemes` == `_meta.securitySchemes`, `[{type: noauth}]` sauf `get_nominative_corpus` `[{type: oauth2, scopes: [nominative:read]}]` ; `testCallsStillReachTheTools` (un `tools/call get_about`) ; `SecuritySchemesRegistryTest` : un outil sans `_meta` → exception ; tous les champs de `Tool` (titre, icônes, annotations, schémas, `_meta`) survivent à la recopie (comparer `jsonSerialize()` moins `securitySchemes`), pour qu'une montée du SDK qui ajoute un champ le fasse rouge.
- [ ] **Step 2 : vérifier l'échec.**
- [ ] **Step 3 : implémenter** ; vérifier l'identifiant du service par `bin/console debug:container mcp.server.portfolio.registry`.
- [ ] **Step 4 : vert** — plus `AuthorNeverNamedTest` (`--group d8` : il lit `tools/list`), `ConnectAssistantPageMirrorsCatalogueTest`, `McpExposureSymmetryTest`, `AnnouncedCapabilitiesTest`, `McpCallLogTest` ; `make back-quality`.
- [ ] **Step 5 : docs** — ADR 0006 D3 : « *Tranché le 2026-10-24 (#648)* : aux deux places… » avec la raison (la référence d'OpenAI tient `_meta` pour un miroir de rétrocompatibilité ; ChatGPT fige la liste à l'approbation) et le coût (un décorateur du registre) ; `mcp.md`, puce « Tools ».
- [ ] **Step 6 : commit** — `feat(mcp): securitySchemes publiés sur chaque outil, en _meta et au premier niveau (#648)`.

### Task 7 : vérification de la branche et suivi

- [ ] `make back-quality` et `make back-test` verts, plus `--group d8` ; `tools/check-claude-rules.sh` (taille des règles : `mcp.md` et `mcp-oauth.md` approchent 50 000 octets — découper par sous-domaine si l'un dépasse, l'index en tête de `mcp.md`).
- [ ] `tasks/todo.md` : tâche 9 cochée à la fusion seulement (pas dans cette branche avant la PR), reports pour #649 (`lastUsedAt` au rafraîchissement, durées en configuration) et #653 (vérifier en préprod ce que ChatGPT lit de `securitySchemes`, et le 401 sur toute requête au jeton refusé).
- [ ] Commit `docs(tasks): …` si `todo.md` change.
