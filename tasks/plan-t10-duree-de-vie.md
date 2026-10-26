# Tâche 10 (#649) — Durée de vie des jetons, rotation et purge : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** une connexion MCP se renouvelle sans nouveau consentement tant qu'elle vit, meurt après 30 jours
sans usage ou 90 jours après l'octroi, et tout rejeu — d'un rafraîchissement tourné ou d'un code échangé — la
coupe ; jetons, codes et connexions mortes sont purgés chaque jour.

**Architecture :** la ligne de la connexion est **verrouillée** (`FOR UPDATE`, relue sous verrou) au point de
jeton avant que le code ou le rafraîchissement soit jugé : ce seul verrou ferme les trois courses relevées par
#647 (deux échanges d'un code, deux rafraîchissements d'un jeton, une révocation pendant un rafraîchissement).
Un rejeu détecté lève une exception de domaine qui annule la transaction de league ; `TokenEndpoint` révoque
alors la connexion **à part** (une instruction SQL, valide même EntityManager fermé), écrit l'audit, et répond
le refus que league aurait rendu. Une connexion se révoque toujours par une instruction `UPDATE … WHERE
revoked_at IS NULL RETURNING`, avec sa cause en colonne : une seule fois, une seule ligne d'audit.

**Tech Stack :** PHP 8.5, Symfony 8.1, Doctrine ORM 3 (DQL, SQL natif DBAL), PostgreSQL 18,
`league/oauth2-server` 9.4.1, PHPUnit, Kubernetes (CronJob).

