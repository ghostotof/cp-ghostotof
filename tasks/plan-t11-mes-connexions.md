# Tâche 11 (#650, et #730) — « Mes connexions MCP » : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** une personne du palier de confiance voit ses connexions MCP (client, octroi, dernier usage, échéance,
état) et en révoque une, avec effet à la requête suivante ; ses connexions sont aussi révoquées quand son mot de
passe change ou quand elle perd `ROLE_TRUSTED` ; la page de consentement dit quel compte accorde ; une connexion
dont le code n'est jamais échangé expire tôt (#730, piste 1).

**Architecture :** trois chemins de révocation nouveaux, **tous des méthodes dédiées du dépôt**, jamais
`McpConnectionRevokerInterface::revoke()` (typé par la compromission depuis #726, CR6) : la révocation par la
personne est **une instruction** (`revokeActiveOfAccount()`, transaction implicite de l'instruction, audit
après) ; les révocations de tout un compte (mot de passe, palier) passent par un **port de `Shared`**
qu'implémente `Mcp/Connection`, appelé **dans** la transaction de `Security/User` (atomicité avec le mot de passe
ou le rôle), audité après son COMMIT. Toutes réutilisent `revokeWhere()` : lignes verrouillées par id, `FOR NO
KEY UPDATE`, une ligne d'audit par connexion révoquée par l'appel. L'API vit sous `/api/me/` (règle
`access_control` `ROLE_TRUSTED` existante). #730 : une colonne `code_exchanged_at`, écrite au premier échange,
une troisième échéance dans `McpConnection::isExpiredAt()`, une troisième borne de présélection de la purge.

**Tech Stack :** PHP 8.5, Symfony 8.1, Doctrine ORM 3 / DBAL 4, PostgreSQL 18, `league/oauth2-server` 9.4.1
(inchangé), PHPUnit ; Vue 3 + TypeScript, vue-i18n, Vitest, axe-core.

