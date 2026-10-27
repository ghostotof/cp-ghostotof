# #726 — Limites acceptées de la rotation et de la révocation : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** trancher les quatre limites de #726 selon les décisions de l'auteur du 2026-10-27 — D1 gardée (écrite
comme telle), D3 un code rejoué ne révoque la connexion que sur preuve PKCE, D4 la purge marque puis supprime en
deux transactions, D2 un nouvel accord ne remplace l'ancien qu'au premier échange réussi de son code.

**Architecture :** trois corrections indépendantes, dans l'ordre du plus contenu au plus large. D3 se loge dans le
`catch` de compromission de `TokenEndpoint` (après le ROLLBACK, sans toucher l'ordre de league 9.4.1) : la charge
chiffrée du code porte son `code_challenge`, que le `code_verifier` du corps doit satisfaire en S256 pour que la
révocation ait lieu. D4 coupe `McpConnectionPurger::purge()` en deux `TransactionInterface::run()`. D2 déplace
`revokeOtherActiveOf()` de `ConsentService::grant()` vers la transaction de `TokenEndpoint`, après la réponse de
league à un échange de code, l'audit suivant le COMMIT.

**Tech Stack :** PHP 8.5, Symfony 8.1, Doctrine ORM 3 / DBAL 4, PostgreSQL 18, `league/oauth2-server` 9.4.1 (épinglé,
inchangé), PHPUnit.

**Spec :** ticket #726 et les décisions ci-dessus ; `.claude/specs/0007-mcp-server.md` (passages « Un nouvel accord
révoque… », « Limites acceptées aussi… », purge) ; règle `.claude/rules/mcp-oauth-cycle-de-vie.md` (puces « A replayed
code… », « A compromised connection… », « A new grant supersedes… », « Every refusal of an authentic grant… », « The
daily purge », « Accepted limits of #649 ») ; `.claude/rules/mcp-oauth.md` (puce « Consent ») ;
`docs/rgpd/registre-traitements.md` §3.4 (ligne 112) et §6 (ligne 155).

## Global Constraints

- Lire avec l'outil `Read` au moins un fichier de `backend/src/Mcp/Connection/` avant d'écrire : c'est ce qui charge
  les règles `mcp-oauth-cycle-de-vie.md` et `mcp-oauth.md`.
- Commandes dans le conteneur, **toujours `-u dev`** : `docker compose exec -u dev backend php bin/phpunit <chemin> [--filter <test>]`,
  puis `make back-test` et `make back-quality` (PHPStan max, Rector, Psalm, `lsp:check`) avant chaque commit.
- Après un `sed -i` ou un `git mv` sur l'hôte : `docker compose restart backend` (montage désynchronisé), et
  `rm -rf var/cache/test` dans le conteneur si le conteneur DI est périmé.
- `league/oauth2-server` reste `9.4.1` : aucun fichier de `vendor/`, aucune sous-classe nouvelle d'un grant.
- Les refus restent ceux de league **mot pour mot** (`invalid_grant`, indice « Authorization code has been revoked ») :
  un attaquant n'apprend rien de plus qu'avant.
- Tout audit d'une révocation s'écrit **après le COMMIT**, jamais dedans ; aucune ligne pour une révocation qui n'a
  pas pris effet.
- Jamais un code, un jeton, un `code_verifier` ni leur empreinte dans un journal, un message d'exception ou un
  message d'échec de test (le modèle : `CodeReplayTest::testExchangingACodeTwiceRevokesTheConnection`).
- Tout paramètre qui reçoit un code ou un vérificateur en clair porte `#[SensitiveParameter]` (`GrantPayloadReader`
  le fait déjà à la main pour `$grant`).
- Aucune exception SPL nue dans `src/` ; `declare(strict_types=1)`, propriétés, paramètres et retours typés ;
  imports `use` triés (`make back-style-fix` après un `rector:fix`) ; commentaires en français.
- Une tâche, un commit (fichiers nommés, jamais `git add -A`), message terminé par
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Chaque tâche met à jour sa propre documentation (règle, spec, registre) **dans son commit** ; la liste « Accepted
  limits of #649 » de la règle perd l'item que la tâche corrige.

## Review Focus

1. Un code rejoué avec le vérificateur **d'un autre flux** (bien formé, mais faux pour ce code) : pas de révocation,
   refus inchangé — Tâche 1, `testAReplayWithAWrongVerifierLeavesTheConnectionActive`.
2. La transaction des suppressions qui échoue après celle du marquage : les connexions marquées restent révoquées et
   auditées, la nuit suivante supprime le reste — Tâche 2, `testTheMarkingIsAuditedEvenWhenTheDeletionsRollBack`.
3. L'échange tardif d'un code plus ancien, après qu'un accord plus récent du même couple a été échangé : sa connexion
   est déjà remplacée, le code est mort (`invalid_grant`, aucune révocation de plus) — Tâche 3,
   `testAnOlderCodeExchangedAfterANewerOneIsRefused`.
4. Un rafraîchissement de l'ancienne connexion qui croise l'échange du nouveau code : l'échange attend le verrou, puis
   révoque, sans interblocage — Tâche 3, `testAnExchangeCrossingARefreshOfTheSupersededConnectionRevokesIt`.
5. Un rafraîchissement d'une connexion ne remplace rien : seul un échange de code le fait — Tâche 3,
   `testARefreshSupersedesNothing`.

---

### Task 1 : D3 — un code rejoué ne révoque que sur preuve PKCE (et D1 écrite comme gardée)

**Files :**
- Create : `backend/src/Mcp/Connection/Application/ReplayProofInterface.php`
- Create : `backend/src/Mcp/Connection/Infrastructure/League/PkceReplayProof.php`
- Modify : `backend/src/Mcp/Connection/Infrastructure/League/GrantPayloadReader.php` (une méthode)
- Modify : `backend/src/Mcp/Connection/Application/TokenEndpoint.php` (`respond()`, le `catch` de compromission, `audit()`)
- Modify : `backend/src/Mcp/Connection/Application/GrantRefusalAuditInterface.php`, `backend/src/Mcp/Connection/Infrastructure/League/LeagueGrantRefusalAudit.php`
- Modify : `backend/src/Mcp/Connection/Domain/ValueObject/GrantRefusal.php` (un cas)
- Modify : `backend/src/Mcp/Connection/Infrastructure/League/AuthCodeRepository.php` (docblock de `isAuthCodeRevoked()` seulement)
- Modify : `backend/config/services.yaml` seulement si l'autowiring ne lie pas l'interface seul
- Test : `backend/tests/Mcp/Connection/CodeReplayTest.php`, `backend/tests/Mcp/Connection/Infrastructure/League/PkceReplayProofTest.php` (nouveau), `backend/tests/Mcp/Connection/Infrastructure/League/LeagueGrantRefusalAuditTest.php`
- Docs : `.claude/rules/mcp-oauth-cycle-de-vie.md`, `.claude/specs/0007-mcp-server.md`, `docs/rgpd/registre-traitements.md` (§6)

**Interfaces :**
- Produces : `GrantRefusal::ReplayUnproven = 'replay-unproven'` ;
  `ReplayProofInterface::provesPossession(ServerRequestInterface $request): bool` ;
  `GrantRefusalAuditInterface::record(ServerRequestInterface $request, OAuthServerException $refusal, ?GrantRefusal $cause = null): void`
  (la cause connue de l'appelant, l'indice de league lu sinon) ;
  `GrantPayloadReader::s256ChallengeOf(#[SensitiveParameter] string $code): ?string`.

- [ ] **Step 1 : écrire les tests rouges de `CodeReplayTest`**

`testAReplayWithoutTheVerifierLeavesTheConnectionActive` : `$code = $this->grantedCode()`, premier échange (retenir
`$held` et le `refresh_token`), puis `$this->exchange($code, ['code_verifier' => null])` →
`assertOAuthError(..., 400, 'invalid_grant')` avec l'indice `self::REPLAY_HINT` ; `assertConnectionActive($held)` ;
`self::securityAuditEvents(self::REVOKED_EVENT) === []` ; une seule ligne `mcp-grant-refused` sur cette requête, avec
`cause` `'replay-unproven'`, `grantType` `'authorization_code'`, `connectionId` `$held->connectionId`, sans le code
(lu comme le fait `GrantRefusalAuditTest::assertAudited()`) ; puis `$this->refresh($refreshToken)` → 200 (la connexion
vit).

`testAReplayWithAWrongVerifierLeavesTheConnectionActive` : la même chose avec `['code_verifier' => <un vérificateur
RFC 7636 valide d'un autre flux, 43 caractères>]` (le générer comme `DrivesTheOAuthFlow` génère le sien).

Mettre à jour le docblock de `testAReplayUnderAnotherClientRevokesTheConnectionToo` : il présente le bon
vérificateur, c'est lui la preuve ; le `client_id` public n'en est pas une.

- [ ] **Step 2 : les lancer, vérifier l'échec**

Run : `docker compose exec -u dev backend php bin/phpunit tests/Mcp/Connection/CodeReplayTest.php`
Expected : les deux nouveaux tests échouent sur `assertConnectionActive` (cause `code-replay`) ; les quatre existants passent.

- [ ] **Step 3 : écrire `PkceReplayProofTest` et le cas de `LeagueGrantRefusalAuditTest`**

`PkceReplayProofTest` (`KernelTestCase`, la vraie `GrantPayloadReader`) : un code et son vérificateur obtenus par le vrai
flux → `true` ; vérificateur absent, non chaîne, faux → `false` ; corps sans `code`, code illisible → `false` ; charge
forgée et rechiffrée sous la clé du serveur (comme `GrantRefusalAuditTest`) sans `code_challenge`, ou avec
`code_challenge_method` `plain` → `false`. `LeagueGrantRefusalAuditTest` : une cause passée l'emporte sur l'indice de
league (`ReplayUnproven` avec l'indice « Authorization code has been revoked ») ; sans cause, le classement est inchangé.

- [ ] **Step 4 : implémenter**

`GrantPayloadReader::s256ChallengeOf()` : le `code_challenge` de la charge si `code_challenge_method` vaut exactement
`S256` et que la valeur est une chaîne non vide, `null` sinon (même lecture que `connectionIdOf()`, via `payloadOf()`).
`PkceReplayProof` (final readonly, implémente l'interface, Infrastructure/League) : lit `code` et `code_verifier` du
corps analysé (chaînes, sinon `false`), puis `(new S256Verifier())->verifyCodeChallenge($verifier, $challenge)` de
league (`League\OAuth2\Server\CodeChallengeVerifiers\S256Verifier`, `hash_equals` en interne).
`TokenEndpoint` : injecter `ReplayProofInterface` ; dans le `catch (CompromisedMcpConnectionException)`, pour un
`AuthorizationCodeReplayedException` sans preuve, **ne pas révoquer** : `$this->audit($request, $refusal,
GrantRefusal::ReplayUnproven)` puis `throw $refusal` (le même `refusalOf()`) ; avec preuve, et pour tout
`RotatedRefreshTokenReusedException`, le chemin actuel. `audit()` relaie la cause. `LeagueGrantRefusalAudit::record()` :
`$cause ?? LeagueGrantRefusals::causeOf($refusal)`. Docblocks : celui de `isAuthCodeRevoked()` (« rendu… le point de
jeton ne révoque que sur preuve PKCE ») et celui du `catch`.

- [ ] **Step 5 : vérifier le vert et la mutation**

Run : `docker compose exec -u dev backend php bin/phpunit tests/Mcp/Connection/` puis le test de concurrence
`tests/Mcp/Connection/TokenEndpointConcurrencyTest.php` (ses deux échanges présentent le même vérificateur : toujours
révoqué).
Expected : PASS. Mutation à mesurer et à écrire dans la règle : `provesPossession()` qui rend toujours `true` → les deux
nouveaux tests de `CodeReplayTest` rouges.

- [ ] **Step 6 : documentation**

Règle `mcp-oauth-cycle-de-vie.md` : « A replayed code is judged under the same lock » — le code révoqué sur une
connexion active lève toujours la compromission, mais `TokenEndpoint` ne révoque que si le corps prouve la possession
du vérificateur ; sans preuve, même refus, aucune révocation, ligne `mcp-grant-refused` cause `replay-unproven` (le
signal d'une interception) ; « A compromised connection is revoked after the ROLLBACK » — la condition ; « Every refusal
of an authentic grant is audited » — `replay-unproven` dans la liste des causes, et « a compromise » n'est plus audité
**sauf** un rejeu sans preuve, écrit depuis le `catch` de compromission. Liste « Accepted limits of #649 » : retirer
l'item 3 ; réécrire l'item 1 comme **gardé par décision du 2026-10-27 (#726)**, à revoir sur les lignes
`mcp-connection-revoked` de cause `refresh-token-reuse` après la sortie de la phase 2 (une fenêtre de grâce rendant un
nouveau couple est écartée : un attaquant rafraîchissant juste avant le client légitime garderait la connexion ; « rendre
le même couple » est impossible, seules les empreintes sont stockées). Spec 0007 (passage des lignes 394-400) : même
chose. Registre §6 : la cause `replay-unproven` dans la phrase de `mcp-grant-refused`.

- [ ] **Step 7 : qualité et commit**

Run : `make back-test` puis `make back-quality`. Expected : tout vert.

```bash
git add backend/src/Mcp/Connection/Application/ReplayProofInterface.php backend/src/Mcp/Connection/Infrastructure/League/PkceReplayProof.php backend/src/Mcp/Connection/Infrastructure/League/GrantPayloadReader.php backend/src/Mcp/Connection/Application/TokenEndpoint.php backend/src/Mcp/Connection/Application/GrantRefusalAuditInterface.php backend/src/Mcp/Connection/Infrastructure/League/LeagueGrantRefusalAudit.php backend/src/Mcp/Connection/Domain/ValueObject/GrantRefusal.php backend/src/Mcp/Connection/Infrastructure/League/AuthCodeRepository.php backend/tests/Mcp/Connection/CodeReplayTest.php backend/tests/Mcp/Connection/Infrastructure/League/PkceReplayProofTest.php backend/tests/Mcp/Connection/Infrastructure/League/LeagueGrantRefusalAuditTest.php .claude/rules/mcp-oauth-cycle-de-vie.md .claude/specs/0007-mcp-server.md docs/rgpd/registre-traitements.md
git commit -m "fix(mcp): un code rejoué ne révoque la connexion que sur preuve PKCE (#726)"
```

### Task 2 : D4 — la purge marque, puis supprime, en deux transactions

**Files :**
- Modify : `backend/src/Mcp/Connection/Application/McpConnectionPurger.php` (`purge()` et le docblock de la classe)
- Create : `backend/tests/Mcp/Connection/Application/RollingBackTheSecondRun.php` (double de test)
- Test : `backend/tests/Mcp/Connection/Application/McpConnectionPurgerTest.php`, `backend/tests/Mcp/Connection/McpConnectionPurgeLockOrderTest.php`
- Docs : `.claude/rules/mcp-oauth-cycle-de-vie.md` (« The daily purge »), `.claude/rules/backend-outillage.md` (« Concurrency tests », le paragraphe de la purge), `.claude/specs/0007-mcp-server.md` (lignes 494-500)

**Interfaces :**
- Consumes : rien des autres tâches.
- Produces : `McpConnectionPurgeResult` inchangé ; `RollingBackTheSecondRun implements TransactionInterface` (classe
  finale non readonly : un compteur ; la première `run()` passe à l'intérieur, la seconde exécute l'opération puis lève
  `RolledBackOnPurposeException`).

- [ ] **Step 1 : écrire les tests rouges**

`McpConnectionPurgeLockOrderTest::testALiveCandidateIsUnlockedDuringTheDeletions` (processus réels, le harnais de la
classe et `Fixtures/purge-mcp-connections.php`, horloge réelle de l'enfant) : une connexion active **candidate mais
vivante** (dernier usage il y a 29 jours et 12 heures, écrit en SQL relativement à `now()`), et un jeton d'accès échu
d'**une autre** connexion active ; le test tient ce jeton `FOR UPDATE`, lance la purge, attend qu'elle bloque
(`SessionLockWait::untilBlocked()` : elle bloque dans `deleteDead()` des jetons d'accès), puis, dans sa propre
transaction, `SELECT id FROM mcp_connection WHERE id = :candidate FOR UPDATE NOWAIT` doit réussir ; il relâche ; l'enfant
écrit `purged …` ; la candidate est toujours active. Filet `statement_timeout = '3s'` comme les deux tests voisins.

`McpConnectionPurgerTest::testTheMarkingIsAuditedEvenWhenTheDeletionsRollBack` : sur le modèle de
`testARolledBackPurgeAuditsNothing`, avec `RollingBackTheSecondRun` ; une connexion échue et un jeton mort ; la purge
lève `RolledBackOnPurposeException` ; la connexion est révoquée `expired` en base, une ligne `mcp-connection-revoked`
(`cause` `expired`) est écrite, le jeton mort est toujours là.

- [ ] **Step 2 : les lancer, vérifier l'échec**

Run : `docker compose exec -u dev backend php bin/phpunit tests/Mcp/Connection/McpConnectionPurgeLockOrderTest.php tests/Mcp/Connection/Application/McpConnectionPurgerTest.php`
Expected : le premier échoue en `55P03` (lock_not_available) sur le `NOWAIT` ; le second sur la révocation annulée.
`testARolledBackPurgeAuditsNothing` passe toujours (la première transaction annulée n'audite rien).

- [ ] **Step 3 : implémenter**

`purge()` : `$expired = $this->transaction->run(fn (): array => $this->revokeExpired($now));`, puis les audits
`mcpConnectionExpired()` (après ce COMMIT), puis une seconde `run()` avec, **dans l'ordre actuel**, `connections`,
`codes`, `accessTokens`, `refreshTokens` (les commentaires d'ordre A puis R et « avant tout jeton » restent sur ces
lignes). Docblock de la classe : deux transactions, pourquoi (le verrou des candidates ne dure que leur jugement),
ce que la coupure coûte (une seconde transaction en échec laisse des connexions marquées non supprimées : la nuit
suivante les supprime ; leurs audits sont écrits, la révocation a eu lieu).

- [ ] **Step 4 : vérifier le vert et la mutation**

Run : les deux fichiers de tests, puis `tests/Mcp/Connection/` entier.
Expected : PASS. Mutation à mesurer et à écrire : les deux `run()` refusionnées en une → `testALiveCandidateIsUnlockedDuringTheDeletions`
rouge en `55P03` ; les deux tests d'ordre existants restent verts dans les deux cas.

- [ ] **Step 5 : documentation**

Règle, « The daily purge » : « in one transaction » devient deux (marquage : (1)-(3), COMMIT, audits ; suppressions :
(4)-(5), l'ordre et les tests d'ordre inchangés) ; retirer l'item 4 de « Accepted limits of #649 » ; « Accepted,
unpinned: no concurrency test proves the `FOR UPDATE` of (1) » reste vrai, et le nouveau test pince la durée du verrou.
`backend-outillage.md` : une phrase dans le paragraphe de la purge (le troisième test, sa mutation). Spec 0007 : retirer
la limite des lignes 496-500, dire les deux transactions.

- [ ] **Step 6 : qualité et commit**

Run : `make back-test` puis `make back-quality`. Expected : tout vert.

```bash
git add backend/src/Mcp/Connection/Application/McpConnectionPurger.php backend/tests/Mcp/Connection/Application/RollingBackTheSecondRun.php backend/tests/Mcp/Connection/Application/McpConnectionPurgerTest.php backend/tests/Mcp/Connection/McpConnectionPurgeLockOrderTest.php .claude/rules/mcp-oauth-cycle-de-vie.md .claude/rules/backend-outillage.md .claude/specs/0007-mcp-server.md
git commit -m "fix(mcp): la purge marque puis supprime en deux transactions (#726)"
```

### Task 3 : D2 — un nouvel accord remplace l'ancien au premier échange de son code

**Files :**
- Create : `backend/src/Mcp/Connection/Application/CodeExchangeSupersessionInterface.php`
- Create : `backend/src/Mcp/Connection/Infrastructure/League/LeagueCodeExchangeSupersession.php`
- Create : `backend/src/Mcp/Connection/Application/IssuedTokens.php` (la réponse de league et les connexions remplacées)
- Modify : `backend/src/Mcp/Connection/Application/TokenEndpoint.php`
- Modify : `backend/src/Mcp/Connection/Application/ConsentService.php` (`grant()` et le docblock de la classe)
- Delete : `backend/src/Mcp/Connection/Application/WrittenGrant.php` (si plus rien ne l'utilise : la transaction de l'accord rend l'URL)
- Test : `backend/tests/Mcp/Connection/Presentation/Controller/ConsentControllerTest.php`, `backend/tests/Mcp/Connection/Application/ConsentServiceTest.php`, `backend/tests/Mcp/Connection/TokenEndpointConcurrencyTest.php`, `backend/tests/Mcp/Connection/Fixtures/present-at-token-endpoint.php` (si l'enfant doit présenter un code pendant que le parent rafraîchit)
- Docs : `.claude/rules/mcp-oauth-cycle-de-vie.md` (« A new grant supersedes the previous one », item 2 de la liste), `.claude/rules/mcp-oauth.md` (« Consent »), `.claude/specs/0007-mcp-server.md` (lignes 360-372, 447-450, 695), `docs/rgpd/registre-traitements.md` (§6 : l'acteur d'un accord remplacé devient `anonymous`, écrit au point de jeton), `tasks/todo.md` (la ligne de la tâche 10 qui renvoie à #726)

**Interfaces :**
- Consumes : `TokenEndpoint` tel que la Tâche 1 l'a laissé (constructeur avec `ReplayProofInterface`).
- Produces : `CodeExchangeSupersessionInterface::supersedeOthersOf(ServerRequestInterface $request): array` —
  `@return list<RevokedConnection>`, `[]` pour un `grant_type` autre que `authorization_code` ou un code dont la charge
  ne désigne aucune connexion ; à appeler **dans** la transaction, après la réponse de league ;
  `IssuedTokens` (final readonly : `ResponseInterface $response`, `list<RevokedConnection> $superseded`).

- [ ] **Step 1 : écrire les tests rouges de `ConsentControllerTest`**

`testAnAbandonedSecondGrantLeavesTheFirstConnection` (la reproduction de D2) : accord 1 échangé (connexion C1, son
`refresh_token`) ; accord 2 approuvé, son code **jamais échangé** → C1 active, aucune ligne `mcp-connection-revoked`, le
rafraîchissement de C1 → 200.
Réécrire `testASecondGrantSupersedesTheFirstConnection` en `testASecondGrantSupersedesTheFirstAtItsExchange` : après
l'approbation 2, C1 active et la requête de l'accord n'audite que `mcp-connection-granted` ; l'échange du code 2 → 200,
C1 révoquée `superseded`, une ligne `mcp-connection-revoked` lue **sur la requête de l'échange** (`cause` `superseded`,
`connectionId` de C1, `clientId` public, `actor` `anonymous`) ; l'ancien rafraîchissement → `invalid_grant` sans
seconde ligne.
`testARetriedApprovalLeavesOneActiveConnection` → `testARetriedApprovalLeavesOneActiveConnectionOnceExchanged` : deux
approbations, deux connexions actives ; l'échange d'un des deux codes en laisse une.
`testAGrantForAnotherClientLeavesTheFirstAlone` : l'échange du code de l'autre client laisse C1 active.
`testAnOlderCodeExchangedAfterANewerOneIsRefused` : accords 1 puis 2, aucun échangé ; échange du code 2 (C1
remplacée) ; échange du code 1 → `invalid_grant` (indice « Authorization code has been revoked »), aucune révocation de
plus, C2 active.
`testARefreshSupersedesNothing` : accord 1 échangé, accord 2 approuvé non échangé ; rafraîchir C1 → 200, C2 toujours
active.

- [ ] **Step 2 : les lancer, vérifier l'échec**

Run : `docker compose exec -u dev backend php bin/phpunit tests/Mcp/Connection/Presentation/Controller/ConsentControllerTest.php`
Expected : `testAnAbandonedSecondGrantLeavesTheFirstConnection` et `testARefreshSupersedesNothing` rouges (C1 déjà
révoquée à l'approbation), les réécrits rouges sur l'ordre des lignes.

- [ ] **Step 3 : implémenter**

`ConsentService::grant()` : la transaction ajoute la connexion et rend `$this->completer->approve(...)`, plus rien
d'autre ; après le COMMIT, `mcpConnectionGranted()` seul. Docblock de la classe à jour (le remplacement vit à
l'échange).
`LeagueCodeExchangeSupersession` (final readonly) : lit `grant_type` et `code` du corps analysé ; hors
`authorization_code` → `[]` ; `GrantPayloadReader::connectionIdOf($code)` ; `McpConnectionRepositoryInterface::findOneById()`
(l'instance gérée, que `isAuthCodeRevoked()` a verrouillée `FOR UPDATE` dans la même transaction) ; null → `[]` ;
sinon `revokeOtherActiveOf($kept, RevocationCause::Superseded, $this->clock->now())`.
`TokenEndpoint::respond()` : la transaction rend `new IssuedTokens($this->server->respondToAccessTokenRequest(...),
$this->supersession->supersedeOthersOf($request))` ; après le COMMIT, une ligne
`SecurityAuditLoggerInterface::mcpConnectionRevoked($revoked, $revoked->cause()->value)` par connexion remplacée (la
deuxième porte de `mcp-oauth.md`, « An assumed exception… » : le seul écrivain de `security_audit`) ; rend
`$issued->response`. Ordre des verrous, à écrire en commentaire : la connexion du code (league, `FOR UPDATE`), ses
jetons, puis les **autres** connexions du couple (l'`UPDATE` de `revokeOtherActiveOf()`) ; un rafraîchissement ne prend
que sa connexion puis ses jetons : pas de cycle.

- [ ] **Step 4 : vérifier le vert**

Run : `ConsentControllerTest`, puis adapter `ConsentServiceTest` : `testTheSupersededConnectionsAreAuditedAfterTheGrant`
devient `testAnApprovalRevokesNothing` (aucun appel à `revokeOtherActiveOf()`, aucune ligne `mcp-connection-revoked`) ;
puis `tests/Mcp/Connection/` entier.
Expected : PASS.

- [ ] **Step 5 : écrire et faire passer le test de concurrence**

`TokenEndpointConcurrencyTest::testAnExchangeCrossingARefreshOfTheSupersededConnectionRevokesIt` : C1 échangée,
accord 2 approuvé ; le parent rafraîchit C1, relâché à sa première écriture (`FlushInterleaving::releaseAfterNextFlush()`,
C1 tenue `FOR UPDATE`) pendant que l'enfant échange le code 2 ; l'enfant attend le COMMIT du parent, puis rend `issued
200` ; C1 révoquée `superseded`, aucune `40P01`. Mutation à mesurer : l'`UPDATE` de `revokeOtherActiveOf()` avec `SKIP
LOCKED` (via une sous-requête `FOR UPDATE SKIP LOCKED`) → C1 laissée active, rouge.
Run : `docker compose exec -u dev backend php bin/phpunit tests/Mcp/Connection/TokenEndpointConcurrencyTest.php`
Expected : PASS, les deux tests existants compris.

- [ ] **Step 6 : documentation**

Règle `mcp-oauth-cycle-de-vie.md`, « A new grant supersedes the previous one » : réécrite — l'approbation ne révoque
rien ; le premier échange réussi d'un code révoque, dans la transaction du point de jeton, les autres connexions
actives du couple (cause `superseded`, acteur `anonymous`, ligne après le COMMIT) ; un accord abandonné laisse l'ancien
vivant ; le double clic laisse deux connexions jusqu'au premier échange, qui n'en laisse qu'une (la limite acceptée
du double clic disparaît, sauf si le client n'échange aucun code) ; un code plus ancien échangé après un plus récent
est mort ; ordre des verrous ; **limite acceptée, nouvelle** : deux échanges simultanés de **deux** codes du même couple
(deux accords du même compte au même client, échangés à la même milliseconde) s'interbloquent — PostgreSQL annule l'un
(`40P01`, un 500), l'autre passe et révoque la connexion du premier, dont le code est alors mort. Retirer l'item 2 de la
liste. `mcp-oauth.md`, « Consent » : « it supersedes the active grant … in that transaction » → au premier échange.
Spec 0007 et registre (§6, acteur) : même chose. `tasks/todo.md` : à la ligne de la tâche 10, « → #726 » complété par
« tranchées le 2026-10-27 : D1 gardée, D2-D4 corrigées ».

- [ ] **Step 7 : qualité et commit**

Run : `make back-test` puis `make back-quality`. Expected : tout vert.

```bash
git add backend/src/Mcp/Connection/Application/CodeExchangeSupersessionInterface.php backend/src/Mcp/Connection/Infrastructure/League/LeagueCodeExchangeSupersession.php backend/src/Mcp/Connection/Application/IssuedTokens.php backend/src/Mcp/Connection/Application/TokenEndpoint.php backend/src/Mcp/Connection/Application/ConsentService.php backend/src/Mcp/Connection/Application/WrittenGrant.php backend/tests/Mcp/Connection/Presentation/Controller/ConsentControllerTest.php backend/tests/Mcp/Connection/Application/ConsentServiceTest.php backend/tests/Mcp/Connection/TokenEndpointConcurrencyTest.php .claude/rules/mcp-oauth-cycle-de-vie.md .claude/rules/mcp-oauth.md .claude/specs/0007-mcp-server.md docs/rgpd/registre-traitements.md tasks/todo.md
git commit -m "fix(mcp): un nouvel accord remplace l'ancien au premier échange de son code (#726)"
```

(Ajouter `backend/tests/Mcp/Connection/Fixtures/present-at-token-endpoint.php` s'il a changé.)