**Spec :** `.claude/specs/0007-mcp-server.md` (« Durées », « Purge », « Modèle », « Journal d'audit ») ; ADR
`docs/adr/0006-serveur-mcp.md` D3/D4 ; ticket #649 et son commentaire de reports ; reports de T8/T9 dans
`tasks/todo.md` (tâche 10).

## Décisions prises avec Christophe (2026-10-26)

1. **Périmètre** : le ticket, plus tous ses reports (importants avant release, robustesse league/Doctrine,
   journaux et tests annexes, accord qui remplace l'ancien).
2. **Un nouvel accord du même couple compte/client révoque l'accord actif précédent** (cause `superseded`).
   Prix accepté : deux postes avec le même client pré-enregistré ne restent pas connectés ensemble.
3. **Révoquer un jeton de rafraîchissement au point de révocation révoque la connexion** (cause `client`,
   RFC 7009 §2.1). Conséquence voulue : un rafraîchissement révoqué présenté sur une connexion encore active ne
   peut venir que d'une rotation — sa réutilisation se reconnaît sans ambiguïté.
4. **Rétention des connexions révoquées ou expirées : 6 mois** (paramètre, `--older-than` lu par
   `RetentionPeriod`).
5. **Colonne `revocation_cause`** ; la purge quotidienne marque une fois chaque connexion expirée (cause
   `expired`, audit acteur `system`).
6. Décidé dans ce plan, à relire : **un rejeu séquentiel révoque aussi** (un code échangé deux fois l'un après
   l'autre, OAuth 2.1 §4.1.2) ; **pas de `--dry-run`** pour la purge (elle ne supprime que des jetons morts et
   des connexions mortes depuis 6 mois, rien qu'une personne pourrait regretter — la purge des invitations a le
   sien parce qu'elle supprime des comptes) ; **la révocation d'une connexion ne touche pas les lignes de ses
   jetons** : tout lecteur juge la connexion (`isRevoked()`), `RevokedOnce` reste la marque d'usage unique
   (code) et de rotation (rafraîchissement). C'est la réponse au report « `RevokedOnce` contre
   `McpConnection::revokedAt` ».

## Global Constraints

- Durées : code `PT10M`, jeton d'accès `PT1H`, rafraîchissement `P30D` avec rotation à chaque usage,
  connexion expirée après `P30D` sans usage et au plus `P90D` après l'octroi — **en configuration**
  (paramètres de `config/services.yaml`), **une seule valeur pour tous les environnements**, sans `%env()%`
  (#659 : `SecurityServicesEnvironmentParityTest` compare toute définition `App\`).
- Dernier usage écrit **au rafraîchissement seulement, au plus une fois par heure écoulée** (`PT1H`) ; jamais
  par appel de `/api/mcp` (`testNoRequestWritesOutsideTheMcpSessions`,
  `testAnAuthenticatedCallCostsExactlyOneQueryMore` restent verts).
- Aucun jeton, code, identifiant de jeton ni empreinte dans un message d'exception, un journal ou l'audit ; un
  compte se désigne par `userId` (UUID) seulement (#363).
- Audit : un événement `mcp-connection-revoked` (champs `userId`, `connectionId`, `clientId`, `cause`), causes
  `client`, `refresh-token-reuse`, `code-replay`, `superseded`, `expired` (#650 ajoutera `person`) ; un
  événement `mcp-access-token-refused` (`userId`, `connectionId`, `clientId`, `cause` = valeur de
  `BearerRefusal`), jamais pour `Malformed` ni `Unknown`. L'audit s'écrit **après** le COMMIT.
- Horodatages `datetimetz_immutable`, paramètres comparés typés `Types::DATETIMETZ_IMMUTABLE` ; durées ajoutées
  ou retranchées par `new DateInterval(…)`, jamais `modify()` (#445).
- Les refus OAuth restent ceux de league, mot pour mot : rafraîchissement réutilisé →
  `OAuthServerException::invalidRefreshToken('Token has been revoked')` ; code rejoué →
  `OAuthServerException::invalidGrant('Authorization code has been revoked')`. Un attaquant n'apprend rien de
  plus qu'avant.
- Exceptions nommées (aucune SPL nue), `#[SensitiveParameter]` sur tout paramètre de jeton, `use` triés
  (`make back-style-fix`), regex en `\z`, commentaires en français, identifiants en anglais.
- Tests : `docker compose restart backend` avant chaque lancement (désynchronisation du montage, mémoire),
  puis `docker compose exec -u dev backend php bin/phpunit --filter '<Classe>'` (`-u dev` : sinon le cache de
  PHPStan casse). Vérification finale : `make back-quality` et `make back-test`.
- Règles à lire **par `Read`** avant d'écrire dans leur zone : `.claude/rules/mcp-oauth.md`,
  `security-authentication.md`, `backend-architecture.md` (migration, horodatages), `deploiement-postgres.md`
  et `deploiement-pipeline.md` (migration), `deploiement.md` (CronJob), `backend-outillage.md` (harnais de
  concurrence).
- **Une règle neuve porte ce que T10 ajoute** : `.claude/rules/mcp-oauth-cycle-de-vie.md` (`paths:` = ceux de
  `mcp-oauth.md` plus `k8s/base/messenger-purge-cronjob.yaml`), créée à la tâche 1, pointée depuis
  `mcp-oauth.md` et ajoutée à la table de `.claude/CLAUDE.md` : `mcp-oauth.md` pèse 34 Ko, T10 et #650-#652 le
  pousseraient vers la borne de 50 000 octets de `tools/check-claude-rules.sh`.
- Messages de commit en français, suffixe `(#649)`, terminés par
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Un commit par tâche, fichiers nommés.

## Review Focus

1. **Un client qui rejoue son rafraîchissement après une réponse perdue** (coupure réseau après le COMMIT)
   présente un jeton déjà tourné : la connexion est révoquée et la personne doit se reconnecter. C'est le prix
   que la rotation OAuth 2.1 accepte, à documenter, pas à contourner — épinglé par
   `RefreshRotationTest::testReusingARotatedRefreshTokenRevokesTheConnection` (tâche 3), dont le docblock le
   dit.
2. **Un rafraîchissement présenté sous un autre `client_id`** doit être refusé par league (« not linked to
   client ») **sans** révoquer la connexion de son vrai client : sinon quiconque connaît un jeton volé coupe la
   connexion d'autrui sans pouvoir l'utiliser — `testARefreshTokenPresentedByAnotherClientLeavesTheConnectionAlone`
   (tâche 3).
3. **Une connexion expirée ou un compte déchu qui rafraîchit** ne doit obtenir aucun jeton neuf (la boucle
   refus/rafraîchissement du report B1 de T9, lignes accumulées) — `testAnExpiredConnectionCannotRefresh`,
   `testAConnectionPastItsMaximumCannotRefresh`, `testAnAccountNoLongerTrustedCannotRefresh` comptent les
   lignes de jetons avant et après (tâche 3).
4. **Les échéances au changement d'heure** : 30 jours de calendrier à Paris durent 721 heures à l'automne ;
   l'échéance tombe à la même heure murale, ni une heure avant ni une heure après —
   `ConnectionLifetimesTest::testThresholdsCountCalendarDaysAcrossTheAutumnClockChange`,
   `McpConnectionExpiryTest` (tâche 1).
5. **La purge et l'authentificateur doivent juger la même échéance à la seconde près** : une connexion que
   `isExpiredAt()` dit vivante ne doit pas être marquée expirée par la purge, ni l'inverse —
   `McpConnectionPurgerTest::testThePurgeAndIsExpiredAtAgreeAtTheBoundary` (tâche 9), et une seule définition
   de l'échéance, `McpConnection::isExpiredAt()`, que la purge applique ligne par ligne après une présélection SQL
   (*amendé en cours d'exécution, relecture de la tâche 1* : les seuils `now − durée` n'étaient pas monotones
   pendant l'heure répétée d'automne).

---

### Tâche 1 : les durées en configuration (`ConnectionLifetimes`)

**Files :**
- Create : `backend/src/Mcp/Connection/Domain/ValueObject/ConnectionLifetimes.php`
- Create : `backend/src/Mcp/Connection/Domain/Exception/InvalidConnectionLifetimeException.php`
- Create : `.claude/rules/mcp-oauth-cycle-de-vie.md`
- Modify : `backend/config/services.yaml` (paramètres + service par fabrique)
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpConnection.php` (constantes `IDLE_LIFETIME`/`MAX_LIFETIME` retirées)
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpAccessToken.php` (`refusalAt()`)
- Modify : `backend/src/Mcp/Connection/Infrastructure/League/AuthorizationServerFactory.php` (constantes de durée retirées)
- Modify : `backend/src/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticator.php`
- Modify : `.claude/rules/mcp-oauth.md` (« Durations live there only » → renvoi vers la règle neuve), `.claude/CLAUDE.md` (table des règles)
- Test : `backend/tests/Mcp/Connection/Domain/ValueObject/ConnectionLifetimesTest.php` (nouveau)
- Test : `backend/tests/Mcp/Connection/Domain/Entity/McpConnectionExpiryTest.php` (nouveau)
- Test : `backend/tests/Mcp/Connection/Infrastructure/League/ConnectionLifetimesWiringTest.php` (nouveau)
- Test : adapter `McpAccessTokenRefusalTest`, `McpTokenEntitiesTest`, `AuthorizationServerTest` à la nouvelle signature

**Interfaces :**
- Produces :
  - `final readonly class ConnectionLifetimes` : `static fromConfiguration(string $authorizationCode, string $accessToken, string $refreshToken, string $idle, string $maximum): self` ;
    `authorizationCode(): DateInterval`, `accessToken(): DateInterval`, `refreshToken(): DateInterval`,
    `idle(): DateInterval`, `maximum(): DateInterval` (chacun rend une **copie** : `DateInterval` est mutable) ;
    `idleThreshold(DateTimeImmutable $now): DateTimeImmutable` (`$now->sub($idle)`),
    `maximumThreshold(DateTimeImmutable $now): DateTimeImmutable` (`$now->sub($maximum)`).
  - `InvalidConnectionLifetimeException extends InvalidArgumentException` : `unreadable(string $parameter)`,
    `notPositive(string $parameter)`, `idleExceedsMaximum()`, `accessTokenOutlivesRefreshToken()` — le message
    nomme le paramètre (`authorizationCode`, `accessToken`…), jamais la valeur.
  - `McpConnection::isExpiredAt(DateTimeImmutable $now, ConnectionLifetimes $lifetimes): bool` =
    `($this->lastUsedAt ?? $this->grantedAt) <= $lifetimes->idleThreshold($now) || $this->grantedAt <= $lifetimes->maximumThreshold($now)`
    — **la seule définition de l'échéance**, que la purge (tâche 9) relira en SQL sur les mêmes seuils.
  - `McpAccessToken::refusalAt(DateTimeImmutable $now, string $expectedAudience, ConnectionLifetimes $lifetimes): ?BearerRefusal`.
  - Paramètres : `app.mcp.lifetime.authorization_code: PT10M`, `app.mcp.lifetime.access_token: PT1H`,
    `app.mcp.lifetime.refresh_token: P30D`, `app.mcp.lifetime.connection_idle: P30D`,
    `app.mcp.lifetime.connection_maximum: P90D` ; service
    `App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes` (`factory: ['App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes', 'fromConfiguration']`).

- [ ] **Étape 1 : écrire les tests rouges**

`ConnectionLifetimesTest` :
- `testTheConfiguredValuesAreRead` : `fromConfiguration('PT10M', 'PT1H', 'P30D', 'P30D', 'P90D')` rend des
  intervalles dont `format('%d %h %i')` vaut respectivement `0 0 10`, `0 1 0`, `30 0 0`, `30 0 0`, `90 0 0` ; muter
  la copie rendue ne change pas la suivante.
- `testAnUnreadableDurationIsRefusedNamingItsParameter` (fournisseur : `'P'`, `'30 days'`, `''`, sur chacun des
  cinq paramètres) : `InvalidConnectionLifetimeException`, message contenant le nom du paramètre et pas la valeur.
- `testAZeroDurationIsRefused` (`'PT0S'`, `'P0D'`) : `notPositive`.
- `testAnIdleLifetimeBeyondTheMaximumIsRefused` : `idle = P91D`, `maximum = P90D`.
- `testAnAccessTokenOutlivingItsRefreshTokenIsRefused` : `accessToken = P31D`, `refreshToken = P30D`.
- `testThresholdsCountCalendarDaysAcrossTheAutumnClockChange` : sous `Europe/Paris`,
  `now = 2026-10-26 10:00:00` → `idleThreshold(now) == 2026-09-26 10:00:00` (même heure murale, 721 heures
  écoulées : `now->getTimestamp() - threshold->getTimestamp() === 721 * 3600`).

`McpConnectionExpiryTest` (connexion construite par `McpConnection::grant()`, compte et client en mémoire
comme dans `McpTokenEntitiesTest`) :
- `testAConnectionExpiresAtItsIdleThresholdAcrossTheClockChange` : octroi `2026-09-26 10:00:00` Paris, jamais
  utilisée ; vivante à `2026-10-26 09:59:59`, morte à `2026-10-26 10:00:00`.
- `testAConnectionExpiresAtItsMaximumWhateverItsUse` : durées `idle = P90D`, `maximum = P90D` (pour isoler la
  borne absolue), octroi `2026-07-28 10:00:00` ; vivante à `2026-10-26 09:59:59`, morte à `10:00:00`.

`ConnectionLifetimesWiringTest` (KernelTestCase) : `testTheContainerBuildsTheConfiguredLifetimes` — le service
du conteneur `test` rend `PT10M`, `PT1H`, `P30D`, `P30D`, `P90D` ; `testTheAuthorizationServerIssuesWithTheConfiguredLifetimes`
— un échange de code par `DrivesTheOAuthFlow` rend `expires_in` = 3600 (déjà vrai : le test fixe que la valeur
vient de la configuration une fois les constantes retirées).

- [ ] **Étape 2 : lancer, constater l'échec** — `--filter 'ConnectionLifetimes|McpConnectionExpiry'` : classe
  `ConnectionLifetimes` introuvable, `isExpiredAt()` à un argument.

- [ ] **Étape 3 : implémenter** `ConnectionLifetimes`, l'exception, les paramètres et le service ;
  `isExpiredAt()` et `refusalAt()` aux nouvelles signatures ; `AuthorizationServerFactory` reçoit
  `ConnectionLifetimes` et en lit les trois durées de league ; `McpBearerAuthenticator` le reçoit et le passe à
  `refusalAt()`. Positivité jugée sur un instant de référence fixe (`2026-01-01T00:00:00+00:00`,
  `ref->add(i) > ref`) ; les deux comparaisons d'ordre sur le même instant. Créer
  `.claude/rules/mcp-oauth-cycle-de-vie.md` (en-tête comme `mcp-oauth.md`, premier paragraphe : où vivent les
  durées, pourquoi sans `%env()%`, l'échéance définie par ses seuils), y renvoyer depuis `mcp-oauth.md`
  (« Durations live there only… ») et l'ajouter à la table de `.claude/CLAUDE.md`. Corriger les docblocks qui
  citaient `IDLE_LIFETIME`/`MAX_LIFETIME` (`BearerRefusal`, `McpConnection`).

- [ ] **Étape 4 : lancer, constater le succès** — mêmes filtres, plus `McpAccessTokenRefusal|McpTokenEntities|AuthorizationServer|McpBearerAuthenticator|SecurityServicesEnvironmentParity`, puis `bash tools/check-claude-rules.sh` à la racine.

- [ ] **Étape 5 : commit** — `feat(mcp): durées de la connexion en configuration (#649)`.

---

### Tâche 2 : la cause de révocation, une révocation unique, son audit

**Files :**
- Create : `backend/src/Mcp/Connection/Domain/ValueObject/RevocationCause.php`
- Create : `backend/src/Mcp/Connection/Domain/ValueObject/RevokedConnection.php`
- Create : `backend/src/Mcp/Connection/Application/McpConnectionRevokerInterface.php`, `McpConnectionRevoker.php`
- Create : `backend/migrations/Version20261026120000.php`
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpConnection.php` (colonne `revocationCause`)
- Modify : `backend/src/Mcp/Connection/Domain/Repository/McpConnectionRepositoryInterface.php`, `Infrastructure/Doctrine/McpConnectionRepository.php`
- Modify : `backend/src/Security/Authentication/Application/SecurityAuditLoggerInterface.php`, `Infrastructure/Log/SecurityAuditLogger.php`
- Modify : `k8s/base/postgres-roles/verify.sql` (bloc #611 : la ligne que `DatabaseOwnerSettingsVerificationTest` imprime)
- Modify : `.claude/rules/security-authentication.md` (liste des événements), `.claude/rules/mcp-oauth-cycle-de-vie.md`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepositoryTest.php`
- Test : `backend/tests/Mcp/Connection/Application/McpConnectionRevokerTest.php` (nouveau)
- Test : `backend/tests/Security/Authentication/Infrastructure/Log/SecurityAuditLoggerTest.php` (chemin à vérifier)

**Interfaces :**
- Produces :
  - `enum RevocationCause: string` : `Client = 'client'`, `RefreshTokenReuse = 'refresh-token-reuse'`,
    `CodeReplay = 'code-replay'`, `Superseded = 'superseded'`, `Expired = 'expired'`.
  - `final readonly class RevokedConnection implements AccountReference` :
    `__construct(public Uuid $connectionId, private string $accountId, public string $clientId, public RevocationCause $cause)`,
    `accountId(): string` (RFC 4122). `clientId` est l'identifiant **public** OAuth du client.
  - `McpConnection::revocationCause(): ?RevocationCause` — **aucun mutateur** : une connexion se révoque
    seulement par le dépôt.
  - `McpConnectionRepositoryInterface::revokeActive(Uuid $connectionId, RevocationCause $cause, DateTimeImmutable $at): ?RevokedConnection`
    — `null` si inconnue ou déjà révoquée (la première révocation garde instant et cause). Une instance déjà
    chargée n'est pas rafraîchie (comme `revokeByHash()`).
  - `SecurityAuditLoggerInterface::mcpConnectionRevoked(AccountReference $account, Uuid $connectionId, string $clientId, string $cause): void`
    (événement `mcp-connection-revoked`, acteur lu comme d'habitude) ;
    `mcpConnectionExpired(AccountReference $account, Uuid $connectionId, string $clientId): void` (même
    événement, `cause: expired`, acteur `system`, comme `userPurged()`).
  - `McpConnectionRevokerInterface::revoke(Uuid $connectionId, RevocationCause $cause): ?RevokedConnection` —
    révoque (horloge `ClockInterface`) puis écrit l'audit si la révocation a eu lieu ; **hors transaction**,
    une instruction : appelé après une transaction annulée, EntityManager fermé compris.

- [ ] **Étape 1 : tests rouges**

`McpConnectionRepositoryTest` (base `_test`, connexion posée comme dans le test existant) :
- `testRevokeActiveRevokesOnceAndNamesTheAccountAndClient` : rend `RevokedConnection` (UUID de la connexion,
  `accountId()` du compte, `clientId` public, cause `Client`) ; en base `revoked_at` = l'instant passé,
  `revocation_cause = 'client'`.
- `testASecondRevocationKeepsTheFirstInstantAndCause` : second appel (`Expired`, autre instant) → `null`, base
  inchangée.
- `testAnUnknownConnectionIsNotRevoked` → `null`.
- `testTheDatabaseRefusesARevocationWithoutCause` : `UPDATE mcp_connection SET revoked_at = now()` en SQL brut →
  `CheckConstraintViolationException` (contrainte `chk_mcp_connection_revocation_cause`), et l'inverse (cause
  sans instant) aussi.

`McpConnectionRevokerTest` (unitaire, dépôt et journal simulés) : `testARevocationIsAuditedOnce` (audit appelé
une fois avec `userId`/`connectionId`/`clientId`/`cause` de la `RevokedConnection`) ;
`testNothingIsAuditedWhenTheConnectionWasAlreadyRevoked` (`revokeActive()` rend `null` → `never()`).

`SecurityAuditLoggerTest` : les deux méthodes neuves écrivent `event: mcp-connection-revoked`, les quatre
champs, l'acteur attendu (`system` pour l'expiration) ; les ajouter à
`testNoContextValueEverCarriesAPasswordATokenOrAnEmail`.

- [ ] **Étape 2 : lancer, constater l'échec** — méthode `revokeActive()` absente, colonne absente.

- [ ] **Étape 3 : implémenter.** Migration : `ALTER TABLE mcp_connection ADD revocation_cause VARCHAR(32) DEFAULT NULL`,
  `ADD CONSTRAINT chk_mcp_connection_revocation_cause CHECK ((revoked_at IS NULL) = (revocation_cause IS NULL))`
  (aucune ligne révoquée n'existe : `revokedAt` n'avait pas de mutateur), index partiel
  `idx_mcp_connection_active_pair ON mcp_connection (account_id, mcp_client_id) WHERE revoked_at IS NULL`
  (tâche 7) ; `down()` les retire. Mapping : `#[ORM\Column(length: 32, nullable: true, enumType: RevocationCause::class)]`.
  `revokeActive()` en SQL natif, par la connexion DBAL de l'EntityManager (jamais l'unité de travail) :

  ```sql
  UPDATE mcp_connection c SET revoked_at = :at, revocation_cause = :cause
    FROM mcp_client cl
   WHERE c.id = :id AND c.revoked_at IS NULL AND cl.id = c.mcp_client_id
  RETURNING c.id, c.account_id, cl.client_id
  ```

  (`:at` typé `Types::DATETIMETZ_IMMUTABLE`, `:id` `UuidType::NAME`). Ligne de `verify.sql` : lancer
  `DatabaseOwnerSettingsVerificationTest`, recopier la ligne qu'il imprime. Docblock de `McpConnection` : la
  révocation par le dépôt seulement, pourquoi (une instruction, une seule fois, valable EntityManager fermé),
  et pourquoi les lignes de jetons ne sont pas touchées (décision 6).

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'McpConnectionRepository|McpConnectionRevoker|SecurityAuditLogger|DatabaseOwnerSettingsVerification|TimestampColumnsCarryTheirOffset'`.

- [ ] **Étape 5 : commit** — `feat(mcp): une connexion se révoque une fois, avec sa cause (#649)`.

---

### Tâche 3 : le rafraîchissement jugé sous le verrou de la connexion

**Files :**
- Create : `backend/src/Mcp/Connection/Domain/Exception/CompromisedMcpConnectionException.php` (abstraite)
- Create : `backend/src/Mcp/Connection/Domain/Exception/RotatedRefreshTokenReusedException.php`
- Create : `backend/src/Mcp/Connection/Infrastructure/Security/TrustedTier.php`
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpConnection.php` (`recordUseAt()`)
- Modify : `McpConnectionRepositoryInterface` / `McpConnectionRepository` (`lockForIssuance()`, `save()`)
- Modify : `Infrastructure/Doctrine/McpRefreshTokenRepository.php`, `McpAuthorizationCodeRepository.php` (`findOneByHash()` en `HINT_REFRESH`)
- Modify : `Infrastructure/League/RefreshTokenRepository.php` (`isRefreshTokenRevoked()`)
- Modify : `Infrastructure/Security/McpBearerAuthenticator.php` (palier par `TrustedTier`)
- Modify : `Application/TokenEndpoint.php` (rejeu → révocation à part)
- Modify : `Infrastructure/League/ConnectionOfToken.php` (docblock : la course est fermée en amont)
- Modify : `.claude/rules/mcp-oauth-cycle-de-vie.md` ; `mcp-oauth.md` (« Known, #649's: league's races » → renvoi)
- Test : `backend/tests/Mcp/Connection/RefreshRotationTest.php` (nouveau, fonctionnel, trait `DrivesTheOAuthFlow` + `ReadsSecurityAuditLog`)
- Test : `backend/tests/Mcp/Connection/Infrastructure/League/LeagueRepositoriesTest.php`, `Domain/Entity/McpConnectionExpiryTest.php`

**Interfaces :**
- Consumes : `ConnectionLifetimes` (tâche 1), `RevocationCause`, `McpConnectionRevokerInterface` (tâche 2).
- Produces :
  - `abstract class CompromisedMcpConnectionException extends DomainException` : `connectionId(): Uuid`,
    `revocationCause(): RevocationCause` ; message constant, jamais le jeton.
  - `final class RotatedRefreshTokenReusedException extends CompromisedMcpConnectionException` :
    `static forConnection(Uuid $connectionId): self` (cause `RefreshTokenReuse`).
  - `McpConnection::USAGE_WRITE_INTERVAL = 'PT1H'` ; `recordUseAt(DateTimeImmutable $at): bool` — écrit
    `lastUsedAt = $at` et rend `true` si `lastUsedAt` est nul ou si `lastUsedAt->add(new DateInterval('PT1H')) <= $at`.
  - `McpConnectionRepositoryInterface::lockForIssuance(Uuid $connectionId): ?McpConnection` (relue
    `FOR UPDATE` par `RereadUnderLock::row(…, forUpdate: true)` ; `null` si la ligne a disparu) ;
    `save(McpConnection $connection): void` (flush).
  - `final readonly class TrustedTier` : `__construct(RoleHierarchyInterface $roleHierarchy)`,
    `reaches(CpgUser $account): bool` (rôles **stockés**, hiérarchie, `McpConnectionPrincipal::TRUSTED_TIER`).
  - `TokenEndpoint` reçoit `McpConnectionRevokerInterface`.

- [ ] **Étape 1 : tests rouges**

`RefreshRotationTest` (chaque test part d'un échange par `codeGrantedByTheSignedInAccount()` + `exchange()`) :
- `testARefreshIssuesANewPairAndTheOldRefreshTokenIsRefusedAfterwards` : `refresh(R1)` → 200, `R2 !== R1`,
  nouveau jeton d'accès ; `refresh(R1)` → `assertOAuthError(…, 400, 'invalid_grant')`.
- `testReusingARotatedRefreshTokenRevokesTheConnection` : après `refresh(R1)` puis `refresh(R1)`, la connexion
  porte `revoked_at` et `revocation_cause = 'refresh-token-reuse'` ; `refresh(R2)` → `invalid_grant` ; une ligne
  `mcp-connection-revoked` (`userId` du compte, `connectionId`, `clientId` = `CLIENT_ID`, `cause`), aucune valeur
  de jeton dans le contexte. Docblock : c'est aussi le cas du client qui rejoue après une réponse perdue (Review
  Focus 1), prix accepté de la rotation.
- `testARefreshTokenPresentedByAnotherClientLeavesTheConnectionAlone` : `refresh(R1, ['client_id' => <second client enregistré>])`
  → `invalid_grant` ; connexion non révoquée ; `refresh(R1)` par le bon client → 200.
- `testAnExpiredConnectionCannotRefresh` : `granted_at` reculé de 30 jours (SQL) → `invalid_grant`, nombre de
  lignes de `mcp_access_token` et `mcp_refresh_token` inchangé, connexion **non** révoquée (la purge la marquera).
- `testAConnectionPastItsMaximumCannotRefresh` : `granted_at` reculé de 90 jours, `last_used_at = now()` → idem.
- `testAnAccountNoLongerTrustedCannotRefresh` : rôles du compte → `["ROLE_USER"]` → idem.
- `testARefreshRecordsTheLastUseAtMostOncePerHour` : après l'échange `last_used_at` est nul ; après
  `refresh(R1)` il est posé ; reculé à `now() - 30 min`, `refresh(R2)` ne le change pas ; reculé à
  `now() - 61 min`, `refresh(R3)` le repose.

`LeagueRepositoriesTest` : `testARefreshTokenExpiryIsJudgedOnTheApplicationClock` — un rafraîchissement en base
dont `expires_at` est atteint selon une `MockClock` posée sur le service `clock` du conteneur (l'horloge de
league, `time()`, le dit encore valide) → `isRefreshTokenRevoked()` vrai. C'est la réponse au report « deux
horloges » : chaque jugement de ce dépôt lit `ClockInterface` ; le contrôle `time()` de league reste un second
filet, identique en production.

`McpConnectionExpiryTest` : `testTheLastUseIsWrittenAtMostOncePerElapsedHour` (`recordUseAt()` : nul → vrai ;
`+59 min` → faux ; `+60 min` → vrai), sous `Europe/Paris` à cheval sur `2026-10-25 02:30`.

- [ ] **Étape 2 : lancer, constater l'échec** — `testReusingARotatedRefreshTokenRevokesTheConnection` (connexion
  intacte), `testAnExpiredConnectionCannotRefresh` (200), `testARefreshRecordsTheLastUseAtMostOncePerHour`
  (`last_used_at` nul).

- [ ] **Étape 3 : implémenter.** `isRefreshTokenRevoked()` (le nom est celui de l'interface de league ; le
  docblock dit qu'il juge tout ce qui rend un rafraîchissement irrecevable) :

  ```php
  $hash = TokenIdentifierHash::of($tokenId);
  $token = $this->tokens->findOneByHash($hash);                      // null → true
  $connection = $this->connections->lockForIssuance($token->connection()->getId()); // null → true
  $token = $this->tokens->findOneByHash($hash);                      // relu sous verrou : une rotation concurrente validée se voit ; null → true
  $now = $this->clock->now();
  // 1. connexion révoquée → true (déjà morte : pas de seconde révocation, pas d'audit)
  // 2. jeton révoqué, connexion active → throw RotatedRefreshTokenReusedException::forConnection($connection->getId())
  // 3. jeton échu (expiresAt <= now), connexion expirée (isExpiredAt), palier perdu (!TrustedTier::reaches) → true
  // 4. recordUseAt($now) → save() si vrai ; return false
  ```

  `TokenEndpoint::respond()` : `try { transaction->run(league) } catch (CompromisedMcpConnectionException $e) { $this->revoker->revoke($e->connectionId(), $e->revocationCause()); throw <refus de league, Global Constraints>; }`
  — le `catch` est **hors** de la transaction (déjà annulée). `TrustedTier` remplace le calcul inline de
  `McpBearerAuthenticator` (comportement inchangé). Docblocks : `TokenEndpoint` (le paragraphe « Non traité ici,
  connu : les courses de league » devient la description du verrou), `ConnectionOfToken` (la lecture se fait
  déjà sous verrou au point de jeton). Règle : le verrou, l'ordre de jugement, la réponse au report des deux
  horloges, l'écriture du dernier usage.

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'RefreshRotation|LeagueRepositories|McpConnectionExpiry|TokenController|OAuthFlow|McpBearerAuthenticator'`.
  `OAuthFlowTest` ou `TokenControllerTest` qui rejouaient un rafraîchissement attendent désormais la révocation
  de la connexion : les ajuster en le disant dans le message de commit.

- [ ] **Étape 5 : commit** — `feat(mcp): rotation des rafraîchissements, réutilisation révoquée (#649)`.

---

### Tâche 4 : le rejeu d'un code révoque la connexion

**Files :**
- Create : `backend/src/Mcp/Connection/Domain/Exception/AuthorizationCodeReplayedException.php`
- Modify : `backend/src/Mcp/Connection/Infrastructure/League/AuthCodeRepository.php` (`isAuthCodeRevoked()`)
- Modify : `Application/TokenEndpoint.php` (le refus du code dans le `match`)
- Modify : `.claude/rules/mcp-oauth-cycle-de-vie.md`
- Test : `backend/tests/Mcp/Connection/CodeReplayTest.php` (nouveau)

**Interfaces :**
- Consumes : `lockForIssuance()` (tâche 3), `CompromisedMcpConnectionException` (tâche 3).
- Produces : `final class AuthorizationCodeReplayedException extends CompromisedMcpConnectionException`,
  `static forConnection(Uuid $connectionId): self` (cause `CodeReplay`).

- [ ] **Étape 1 : tests rouges** (`CodeReplayTest`)
- `testExchangingACodeTwiceRevokesTheConnection` : `exchange(code)` → 200 (R1) ; `exchange(code)` →
  `invalid_grant` ; connexion `revocation_cause = 'code-replay'` ; `refresh(R1)` → `invalid_grant` ; une ligne
  `mcp-connection-revoked` cause `code-replay`.
- `testAReplayOnARevokedConnectionWritesNoSecondLine` : après le cas précédent, un troisième `exchange(code)` →
  `invalid_grant`, toujours une seule ligne d'audit, cause inchangée.

Pas de test pour un code rejoué **après** ses 10 minutes : league juge l'échéance sur la charge chiffrée
(`expire_time < time()`, horloge réelle) **avant** `isAuthCodeRevoked()`, si bien qu'un tel rejeu est un simple
`invalid_grant`, sans révocation, et qu'aucune `MockClock` ne l'atteint. Limite acceptée, écrite dans la règle :
au-delà de 10 minutes, les jetons issus du code vivent déjà sous rotation.

- [ ] **Étape 2 : lancer, constater l'échec** — la connexion n'est pas révoquée au second échange.

- [ ] **Étape 3 : implémenter** `isAuthCodeRevoked()` sur le modèle de la tâche 3 : chercher, verrouiller la
  connexion, relire le code ; connexion révoquée → `true` ; code révoqué → `throw AuthorizationCodeReplayedException::forConnection(…)` ;
  sinon `false`. Le `match` de `TokenEndpoint` rend `OAuthServerException::invalidGrant('Authorization code has been revoked')`.

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'CodeReplay|RefreshRotation|TokenController|OAuthFlow|LeagueRepositories'`.

- [ ] **Étape 5 : commit** — `feat(mcp): un code rejoué révoque sa connexion (#649)`.

---

### Tâche 5 : les courses, en processus réels

**Files :**
- Create : `backend/tests/Mcp/Connection/TokenEndpointConcurrencyTest.php`
- Create : `backend/tests/Mcp/Connection/Fixtures/present-at-token-endpoint.php` (script enfant)
- Modify : `.claude/rules/backend-outillage.md` (liste des tests de concurrence du harnais #403), `.claude/rules/mcp-oauth-cycle-de-vie.md`

**Interfaces :**
- Consumes : `ChildPhpProcess::inTestEnvironment()`, `ProcessBarrier`, `FlushInterleaving::releaseAfterNextFlush()`,
  `TestServiceLocator` (lire `.claude/rules/backend-outillage.md`, « Concurrency tests with child processes »,
  et `SendAccountInvitationConcurrencyTest` comme modèle) ; `TokenEndpointInterface::respond()`.

- [ ] **Étape 1 : tests rouges**
- `testTwoSimultaneousRefreshesOfOneTokenRevokeTheConnection` : le parent arme
  `releaseAfterNextFlush()` puis rafraîchit `R1` par `TokenEndpointInterface` ; à son premier flush (nouveau
  jeton d'accès écrit, non validé, verrou de la connexion tenu), l'enfant présente `R1` et attend sur le verrou ;
  le parent valide. Attendus : réponse du parent 200 ; enfant `invalid_grant` (code de sortie et corps lus) ;
  connexion révoquée `refresh-token-reuse` ; une seule paire neuve en base.
- `testTwoSimultaneousExchangesOfOneCodeRevokeTheConnection` : même schéma sur l'échange d'un code → connexion
  `code-replay`.
- Chaque attente porte le filet `statement_timeout = '3s'` des autres tests de concurrence.

- [ ] **Étape 2 : prouver que les tests échouent sans le verrou** — mutation : `forUpdate: false` dans
  `lockForIssuance()` ; l'enfant lit alors le jeton non révoqué et obtient sa propre paire (200) : les deux tests
  rouges. Noter la mesure dans le docblock de la classe, puis retirer la mutation.

- [ ] **Étape 3 : lancer, constater le succès** — `--filter TokenEndpointConcurrency` (et
  `ChildProcessHarnessTest`, qui contrôle l'usage du harnais).

- [ ] **Étape 4 : commit** — `test(mcp): courses du point de jeton en processus réels (#649)`.

---

### Tâche 6 : révoquer un rafraîchissement révoque la connexion

**Files :**
- Modify : `backend/src/Mcp/Connection/Infrastructure/League/LeagueRevocationEndpoint.php`
- Modify : `.claude/rules/mcp-oauth.md` (« The connection is **not** revoked (#649) » → renvoi), `mcp-oauth-cycle-de-vie.md`
- Test : `backend/tests/Mcp/Connection/Presentation/Controller/RevocationControllerTest.php`

**Interfaces :**
- Consumes : `revokeActive()` (tâche 2), `SecurityAuditLoggerInterface::mcpConnectionRevoked()`.

- [ ] **Étape 1 : tests rouges**
- `testRevokingARefreshTokenRevokesItAndItsAccessToken` devient `testRevokingARefreshTokenRevokesItsConnection` :
  jeton et jeton d'accès révoqués comme avant, **plus** la connexion (`revocation_cause = 'client'`), une ligne
  `mcp-connection-revoked` cause `client` ; `refresh(R)` ensuite → `invalid_grant` **sans** seconde ligne d'audit
  ni changement de cause (la connexion est déjà révoquée : ce n'est pas une réutilisation).
- `testRevokingAnAccessTokenRevokesItsRowOnly` : inchangé, et la connexion reste active (assertion ajoutée).
- `testATokenIssuedToAnotherClientIsLeftAlone` : la connexion reste active (assertion ajoutée).

- [ ] **Étape 2 : lancer, constater l'échec** — connexion intacte.

- [ ] **Étape 3 : implémenter** : dans la transaction existante de `revokeRefreshToken()`, après les deux
  `revokeByHash()`, `revokeActive($connectionId, RevocationCause::Client, $at)` ; la transaction rend la
  `?RevokedConnection`, l'audit s'écrit après. Docblock de la classe : la puce « La connexion n'est pas
  révoquée » réécrite (RFC 7009 §2.1, décision 3).

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'RevocationController|OAuthFlow'`.

- [ ] **Étape 5 : commit** — `feat(mcp): la révocation d'un rafraîchissement révoque sa connexion (#649)`.

---

### Tâche 7 : un nouvel accord remplace l'ancien

**Files :**
- Modify : `McpConnectionRepositoryInterface` / `McpConnectionRepository` (`revokeOtherActiveOf()`)
- Modify : `backend/src/Mcp/Connection/Application/ConsentService.php`
- Modify : `.claude/rules/mcp-oauth.md` (puce du consentement), `mcp-oauth-cycle-de-vie.md`
- Test : `backend/tests/Mcp/Connection/Application/ConsentServiceTest.php`, `Presentation/Controller/ConsentControllerTest.php`

**Interfaces :**
- Produces : `McpConnectionRepositoryInterface::revokeOtherActiveOf(McpConnection $kept, RevocationCause $cause, DateTimeImmutable $at): list<RevokedConnection>`
  — même compte, même client, autre id, active ; SQL natif comme `revokeActive()` (servi par l'index partiel
  de la tâche 2).

- [ ] **Étape 1 : tests rouges**
- `ConsentControllerTest::testASecondGrantSupersedesTheFirstConnection` : deux approbations du même compte pour
  le même client → la première connexion `revocation_cause = 'superseded'`, son rafraîchissement refusé
  (`invalid_grant`), le code de la seconde s'échange ; audit : `mcp-connection-granted` puis
  `mcp-connection-revoked` cause `superseded`.
- `testAGrantForAnotherClientLeavesTheFirstAlone` : second client → la première reste active.
- `testARetriedApprovalLeavesOneActiveConnection` : la même décision envoyée deux fois (le réessai après un 5xx
  du report de T8) → une seule connexion active.
- `ConsentServiceTest::testTheSupersededConnectionsAreAuditedAfterTheGrant` (unitaire : ordre des appels
  d'audit, aucun sur un refus) et `testADenialRevokesNothing`.

- [ ] **Étape 2 : lancer, constater l'échec** — deux connexions actives.

- [ ] **Étape 3 : implémenter** : dans la transaction de `ConsentService::grant()`, après l'ajout de la
  connexion, `revokeOtherActiveOf($connection, RevocationCause::Superseded, $now)` ; après le COMMIT, l'audit
  d'octroi puis une ligne par connexion remplacée. **Limite acceptée, écrite dans la règle** : deux
  approbations simultanées (double clic que `useDeliberateActivation` rend improbable) peuvent laisser deux
  connexions actives — chacune ne voit pas l'autre, non validée ; la seconde n'a jamais servi et expire à
  30 jours. Une contrainte d'unicité partielle a été écartée : elle transformerait ce double clic en 500.

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'ConsentController|ConsentService|OAuthFlow'`.

- [ ] **Étape 5 : commit** — `feat(mcp): un nouvel accord remplace celui du même client (#649)`.

---

### Tâche 8 : le refus d'un jeton d'accès connu est audité

**Files :**
- Modify : `SecurityAuditLoggerInterface` / `SecurityAuditLogger` (`mcpAccessTokenRefused()`)
- Modify : `backend/src/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticator.php`
- Modify : `.claude/rules/security-authentication.md` (événements), `mcp-oauth.md` (authentificateur)
- Test : `backend/tests/Mcp/Connection/Infrastructure/Security/McpBearerAuthenticatorTest.php`, `SecurityAuditLoggerTest`

**Interfaces :**
- Produces : `SecurityAuditLoggerInterface::mcpAccessTokenRefused(AccountReference $account, Uuid $connectionId, string $clientId, string $cause): void`
  (événement `mcp-access-token-refused`).

- [ ] **Étape 1 : tests rouges**
- `testAKnownRefusedTokenIsAudited` (fournisseur : révoqué, échu, audience étrangère, connexion révoquée,
  connexion expirée, scope, compte hors palier — mêmes altérations SQL que les tests existants) : une ligne
  `mcp-access-token-refused`, `cause` = valeur de `BearerRefusal`, `userId`, `connectionId`, `clientId` ; la
  réponse reste le 401 commun.
- `testAMalformedOrUnknownTokenIsNotAudited` : aucune ligne.
- `testAnAuthenticatedCallCostsExactlyOneQueryMore` reste vert ; ajouter `testARefusedKnownTokenCostsNoExtraQuery`
  (même compte de requêtes que l'appel accepté).

- [ ] **Étape 2 : lancer, constater l'échec.**

- [ ] **Étape 3 : implémenter** : l'audit juste avant le `throw` de `McpBearerRefusedException` pour un refus
  issu de `refusalAt()` ou de `TrustedTier` ; le compte désigné par `McpConnection::account()` (un
  `AccountReference`). Volume, à écrire dans la règle : un client sain voit son jeton d'accès échoir une fois
  par heure, puis rafraîchit — au plus une ligne par connexion et par heure en usage normal.

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'McpBearerAuthenticator|SecurityAuditLogger|AccountIdentifierReachesNoLog'`.

- [ ] **Étape 5 : commit** — `feat(mcp): auditer le refus d'un jeton d'accès connu (#649)`.

---

### Tâche 9 : la purge quotidienne

**Files :**
- Create : `backend/src/Mcp/Connection/Application/McpConnectionPurgerInterface.php`, `McpConnectionPurger.php`, `McpConnectionPurgeResult.php`
- Create : `backend/src/Mcp/Connection/Domain/Exception/McpPurgeRetentionTooShortException.php`
- Create : `backend/src/Mcp/Connection/Presentation/Command/PurgeMcpConnectionsCommand.php`
- Modify : `McpConnectionRepositoryInterface` / dépôt (`revokeExpired()`, `deleteRevokedBefore()`)
- Modify : `McpAccessTokenRepositoryInterface`, `McpRefreshTokenRepositoryInterface`, `McpAuthorizationCodeRepositoryInterface` et leurs dépôts (`deleteDead()`)
- Modify : `backend/config/services.yaml` (`app.mcp.revoked_connection_retention: '6 months'`)
- Modify : `k8s/base/messenger-purge-cronjob.yaml` (troisième ligne)
- Modify : `.claude/rules/mcp-oauth-cycle-de-vie.md`
- Test : `backend/tests/Mcp/Connection/Application/McpConnectionPurgerTest.php` (intégration, base `_test`)
- Test : `backend/tests/Mcp/Connection/Presentation/Command/PurgeMcpConnectionsCommandTest.php`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Kubernetes/McpConnectionsPurgedDailyTest.php` (modèle : `McpSessionsPrunedHourlyTest`)

**Interfaces :**
- Consumes : `McpConnection::isExpiredAt()` et `ConnectionLifetimes` (tâche 1, forme échéance `idleDeadline()` /
  `maximumDeadline()`), `RetentionPeriod`,
  `mcpConnectionExpired()` (tâche 2).
- Produces :
  - *Amendé (relecture de la tâche 1)* : `McpConnectionRepositoryInterface::findActiveCandidatesForExpiry(DateTimeImmutable $idleBefore, DateTimeImmutable $maximumBefore): list<McpConnection>`
    — présélection SQL **large** (`revoked_at IS NULL AND (COALESCE(last_used_at, granted_at) <= :idleBefore OR granted_at <= :maximumBefore)`,
    les deux bornes calculées en PHP avec **un jour de marge** : `now − durée + P1D`), puis le purgeur juge chaque
    connexion par `isExpiredAt($now, $lifetimes)` — la seule définition — et révoque celles qu'elle dit expirées par
    `revokeExpired(list<Uuid> $connectionIds, DateTimeImmutable $at): list<RevokedConnection>` (cause `expired`,
    `revoked_at IS NULL` gardé, `RETURNING` comme `revokeActive()`) ;
    `deleteRevokedBefore(DateTimeImmutable $threshold): int` (`revoked_at < :threshold`).
  - `deleteDead(DateTimeImmutable $now): int` sur les trois dépôts de jetons : `expires_at <= :now` **ou**
    connexion révoquée.
  - `McpConnectionPurgerInterface::purge(RetentionPeriod $retention): McpConnectionPurgeResult` ;
    `final readonly class McpConnectionPurgeResult` : `list<string> $expired` (UUID), `int $deletedAuthorizationCodes`,
    `int $deletedAccessTokens`, `int $deletedRefreshTokens`, `int $deletedConnections`, `DateTimeImmutable $threshold`.
  - `McpPurgeRetentionTooShortException::belowOneDay()` (seuil postérieur à `now - P1D`).
  - Commande `app:mcp:purge`, option `--older-than` (défaut : `#[Autowire('%app.mcp.revoked_connection_retention%')]`),
    lue par `RetentionPeriod::fromString()` ; `Command::INVALID` sur un refus.

- [ ] **Étape 1 : tests rouges**

`McpConnectionPurgerTest` (horloge du conteneur remplacée par une `MockClock`, lignes posées en SQL) :
- `testAnExpiredConnectionIsMarkedOnceAndAudited` : une connexion inactive depuis 30 jours → révoquée
  `expired`, une ligne `mcp-connection-revoked` acteur `system` ; une seconde purge n'écrit rien de plus.
- `testThePurgeAndIsExpiredAtAgreeAtTheBoundary` : quatre connexions, dernier usage exactement au seuil
  d'inactivité, une seconde après ; octroi exactement au seuil absolu, une seconde après — pour chacune, être
  marquée par la purge ⇔ `isExpiredAt(now, lifetimes)` (Review Focus 5).
- `testDeadTokensAndCodesAreDeletedAndLiveOnesKept` : échus et ceux d'une connexion révoquée partent ; ceux
  d'une connexion vivante non échus restent ; un rafraîchissement vivant dont le jeton d'accès a été purgé
  survit (`ON DELETE SET NULL`).
- `testAConnectionRevokedBeforeTheRetentionIsDeleted` : révoquée il y a 6 mois et une seconde → supprimée ;
  il y a 6 mois moins une seconde → gardée.
- `testARetentionUnderADayIsRefused` : `'12 hours'` → `McpPurgeRetentionTooShortException`, rien d'écrit.

`PurgeMcpConnectionsCommandTest` : `testTheDefaultRetentionIsSixMonths` (le seuil affiché) ;
`testAnUnreadableRetentionIsInvalid` (`'first day of next month'` → code 2) ; `testTheOutputNamesConnectionsByUuidOnly`
(aucune empreinte, aucun identifiant de client hors des UUID).

`McpConnectionsPurgedDailyTest` : `testTheDailyHousekeepingCronJobRunsThePurge` — le conteneur `purge` de
`messenger-purge-cronjob.yaml` (`KubernetesManifests`) lance `php bin/console app:mcp:purge --no-interaction`
sous `set -e`, avec `PGOPTIONS` `-c lock_timeout=5s` ; `testNoOtherManifestRunsThePurge` (une seule source).

- [ ] **Étape 2 : lancer, constater l'échec.**

- [ ] **Étape 3 : implémenter.** `purge()` : seuil de rétention refusé sous un jour ; dans **une** transaction :
  présélection + jugement `isExpiredAt()` + `revokeExpired()`, puis les trois `deleteDead()`, puis `deleteRevokedBefore()` ; après le COMMIT, une ligne
  `mcpConnectionExpired()` par connexion marquée. Paramètres typés `Types::DATETIMETZ_IMMUTABLE`. Commande :
  sortie par UUID et compteurs, succès même à zéro (habillage de `PurgePendingInvitationsCommand`). CronJob :
  lire `.claude/rules/deploiement.md` d'abord ; ajouter la ligne après les invitations. Règle : ordre des
  étapes, pourquoi sans `--dry-run` (décision 6), volumes (tables bornées par la purge quotidienne : jetons
  d'accès d'une heure, rafraîchissements tournés ; aucun index sur `expires_at`, à revoir si la volumétrie le
  demande — l'index partiel de la tâche 2 sert l'accord).

- [ ] **Étape 4 : lancer, constater le succès** — `--filter 'McpConnectionPurger|PurgeMcpConnectionsCommand|McpConnectionsPurgedDaily|SystemCachePodVolume'`.

- [ ] **Étape 5 : commit** — `feat(mcp): purge quotidienne des jetons et des connexions mortes (#649)`.

---

### Tâche 10 : documents de référence, vérification finale

**Files :**
- Modify : `docs/rgpd/registre-traitements.md` (§3.4 « Durée de conservation » : purge quotidienne, 6 mois,
  accord remplacé, révocation par le client ; §6 : les deux événements)
- Modify : `.claude/specs/0007-mcp-server.md` (« Modèle », « Durées », « Purge », « Journal d'audit » : un
  paragraphe `*Précisé le 2026-10-26 (#649)*` chacun, sans réécrire le texte d'origine)
- Modify : `tasks/todo.md` (tâche 10 cochée ; reports traités barrés ou retirés de #649, ceux qui restent
  portés par #650-#653 vérifiés)

- [ ] **Étape 1 : écrire les trois documents.** Vérifier que le registre ne dit plus « reste à livrer ».
- [ ] **Étape 2 : vérification** — `make back-quality` puis `make back-test`, sorties lues en entier ;
  `bash tools/check-claude-rules.sh` ; `superpowers:verification-before-completion`.
- [ ] **Étape 3 : commit** — `docs(mcp): registre, spec et suivi de la tâche 10 (#649)`.