**Spec :** `.claude/specs/0007-mcp-server.md` (user stories 34, 35, 43, 45 ; « Journal d'audit » ; « Mes
connexions MCP » ; « Phase 2, backend » et « Frontend » des Testing Decisions) ; ADR `docs/adr/0006-serveur-mcp.md`
(D3, D4), ADR 0003 (nominatif sous `ROLE_TRUSTED`) ; ticket #650 et ses trois commentaires (reports de #647,
#649, #726) ; ticket #730 (piste 1) ; `CONTEXT.md`, « Accès MCP ». Règles : `.claude/rules/mcp-oauth-cycle-de-vie.md`
(« A connection is revoked once… », « Two application paths… », « A new grant supersedes… », durées),
`mcp-oauth-purge.md`, `mcp-oauth-audits.md`, `mcp-oauth.md` (« Consent »), `security-user.md`,
`security-authentication.md` (journal d'audit), `backoffice-api.md` (« Authorization »), `frontend*.md`.

## Décisions de conception (tranchées ici, à valider à la relecture du plan)

1. **La cause `person` passe par une méthode dédiée du dépôt, hors transaction applicative.**
   `McpConnectionRepositoryInterface::revokeActiveOfAccount($connectionId, $accountId, $cause, $at)` : la forme
   commune `revokeWhere()` avec `WHERE c.id = :id AND c.account_id = :account`. Une seule écriture : l'instruction
   *est* la transaction (autocommit), l'audit suit son retour, donc son COMMIT. Un `TransactionInterface::run()`
   autour n'ajouterait rien. Rejeté : rouvrir `revoke()` à une cause générique (ramènerait les gardes à
   l'exécution que CR6 a retirées) ; un `lockForUpdate()` préalable (deux allers-retours, même verrou).
2. **« Pas à vous » et « inconnue » répondent le même 404**, corps identique (pas d'oracle sur l'existence d'un
   UUID). **Votre connexion déjà révoquée → 204 sans écriture ni ligne** (DELETE idempotent ; la première
   révocation garde instant et cause). Le 404 se décide après l'`UPDATE` rendu `null`, par
   `belongsToAccount()` : le cas courant (révoquer une connexion active) coûte une requête.
3. **Mot de passe et palier : un port dans `Shared`**, `Shared/Application/Security/AccountMcpConnectionsRevokerInterface`,
   implémenté par `Mcp/Connection/Application/AccountMcpConnectionsRevoker`. `Security` ne dépend toujours
   d'aucune classe de `Mcp` (règle `security-authentication.md`), et `Mcp` n'ouvre pas de troisième porte vers
   `Security`. Appelé **dans** la fermeture de `AccountLockInterface::whileLocked()` / `whileAdministering()` :
   le mot de passe (ou le rôle) et la révocation valident ensemble ; les lignes d'audit suivent le COMMIT, par
   `FailSafeAudit`. Rejeté : un événement Symfony après le COMMIT (non atomique : un échec laisse un mot de passe
   changé et des connexions vivantes) ; un événement dans la transaction (ordre implicite, audit avant COMMIT).
4. **Causes nouvelles** : `person`, `password-changed`, `trusted-tier-withdrawn` (≤ 32 caractères, kebab), toutes
   trois ajoutées au `CHECK` par **une** migration, première tâche, avant tout code qui les écrit
   (expand/contract, #175).
5. **Le palier perdu se décide là où les rôles s'écrivent** : `CpgUserRoleAdministrator::setSuperAdmin()`, une
   rétrogradation dont la liste écrite (`rolesAfterDemotion()`) ne contient pas `ROLE_TRUSTED` (compte sans
   e-mail, ADR 0003 D1). C'est le seul chemin applicatif qui retire le palier aujourd'hui ; la suppression de
   compte coupe déjà les connexions par cascade (son audit est #652).
6. **Mot de passe** : deux chemins changent le mot de passe d'un compte pouvant tenir une connexion.
   `CpgUserAdministrator::changePassword()` (backoffice), et — **correction de l'auteur, 2026-10-28** — l'activation
   par lien d'invitation, `PasswordSetupService::complete()` : la prémisse d'origine (« elle ne touche qu'un compte
   sans mot de passe ») était inexacte, `activate()` n'exigeant que `isPendingActivation()` (`invitedAt` posé,
   `activatedAt` nul). Un compte invité à qui un `ROLE_SUPER` a posé un mot de passe (#460) reste « en attente »
   mais se connecte déjà, peut avoir `ROLE_TRUSTED` et accorder des connexions ; une relance puis le nouveau lien
   **remplace** son mot de passe. Règle : quand `hasPassword()` est vrai **avant** `setPassword()` dans
   `activate()`, le lien remplace un mot de passe et révoque les connexions (tâche 5b, même port, même transaction,
   cause `password-changed`) ; sans mot de passe préexistant le lien en *pose* un, aucun appel. Le troisième
   écrivain, `CpgUserRepository::upgradePassword()` (re-hachage à la connexion, même mot de passe), ne change pas
   le secret : hors périmètre ; `CpgUserRegistrar` (CLI) crée un compte, il n'en change aucun.
7. **API** : `GET /api/me/mcp-connections` (200) et `DELETE /api/me/mcp-connections/{id}` (204), contrôleur
   léger → `Application/OwnMcpConnectionsInterface`. Sous `^/api/me(/|$)` (`ROLE_TRUSTED`, règle existante) :
   aucune règle `access_control` nouvelle, aucune entrée de `PUBLIC_PATHS`/`BASE_TIER_PATHS`, parité entre
   environnements inchangée ; `ApiRouteExposureTest` exige seul le 401 anonyme et le 403 du palier de base. Pas
   d'API Platform : comme `ConsentController`, une réponse JSON écrite à la main, `Cache-Control: no-store`.
8. **État** (`OwnMcpConnectionState`) : `revoked` si `revoked_at` posé et cause ≠ `expired` ; `expired` si la
   cause est `expired` **ou** si `isExpiredAt(now)` (jamais `revoked_at` seul, commentaire #649) ; `active` sinon.
   L'échéance affichée est **une** définition : `McpConnection::deadline()`, dont `isExpiredAt()` devient
   `$now >= deadline(...)`.
9. **#730, piste 1** : colonne `code_exchanged_at` (`datetimetz_immutable`, nullable), écrite par
   `CodeExchangeSupersession::supersedeOthersOf()` sur l'instance que league a verrouillée (même déclencheur,
   « premier échange réussi », même transaction). Sixième durée `app.mcp.lifetime.connection_unexchanged`
   (`PT1H`), **temps écoulé seulement** (aucune composante d'année, de mois ni de jour), refusée si elle ne
   dépasse pas la durée d'un code plus deux fois `SECOND_TRUNCATION_AND_SKEW_MARGIN`, ou si elle atteint
   `connection_idle`. Borne de présélection : `now − connection_unexchanged + SECOND_TRUNCATION_AND_SKEW_MARGIN`.
   Ainsi une connexion dont le code est encore échangeable n'est **jamais** candidate (CR1 de #726 préservé) :
   candidate ⇒ âge ≥ U − 1 min > code + 1 min. Une colonne plutôt que `last_used_at` écrit à l'échange : celui-ci
   ne s'écrit qu'au rafraîchissement (`RefreshRotationTest` le pince), et un `EXISTS` sur les jetons ne survit
   pas à la purge des jetons morts.
10. **Consentement** : le résumé `GET /api/oauth/consent` porte `account.username`, lu sur `#[CurrentUser]` —
    le compte dont le cookie arrive réellement, pas l'état du frontend (qu'un autre onglet a pu périmer). Route
    déjà `ROLE_TRUSTED`, l'identifiant est celui de l'appelant lui-même, jamais journalisé. Lien « Changer de
    compte » vers la connexion avec `reauth=1` et `redirect` (mécanisme existant de #647).
11. **Page** : `/<locale>/mcp-connections`, `requiresAuth`, `roles: [ROLE_TRUSTED, ROLE_SUPER]`, `noindex`.
    Confirmation par `window.confirm()` (convention du backoffice, `frontend-backoffice.md`). Lien depuis le menu
    du compte (mobile) **et** depuis les actions du palier de confiance en desktop, où aucun menu n'existe
    aujourd'hui (voir question 2).

## Global Constraints

- Lire avec l'outil `Read` au moins un fichier de la zone avant d'y écrire (`backend/src/Mcp/Connection/…` charge
  `mcp-oauth*.md` ; `backend/src/Security/User/…` charge `security-user.md` ; `frontend/src/…` charge
  `frontend*.md`) : une commande shell ne charge aucune règle.
- Backend dans le conteneur, **toujours `-u dev`** : `docker compose exec -u dev backend php bin/phpunit <chemin>
  [--filter <test>]`, puis `make back-test` et `make back-quality` (PHPStan max, Rector, Psalm, `lsp:check`)
  avant chaque commit backend. Frontend : `make front-test`, `make front-lint`, `make front-build`.
- Après `sed -i` ou `git mv` sur l'hôte : `docker compose restart backend` (ou `frontend`) ; `rm -rf
  var/cache/test` si le conteneur DI est périmé.
- `declare(strict_types=1)`, propriétés/paramètres/retours typés, `final readonly` quand possible, aucune
  exception SPL nue dans `src/`, imports triés (`make back-style-fix`), commentaires en français.
- Horodatages `Types::DATETIMETZ_IMMUTABLE`, paramètres comparés typés pareil ; durées par `new DateInterval`,
  jamais `modify()`.
- **Aucun compte réel nommé** dans le dépôt : comptes de test fictifs (`trusted`, `other-trusted`, `admin`…),
  mots de passe par `TestCredentials`, jamais une chaîne en dur ; jamais un jeton, un code ni leur empreinte
  dans un journal, un message d'exception ou un message d'échec de test.
- Toute ligne d'audit d'une révocation s'écrit **après le COMMIT**, une par connexion **que l'appel a
  révoquée**, aucune pour une connexion inconnue ou déjà révoquée ; une panne de l'audit ne change pas la
  réponse (`Application/FailSafeAudit`, `critical`, classe seule).
- Chaque migration ajoute sa ligne d'empreinte à `k8s/base/postgres-roles/verify.sql` (bloc `#611`) :
  `DatabaseOwnerSettingsVerificationTest` la refuse absente et l'imprime.
- Une tâche, un commit (fichiers nommés, jamais `git add -A`), message en français terminé par
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Les règles, la spec et le registre sont mis à jour
  par la tâche 11 ; chaque tâche garde ses docblocks justes.
- PR de chaque tâche vers `feature/spec-0007-t11-mes-connexions` (mère de la tâche), jamais `develop`.

## Review Focus

1. **Une connexion échue mais pas encore marquée par la purge** : listée `expired` (pas de bouton) ; un DELETE
   direct la révoque `person` (elle n'était pas révoquée) — Tâche 2 `testAnExpiredButUnmarkedConnectionIsListedExpired`,
   Tâche 3 `testRevokingAnExpiredUnmarkedConnectionRecordsPerson`.
2. **DELETE d'une connexion à soi déjà révoquée** : 204, instant et cause inchangés, aucune ligne — Tâche 3
   `testRevokingAnAlreadyRevokedConnectionIsIdempotent`.
3. **Révocation par la personne croisant un rafraîchissement ou le marquage de la purge** : elle attend le
   COMMIT, puis révoque (rafraîchissement) ou rend 204 sans ligne (purge déjà passée) — Tâche 4.
4. **UUID d'un autre compte, UUID inconnu, UUID en majuscules ou malformé** : 404 au corps identique pour les
   deux premiers, 404 du routeur pour les deux derniers — Tâche 3 `testAnotherAccountsConnectionIsIndistinguishableFromAnUnknownOne`.
5. **Cause inconnue du frontend** (un backend plus récent) : libellé générique, aucune erreur — Tâche 10
   `affiche un libellé générique pour une cause inconnue`.

---

### Task 1 : élargir le `CHECK` des causes de révocation — **Sensible (migration)**

**Files :**
- Create : `backend/migrations/Version20261028120000.php`
- Modify : `backend/src/Mcp/Connection/Domain/ValueObject/RevocationCause.php` (trois cas, docblock)
- Modify : `k8s/base/postgres-roles/verify.sql` (une ligne d'empreinte, n° 36)
- Test : `backend/tests/Migrations/Version20261028120000Test.php` (nouveau, sur le modèle de `Version20261027120000Test`),
  `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepositoryTest.php` (le jeu de données des causes lit l'énumération : rien à écrire si c'est déjà le cas, sinon l'étendre)

**Interfaces :**
- Produces : `RevocationCause::Person = 'person'`, `RevocationCause::PasswordChanged = 'password-changed'`,
  `RevocationCause::TrustedTierWithdrawn = 'trusted-tier-withdrawn'`.

- [x] **Step 1 : tests rouges.** Ajouter les trois cas à l'énumération **sans** migration ; lancer
  `McpConnectionRepositoryTest` → rouge en SQLSTATE 23514 (`chk_mcp_connection_revocation_cause_value`) sur les
  trois nouvelles valeurs (la mesure de l'expand/contract). Écrire `Version20261028120000Test` :
  `testUpAdmitsTheThreeNewCauses` (une connexion écrite en SQL, révoquée en SQL avec chacune), `testDownRestoresTheFiveCauses`
  (après `down()`, `person` refusé en 23514), `testDownRefusesWhileANewCauseIsStored` (une ligne `person` : `down()`
  échoue sur l'`ADD CONSTRAINT`, la base inchangée — le retour arrière exige de décider quoi faire de ces lignes,
  jamais de les réécrire en silence). Transactions annulées, comme le test modèle.
- [x] **Step 2 : implémenter la migration.** `up()` : `ALTER TABLE mcp_connection DROP CONSTRAINT
  chk_mcp_connection_revocation_cause_value`, puis `ADD CONSTRAINT` du même nom avec les huit valeurs **écrites en
  clair** (`client`, `refresh-token-reuse`, `code-replay`, `superseded`, `expired`, `person`, `password-changed`,
  `trusted-tier-withdrawn`). `down()` : la contrainte à cinq valeurs. Docblock : pourquoi avant le code (#175),
  aucune ligne existante ne peut violer l'élargissement, verrou bref borné par le `lock_timeout` du Job (#525),
  migration avant rollout sans fenêtre.
- [x] **Step 3 : empreinte.** `docker compose exec -u dev backend php bin/phpunit tests/Shared/Infrastructure/Postgres/DatabaseOwnerSettingsVerificationTest.php`
  → rouge, imprime la ligne ; l'ajouter à `verify.sql` (`('DoctrineMigrations\Version20261028120000', 36, '<empreinte>')`).
- [x] **Step 4 : vert.** `tests/Migrations/`, `McpConnectionRepositoryTest`, puis `make back-test`, `make back-quality`.
  `php bin/console doctrine:migrations:diff` (dans le conteneur) ne propose rien.
- [x] **Step 5 : commit** `feat(mcp): trois causes de révocation admises par la base avant le code (#650)`.

### Task 2 : lister ses connexions — `GET /api/me/mcp-connections`

**Files :**
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpConnection.php` (`deadline()`, `isExpiredAt()` réécrit dessus)
- Modify : `backend/src/Mcp/Connection/Domain/Repository/McpConnectionRepositoryInterface.php`, `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepository.php` (`findAllOfAccount()`)
- Create : `backend/src/Mcp/Connection/Application/OwnMcpConnectionsInterface.php`, `OwnMcpConnections.php`, `OwnMcpConnectionView.php`, `OwnMcpConnectionState.php`
- Create : `backend/src/Mcp/Connection/Presentation/Controller/OwnMcpConnectionsController.php`
- Test : `backend/tests/Mcp/Connection/Presentation/Controller/OwnMcpConnectionsControllerTest.php` (nouveau, `HoldsAnMcpConnection`), `backend/tests/Mcp/Connection/Domain/Entity/McpConnectionExpiryTest.php`, `McpConnectionRepositoryTest.php`

**Interfaces :**
- Produces :
  - `McpConnection::deadline(DateTimeZone $zone, ConnectionLifetimes $lifetimes): DateTimeImmutable` — la plus
    proche des échéances (inactivité, maximum ; #730 en ajoute une), dans `$zone` ; `isExpiredAt($now, $l)` ≡
    `$now >= $this->deadline($now->getTimezone(), $l)`.
  - `McpConnectionRepositoryInterface::findAllOfAccount(Uuid $accountId): list<McpConnection>` — client joint
    (`innerJoin` + `addSelect`, QueryBuilder), `ORDER BY grantedAt DESC, id DESC`, révoquées comprises (rétention
    6 mois).
  - `enum OwnMcpConnectionState: string { Active = 'active'; Expired = 'expired'; Revoked = 'revoked'; }`
  - `final readonly class OwnMcpConnectionView` : `Uuid $id`, `string $clientId`, `string $clientName`,
    `DateTimeImmutable $grantedAt`, `?DateTimeImmutable $lastUsedAt`, `DateTimeImmutable $expiresAt`,
    `OwnMcpConnectionState $state`, `?DateTimeImmutable $revokedAt`, `?RevocationCause $revocationCause`.
  - `OwnMcpConnectionsInterface::of(AccountReference $account): list<OwnMcpConnectionView>` (l'instant de
    `ClockInterface`, les durées de `ConnectionLifetimes`).
  - Route `me_mcp_connections_list`, `GET /api/me/mcp-connections` → 200
    `{"connections":[{"id","client":{"id","name"},"grantedAt","lastUsedAt","expiresAt","state","revokedAt","revocationCause"}]}`,
    instants en `DateTimeInterface::ATOM` dans le fuseau de l'horloge, `Cache-Control: no-store`, compte lu par
    `#[CurrentUser] CpgUser`.

- [x] **Step 1 : tests rouges du domaine.** `McpConnectionExpiryTest::testTheDeadlineIsTheEarliestOfIdleAndMaximum`
  (dernier usage récent : maximum ; ancien : inactivité) ; `testIsExpiredAtAgreesWithTheDeadlineSecondBySecond`
  (la seconde de l'échéance et celle d'avant, dans les deux changements d'heure, comme le balayage existant).
- [x] **Step 2 : tests rouges du contrôleur** (`OwnMcpConnectionsControllerTest`) :
  `testItListsOnlyTheCallersConnections` (deux connexions du compte `trusted` — `holdMcpConnection()`,
  `holdAnotherMcpConnection()` —, une d'un compte `other-trusted` : la liste du premier en a deux, aucune
  `id` de l'autre) ; `testTheShapeIsExact` (clés exactes, `client.name` celui de `RegisteredMcpClient`,
  `lastUsedAt` `null` après l'échange, `state` `active`, `no-store`) ;
  `testAnExpiredButUnmarkedConnectionIsListedExpired` (`alterHeldConnection()` recule `granted_at` au-delà de
  `connection_maximum`, `revoked_at` nul : `expired`) ; `testARevokedConnectionShowsItsCause` (révoquée en SQL
  `superseded` : `revoked`, `revocationCause` `superseded` ; cause `expired` : état `expired`) ;
  `testAccumulatedGrantsAreListed` (deux approbations au même client, aucun échange : deux entrées `active`) ;
  `testTheBaseTierIsForbidden` (jeton de `POST /api/account/base-access` : 403) ;
  `testAnAnonymousCallerIsUnauthorized` (401) ; `testTheListCostsOneQuery` (trois connexions, une seule requête
  pour la liste en plus de l'authentification, mesurée comme `testAnAuthenticatedCallCostsExactlyOneQueryMore`) ;
  `testNoTokenReachesTheBody` (ni le jeton d'accès ni son empreinte dans le corps).
- [x] **Step 3 : les lancer** : rouges (route absente, méthode absente).
- [x] **Step 4 : implémenter.** `deadline()` puis `isExpiredAt()` dessus (même arithmétique : instants ramenés
  au fuseau, `add(new DateInterval)` dans `ConnectionLifetimes`) ; `findAllOfAccount()` ; `OwnMcpConnections::of()`
  (règle d'état de la décision 8, aucune autre requête : le client est joint) ; contrôleur léger (aucun calcul :
  il formate les vues). `ApiRouteExposureTest`, `ItemRouteRequirementTest`, `McpExposureSymmetryTest` (palier de
  confiance : hors de son sens 1), `RouteEnvironmentParityTest` doivent rester verts sans entrée nouvelle.
- [x] **Step 5 : vert.** Les fichiers ci-dessus, puis `tests/Mcp/`, `tests/Security/`, `make back-test`, `make back-quality`.
- [x] **Step 6 : commit** `feat(mcp): la personne liste ses connexions MCP (#650)`.

### Task 3 : révoquer une de ses connexions — `DELETE /api/me/mcp-connections/{id}` — **Sensible (sécurité)**

**Files :**
- Modify : `McpConnectionRepositoryInterface.php`, `McpConnectionRepository.php` (une constante `WHERE_ONE_OF_ACCOUNT`, deux méthodes)
- Modify : `OwnMcpConnectionsInterface.php`, `OwnMcpConnections.php`, `OwnMcpConnectionsController.php`
- Create : `backend/src/Mcp/Connection/Domain/Exception/McpConnectionNotFoundException.php`
- Modify : `backend/config/packages/framework.yaml` (`log_level: info` de l'exception)
- Test : `OwnMcpConnectionsControllerTest.php`, `McpConnectionRepositoryTest.php`, `backend/tests/Mcp/Connection/Application/OwnMcpConnectionsTest.php` (nouveau, unitaire)

**Interfaces :**
- Consumes : `RevocationCause::Person` (Task 1) ; `OwnMcpConnections` (Task 2).
- Produces :
  - `revokeActiveOfAccount(Uuid $connectionId, Uuid $accountId, RevocationCause $cause, DateTimeImmutable $at): ?RevokedConnection`
    — `revokeWhere(self::WHERE_ONE_OF_ACCOUNT, …)`, `WHERE_ONE_OF_ACCOUNT = 'c.id = :id AND c.account_id = :account'`.
  - `belongsToAccount(Uuid $connectionId, Uuid $accountId): bool` (une requête, révoquée ou non).
  - `OwnMcpConnectionsInterface::revoke(AccountReference $account, Uuid $connectionId): void` — lève
    `McpConnectionNotFoundException` (404, `ProblemExceptionInterface`, message constant sans UUID, sur le modèle
    de `ReauthenticationRequiredException`).
  - Route `me_mcp_connections_revoke`, `DELETE /api/me/mcp-connections/{id}`, `requirements: ['id' => Requirement::UUID]`
    → 204 `no-store` ; garde CSRF double-submit appliquée (aucune exclusion).

- [x] **Step 1 : tests rouges** (`OwnMcpConnectionsControllerTest`) :
  `testRevokingOwnConnectionCutsTheNextNominativeCall` (**bout en bout**, critère de #650 : `holdMcpConnection()`,
  la personne se reconnecte — `signInAs(TRUSTED_USERNAME, TestCredentials::superPassword())` —, DELETE → 204 ;
  cookies vidés ; `tools/call` de `get_nominative_corpus` avec `bearer()` → 401 `invalid_token`, comme
  `GetNominativeCorpusToolTest` ; le rafraîchissement de la connexion → `invalid_grant` sans ligne) ;
  `testTheRevocationIsAuditedOnce` (une ligne `mcp-connection-revoked` lue **sur la requête du DELETE** : `cause`
  `person`, `userId`, `connectionId`, `clientId` public, `actor` = l'UUID du compte ; aucune sur la requête
  suivante) ; `testRevokingAnAlreadyRevokedConnectionIsIdempotent` (révoquée `client` en SQL : 204, cause et
  instant inchangés, aucune ligne) ; `testRevokingAnExpiredUnmarkedConnectionRecordsPerson` ;
  `testAnotherAccountsConnectionIsIndistinguishableFromAnUnknownOne` (connexion de `other-trusted` et UUID v7
  inconnu : 404, même `type`, même `detail`, même corps hors `trace` ; la connexion de l'autre reste active,
  aucune ligne) ; `testAMalformedOrUpperCaseIdIsARouterNotFound` ; `testTheOtherConnectionsAreLeftAlone`
  (`holdAnotherMcpConnection()` reste active) ; `testWithoutTheCsrfHeaderItIsForbidden` (403, rien révoqué) ;
  `testTheBaseTierIsForbidden` ; `testAnAuditFailureLeavesThe204` (le journaliseur remplacé après un redémarrage
  du noyau, comme `TokenEndpointSupersessionAuditTest` : 204, connexion révoquée, ligne `critical`
  `Revoked connection not audited.` classe seule).
  `McpConnectionRepositoryTest::testRevokeActiveOfAccountTouchesOnlyThatAccountsRow` (autre compte → `null`,
  ligne intacte) ; `OwnMcpConnectionsTest` (dépôt et journaliseur doublés : `null` + `belongsToAccount` vrai →
  aucun audit ; `null` + faux → exception ; révoquée → un audit, `person`).
- [x] **Step 2 : les lancer** : rouges (405 ou 404 de route).
- [x] **Step 3 : implémenter.** `revoke()` : `revokeActiveOfAccount(…, RevocationCause::Person, $clock->now())` ;
  `null` → `belongsToAccount()` ? retour : exception ; sinon `FailSafeAudit::attempt(fn () =>
  $audit->mcpConnectionRevoked($revoked, $revoked->cause()->value), 'Revoked connection not audited.')`. Docblock
  de la classe : pourquoi une instruction sans `TransactionInterface` (décision 1), l'ordre des verrous (la
  connexion seule, `FOR NO KEY UPDATE`, par id : aucun cycle avec le point de jeton, la purge, le remplacement,
  qui prennent tous la connexion d'abord), la cause `person` sur une connexion échue non marquée (décision du
  Review Focus 1).
- [x] **Step 4 : vert** ; `tests/Mcp/`, `tests/Security/` (exposition, `ExceptionLogLevelCoverageTest`), `make back-test`, `make back-quality`.
- [x] **Step 5 : commit** `feat(mcp): la personne révoque une de ses connexions MCP (#650)`.

### Task 4 : la révocation par la personne sous concurrence — **Sensible (concurrence)**

**Files :**
- Create : `backend/tests/Mcp/Connection/OwnMcpConnectionRevocationConcurrencyTest.php`
- Create : `backend/tests/Mcp/Connection/Fixtures/revoke-own-mcp-connection.php` (enfant, sur le modèle de `revoke-at-revocation-endpoint.php` : appelle `OwnMcpConnectionsInterface::revoke()` dans le noyau de test, écrit `done` — 204 —, ou `not-found` sur sa sortie ; la base dit ensuite qui a révoqué)
- Modify : `.claude/rules/backend-outillage.md` (« Concurrency tests » : les deux tests et leur mutation) — seule exception à la tâche de documentation, la règle nomme chaque test de concurrence.

**Interfaces :** Consumes : `OwnMcpConnectionsInterface::revoke()` (Task 3) ; `SessionLockWait`, harnais de `RevocationEndpointConcurrencyTest`.

- [x] **Step 1 : tests.** `testARevocationCrossingARefreshRevokesTheConnection` : le test tient la connexion
  `FOR UPDATE` dans sa transaction et y écrit `last_used_at` (ce que fait un rafraîchissement) ; l'enfant lance
  la révocation, `SessionLockWait::untilBlocked()` ; le test valide ; l'enfant écrit `done` ; la connexion
  révoquée `person`, `last_used_at` celui du test, une ligne d'audit. `testARevocationCrossingThePurgeMarkingWritesNoSecondLine` :
  le test révoque la connexion `expired` sous son verrou (ce que fait le marquage), l'enfant bloque, le test
  valide ; l'enfant écrit `done` (204 : la connexion est à elle), la cause reste `expired`, aucune ligne
  `person`. Filet `statement_timeout = '3s'` comme les voisins.
- [x] **Step 2 : les lancer et mesurer.** Attendu vert sans changement de code (après l'attente, PostgreSQL
  réévalue `revoked_at IS NULL` sur la ligne validée). Mutations à mesurer et écrire : `SKIP LOCKED` dans la
  sous-requête de `revokeWhere()` → premier test rouge (connexion laissée active) ; les deux conditions
  `revoked_at IS NULL` retirées → second rouge (cause réécrite `person`, seconde ligne). Si une mutation laisse
  son test vert, le dire et chercher une disposition qui le rende discriminant.
  **Mesuré (2026-10-28)** : `SKIP LOCKED` rougit les **deux** tests (au `untilBlocked()` : le fils ne se bloque plus) ;
  les deux `revoked_at IS NULL` retirés rougissent le second seul ; chacune des deux conditions retirée **seule**
  laisse les deux verts (redondantes sur ce croisement), de même `FOR NO KEY UPDATE` retiré (rôle : l'ordre par
  identifiant, épinglé ailleurs). Écrit dans `backend-outillage.md`.
- [x] **Step 3 : `make back-test`, `make back-quality`, commit** `test(mcp): la révocation par la personne croise un rafraîchissement et la purge (#650)`.

### Task 5 : révoquer les connexions d'un compte au changement de mot de passe — **Sensible (sécurité, transaction)**

**Files :**
- Create : `backend/src/Shared/Application/Security/AccountMcpConnectionsRevokerInterface.php`, `AccountAccessWithdrawal.php`, `RevokedAccountMcpConnections.php`
- Create : `backend/src/Mcp/Connection/Application/AccountMcpConnectionsRevoker.php`
- Create : `backend/src/Mcp/Connection/Infrastructure/Doctrine/RevocationOutsideTransactionException.php`
- Modify : `McpConnectionRepositoryInterface.php`, `McpConnectionRepository.php` (`WHERE_ALL_OF_ACCOUNT`, une méthode)
- Modify : `backend/src/Security/User/Application/CpgUserAdministrator.php` (`changePassword()`)
- Test : `backend/tests/Mcp/Connection/AccountWideRevocationTest.php` (nouveau, HTTP), `backend/tests/Mcp/Connection/Application/AccountMcpConnectionsRevokerTest.php` (nouveau), `backend/tests/Security/User/Application/CpgUserAdministratorTest.php` (doublure du port), `McpConnectionRepositoryTest.php`

**Interfaces :**
- Consumes : `RevocationCause::PasswordChanged` (Task 1).
- Produces :
  - `enum AccountAccessWithdrawal: string { PasswordChanged = 'password-changed'; TrustedTierWithdrawn = 'trusted-tier-withdrawn'; }`
  - `final readonly class RevokedAccountMcpConnections` : `AccountAccessWithdrawal $reason`, `list<McpConnectionReference> $connections`.
  - `AccountMcpConnectionsRevokerInterface::revokeAllOf(AccountReference $account, AccountAccessWithdrawal $reason): RevokedAccountMcpConnections`
    — **dans** la transaction de l'appelant (refuse hors transaction) ;
    `AccountMcpConnectionsRevokerInterface::auditRevoked(RevokedAccountMcpConnections $revoked): void` — après le
    COMMIT, une ligne `mcp-connection-revoked` par connexion, `FailSafeAudit`.
  - `McpConnectionRepositoryInterface::revokeAllActiveOfAccount(Uuid $accountId, RevocationCause $cause, DateTimeImmutable $at): list<RevokedConnection>`
    — `WHERE_ALL_OF_ACCOUNT = 'c.account_id = :account'` ; lève `RevocationOutsideTransactionException` (nommée,
    non mappée, `critical` 500) si aucune transaction n'est ouverte sur la connexion de l'EntityManager.
  - Correspondance `AccountAccessWithdrawal` → `RevocationCause` par un `match` exhaustif dans l'implémentation.

- [x] **Step 1 : tests rouges.** `AccountWideRevocationTest::testAPasswordChangeRevokesEveryConnectionOfTheAccount`
  (le compte `trusted` tient deux connexions, `other-trusted` une ; un compte `admin` `ROLE_SUPER`
  `PUT /api/backoffice/users/{id}/password` → 204 ; les deux connexions révoquées `password-changed`, celle de
  l'autre intacte ; sur la requête du PUT : `password-changed` (`event`) puis deux `mcp-connection-revoked`, `actor`
  l'admin ; `tools/call` nominatif avec l'ancien jeton → 401 ; rafraîchissement → `invalid_grant`).
  `testARefusedRevocationLeavesThePasswordUnchanged` (un déclencheur de test refuse l'`UPDATE` de
  `mcp_connection` : le PUT répond 500, l'ancien mot de passe ouvre toujours la session, aucune ligne
  `password-changed` — l'atomicité, rouge si l'appel sort de la fermeture ; même technique que
  `TokenControllerTest::testAFailedExchange…`). `testAPasswordChangeWithoutConnectionsWritesNoMcpLine`.
  `AccountMcpConnectionsRevokerTest::testItRefusesOutsideATransaction` ; `testEachRevokedConnectionIsAuditedOnceAfterwards`
  (doublure du journaliseur, deux connexions, la seconde audit en panne → la première écrite, une ligne `critical`).
  `McpConnectionRepositoryTest::testRevokeAllActiveOfAccountLeavesRevokedAndForeignRowsAlone`.
- [x] **Step 2 : les lancer** : rouges.
- [x] **Step 3 : implémenter.** `changePassword()` : dans la fermeture de `whileLocked()`, après `save()` et la
  purge des jetons d'invitation, `return $this->mcpConnections->revokeAllOf($locked, AccountAccessWithdrawal::PasswordChanged);`
  ; après le verrou : `passwordChanged()`, puis `auditRevoked()`. Docblocks : l'ordre des verrous (le compte
  `FOR UPDATE`, puis ses connexions par id — aucun chemin ne prend une connexion puis le compte : le point de
  jeton lit le compte sans verrou, le consentement prend un `FOR KEY SHARE` sur le compte avant d'insérer, la
  suppression en cascade suit le même sens) ; pourquoi un port de `Shared` (décision 3).
- [x] **Step 4 : vert** ; `tests/Security/`, `tests/Mcp/`, `PlainPasswordInputsTest` (le paramètre du mot de passe
  inchangé), `make back-test`, `make back-quality`.
- [x] **Step 5 : commit** `feat(security): un changement de mot de passe révoque les connexions MCP du compte (#650)`.

### Task 5b : un lien d'invitation qui remplace un mot de passe révoque les connexions — **Sensible (sécurité, transaction)**

Ajoutée par décision de l'auteur du 2026-10-28 (prémisse inexacte de la décision 6, corrigée ci-dessus).

**Files :**
- Modify : `backend/src/Security/User/Application/PasswordSetupService.php` (nouveau paramètre `AccountMcpConnectionsRevokerInterface`, `activate()` rend `?RevokedAccountMcpConnections`)
- Test : `backend/tests/Mcp/Connection/AccountWideRevocationTest.php` (3 tests HTTP), `backend/tests/Security/User/Application/PasswordSetupServiceTest.php` (3 tests, doublure du port)

**Constat :** `activate()` tourne déjà dans `AccountLockInterface::whileLocked()` (transaction `PostgresLockedTransaction`, compte `FOR UPDATE`, relu) : aucune transaction à introduire. Ordre des verrous : compte, jeton (`claim()`), puis connexions par identifiant — le sens de tous les autres chemins.

- [x] **Step 1 : tests rouges.** HTTP : `testAnInvitationLinkThatReplacesAPasswordRevokesEveryConnectionOfTheAccount` (compte rendu « en attente » en gardant son mot de passe, deux connexions + une d'un autre compte ; `POST /api/account/password-setup` → 204 ; révoquées `password-changed`, l'autre intacte ; lignes `account-activated` puis une par connexion, acteur `anonymous` ; ancien jeton 401 `invalid_token`, rafraîchissement `invalid_grant`), `testARefusedRevocationLeavesTheActivationUndone` (déclencheur sur `mcp_connection` : 500, ancien mot de passe valable, `activated_at` nul, `used_at` du jeton nul — le lien reste réessayable —, connexion active, aucune ligne d'audit), `testAnInvitationLinkOfAnAccountWithoutPasswordRevokesNothing` (contrôle : verte dès le départ). Unitaire : ordre `claim` → `revokeAllOf` (dans la fermeture, mot de passe déjà écrit) → retour du verrou → `accountActivated` → `auditRevoked` ; `testALinkSettingAFirstPasswordNeverCallsThePort` (`never()` sur les deux méthodes du port) ; `testARefusedRevocationIsNeitherSwallowedNorAudited`. Rouges : 204 sans révocation, et `revokeAllOf`/`auditRevoked` jamais appelés.
- [x] **Step 2 : implémenter.** `$replacesAPassword = $user->hasPassword()` lu **avant** `setPassword()`, sur le compte relu sous le verrou ; après `deleteUnusedForUser()`, `return $replacesAPassword ? revokeAllOf($user, PasswordChanged) : null` ; dans `complete()`, `accountActivated()` puis `auditRevoked()` (si non nul) après le retour du verrou.
- [x] **Step 3 : mutations mesurées.** Révocation sortie de la fermeture (garde de transaction du dépôt retirée) → `testARefusedRevocationLeavesTheActivationUndone` et les deux tests unitaires d'ordre/refus rougissent (garde en place : 500 sur les trois HTTP) ; `hasPassword()` remplacé par `true` → les trois tests unitaires rougissent (le test HTTP du compte sans mot de passe ne le voit pas : ce compte n'a aucune connexion à révoquer, seul le `never()` du port le prouve) ; audit dans la fermeture → ordre HTTP et unitaire rouges ; audit absent → les deux rouges.
- [x] **Step 4 : `make back-test`, `make back-quality`, commit** `fix(security): un lien d'invitation qui remplace un mot de passe révoque les connexions MCP (#650)`.

### Task 6 : révoquer les connexions d'un compte qui perd `ROLE_TRUSTED` — **Sensible (sécurité)**

**Files :**
- Modify : `backend/src/Security/User/Application/CpgUserRoleAdministrator.php`
- Test : `backend/tests/Mcp/Connection/AccountWideRevocationTest.php`, `backend/tests/Security/User/Application/CpgUserRoleAdministratorTest.php`

**Interfaces :** Consumes : le port de la Task 5, `RevocationCause::TrustedTierWithdrawn` (Task 1).

- [x] **Step 1 : tests rouges.** `testADemotionOutOfTheTrustedTierRevokesEveryConnection` (connexion tenue par
  `trusted`, ses rôles stockés réécrits `['ROLE_SUPER']` par `rewriteHeldAccountRoles()`, sans e-mail ; `admin`
  `PUT …/roles` `{"superAdmin": false}` → 204 ; connexion révoquée `trusted-tier-withdrawn`, ligne après
  `role-changed` sur la même requête ; rétabli `ROLE_TRUSTED` en SQL, l'ancien jeton reste refusé — le défaut que
  le commentaire de #649 relevait). `testADemotionKeepingTheTrustedTierRevokesNothing` (même chose avec un e-mail
  posé en SQL : rôles `['ROLE_TRUSTED']`, connexion active, aucune ligne MCP). `testAPromotionRevokesNothing`.
  `CpgUserRoleAdministratorTest` : le port doublé n'est appelé qu'à la rétrogradation sans e-mail, jamais sur la
  sortie idempotente ni sur un refus (`CannotDemoteLastSuperAdminException`).
- [x] **Step 2 : rouges.** **Step 3 : implémenter** : la fermeture de `whileAdministering()` rend
  `?RevokedAccountMcpConnections` (`null` : rien à révoquer ou rien changé) en plus du changement — un petit objet
  de retour privé ou un tableau typé `array{bool, ?RevokedAccountMcpConnections}` au choix motivé ; après le
  verrou, `roleChanged()` puis `auditRevoked()`. Le palier perdu : `!$grant && !\in_array(CpgUser::ROLE_TRUSTED, $newRoles, true)`
  sur la liste **écrite** (jamais `getRoles()`, règle #560), commentaire qui le dit.
- [x] **Step 4 : vert** ; `AccountAdministrationConcurrencyTest`, `tests/Security/`, `tests/Mcp/`, `make back-test`, `make back-quality`.
- [x] **Step 5 : commit** `feat(security): perdre le palier de confiance révoque les connexions MCP du compte (#650)`.

### Task 7 : #730 — une connexion jamais échangée expire tôt — **Sensible (migration, concurrence)**

**Files :**
- Create : `backend/migrations/Version20261028140000.php` ; Modify : `k8s/base/postgres-roles/verify.sql` (n° 37)
- Modify : `McpConnection.php` (colonne, accesseur, `recordCodeExchangeAt()`, `deadline()`)
- Modify : `ConnectionLifetimes.php`, `PreselectionBounds.php`, `backend/src/Mcp/Connection/Domain/Exception/InvalidConnectionLifetimeException.php`, `backend/config/services.yaml` (paramètre et argument)
- Modify : `CodeExchangeSupersession.php` (`supersedeOthersOf()`), `McpConnectionRepositoryInterface.php` / `McpConnectionRepository.php` (`findActiveCandidatesForExpiry(PreselectionBounds $bounds)`), `McpConnectionPurger.php` (l'appel)
- Test : `backend/tests/Migrations/Version20261028140000Test.php` (nouveau), `ConnectionLifetimesTest.php`, `ConnectionLifetimesWiringTest`, `McpConnectionExpiryTest.php`, `CodeExchangeSupersessionTest.php`, `McpConnectionPurgerTest.php`, `McpConnectionPurgeLockOrderTest.php` (appel adapté), `backend/tests/Mcp/Connection/AbandonedGrantsTest.php` (nouveau), `RefreshRotationTest.php`

**Interfaces :**
- Produces :
  - colonne `mcp_connection.code_exchanged_at TIMESTAMP(0) WITH TIME ZONE NULL` ; `McpConnection::codeExchangedAt(): ?DateTimeImmutable` ;
    `McpConnection::recordCodeExchangeAt(DateTimeImmutable $at): void` (écrit une fois, ignore les suivants).
  - `ConnectionLifetimes::fromConfiguration(…, string $unexchangedLifetime)` (sixième paramètre, nom de refus
    `unexchangedLifetime`) ; `unexchanged(): DateInterval` ; `unexchangedDeadline(DateTimeImmutable $grantedAt): DateTimeImmutable` ;
    `preselectionBoundsAt()` rend aussi `PreselectionBounds::unexchangedBefore()`.
  - `InvalidConnectionLifetimeException::notElapsedTime(string $parameter)`, `::notBeyondCodeWindow(string $parameter)`, `::notBelowIdle(string $parameter)`.
  - `findActiveCandidatesForExpiry(PreselectionBounds $bounds): list<McpConnection>` (ajoute `OR (c.code_exchanged_at IS NULL AND c.granted_at <= :unexchangedBefore)`).
  - Paramètre `app.mcp.lifetime.connection_unexchanged: 'PT1H'`.

- [x] **Step 1 : la reproduction de #730, rouge.** `AbandonedGrantsTest::testAbandonedApprovalsAccumulateThenExpire` :
  trois approbations du compte `trusted` au même client, aucun code échangé → trois connexions actives (la
  reproduction) ; `granted_at` reculé en SQL de `connection_unexchanged` plus une seconde → `GET /api/me/mcp-connections`
  les dit `expired` ; `app:mcp:purge` les marque `expired`, trois lignes `mcp-connection-revoked` acteur `system`.
  `testAnExchangedConnectionOutlivesTheUnexchangedLifetime` (une connexion échangée, `granted_at` reculé pareil :
  `active`, rafraîchissement 200, non marquée). `testACodeStillExchangeableIsNeverPreselected` : une connexion
  octroyée il y a la durée d'un code plus une minute n'est pas présélectionnée.
- [x] **Step 2 : tests rouges unitaires.** `ConnectionLifetimesTest` : `PT1H` accepté ; `P1D`, `P1DT1H` refusés
  `notElapsedTime` ; `PT12M` refusé et `PT12M1S` accepté avec un code `PT10M` (`notBeyondCodeWindow`) ;
  `connection_idle` égal refusé `notBelowIdle` ; `testAFreshConnectionIsNeverPreselectedAsUnexchanged` (balayage
  seconde à seconde autour des deux changements d'heure, sur le modèle de
  `testAFreshConnectionIsNeverPreselectedAcrossTheClockChanges`). `McpConnectionExpiryTest` : l'échéance des non
  échangées à la seconde ; une connexion échangée ne la connaît pas. `CodeExchangeSupersessionTest` :
  `recordCodeExchangeAt()` appelé sur l'instance gardée, à l'instant de l'horloge. `McpConnectionPurgerTest::testThePurgeAndIsExpiredAtAgreeAtTheBoundary`
  étendu à la troisième borne. `Version20261028140000Test` : la colonne ajoutée, le rattrapage (une connexion avec
  jeton ou `last_used_at` reçoit `granted_at`, une sans aucun reste `NULL`), `down()` retire la colonne.
- [x] **Step 3 : implémenter.** Migration : `ALTER TABLE mcp_connection ADD code_exchanged_at TIMESTAMP(0) WITH
  TIME ZONE DEFAULT NULL`, puis `UPDATE … SET code_exchanged_at = granted_at WHERE last_used_at IS NOT NULL OR
  EXISTS (mcp_access_token) OR EXISTS (mcp_refresh_token)` (l'instant est approché : seul « nul ou non » est lu,
  docblock le dit ; la phase 2 n'étant jamais sortie, aucune base de production n'a de ligne). Domaine et
  durées selon les Interfaces ; `deadline()` ajoute `unexchangedDeadline(grantedAt)` quand `codeExchangedAt` est nul.
  `supersedeOthersOf()` : `$kept->recordCodeExchangeAt($now)` puis `save($kept)` **avant** `revokeOtherActiveOf()`
  (même transaction, instance déjà verrouillée par league ; l'ordre des verrous inchangé). Docblocks :
  `ConnectionLifetimes` (pourquoi le temps écoulé seul et la marge d'une minute suffisent ; pourquoi CR1 tient :
  candidate ⇒ code mort ⇒ jamais verrouillée la première par un échange, toutes les autres prises par id).
- [x] **Step 4 : empreinte** de la migration dans `verify.sql` (Task 1, Step 3) ; `doctrine:migrations:diff` vide.
- [x] **Step 5 : vert** ; `tests/Mcp/` entier (dont les tests de concurrence et d'ordre de verrous), `tests/Migrations/`,
  `make back-test`, `make back-quality`. Mutation à mesurer et écrire dans le commit : la borne sans la marge
  d'une minute → `testThePurgeAndIsExpiredAtAgreeAtTheBoundary` rouge ; `recordCodeExchangeAt()` retiré →
  `testAnExchangedConnectionOutlivesTheUnexchangedLifetime` rouge.
- [x] **Step 6 : commit** `fix(mcp): une connexion dont le code n'est jamais échangé expire au bout d'une heure (#730)`.

### Task 8 : la page de consentement dit quel compte accorde

**Files :**
- Modify : `backend/src/Mcp/Connection/Presentation/Controller/ConsentController.php` (`show()`)
- Modify : `frontend/src/domain/mcp/entities/ConsentRequest.ts`, `frontend/src/infrastructure/mcp/HttpOAuthConsentRepository.ts`, `frontend/src/presentation/pages/OAuthConsentPage.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `backend/tests/Mcp/Connection/Presentation/Controller/ConsentControllerTest.php`, `frontend/tests/infrastructure/mcp/HttpOAuthConsentRepository.spec.ts`, `frontend/tests/presentation/pages/OAuthConsentPage.spec.ts`

**Interfaces :**
- Produces : le résumé JSON gagne `"account": {"username": "<identifiant de connexion de l'appelant>"}` ;
  `ConsentRequest.account: { username: string }`.

- [x] **Step 1 : tests rouges.** `ConsentControllerTest::testTheSummaryNamesTheSignedInAccount` (compte `trusted` :
  `account.username` `trusted` ; après `signInAs()` d'`other-trusted` dans le même client, le sien) ; le 403 du
  palier de base et le 401 anonyme inchangés ; `SecurityAuditLoggerTest`/`AccountIdentifierReachesNoLogTest`
  restent verts (l'identifiant n'entre dans aucun journal). Vitest : le dépôt refuse un résumé sans `account`
  ou à `username` non chaîne (`invalid`) ; la page affiche « Vous accordez cet accès en tant que trusted » (FR/EN),
  et le lien « Changer de compte » vise `/<locale>/login?redirect=<fullPath>&reauth=1`.
- [x] **Step 2 : rouges.** **Step 3 : implémenter** : `show(Request $request, #[CurrentUser] CpgUser $account)` ;
  `getUserIdentifier()` ; côté page, le compte placé au-dessus des boutons, dans le résumé (`<dl>`), le lien hors
  de la zone d'activation délibérée.
- [x] **Step 4 : vert** : `make back-test`, `make back-quality`, `make front-test`, `make front-lint`, `make front-build`.
- [x] **Step 5 : commit** `feat(mcp): la page de consentement dit quel compte accorde (#650)`.

### Task 9 : frontend — le dépôt et le composable des connexions

**Files :**
- Create : `frontend/src/domain/mcp/entities/McpConnection.ts`, `frontend/src/domain/mcp/repositories/McpConnectionsRepository.ts`, `frontend/src/domain/mcp/errors/McpConnectionNotFoundError.ts`, `frontend/src/domain/mcp/errors/McpConnectionsRequestFailedError.ts`
- Create : `frontend/src/infrastructure/mcp/HttpMcpConnectionsRepository.ts`, `frontend/src/application/mcp/useMcpConnections.ts`
- Modify : `frontend/src/main.ts` (provide)
- Test : `frontend/tests/infrastructure/mcp/HttpMcpConnectionsRepository.spec.ts`, `frontend/tests/application/mcp/useMcpConnections.spec.ts`

**Interfaces :**
- Consumes : l'API des Tasks 2 et 3.
- Produces :
  - `type McpConnectionState = 'active' | 'expired' | 'revoked'` ; `interface McpConnectionSummary { id: string;
    client: { id: string; name: string }; grantedAt: string; lastUsedAt: string | null; expiresAt: string;
    state: McpConnectionState; revokedAt: string | null; revocationCause: string | null }` (la cause reste une
    chaîne : une valeur inconnue s'affiche, Review Focus 5).
  - `interface McpConnectionsRepository { list(): Promise<McpConnectionSummary[]>; revoke(id: string): Promise<void> }`
  - `MCP_CONNECTIONS_REPOSITORY: InjectionKey<McpConnectionsRepository>` ;
    `useMcpConnections()` → `{ status: Ref<'loading'|'ready'|'error'>, connections: Ref<McpConnectionSummary[]>,
    revokingId: Ref<string|null>, lastOutcome: Ref<'revoked'|'already-gone'|'failed'|null>, load(): Promise<void>,
    revoke(id: string): Promise<void> }`.

- [x] **Step 1 : tests rouges.** Dépôt : `GET` avec `credentials: 'include'` ; `DELETE` avec `X-XSRF-TOKEN` de
  `readCsrfToken()` ; 401/403 → `SessionExpiredError` ; 404 du DELETE → `McpConnectionNotFoundError` ; corps
  malformé (clé manquante, `state` inconnu) → `McpConnectionsRequestFailedError`. Composable : chargement puis
  `ready` ; une révocation rechargée depuis le serveur (`lastOutcome` `revoked`) ; un 404 → `already-gone` et
  rechargement ; une erreur → `failed`, liste inchangée ; une session expirée → `markSessionExpired()` (comme
  `useAssistant`) ; deux révocations simultanées impossibles (`revokingId`).
- [x] **Step 2 : rouges.** **Step 3 : implémenter** (sur le modèle de `HttpOAuthConsentRepository` et
  `useOAuthConsent`). **Step 4 : vert** : `make front-test`, `make front-lint`, `make front-build`.
- [x] **Step 5 : commit** `feat(front): dépôt et composable des connexions MCP (#650)`.

### Task 10 : frontend — la page « Mes connexions MCP », sa route et ses liens

**Files :**
- Create : `frontend/src/presentation/pages/McpConnectionsPage.vue`, `frontend/src/presentation/format/instant.ts`
- Modify : `frontend/src/presentation/router/index.ts`, `frontend/src/presentation/layout/AppHeader.vue`, `frontend/src/infrastructure/i18n/locales/{fr,en}.json`
- Test : `frontend/tests/presentation/pages/McpConnectionsPage.spec.ts`, `frontend/tests/presentation/format/instant.spec.ts`, `frontend/tests/presentation/layout/AppHeader.spec.ts`, la spec du routeur qui couvre `requiresAuth`/`roles` (`adminGuard.spec.ts`)

**Interfaces :**
- Consumes : `useMcpConnections()` (Task 9).
- Produces : route `mcp-connections` (`path: 'mcp-connections'`, `meta: { requiresAuth: true, roles: [ROLE_TRUSTED, ROLE_SUPER], noindex: true, titleKey: 'seo.mcpConnections.title', descriptionKey: 'seo.mcpConnections.description' }`) ;
  `formatInstant(iso: string, locale: Locale): string` (`Intl.DateTimeFormat`, `dateStyle: 'medium'`, `timeStyle: 'short'`, fuseau `Europe/Paris`) ;
  clés i18n `nav.mcpConnections`, `seo.mcpConnections.*`, `mcpConnections.*` (dont une par état et une par cause
  connue : `person`, `client`, `refresh-token-reuse`, `code-replay`, `superseded`, `expired`, `password-changed`,
  `trusted-tier-withdrawn`, plus `unknownCause`).

- [x] **Step 1 : tests rouges** (`McpConnectionsPage.spec.ts`, dépôt doublé par l'`InjectionKey`) : liste en FR et
  en EN (client, dates formatées, état) ; `affiche l'état vide avec un lien vers « Brancher votre assistant »` ;
  bouton « Révoquer » sur les seules connexions `active`, nom accessible portant le client et la date ;
  `confirm` accepté → `revoke(id)` puis message dans la région `role="status"` ; `confirm` refusé → aucun appel ;
  404 → message « déjà terminée » ; erreur de chargement → `role="alert"` ; `affiche un libellé générique pour une
  cause inconnue` ; `aria-busy` pendant le chargement ; `expectNoAccessibilityViolation()` (liste et état vide).
  `AppHeader.spec.ts` : le lien vers `/<locale>/mcp-connections` présent au palier de confiance et pour
  `ROLE_SUPER` (menu du compte mobile et menu du compte desktop), absent aux paliers anonyme et de base. Routeur :
  anonyme → login avec `redirect`, palier de base → page interdite.
  Menu du compte desktop (décision de l'auteur du 2026-10-28, question 2) : `AppHeader.spec.ts` (ou la spec d'un
  composant extrait, `AccountMenu.spec.ts`) couvre le bouton `aria-expanded` (faux puis vrai au clic), `aria-controls`
  vers la liste, Échap qui ferme et rend le focus au bouton, un clic hors du menu qui le ferme, la fermeture à la
  navigation, et `expectNoAccessibilityViolation()` menu ouvert.
- [x] **Step 2 : rouges.** **Step 3 : implémenter.** `<h1>`, un tableau (`<caption>`, `<th scope="col">`), une
  `<h2>` par groupe si les connexions terminées sont séparées des actives (au choix motivé, sans saut de niveau),
  `window.confirm()` nommant le client ; texte via `t()` seulement (`no-raw-text`). Lien desktop : **un vrai menu
  du compte desktop** (décision de l'auteur du 2026-10-28), pas un bouton de plus dans les actions. Motif WAI-ARIA
  « disclosure » (bouton `aria-expanded` + `aria-controls`, liste de liens ordinaires), pas `role="menu"` : ce
  dernier impose la navigation aux flèches et sert aux menus d'application, pas à des liens de site. Il regroupe
  les actions de compte que l'en-tête desktop montre aujourd'hui à plat et que le menu mobile contient déjà (les
  relever dans `AppHeader.vue` avant d'écrire), plus « Mes connexions MCP » ; extraire un composant
  `AccountMenu.vue` si `AppHeader.vue` grossit trop. Vérifier à 768 px et 1024 px que l'en-tête ne déborde pas (le
  débordement de #265 est le précédent) et que le menu ouvert reste dans la fenêtre.
- [x] **Step 4 : vert** : `make front-test`, `make front-lint`, `make front-build` ; contrôle manuel au navigateur
  (tabulation, focus visible, contraste — ce que jsdom ne voit pas, `frontend-a11y.md`) noté dans la PR. *(Commandes vertes ; contrôle au navigateur non fait : il exige une session du palier de confiance — largeurs 768/1024 px justifiées par le CSS, la barre y est plus étroite qu'avant.)*
- [x] **Step 5 : commit** `feat(front): page « Mes connexions MCP » (#650)`.

### Task 11 : documentation, et clôture de #650 et #730

**Files :**
- Create : `.claude/rules/mcp-oauth-revocation-personne.md` (`paths:` : `backend/src/Mcp/Connection/Application/OwnMcpConnections*.php`,
  `backend/src/Mcp/Connection/Application/AccountMcpConnectionsRevoker.php`, `backend/src/Mcp/Connection/Presentation/Controller/OwnMcpConnectionsController.php`,
  `backend/src/Shared/Application/Security/AccountMcpConnectionsRevokerInterface.php`, les tests des Tasks 2 à 6,
  `frontend/src/presentation/pages/McpConnectionsPage.vue`, `frontend/src/*/mcp/*McpConnections*`) — la règle de
  `mcp-oauth-cycle-de-vie.md` (43,5 Ko) ne peut pas tout recevoir sous les 50 000 octets
- Modify : `.claude/rules/mcp-oauth-cycle-de-vie.md` (liste des causes ; « #650 adds `person` » et « #650's `person`
  cause widens… » au passé ; « Two application paths » : les trois nouvelles méthodes et le port, renvoi à la
  nouvelle règle ; « The durations live in configuration » : la sixième durée, ses trois refus ; « A connection's
  expiry » : `deadline()` et la troisième échéance ; « A new grant supersedes » : `recordCodeExchangeAt()`, B1 levé —
  la personne peut révoquer —, le double clic borné à une heure), `.claude/rules/mcp-oauth-purge.md` (troisième
  borne, CR1 préservé), `.claude/rules/mcp-oauth.md` (« Consent » : le compte affiché ; « revoking a connection
  from the site is #650 » → fait), `.claude/rules/security-user.md` (`CpgUserAdministrator`, `CpgUserRoleAdministrator` :
  la révocation dans leur transaction), `.claude/rules/security-authentication.md` (`mcp-connection-revoked` :
  les trois causes, leurs écrivains et acteurs ; le port de `Shared`, `Security` toujours sans classe de `Mcp`),
  `.claude/rules/frontend.md` (la tranche `mcp` des connexions, une phrase), `.claude/rules/mcp.md` et
  `.claude/CLAUDE.md` (index des règles)
- Modify : `.claude/specs/0007-mcp-server.md` (« Journal d'audit » : `person`, `password-changed`,
  `trusted-tier-withdrawn` ; « Mes connexions MCP » : routes, état, idempotence ; expiration d'une connexion
  jamais échangée ; B1 levé ; tests), `docs/rgpd/registre-traitements.md` (§3.4 : révocation par la personne, au
  changement de mot de passe, à la perte du palier, expiration à une heure d'un accord jamais échangé, le
  préalable de la phase 2 levé ; §6 : les causes nouvelles et leur acteur ; ligne 17 de l'en-tête),
  `CONTEXT.md` (« Connexion MCP » : « par cette personne, par un changement de mot de passe ou par la perte du
  palier de confiance » ; ajouter **Accord abandonné** si la relecture le juge utile : approbation dont le code
  n'est jamais échangé)
- À écrire aussi (tâche 5b) : `.claude/rules/security-user.md` (`PasswordSetupService` : le lien qui *remplace* un
  mot de passe — `hasPassword()` avant `setPassword()` — révoque les connexions MCP dans la transaction de
  l'activation, `auditRevoked()` après `accountActivated()`), `.claude/rules/security-authentication.md`
  (`mcp-connection-revoked`, cause `password-changed` : deux écrivains, le `PUT` du backoffice — acteur
  l'administrateur — et l'activation par lien — acteur `anonymous`), la règle nouvelle et la spec 0007
  (journal d'audit) ; la décision 6 du plan a été corrigée, ne pas reprendre l'ancienne prémisse
- Modify : `tasks/todo.md` (T11 cochée, #730, notes de release)

- [x] **Step 1 : écrire** les fichiers ci-dessus ; nouvelle règle sur le modèle d'en-tête de `mcp-oauth-purge.md`.
- [x] **Step 2 : vérifier** : `bash tools/check-claude-rules.sh` vert (aucune règle sans `paths:`, aucun glob vide,
  aucune au-delà de 50 000 octets) ; `git grep -n "#650 adds\|will come with #650\|viendra avec #650"` vide ;
  `git grep -n "#650 is a prerequisite"` réécrit au passé.
- [x] **Step 3 : `tasks/todo.md`** *(notes écrites ; fermer #650 et #730 reste à faire à la main, après la fusion)* — notes de release : deux migrations (`Version20261028120000`, `Version20261028140000`)
  avant le rollout, une durée nouvelle (`connection_unexchanged`), aucune variable d'environnement ; rappel :
  `Closes #650`/`Closes #730` ne ferment rien vers une base qui n'est pas `main` — fermer les deux issues à la
  main après la fusion dans la mère.
- [x] **Step 4 : commit** `docs(mcp): spec, règles, registre et vocabulaire de « Mes connexions MCP » (#650, #730)`.

---

## Limites acceptées par ce plan (à écrire dans la règle nouvelle, Task 11)

- **Le cookie de session survit au changement de mot de passe** (ADR 0003, JWT non révocable, une heure) : dans
  les dix minutes de son `iat`, un cookie volé peut encore accorder une connexion **après** le changement. La
  révocation de la Task 5 coupe tout ce qui existait, pas ce qui naît ensuite.
- **Course consentement × perte du palier** : une approbation en vol, autorisée par `access_control` avant la
  rétrogradation, peut insérer une connexion juste après la révocation de la Task 6. Elle reste refusée à l'usage
  (l'authentificateur et le rafraîchissement rejugent le palier), mais revivrait si le palier était rendu dans
  les 30 jours. Voir la question 5.
- **Une connexion échue non marquée, révoquée par la personne, porte la cause `person`**, pas `expired`.

## Questions tranchées par l'auteur (2026-10-28)

1. **Liste** : les connexions révoquées et expirées gardées 6 mois sont listées, avec leur état et leur cause.
2. **Lien desktop** : **un vrai menu du compte desktop** (motif disclosure), pas un bouton compact — Task 10.
3. **#730** : `connection_unexchanged` à `PT1H`, en temps écoulé seulement.
4. **Lier la décision au compte affiché** : hors de #650. Le `POST /api/oauth/consent` accorde au compte du
   cookie à l'envoi ; la limite est écrite dans la règle nouvelle (Task 11), un suivi peut être ouvert.
5. **Course consentement × perte du palier** : limite acceptée, écrite dans la règle nouvelle (l'authentificateur
   refuse la connexion à l'usage ; elle n'est simplement pas marquée révoquée).
6. **DELETE d'une connexion échue non marquée** : révoquée `person`, auditée.
7. **Noms des causes** : `person`, `password-changed`, `trusted-tier-withdrawn` confirmés.

## Corrections de relecture (étape 07, 2026-10-28)

Relectures au point fixe `19d8323e...HEAD` : `/code-review medium`, audit de sécurité, Standards, Spec. Toutes
les corrections ci-dessous sont faites dans cette branche (décision de l'auteur du 2026-10-28).

### Task R1 : le cookie d'avant un changement de mot de passe n'accorde plus (F2) ; re-hachage sous condition (F3) — **Sensible (sécurité, migration)**
- [x] `CpgUser::credentialsChangedAt` (`datetimetz_immutable`, nullable), migration + empreinte `verify.sql` ; écrit dans
  la même fermeture que `setPassword()` par `CpgUserAdministrator::changePassword()` et `PasswordSetupService::activate()`
  (quand un mot de passe est remplacé comme quand il est posé). *Fait : `Version20261028160000`, empreinte n° 38, `CpgUser::replacePassword()` ; 0958fd02.*
- [x] `ConsentService` refuse (le 401 « réauthentification » existant) un JWT dont l'instant d'authentification est
  antérieur à `credentialsChangedAt` ; tests GET et POST du consentement. *Fait : résumé, refus et accord, `hasChangedCredentialsSince()` (égalité comprise) ; 0958fd02.*
- [x] F3 : `CpgUserRepository::upgradePassword()` n'écrase pas un mot de passe changé entre-temps (`UPDATE … WHERE
  password = :ancien`, ou verrou du compte) ; test qui reproduit la course. *Fait : `UPDATE … WHERE password = :ancien`, a2787cc4.*

### Task R1b : une session antérieure au changement de mot de passe est refusée par toute l'API (F2 étendu) — **Sensible (authentification)**
Ajoutée par décision de l'auteur du 2026-10-28 : le contrôle de R1, né au consentement, vaut pour toute l'API
authentifiée par le cookie.
- [x] `Security/Authentication/Infrastructure/Security/SessionBeforePasswordChangeRefusalListener` : écouteur de
  `CheckPassportEvent` **sur le seul dispatcher du firewall `api`**, priorité 128 (après `UserCheckerListener`, qui
  charge le compte), qui lève `SessionPredatesPasswordChangeException` (401 commun de Lexik, `log_level: info`) quand
  `CpgUser::hasChangedCredentialsSince(iat du payload)` — **le prédicat de R1**, une seule source de vérité. Aucune
  requête ni vérification de signature de plus (compte et payload déjà là). Écarté, avec raisons dans le docblock :
  `JWTAuthenticatedEvent`, `UserChecker` (jamais le payload), `JWTAuthenticator` décoré, liste de `jti`.
- [x] `Jwt/StaleSessionCookiesClearingListener` (`JWT_INVALID`) : ce refus **efface `BEARER` et `XSRF-TOKEN`**
  (`AuthCookieFactory::expired()`), faute de quoi le cookie mort ferait répondre 401 à tout `/api`, **routes publiques
  comprises** (le firewall authentifie dès qu'un cookie est présent), pendant l'heure qui lui reste.
- [x] Tests : `SessionBeforePasswordChangeTest` (HTTP : avant / même seconde / après, jamais changé, parcours réel
  `PUT` d'un `ROLE_SUPER` puis rejeu du cookie, l'administrateur qui change son propre mot de passe, l'accès de base
  non concerné, cookies effacés puis route publique servie), `…RefusalListenerTest` (frontières à la seconde, instant
  illisible = antérieur, `BaseAccessUser` ignoré), `…RegistrationTest` (inscrit sur `api`, pas sur `login`, `mcp`,
  `oauth`). Les deux tests HTTP de R1 au consentement attendent désormais le 401 du firewall.
- [x] ADR 0003 amendé (2026-10-28), `.claude/CLAUDE.md` (mention de l'ADR).
- Mutations mesurées, chacune rouge : écouteur désactivé (attribut retiré, conteneur reconstruit) → 4 tests HTTP +
  enregistrement ; `<=` → `<` → même seconde (HTTP, unitaire, `CpgUserTest`) ; garde `null === credentialsChangedAt`
  de l'entité retirée → test unitaire de l'écouteur et `CpgUserTest` (pas le HTTP : le fournisseur de jetons de Lexik
  pose toujours `iat`) ; garde `instanceof CpgUser` retirée → test unitaire de l'accès de base ; effacement des cookies
  désactivé → test d'effacement ; dispatcher global → trois tests d'enregistrement.
- À écrire par R5 (règles) : voir le rendu de la tâche.

### Task R1c : un 401 du backoffice ramène à la connexion (suite de R1b) — frontend seul
Ajoutée par décision de l'auteur du 2026-10-28 : après R1b, un administrateur qui change **son propre** mot de passe
voyait « mot de passe changé », puis sa prochaine action échouait en « Une erreur est survenue » et l'en-tête restait
« connecté » (le même défaut existait pour l'expiration ordinaire du JWT).
- [x] `BackofficeHttpClient` (`get` et `mutate`) : tout 401 appelle une réaction enregistrée une fois par
  `setBackofficeUnauthorizedHandler()` et rend la réponse telle quelle (les dépôts gardent leur mapping). Registre au
  niveau du module, branché dans `main.ts` : la couche infrastructure n'importe ni la session ni le routeur (aucune
  importation `application/` ou `presentation/` sous `infrastructure/`). **Le 403 est inchangé** (non autorisé, CSRF
  refusé : aucun dépôt du backoffice ne le lit comme une session finie, il reste une erreur du dépôt).
- [x] `presentation/router/backofficeSessionExpiry.ts` (`createBackofficeSessionExpiryHandler(router)`) : `markSessionExpired()`
  puis `router.replace` vers `login` (langue de la page courante, `redirect` = `fullPath`) ; une seule navigation quand
  plusieurs listes d'une page répondent 401 ensemble ; rien si l'on est déjà sur `login`.
- [x] Tests : `BackofficeHttpClient.spec.ts` (401 en lecture et en écriture, 200/204/403/404/409/422/500 sans réaction, sans
  réaction enregistrée), `HttpAdminUserRepository.spec.ts` (401 sur `changePassword` et `list`, 403 sans réaction),
  `backofficeSessionExpiry.spec.ts` (session fermée + `login?redirect`, langue, une seule navigation, déjà sur `login`).
  Rouge vu sur les assertions avant le code (8 échecs) ; mutations : `unauthorizedHandler?.()` retiré → 4 rouges ;
  garde `isRedirecting` retirée → test de navigation unique rouge.
- Pas de message dédié « votre mot de passe a changé » : le 401 de Lexik ne porte pas de `type` stable (corps
  `{code, message}`), le reconnaître au texte serait fragile. Pas non plus de bandeau générique sur la page de
  connexion (elle n'en a pas pour une session expirée) : la redirection seule. Question laissée à l'auteur.
- À écrire par R5 dans `frontend-backoffice.md` (section « Route protection ») : un 401 d'une requête du
  backoffice ferme la session côté interface et mène à `login?redirect=<page courante>` (le garde de route ne joue qu'à
  la navigation, il ne voit pas une session qui meurt en cours de page) ; la réaction est centrale dans
  `BackofficeHttpClient`, jamais reprise dans un dépôt ; elle est branchée dans `main.ts`
  (`setBackofficeUnauthorizedHandler(createBackofficeSessionExpiryHandler(router))`), un nouveau client de requêtes
  d'administration passe par `BackofficeHttpClient` ou reproduit ce branchement ; le 403 garde son sens (refus
  d'autorisation ou de CSRF, lu par chaque dépôt) ; les dépôts `Mcp` (`HttpMcpConnectionsRepository`,
  `HttpOAuthConsentRepository`) ont leur propre `fetch` et marquent la session expirée dans le composable, sans
  redirection (la page affiche l'erreur) ; `BackofficeHttpClient.spec.ts`, `backofficeSessionExpiry.spec.ts` épinglent le tout.

### Task R2 : le consentement est lié au compte affiché (F5)
- [x] Le `GET` du consentement renvoie l'UUID du compte affiché ; le `POST` le renvoie et reçoit 409 s'il diffère du
  compte du cookie ; frontend : message et rechargement de la page ; tests backend et Vitest.
  *Fait.* `GET` : `account{id,username}` ; `POST` : `accountId` exigé (`Assert\NotBlank` + `Assert\Uuid` → 422), comparé par
  `Uuid::equals()` dans `ConsentService::decide()` **après** la revalidation (un 400 reste un 400) et **avant** le
  jugement du compte du cookie ; refus : `ConsentAccountChangedException`, 409 `/errors/consent-account-changed`, `detail`
  constant, `log_level: info`. **Accord et refus liés** (règle unique, un clic de plus pour qui refuse). **Pas de
  tolérance d'un `accountId` absent** : 422 (un ancien frontend resté ouvert pendant un déploiement relit la demande
  depuis son assistant ; une garde facultative se contourne en omettant le champ). Frontend : `ConsentAccountChangedError`
  (409 reconnu par son `type`, jamais le `detail`), `HttpOAuthConsentRepository.decide(params, approved, accountId)`,
  `useOAuthConsent` (`failure: 'account-changed'`, `reload()`), alerte FR/EN et bouton « Relire la demande » dans
  `OAuthConsentPage.vue`. Mutations : comparaison retirée → `testAnApprovalShownToAnotherAccountIsRefusedAndGrantsNothing`,
  `testADenialShownToAnotherAccountIsRefusedToo` et 5 tests unitaires rouges ; `NotBlank` retiré → 4 rouges (422 absent / vide).
- À écrire par R5 : voir le rendu de la tâche.

### Task R3 : liste bornée (F6)
- [x] `GET /api/me/mcp-connections` : toutes les connexions non révoquées, plus les 50 dernières terminées ; champ
  `terminatedTruncated` ; une requête par moitié au plus, pas de N+1 ; frontend : mention de la troncature ; tests.
  *Fait.* Dépôt : `findAllOfAccount()` est remplacée par `findNotRevokedOfAccount()` (sans limite) et
  `findTerminatedOfAccount($id, $limit)` (`revoked_at IS NOT NULL`, `LIMIT`), socle commun `ofAccountNewestFirst()`
  (client joint, `granted_at DESC, id DESC`). `OwnMcpConnections::of()` demande **limite + 1** terminées (la ligne en
  trop dit « il y en a davantage », sans `COUNT`), mêle les deux moitiés dans l'ordre du client et rend un
  `OwnMcpConnectionList{connections, terminatedTruncated}`. La borne vit à un seul endroit :
  `OwnMcpConnections::TERMINATED_LIMIT = 50` (la page n'en recopie aucune : mention sans nombre, texte via `t()`).
  Index : `account_id` suffit, aucune migration (EXPLAIN : 0,24 ms et 1,0 ms pour 3 005 lignes du compte parmi 63 000).
  Frontend : `McpConnectionList`, parsing strict du booléen, mention `data-testid="terminated-truncated"` (pas un
  `role="status"`).
- À écrire par R5 : `mcp-oauth-revocation-personne.md` (« `GET`: one query », paths:) : deux lectures au plus, non
  révoquées toutes (bornées par nature : accord abandonné échu en 1 h, premier échange révoque les autres de la paire,
  clients enregistrés en CLI, purge nocturne) + 50 terminées, `terminatedTruncated`, ordre mêlé rejoué en PHP, ligne
  de plus pour détecter la troncature, absence de migration (EXPLAIN) ; liste des tests (`McpConnectionRepositoryTest`,
  `OwnMcpConnectionsTest`, `OwnMcpConnectionsControllerTest`) ; docblock de `OwnMcpConnectionsController` déjà à jour.

### Task R4 : `code_exchanged_at` robuste (CR3) ; interblocage F1 écrit et épinglé — **Sensible (concurrence)**
- [x] CR3 : la colonne est écrite même si la relecture du code par `connectionIdOf()`/`findOneById()` échoue (instance
  verrouillée par league), ou l'échec est journalisé `critical` ; test. *Fait.* Écrite par la **révocation du code**
  (`AuthCodeRepository::revokeAuthCode()` → `McpConnectionRepositoryInterface::recordCodeExchangeOf($codeHash, $at)`, une
  instruction `UPDATE … WHERE code_exchanged_at IS NULL AND id IN (SELECT connection_id FROM mcp_authorization_code WHERE
  code_hash = …)`), le dernier geste de league : ni déchiffrement, ni relecture, ni instance. `CodeExchangeSupersession` ne
  l'écrit plus. Test : `CodeExchangedAtTest` (port de lecture du code remplacé par un port muet : jetons émis, colonne écrite ;
  rouge vu avant le code) ; mutation (appel retiré) → `CodeExchangedAtTest` et `AbandonedGrantsTest` rouges.
- [ ] F1 : croisement « révocation de tout le compte × premier échange d'un code » écrit comme limite acceptée (phrase
  « no cycle » corrigée dans les règles) et épinglé par un test en processus réels : les deux transactions restent
  atomiques, l'une échoue proprement.

### Task R5 : corrections mineures et documentation
- [ ] Règles et docblocks : « three » → « five » révocations (`mcp-oauth-cycle-de-vie.md:92`, `:332`,
  `mcp-oauth-purge.md:76`, `McpConnectionRepository.php:338`), `revokeActiveOfAccount()` hors transaction
  (`mcp-oauth-cycle-de-vie.md:122`), fenêtre migration→rollout documentée (aucune exposition : `main`/`develop` sans
  `Mcp/Connection`), limites F1/F2/F5 mises à jour, R1–R4 décrits.
- [ ] Page : pas d'échéance pour une connexion révoquée ; « Jamais utilisée » distingue un accord abandonné
  (`code_exchanged_at` nul) d'une connexion échangée pas encore rafraîchie ; `aria-controls` valide menu fermé
  (`v-show`) ; `load()` protégé contre les réponses dans le désordre.
- [ ] Backend : `deadline()` calculée une fois par ligne ; ligne d'audit de révocation factorisée ; tuple de
  `setSuperAdmin()` remplacé par un type ; test « révoquée puis échue reste `revoked` ».
