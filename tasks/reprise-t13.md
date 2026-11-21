# T13 (#652) — Reprise

État tenu par `/cpg-dev` : lu en premier par `/cpg-dev reprise spec-0007-t13`.

## Où en est la tâche

- Branche : `feature/spec-0007-t13-backoffice-mcp`, coupée le 2026-11-19 de la mère
  `feature/379-mcp-paliers` à jour (tête `bd61b215`, fusion de la T12, PR #777). Non poussée.
- Étape : **04 close** — plan `tasks/plan-t13-backoffice-mcp.md` commité (`32c9fb3e`, 28 tâches :
  1-18 backend dont 7A/7B et 17A/17B, F1-F7 frontend, 19 documentation ; aucune migration, aucune
  variable d'environnement). Contrôlé par le fil principal (aucun marqueur, arbre propre).
- Étape : **05**, un agent par tâche (sonnet/high, `agent-skills:build` +
  `superpowers:test-driven-development`), l'un après l'autre.
  - **Task 1 faite** : `7196ec62` — `ViolatedUniqueIndex` (Shared/Infrastructure/Doctrine) + test ;
    `CpgUserUniqueViolation` et `McpClientRegistrar` l'appellent. Preuve contrôlée par le fil
    principal : `--filter 'ViolatedUniqueIndexTest|CpgUserUniqueViolationTest|McpClientRegistrarTest|InviteUniqueViolationTest'`
    OK (22 tests) ; rouge vérifié par l'agent (classe absente) ; PHPStan, Rector verts sur les fichiers.
    Écart sans conséquence : `CpgUserUniqueViolation` n'avait pas de `violatedIndex()` (lecture inline).
  - **Task 2 faite** : `2d4488d2` — `RedirectUri::MAX_LENGTH` (2 048) / `MAX_QUERY_PARAMETERS` (50),
    `InvalidRedirectUriException::tooLong()` / `tooManyQueryParameters()`. Preuve contrôlée :
    `--filter 'RedirectUriTest|McpClientTest|ClientMetadataDocumentTest'` OK (183 tests) ; rouge vérifié
    par l'agent (4 échecs pour la bonne raison) ; PHPStan, Rector verts. Écart justifié : le test des
    1 001 paramètres utilise `a&a&…` (sinon la borne de longueur coupe avant). Trailer de l'agent :
    `Claude Sonnet 5.5` (modèle réel), accepté.
  - **Task 3 faite** : `3d4f3b73` — `McpClient::MAX_REDIRECT_URIS` (= `ClientMetadataDocument::MAX_REDIRECT_URIS`),
    `InvalidMcpClientRedirectsException::none()` / `tooMany()`, dédoublonnage puis bornes dans `register()`.
    Preuve contrôlée : rouge vérifié par l'agent ; `McpClientTest|McpClientRegistrarTest|McpClientRepositoryTest`
    OK (26) ; PHPStan, Rector verts. L'invariant cassait 65 tests (5 fixtures `RegisteredMcpClient::of(…, [])`) :
    corrigé par le fil principal en `test(mcp)` séparé (redirection `https://client.example.test/callback`,
    conforme à la docblock de la fixture) ; `tests/Mcp/Connection` OK (1 447 tests) contrôlé.
  - **Task 4 faite** : `23c68ca7` — `catch` de `InvalidMcpClientRedirectsException` dans la commande
    (`$io->error()`, `FAILURE`), `@throws` ajouté à `McpClientRegistrarInterface` (sinon `catch.neverThrown`).
    Écart justifié et vérifié dans le vendor : `SymfonyStyle::block()` échappe déjà (`$escape = true`), donc pas
    d'`OutputFormatter::escape()` dans `success()` (il doublerait l'échappement) ; le test de la balise est un
    garde-fou qui n'a pas pu être rouge. `listing()` n'échappe pas, mais `RedirectUri` refuse `<` : non échappé,
    **question posée à l'utilisateur** (défense en profondeur sans test rouge ?). Message de commit différent
    du plan (`… échappe sa sortie et rend la borne lisible`), non amendé. `tests/Mcp/Connection` OK (1 449).
  - **Étape 07, série S1 (Tasks 1-4)** : `/code-review medium` sur `diff bd61b215...HEAD` lancé le 2026-11-19,
    HEAD relu = celui du commit de cette reprise (point fixe de S2). Pas d'audit sécurité (S1 hors auth).
  - **S1 relue** (10 constats : 1 moyen, 9 faibles). Moyen confirmé : `matches()` reçoit aussi la
    `redirect_uri` du corps de `POST /token` (`LoopbackAwareAuthCodeGrant.php:198`, nginx 16 Ko à 1 Mo).
  - **Série de correction C1** (sonnet/high) : (1) `matches()` rend `false` au-delà de `MAX_LENGTH` avant
    `Uri::parse` ; (5) compte des paramètres sorti de `carriesIss()` vers `fromString()` ; (6) messages dérivés
    des constantes ; (9) `discovered()` passe par `distinctBounded()` ; (10) le test des 11 redirections vérifie
    le message `tooMany()`. Point fixe de sa relecture : `8a44a8a0`.
  - **C1 faite** : `d3b6ea3a` (matches borné, rouge vérifié), `5ca627a7` (compte hors de `carriesIss()`, prédicat
    privé `exceedsQueryParameterBound()`), `9ec95642` (messages via constantes), `54746105` (`discovered()` par
    `distinctBounded()`, rouge vérifié ; test qui construit `ClientMetadataDocument` par réflexion), `3dba345f`
    (assertion du message, mordant prouvé par mutation). Contrôlé : `tests/Mcp/Connection` OK (1 453).
  - **Relecture de C1** (`8a44a8a0...45daeb53`) : 9 constats, tous faibles → **boucle S1 close**.
  - **Point fixe de S2** : `45daeb53`.
  - **Task 5 faite** : `1067573a` — `McpClient::revise()` (via `distinctBounded()`), `McpClientDeclaredByDocumentException`
    (409 `client-declared-by-document` par `HasProblemType`, sans `exception_to_status` : plan l. 555), `log_level: info`
    dans `framework.yaml` (exigé par `ExceptionLogLevelCoverageTest`). Contrôlé : `McpClientTest|ExceptionLogLevelCoverageTest`
    OK (53) ; dossier OK (1 458) selon l'agent.
  - **Task 6 faite** : `44955ac3` — `all()`, `findOneByIdForUpdate()` (`RereadUnderLock`), `findOneByClientIdForShare()`
    (DQL `PESSIMISTIC_READ` + `HINT_REFRESH`), `remove()` ; cascade assurée par la base (`Version20261023120000.php`
    l. 47-64, `ON DELETE CASCADE`) ; 9 tests (sonde `ProbesLocks` : `RowShareLock`, `FOR UPDATE SKIP LOCKED` bloqué).
    Contrôlé : `McpClientRepositoryTest|ConsentServiceTest|McpClientRegistrarTest` OK (58). Fake : `$removed` public.
  - **Task 7A faite** : `02450161` — `lockActiveOfClient()` (SQL natif `FOR UPDATE OF c`, refus hors transaction par
    `RevocationOutsideTransactionException::forClient()`), `countActiveByClient()` (une requête, prouvée par
    `doctrine.debug_data_holder`, variante à 2 requêtes vue rouge). Contrôlé : `McpConnectionRepositoryTest` OK (39).
    **Écart accepté** : rend `list<ConnectionReference>` et non `list<RevokedConnection>` (rien n'est encore révoqué,
    pas de cause) ; la Task 11 consomme `record(McpConnectionReference, string)`, que `ConnectionReference` implémente.
    **Constat écrit (PR)** : une connexion échue non encore marquée par la purge compte comme active (comme
    `findNotRevokedOfAccount`) — à revoir en 7B/12 si le statut affiché doit la distinguer.
  - **Task 7B faite** : `65696e47` — `findNotRevokedForAdministration()`, `findTerminatedForAdministration()` par
    `forAdministration()` (client et compte joints, 0 requête paresseuse prouvée : sans `account`, 4 au lieu de 2).
    Contrôlé : `McpConnectionRepositoryTest` OK (42). Accesseurs réels : `client()`, `account()`, `getUsername()`.
  - **Correction 7B' (écart plan/spec, tranché par la spec)** : plan l. 362 trie les terminées par `grantedAt`, mais
    spec 0007 S4 (l. 503, 812) et D-D (« même motif que `OwnMcpConnections` ») les **choisissent par instant de fin**
    (`revokedAt DESC, id DESC` puis `LIMIT`), affichées ensuite par date d'octroi. 7B' : `findTerminatedForAdministration`
    trie par `revokedAt DESC, id DESC` + test qui discrimine. **Task 12** : le service retrie par octroi (`usort`, comme
    `OwnMcpConnections::newestGrantFirst`) — à mettre dans son brief.
  - **7B' faite** : `3f381fee` (tri `revokedAt DESC, id DESC` puis `LIMIT`, test discriminant
    `testTerminatedForAdministrationKeepsTheMostRecentlyTerminated`) ; `e422deec` (fil principal : docblock
    d'`ofAccount()` remis au-dessus de sa méthode, la 7B l'avait déplacé). Contrôlé : `McpConnectionRepositoryTest`
    OK (43 tests, 412 assertions), PHPStan et Rector verts sur le fichier.
  - **Task 8 faite** : `931e9616` — `grant()` lit son client par `findOneByClientIdForShare()` ; test à processus fils
    `ConsentClientRemovalRaceTest` (rouge FK 23503 vérifié, mutation rejouée) + fixture `delete-mcp-client.php`. Écart :
    le second test supprime après la validation (stub de validateur), sinon league refuse avant `clientNoLongerRegistered()`.
    Contrôlé par le fil principal : `ConsentClientRemovalRaceTest|ConsentServiceTest|ConsentControllerTest` OK (79) deux
    fois ; l'agent : trois fois, `tests/Mcp/Connection` OK (1 475), PHPStan et Rector verts.
  - **Relecture S2 faite** (`/code-review medium` sur `diff 45daeb53...2878d007` + auditeur sécurité opus/high) :
    un haut (redirection non revérifiée sous `FOR SHARE`, = A1 de l'audit) → série de correction **C2**. Point fixe
    de la relecture de C2 : **`2878d007`**. Le reste : brief de la Task 11 (ci-dessous) ou constats écrits.
  - **C2 faite** (agent sonnet/high) : `4694b145` (A1, refus `redirectNoLongerRegistered()` sous `FOR SHARE`,
    rouge vérifié), `f3d2b179` (A6, sonde `55P03` + deux refus hors transaction, mutations M1-M3 rouges),
    `d5bc3dcd` (A5, `forClient(Uuid)`), puis le fil principal `c5910b79` (docblock de
    `findNotRevokedForAdministration` : le plafond est la zone nginx `oauth`, 10 r/min par adresse et par pod,
    pas des « limiteurs du consentement »). Preuve de l'agent : `tests/Mcp/Connection` OK (1 479), PHPStan et
    Rector verts ; arbre propre contrôlé.
  - **Relecture de C2 faite** (`/code-review medium diff 2878d007...5a5440fd`) : 8 constats, triés avec l'utilisateur.
    Un docblock corrigé dans la foulée (`d604141b`, l'implémentation attribue aussi le plafond à la zone nginx `oauth`).
    Constat 2 (moyen : accords non échangés non bornés) → **Task 13** (brief ci-dessous) ; constat 6 (redirection non
    revérifiée à l'échange) = T6 du brief de la Task 11 ; le reste → constats écrits. **Boucle S2 close.**
  - **Point fixe de la relecture S3** : **`8c5d8586`** (reprise « C2 relue et triée »).
  - **Task 9 faite** (agent sonnet/high) : `02ace373` (`AuditOrigin`, trois événements, règle d'audit : 36 méthodes
    au garde des contextes). Preuve contrôlée : rouge (méthodes absentes), `--filter 'SecurityAuditLoggerTest|SecurityEventsSubscriberTest'`
    OK (68), suite complète OK (6 510), PHPStan et Rector verts. À relire en S3 : le `clientId` d'un client CIMD est
    une URL (publique), portée au contexte.
  - **Task 10 faite** (agent sonnet/high) : `d36775ab` (`register(…, AuditOrigin)`, audit après `add()`, valeur de
    `ClientRegistration::Preregistered`, commande en `Console`). Preuve contrôlée : rouge (audit jamais émis),
    `--filter 'McpClientRegistrarTest|RegisterMcpClientCommandTest'` OK (35), suite complète OK (6 514), PHPStan et
    Rector verts.
  - **Task 11 découpée en trois agents** (mémoire « budget MCP », > 4 fichiers) : **11a** `McpClientAdministrator`
    (interface, classe, exception, test ; T1 relance `retryOnce` dans le code, T3, T4, T8, T10, révision sous
    `findOneByIdForUpdate()` auditée) ; **11b** test de concurrence de l'interblocage (T1, modèle
    `AccountWideRevocationDeadlockTest`) ; **11c** T6, revérification de la redirection à l'échange dans
    `LoopbackAwareAuthCodeGrant` (`invalid_grant`). T5, T7, T9 (DTO, `Assert\Count`, 409, borne de `$limit`) vont aux
    Tasks 12-15 (présentation). Attention : `register()` du registrar audite après son `flush` ; l'administrateur ne
    doit pas l'envelopper dans une transaction (l'audit précéderait le COMMIT).
  - **Task 11a faite** (agent sonnet/high) : `8d7aadde` (`McpClientAdministrator` + interface,
    `McpClientNotFoundException` 404 `log_level: info`, `remove()` sous `retryOnce` → `findOneByIdForUpdate` →
    `lockActiveOfClient` → suppression, audit `FailSafeAudit` après le COMMIT, cause `client-removed`). Preuve
    contrôlée : `--filter McpClientAdministratorTest` OK (12) ; suite complète OK (6 526) et `make back-quality` vert
    (rapportés par l'agent). Décisions de l'utilisateur sur 11a (2026-11-19, toutes recommandées) :
    1. **déplacer** `DeadlockRetryInterface` vers `Shared/Application/` et `DoctrineDeadlockRetry` vers
       `Shared/Infrastructure/Doctrine/` (appelants, tests, règles `security-user.md`,
       `mcp-oauth-revocation-personne.md`, `mcp-oauth-cycle-de-vie.md`) ;
    2. **corriger** le docblock de `RevokedConnectionAudit::record()` et la règle d'audit pour admettre `client-removed` ;
    3. **renommer** `McpClientNotFoundException::withId()` en `forTheCaller()` ;
    4. **accepter** que `revise()` audite le nombre de redirections après déduplication, et le **noter au plan** (Task 11).
  - **Task 11b-1 faite** (agent sonnet/high) : `08ac6b9d` (relance déplacée dans `Shared`, RED 7 erreurs de classe
    absente), `4dbdad67` (`client-removed` cause d'audit seule, docblock + `security-authentication-audit.md`),
    `43e1df98` (`forTheCaller()`), `591d7c01` (écart noté au plan). Preuve contrôlée : plus aucune ancienne référence,
    `--filter 'DoctrineDeadlockRetryTest|DeadlockRetryIntegrationTest|AccountWideRevocationDeadlockTest|McpClientAdministratorTest'`
    OK (21) ; suite complète OK (6 526) et `make back-quality` vert (rapportés). Décisions de l'utilisateur
    (2026-11-19) : `DeadlockRetryIntegrationTest` **reste** sous `tests/Shared/Application` ; **figer l'écart** par un
    test (redirection en double → audit à 1) dans la 11b-2.
  - **Task 11b-2 faite** (agent sonnet/high) : `99450963` (test de caractérisation de l'écart, vert d'emblée) ;
    `e9b5f295` (`McpClientRemovalDeadlockTest` + fixture `remove-mcp-client.php` qui assemble l'administrateur à la
    main, non câblé au conteneur ; `backend-tests.md` complétée). Chemin réel : le test tient C1, `remove()` prend le
    client puis attend C1 (`lockActiveOfClient`, ordre des id), l'échange tient C2 puis veut C1 (supersession) → `40P01`.
    Deux cas (victime choisie par `deadlock_timeout`) : `remove()` relancé une fois et aboutit, ou l'échange échoue
    (`40P01`, 500 côté client, non relancé). RED : relance neutralisée dans le fixture → `failed 40P01`. Preuve
    contrôlée : `--filter 'McpClientRemovalDeadlockTest|McpClientAdministratorTest'` OK (15), deux passages ; 5 passages,
    suite complète OK (6 529), `make back-quality` vert (rapportés). Écart d'agent : deux `Read` dans `tests/Mcp`.
  - **À reprendre en Task 19** : la règle MCP (`mcp-oauth.md` ou `mcp-oauth-cycle-de-vie.md`) ne décrit pas encore la
    suppression d'un client par le backoffice — verrous (client puis connexions par id), relance unique, limite
    acceptée (l'échange perdant rend un 500, comme la limite 4), audit `client-removed` après COMMIT — ni ne cite
    `McpClientRemovalDeadlockTest` ; et le fixture assemble l'administrateur à la main tant que le conteneur ne le câble
    pas (Tasks 16-17B : le câbler, puis passer le fixture au conteneur ?).
  - **Task 11c faite** (agent sonnet/high) : `b4846612` (`respondToAccessTokenRequest()` surchargé : client validé, code
    déchiffré, `allowsRedirect()` sur le client relu ; refus `invalid_grant` constant ; cause d'audit
    `redirect-no-longer-registered`). RED réel : league ne revérifie pas la liste du client (200 + jetons). Preuve
    contrôlée : `--filter 'GrantRefusalAuditTest|TokenControllerTest'` OK (77) ; suite complète OK (6 531),
    `make back-quality` vert (rapportés). **Constat du fil principal à verser à la relecture S3** (moyen, confiance
    haute) : le contrôle passe **avant** `isAuthCodeRevoked()` de league, donc un code **déjà échangé** rejoué avec une
    redirection retirée est refusé sans la révocation `code-replay` de sa connexion (RFC 6749 §4.1.2), et un code
    échu/révoqué est audité sous la nouvelle cause. Questions de l'agent : préséance des causes (il recommande de
    garder) ; `validateClient()` appelé deux fois (secret vérifié deux fois).
  - **S3 relue** (`/code-review medium diff 8c5d8586...HEAD` + auditeur opus/high, HEAD relu `a7a360bb`). Tri :
    - **Série C3 (correction)**, décisions de l'utilisateur (2026-11-19, toutes recommandées) :
      - **C3a** (grant) : rejeu masqué (relecture haute / auditeur moyenne, confiance haute) → **laisser league juger
        d'abord** : la surcharge ne refuse qu'un code non échu (`expire_time`) et non consommé (`isAuthCodeRevoked()`,
        qui lève le rejeu sous verrou) ; tests de régression (rejeu avec vérificateur → connexion révoquée
        `code-replay` ; sans → `replay-unproven`, connexion active ; code échu → sa cause, pas la nouvelle) ; règle
        `mcp-oauth-cycle-de-vie.md` l. ~263 et ~307 (l'échange compare désormais à la liste du client) ; **course
        révision/échange acceptée et documentée** (même règle) ; docblock de `DeadlockRetryInterface` (« opération de
        compte » → générique ; l'appelant ne tient aucune entité ni transaction englobante, F7).
      - **C3b** (audit) : `register()` audite via `FailSafeAudit` (relecture moyenne / auditeur faible) + test de
        panne du journal ; `mcp-client-revised` porte **`addedRedirectCount` / `removedRedirectCount`** (aucune URI) ;
        fixture `remove-mcp-client.php` vérifie la cause `client-removed` (F8).
    - **Constats écrits pour la PR** : `validateClient()` deux fois (clients tous publics, coût négligeable) ; branche
      `redirect_uri` nulle inatteignable (obligatoire à l'autorisation) ; refus avant PKCE (indice à un porteur de code
      volé, bruit d'audit) ; `revise()` sans relance sur interblocage ; pas d'`AuditOrigin` sur révision/suppression ;
      cycles de cascade autres que T1 (purge, suppression de compte) absorbés par `retryOnce` ; lot de verrous et
      cascade non bornés ; « altitude » (règle placée devant league plutôt que dans le jugement sous verrou).
    - **Vers la Task 17B** : rappeler à l'écran que supprimer un client CIMD ne le bannit pas (F10).
  - **C3a faite** : `1df6ea5b` (surcharge + 3 tests de régression, RED constaté), `64b0c9f7` (règle cycle de vie :
    ordre des refus, limites « client lu sans verrou » et « refus avant PKCE »), `05a9e12a` (docblock
    `DeadlockRetryInterface`), `f13db9bc` (`expire_time` gardé par `is_int`, PHPStan). Preuve contrôlée : tests ciblés
    OK (113), `make back-quality` vert (PHPStan, Rector, Psalm, lsp:check sans bloquant), suite complète 6534 OK
    avant `f13db9bc` (changement de garde de type seul), `isAuthCodeRevoked()` appelé deux fois dans la même
    transaction (`TokenEndpoint::issue()` sous `transaction->run`), lecture seule.
  - **C3b faite** : `d07e83e9` (`register()` audite par `FailSafeAudit`, après COMMIT), `2da88040`
    (`addedRedirectCount` / `removedRedirectCount`, sans URI), `1a3bf4c6` (fixture : cause `client-removed`,
    mutation testée), `a769e116` + `452cbaf6` (style, PHPStan). Preuve contrôlée : suite complète 6540 OK après
    `452cbaf6`, qualité verte (lsp:check : 6 faux positifs `ai.yaml`, 0 bloquant), tests ciblés 79 OK.
    `McpClientAdministratorInterface` toujours non câblé → fixture par `new` jusqu'à la Task 17B. À noter pour la
    PR / Task 19 : docblock de `FailSafeAudit` dit « sept sites », il y en a 11.
  - **C3 relue** (`/code-review medium diff 32afd264...HEAD` + auditeur opus/high, HEAD relu `4c61e429`). Ni critique
    ni haut ; un seul moyen (CR « ordre de league recopié / altitude ») : **rangé sous la décision 1** de l'utilisateur
    (« laisser league juger d'abord », alternative « altitude » écartée), risque de montée réduit par l'épinglage
    exact `league/oauth2-server 9.4.1` et par les tests d'ordre ci-dessous. Tri :
    - **Micro-série C3c** (faibles à risque réel, peu coûteux) :
      - **C3c-1** (grant) : repli strict si la charge est mal typée alors que la redirection n'est plus admise
        (`invalidRequest`, test unitaire `expire_time` en chaîne) ; test « code mort (connexion révoquée) + redirection
        retirée → cause de league, pas `redirect-no-longer-registered` » ; docblock du grant (second appel seulement
        pour un code déjà mort, verrou seulement si le premier l'a pris ; conditions de typage) ; règle
        `mcp-oauth-cycle-de-vie.md` l. 316 (même précision) + limite « un code refusé redirection-retirée n'est pas
        consommé : rétablir la redirection dans ses 10 min le rend échangeable ».
      - **C3c-2** : `$before` dédoublonné dans `revise()` + cas `DataProvider` ; docblock `FailSafeAudit` (« sept
        sites » → sans compte figé, sites nommés) ; indentation `McpClientRemovalDeadlockTest.php:122`.
    - **Constats écrits pour la PR** : comptes ajoutées/retirées littéraux (tolérance de port loopback et casse non
      normalisées) ; la commande `app:mcp:client:register` répond succès quand l'audit tombe (seul un `critical` en
      témoigne) ; `time()` lu avant l'attente du verrou (cause `redirect-no-longer-registered` au lieu de
      `code-expired` à la seconde près, refus dans les deux cas).
    - **Vers la Task 17A/B** : le futur processeur d'inscription ne doit pas envelopper `register()` dans une
      transaction (audit après COMMIT) — test « pas d'audit après ROLLBACK » ou garde ; câbler
      `McpClientAdministratorInterface`.
  - **C3c-1 faite** : `aa2a9c69` (repli strict `invalidRequest('code', 'Authorization code malformed')` → cause
    `not-a-code`, RED constaté ; docblock du grant), `b6296090` (code mort + redirection retirée → `code-refused`,
    mutation constatée rouge), `fbb4b631` (règle l. 316 + limite 4 ; règle à 49 026 octets, plafond 50 000 :
    **ne plus rien y ajouter sans la découper**), `030c8836` (Rector). Preuve contrôlée : tests ciblés 98 OK,
    qualité verte, suite complète 6542 OK (avant `030c8836`, helper de test seul).
  - **C3c-2 faite** : `6513c480` (`$before`/`$after` dédoublonnés, RED « 2 is identical to 1 » constaté),
    `5efcc8c7` (docblock `FailSafeAudit` sans compte figé — 10 appels relevés —, indentation). Trailers « Sonnet
    5.5 » (rappel du harnais), comme la 11c : laissé tel quel (pas d'amend). Preuve contrôlée : suite complète 6543
    OK et qualité verte, toutes deux après `5efcc8c7`.
  - **C3c relue** (`/code-review medium diff 4c61e429...HEAD` + auditeur opus/high, HEAD relu `5efcc8c7`). Ni
    critique, ni haut, ni moyen. Constat 3 du code-review (`isAuthCodeRevoked` hors du try) **écarté**, conf. haute :
    league fait le même appel hors de son try (`AuthCodeGrant.php:212`), la seule LogicException possible est un
    défaut de câblage, 500 voulu. Tri (décision « micro-série C3d courte ») :
    - **Micro-série C3d** (un agent sonnet/high, relue par `/code-review medium` seul, sans auditeur : aucun
      comportement d'authentification modifié) :
      - F2/CR5 : `testACodeWithAMistypedPayloadAndARemovedRedirectIsRefusedAsMalformed` en `#[DataProvider]` sur
        4 charges (`expire_time` chaîne, `expire_time` absent, `auth_code_id` entier, `auth_code_id` absent) ; RED
        vérifié au moins sur `auth_code_id` entier (la mutation qui ne teste que `expire_time` survit aujourd'hui).
      - F3/CR8 : dans `McpClientAdministrator::revise()`, `$before` brut sous le verrou ; le dédoublonnage
        (`array_unique`) de `$before` et `$after` passe dans la closure de `FailSafeAudit::attempt()` (aucun calcul
        d'audit ne peut faire échouer l'écriture ; plus de redondance). Le test du dédoublonnage reste vert.
      - F4/CR9 : docblock de `FailSafeAudit.php:18` → « l'inscription, la révision et la suppression d'un client »
        (+ la supersession si elle passe par là), rewrappée.
      - F5 : docblock de `GrantRefusal::NotACode` (`GrantRefusal.php:61`) + « ou une charge de code dont l'échéance
        ou l'identifiant sont mal typés (#652) ».
      - F8 : `@throws` de `refuseTheRemovedRedirect()` complété (`AuthorizationCodeReplayedException` /
        `CompromisedMcpConnectionException`).
      - CR10/F9 : phrase de la l. 316 de `mcp-oauth-cycle-de-vie.md` reformulée (« quand ce contrôle a fait le premier
        appel ») et citant les deux tests, **sans dépasser 50 000 octets** (49 026 aujourd'hui : remplacer, pas ajouter).
    - **Pour la PR** : voir « Relecture de C3c » dans les constats écrits.
    - **Vers la Task 17A/B** : F10, supprimer un client CIMD ne le bannit pas (déjà T8).
  - **C3d faite** (agent sonnet/high, 2026-11-20), point fixe de sa relecture **`7cdad714`** : `90bb1755` (test en
    DataProvider sur 4 charges, RED par mutation sur `auth_code_id`), `9056aee5` (dédoublonnage de `revise()` dans la
    closure d'audit, mutation vérifiée), `c3a9282b` (docblocks FailSafeAudit / NotACode / `@throws` + `use`),
    `f4315586` (phrase de la règle, 49 228 octets, `check-claude-rules.sh` conforme). Preuve contrôlée par le fil
    principal : 25 tests ciblés OK, `make back-quality` exit 0, suite complète 6546 OK (124334 assertions).
    Signalé par l'agent, à verser au tri : le docblock de `respondToAccessTokenRequest()` (l. 99-103 de
    `LoopbackAwareAuthCodeGrant.php`) garde l'ancienne formulation du second appel de `isAuthCodeRevoked()`
    (« pour un code que le premier a déjà jugé mort ») — faible, à aligner sur la règle.
  - **C3d relue** (2026-11-20) : `/code-review medium diff 7cdad714...HEAD`, HEAD relu `8597490a`, sans auditeur
    (aucun comportement d'authentification modifié). Aucun constat critique, haut ni moyen : **boucle close**. Six
    constats faibles (R1-R6), écrits dans « Constats écrits non corrigés » ; décision de l'utilisateur : R1, R2, R3,
    R4, R6 corrigés en **Task 19**, R5 dans la PR.
  - **Task 12 faite** (2026-11-20) : `c791cf91` (`McpConnectionStatus`, `final readonly`, `ConnectionLifetimes` +
    `ClockInterface`, `expiresAt()` / `stateOf()` ; `OwnMcpConnections` le reçoit). Contrôlé : 39 tests OK
    (`McpConnectionStatusTest|OwnMcpConnectionsTest|OwnMcpConnectionsControllerTest`). Écart : l'échéance est
    calculée deux fois par ligne dans `viewOf()` (`stateOf()` rappelle `expiresAt()`), régression de R5a (#650), et
    le commentaire dit « même calcul ». Trailer `Co-Authored-By` de ce commit : Sonnet 5.5 (modèle réel de l'agent).
  - **Task 12' faite** : `8a49d3c7` (`stateOf(connection, now, deadline)`, `viewOf()` n'évalue l'échéance qu'une
    fois, test `testStateOfJudgesOnTheReceivedDeadlineNotOnARecomputation` rouge puis vert). Contrôlé : 40 tests OK.
  - **Task 13 découpée** (8 fichiers > 4) : **13A** dépôt (seuil des non échangées dans
    `findNotRevokedForAdministration(threshold)`, nouvelle `findAbandonedForAdministration(threshold, limit)`, tests
    59/61 min et échangée jamais filtrée) ; **13B** service `AdministeredMcpConnections` + vue + interface + test,
    fusion PHP, D-D du plan et docblocks.
  - **Task 13A faite** : `e03e4284` (`ConnectionLifetimes::abandonedBefore(now)`, `findNotRevokedForAdministration(
    abandonedBefore)`, `findAbandonedForAdministration(abandonedBefore, limit)`, borne incluse testée). Contrôlé :
    125 tests OK (`McpConnectionRepositoryTest|ConnectionLifetimesTest`). `$limit` borné par `@param positive-int`
    seulement (comme les terminées), le service passe une constante.
  - **Task 13B faite** : `993d4b49` (`AdministeredMcpConnections[Interface]`, `AdministeredMcpConnectionView`, fusion
    PHP, 3 `SELECT` prouvés). Contrôlé : 22 tests OK ; suite complète 6566 OK (agent). D-D du plan corrigé (fil
    principal). Écrits pour la PR : `expiresAt` rendu même pour une révoquée (vue non nullable, comme le plan ; la
    vue de la personne le met à `null`) → à trancher à F1-F7 ; compteur de requêtes recopié du helper privé de
    `McpConnectionRepositoryTest` (à extraire vers `tests/Support` en Task 19).
  - **Task 14 faite** : `ee46a332` (`#[ValidMcpClientFields]` + validateur, distinctes comptées sur
    `(string) RedirectUri::fromString()` comme `distinctBounded()`, accord avec `McpClient::register` testé ; message
    « Dix redirections au plus. » épinglé à `MAX_REDIRECT_URIS === 10`). Contrôlé : 15 tests OK. Écrit pour la PR :
    « Au moins une redirection est requise. » n'apparaît que sur une liste vide (toutes invalides → violations par
    index seulement) ; message ajouté « Chaque redirection doit être une chaîne de caractères. ».
  - **Task 15 faite** : `d2660a65` (`exception_to_status` : `McpClientNotFoundException` 404,
    `McpClientAlreadyRegisteredException` 409, `InvalidMcpClientRedirectsException` 422 avant les défauts rétablis ;
    `log_level: info` des deux nouvelles ; `McpClientExceptionMappingTest`, 8 cas). Contrôlé : 43 tests OK (filtre des
    trois classes). Écrit pour la PR : l'entrée 404 de `McpClientNotFoundException` double son `getStatus()` (404) —
    « second source » au sens de `backoffice-api.md`, imposée par le plan, cohérence épinglée par le test.
    La sortie PHPStan « Cannot cast mixed to int » (`LoopbackAwareAuthCodeGrant.php:125`) de `quality.txt` est
    antérieure à `c3a9282b` : PHPStan relancé sur le fichier, `[OK] No errors`.
  - **Relecture S4 faite** (`/code-review medium diff 5660113e...HEAD`, HEAD relu `c25e103b`) : 10 constats.
    Moyens confirmés : (1) `endOf()` prend `revokedAt`, que la purge pose à l'instant de la purge
    (`McpConnectionPurger::revokeExpired($now)`) — une expirée/abandonnée purgée change de rang et occupe les
    200 places du tri `revoked_at DESC` ; (2) aucun index sur `granted_at` / `revoked_at` pour les trois requêtes
    d'administration (seuls `account_id`, `mcp_client_id`, `idx_mcp_connection_active_pair`). Faibles réels :
    (3) `property_exists()` vrai pour une propriété non initialisée ou privée → `\Error`, 500 ;
    (9) chemin `redirectUris[%s]` bâti sur une clé brute : un objet JSON renvoie la saisie dans `propertyPath`.
    Écrits pour la PR : (4) `expiresAt()` relit l'horloge par ligne ; (5) docblock « une ligne de plus » de
    `findAbandonedForAdministration` ; (6) messages doublés de `InvalidMcpClientRedirectsException::none()/tooMany()` ;
    (7) 422 global de `InvalidMcpClientRedirectsException` aussi levée par CIMD, docblock périmé ; (8)
    `revocationCause` en `?string` dans la vue d'administration ; (10) messages français codés en dur.
  - **S4c-1 faite** (2026-11-20, `427bdef4`, `fix(mcp): fin réelle des connexions expirées dans l'administration`) :
    option A livrée, `EndedMcpConnection` (fin calculée une fois), `findExchangedExpiredForAdministration(?McpConnection
    $after, limit)` par pages de 200 à clé `(revoked_at, id)`, arrêt quand `revoked_at` < 200e fin (strict : à
    égalité l'id départage encore). Rouge vérifié (7 échecs pour la bonne raison), vert contrôlé par le fil principal
    (61 tests, 542 assertions) ; `tests/Mcp` 2177 OK, PHPStan, Rector, style OK selon l'agent.
    SQL final pour S4c-2 (jointures `mcp_client`, `cpg_user` identiques) :
    (1) `revoked_at IS NOT NULL AND (revocation_cause IS NULL OR revocation_cause <> 'expired') ORDER BY revoked_at
    DESC, id DESC LIMIT 200` ; (2) `code_exchanged_at IS NULL AND ((revoked_at IS NULL AND granted_at <= :seuil) OR
    revocation_cause = 'expired') ORDER BY granted_at DESC, id DESC LIMIT 200` ; (3) `revocation_cause = 'expired'
    AND code_exchanged_at IS NOT NULL [AND (revoked_at < :r OR (revoked_at = :r AND id < :id))] ORDER BY revoked_at
    DESC, id DESC LIMIT 200`. CHECK `chk_mcp_connection_revocation_cause` : cause NULL ⇔ `revoked_at` NULL.
    Questions rendues : curseur `?McpConnection` ou objet dédié ; documenter et figer par un test l'invariant
    « fin réelle ≤ `revoked_at` » de la purge. Annexes (à écrire dans la PR) : identity map Doctrine retient chaque
    page lue dans le pire cas (confiance moyenne, sévérité basse).
  - **Questions de S4c-1 tranchées** (2026-11-20) : curseur = **Value Object dédié** (`final readonly`,
    `revokedAt` non nul + id, construit depuis la dernière connexion lue) ; invariant « fin réelle ≤ `revoked_at` »
    = **test + ligne dans `mcp-oauth-purge.md`** (édition en sed).
  - **S4c-2 faite** (2026-11-20) : `5454cc9f` curseur `ExchangedExpiredCursor` (`Domain/ValueObject`, fabrique
    `after()`, `ConnectionNeverRevokedException` = LogicException) ; `dc6af1e7` invariant : la purge présélectionne
    en SQL mais juge en PHP (`McpConnectionPurger.php:173-183`, `isExpiredAt`), donc échéance ≤ `revoked_at` tient ;
    assertion ajoutée à `McpConnectionPurgerTest::testThePurgeAndIsExpiredAtAgreeAtTheBoundary`, paragraphe en fin
    de `mcp-oauth-purge.md`. Contrôle fil principal : `tests/Mcp/Connection` 1568 OK. Annexes à écrire dans la PR :
    échec transitoire non reproduit juste après ajout de classes (confiance faible, sévérité basse) ; `docker
    compose` lancé depuis `backend/` prend le `compose.yaml` résiduel de Flex.
  - **Décision** (2026-11-20) : allonger `app.mcp.lifetime.*` après une purge pouvait rendre une échéance
    recalculée > `revoked_at` et fausser l'arrêt anticipé → **fin d'une expirée = min(`deadline()`, `revoked_at`)**,
    invariant vrai par construction ; ajouté à S4c-4.
  - **S4c-3 faite** (2026-11-20, `b5b9ce4a`) : `Version20261120120000` (empreinte n° 42 dans `verify.sql`), trois
    index partiels ascendants `idx_mcp_connection_admin_{terminated,abandoned,exchanged_expired}` (scan backward),
    déclarés dans l'entité ; `'expired'` en **littéral** dans le DQL (`expiredLiteral()` depuis l'enum : un
    paramètre lié n'est pas prouvable en plan générique) ; sans `CONCURRENTLY` (migrations transactionnelles,
    table petite, `lock_timeout` 5 s). EXPLAIN : 4,5-5,6 ms → 0,2-0,27 ms. Contrôle fil principal : 84 tests OK,
    `schema:validate` OK. Annexes à écrire dans la PR : `findNotRevokedForAdministration` sans index dédié
    (confiance moyenne, sévérité basse) ; pages suivantes du keyset en forme OR, index peut-être partiellement
    servi, comparaison de ligne non concluante (confiance moyenne, sévérité basse) ; test d'index sous
    `enable_seqscan/enable_sort = off` à cause des lignes mortes de la base de test (confiance haute, sévérité basse).
  - **S4c-4 tâche 1 faite** (2026-11-20, `85d9944f`) : `readPublicProperty()` (ReflectionProperty publique +
    initialisée, sinon `UnexpectedValueException`), `array_is_list()` + `notAListMessage` (texte fixe, messages de
    la contrainte non traduits). Rouge 9 échecs pour la bonne raison ; contrôle fil principal : 25 tests OK.
    Annexe à écrire dans la PR : appelants du validateur non vérifiés pour un `redirectUris` à clés (confiance
    moyenne, sévérité basse ; une entrée JSON objet est désormais refusée, voulu).
  - **Décision** (2026-11-20) : min(échéance, marquage) **sur les seules échangées expirées** (seules soumises à
    l'arrêt anticipé) ; les jamais échangées gardent octroi + délai (tri SQL `granted_at` exact, sans omission) ;
    limite documentée : après allongement des durées, une abandonnée purgée est classée un peu trop haut.
  - **S4c-4 tâche 2 faite** (2026-11-20, `afa707f8`) : `endOf()` d'une expirée échangée = min(`deadline()`,
    `revokedAt`), limite documentée en tête de classe ; deux tests à durées allongées (rouge 2 échecs pour la
    bonne raison). Contrôle fil principal : 13 tests OK. Annexe : bind-mount désynchronisé a donné un faux vert,
    résolu par `restart backend` (confiance haute, sévérité basse, outillage).
  - **Série S4c close côté implémentation.**
  - **Relecture S4c faite** (2026-11-20, `diff c25e103b...HEAD`, HEAD `f4cca85a`) : 8 constats.
    1 (MOYEN, conf. moyenne) un lot de purge partage un même `revoked_at` : `nothingCanEnter()` (strict) ne peut
    jamais arrêter dans le lot → lecture de toutes les pages ; 2 (faible) keyset en forme `OR`, coût quadratique ;
    3 (faible) `endOf()` d'une jamais échangée devrait passer par `deadline()` ; 4 (faible, conf. haute) `expiresAt`
    affiché non borné, échéance calculée deux fois ; 5 (faible) `expiredLiteral()` mal placé, docblock « deux
    listes » ; 6 (faible, conf. haute) `mcp-oauth-purge.md:106` et docblock de l'interface contredisent
    `nothingCanEnter()` ; 7 (faible, conf. faible) violation de type qui expose la forme interne, `?string clientId`
    non initialisé → PR ; 8 faux positif (contrainte câblée en Task 15).
  - **Décision** (2026-11-20) : constat 1 → **pages par activité** (règle aussi le 2) : lire les échangées expirées
    par `COALESCE(last_used_at, granted_at) DESC, id DESC`, majorant de fin = `idleDeadline(activité)` en PHP + marge
    d'un jour, arrêt quand le majorant < 200e fin ; index d'expression partiel (nouvelle migration, empreinte n° 43),
    `idx_mcp_connection_admin_exchanged_expired` supprimé ; comparaison de lignes servie par l'index.
  - **Prochaine action** : série S4d (étape 05, sonnet/high) — tâche 1 constats 1+2, tâche 2 constat 3, tâche 3
    constat 4, tâche 4 constats 5+6 ; puis relecture `/code-review medium diff f4cca85a...HEAD` (point fixe
    `f4cca85a`), sans auditeur.
  - **Découpage de la tâche 1** (budget de contexte de la zone Mcp, > 4 fichiers) : **1a** curseur par activité
    (`ExchangedExpiredCursor` porte `COALESCE(lastUsedAt, grantedAt)` + id), requête du dépôt (tri par activité
    `DESC, id DESC`, comparaison de lignes), migration n° 43 (index d'expression partiel, ancien supprimé), empreinte
    dans `verify.sql`, déclaration d'index dans l'entité, tests dépôt/index/curseur, adaptation minimale du service
    (arrêt inchangé sur le fond, juste pour compiler) ; **1b** condition d'arrêt du service (majorant
    `idleDeadline(activité)` + 1 jour < 200e fin) et ses tests (dont durées allongées). Agent 1a lancé depuis
    `2deb62e6`+1, **interrompu** (« Autocompact is thrashing », contexte saturé, rien commité) : travail
    partiel dans l'arbre (10 fichiers + `Version20261120130000.php` écrite, déclaration d'index retirée de l'entité,
    arrêt anticipé désactivé ; manquent l'empreinte n° 43, les tests lancés, le commit). **Agent 1a-bis** (`sonnet`,
    `high`, même cran : l'échec tient au contexte, pas au modèle) lancé pour le terminer, brief économe en contexte.
    **1a close** : `a4321644`, `make back-test` vert (6623 tests, 125688 assertions), `make back-quality` rc=0 (rapport
    agent), voie SQL native (`ResultSetMappingBuilder`), preuve rouge avec `ORDER BY revoked_at` (2 échecs). Questions
    ouvertes pour la PR : `ConnectionNeverRevokedException` inutilisée mais conservée ; `schema:validate` en écart attendu
    (index d'expression absent du mapping). **Agent 1b** (`sonnet`, `high`, cran par défaut de l'étape 05) lancé : arrêt
    anticipé par majorant `idleDeadline(activité du curseur en zone) + P1D` strictement sous la 200e fin connue.
    **Agent 1b perdu au `/clear`** (2026-11-20, rien commité, arbre propre en `1be75f8c`) : **1b relancé** au même cran
    (sonnet/high, l'échec ne tient pas au modèle).
  - **1b faite** : `a155c801` (majorant `idleDeadline(activité du curseur)` + P1D, arrêt strict sous la 200e fin ;
    RED 6 SELECT au lieu de 4 ; mutations `<=` et retrait de P1D rouges). Contrôlé : 15 tests OK, arbre propre ;
    `make back-test` 6625 OK et `make back-quality` vert (agent). Annexes (PR) : test d'égalité dépendant du calendrier
    Europe/Paris (conf. moyenne, sév. basse) ; `verification-before-completion` non relancée après compactage de l'agent.
  - **Tâches 2+3 de S4d réunies** (mêmes fichiers : service + test) : constat 3 (`endOf()` d'une jamais échangée par
    `deadline()`) et constat 4 (échéance affichée d'une expirée échangée = fin bornée déjà calculée, sans recalcul).
    Agent sonnet/high lancé. Puis **tâche 4** (constats 5+6 et règle `mcp-oauth-purge.md:106-112`, qui décrit encore
    l'arrêt par `revoked_at`).
  - **Tâches 2+3 faites** : `6c296bd0`. Constat 4 : RED `1796036400 ≠ 1793617200` (échéance recalculée au lieu du
    marquage), test `testAnExpiredExchangedConnectionDisplaysItsBoundedEndWhenLifetimesWereLengthened`. Constat 3 :
    refactoring sans rouge possible (expérience : `fromConfiguration()` refuse toute `unexchanged` ≥ idle), « limite
    acceptée » retirée du docblock. Contrôlé : 16 tests OK, PHPStan des 2 fichiers OK, arbre propre ; `make back-test`
    6626 OK (agent). **Tâche 4 lancée** (sonnet/high) : constat 5 (`expiredLiteral()`), règle
    `mcp-oauth-purge.md:106-112` (arrêt par majorant d'activité + P1D, plus par `revoked_at`), docblock « deux listes »
    du repository (l. 215-218). Le docblock de l'interface est déjà à jour (1b). Ensuite : relecture de série
    `/code-review medium diff f4cca85a...HEAD`.
  - **Tâche 4 faite** : `6d229019` (docblock orphelin retiré, socle = 3 lectures DQL, la native des expirées échangées
    hors socle ; règle `mcp-oauth-purge.md` réécrite sur l'arrêt par activité). Contrôlé : arbre propre ; agent :
    6626 OK, PHPStan/Rector OK, `check-claude-rules.sh` OK. Correction du fil principal `0615d115` : la règle disait la
    borne `revoked_at` toujours sans effet, faux après un allongement des durées. Annexes (PR) : borne `revoked_at`
    sans effet tant que la purge est seule à écrire `expired` (conf. moyenne, sév. basse) ; `deadline() ≤ idleDeadline`
    de la même activité non démontré cas par cas de calendrier (conf. moyenne, sév. basse) ; commit `6d229019` attribué
    à Sonnet 5.5 (exact, modèle de l'agent). **Relecture de série S4d en cours** : `/code-review medium diff
    f4cca85a...HEAD`, sans auditeur (pas d'auth/session/CSRF/cookies).
  - **Relecture S4d faite** (HEAD relu `6de1ba0e`, point fixe de la suivante). 9 constats, tri :
    - **C1 moyen, confirmé** : marge P1D de `endUpperBound()` insuffisante si `connection_idle` est en mois/années
      (P1M : activité 31/01 → 03/03 > 01/02 + P1M + P1D = 03/02), arrêt anticipé qui omet une ligne. Latent (P30D).
      **Décision utilisateur (2026-11-20) : `fromConfiguration()` refuse y/m dans `connection_idle`** (comme pour
      `unexchanged`), test rouge sur P1M/P1Y. → **correction A** (sonnet/high).
    - **C8 faible, lié** : la doc justifie la marge par « PHP et PostgreSQL divergent » ; c'est la non-monotonie du
      calendrier de PHP (changement d'heure) qu'elle couvre. → **correction B** (docblocks + règle purge).
    - **C7 faible à risque réel** : `list()` répète le prédicat de `endOf()` (Expired + échangé) pour choisir
      l'échéance affichée ; à porter par `EndedMcpConnection`. → **correction B**.
    - **C3 rétrogradé faible, confirmé** (`schema:update --dump-sql` = `DROP INDEX
      idx_mcp_connection_admin_exchanged_expired_activity`) : `make:migration` le générera ; garde existante
      `McpConnectionAdministrationIndexesTest`. → une ligne de règle, **correction B**.
    - **C4 faible, conf. haute** : `prefixedColumns()` refait `ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT`, ne
      renomme pas les colonnes de jointure. → **correction C** (repository seul).
    - Écrits (PR) : C2 pas de test de bord du majorant hors mutations 1b (sév. basse) ; C5 `ConnectionNeverRevokedException`
      morte (déjà notée) ; C6 SQL natif contre la préférence QueryBuilder, justifié (constructeur de ligne) ;
      C9 `Version20261120120000Test::setUp()` recopie le `down()` de la migration suivante (sév. basse).
    - **Correction A lancée.** Ensuite B, C, puis relecture `/code-review medium diff 6de1ba0e...HEAD`.
  - **Correction A faite** : `a51a472d` (`notMonotonicInActivity('idleLifetime')`, garde après la positivité ; RED
    4 échecs P1M/P1Y/P1M2D/P1Y1M1D ; règle cycle-de-vie complétée). Contrôlé : 84 tests OK, arbre propre, aucune
    inactivité en mois hors cas refusés ; agent : 6633 OK, PHPStan/Rector OK. **Correction B lancée** (sonnet/high) :
    C7 (fin affichée portée par `EndedMcpConnection`), C8 (docblocks de la marge : calendrier PHP + garde de
    `fromConfiguration()`), C3 (ligne de règle sur le `DROP INDEX` généré par `make:migration`).
  - **Correction B faite** : `b9bf765b` (`endedOf()` unique : fin de rangement + `displayedDeadline` portée par
    `EndedMcpConnection`, `null` = `expiresAt()` ; docblocks de la marge = calendrier PHP + garde y/m ; règle purge :
    marge et `DROP INDEX` des diffs). Contrôlé : 16 tests OK, un seul prédicat de cause, arbre propre ; agent : 6633 OK,
    PHPStan/Rector OK. Annexes : DTO non testé en direct (sév. basse). **Correction C lancée** (sonnet/high) : C4,
    `prefixedColumns()` → `ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT`.
  - **Correction C faite** : `f5985b0b` (`COLUMN_RENAMING_INCREMENT`, `prefixedColumns()` supprimée, WHERE/ORDER BY
    intacts). Contrôlé : repository + index 57 tests OK, arbre propre ; agent : 6633 OK, PHPStan/Rector/Psalm OK.
    Écrit pour la PR (faible, conf. haute) : le test N+1 (`testAdministrationListsHydrateClientAndAccountWithoutFurtherQueries`)
    ne couvre que `findNotRevoked…` et `findTerminated…`, pas la lecture native `findExchangedExpired…`.
  - **Relecture de la série de corrections A-C faite** (`/code-review medium diff 6de1ba0e...1ffa3aef`, 2026-11-20) :
    7 constats, aucun critique, haut ni moyen → **boucle de la série close**. Tests de la relecture :
    `ConnectionLifetimesTest|AdministeredMcpConnectionsTest` OK (100), `McpConnectionRepositoryTest|McpConnectionAdministrationIndexesTest`
    OK (61). Constats écrits : section « Constats écrits non corrigés », « Relecture de la série A-C ».
  - **Micro-série D lancée** (sonnet/high, faible à risque réel ; décision utilisateur « Déplacer ») :
    (a) garde du nombre de requêtes sur `findExchangedExpiredForAdministration()` (test N+1 étendu ou dédié, rouge
    vérifié) ; (b) déplacement du paragraphe « mois et années interdits dans `idle` » de `mcp-oauth-cycle-de-vie.md`
    (49,8 Ko, près du plafond de 50 000 octets) vers `mcp-oauth-purge.md`, à côté de `endUpperBound()`, liens vérifiés,
    `tools/check-claude-rules.sh` vert. Un commit chacune. **Point fixe de sa relecture** : le commit de cette reprise.
  - **Micro-série D faite** (2026-11-20) : `19bbad43` — (a) `testExchangedExpiredReadHydratesClientAndAccountWithoutFurtherQueries`
    (`McpConnectionRepositoryTest`, l. 1335 : deux expirées échangées, `em->clear()`, une seule requête en touchant
    client et compte) ; `bafb0a05` — (b) paragraphe « mois et années interdits dans `idle` » déplacé de
    `mcp-oauth-cycle-de-vie.md` (49 422 octets) vers `mcp-oauth-purge.md` (14 011 octets), renvoi à sa place.
    Preuve rendue par l'agent : RED en retirant les deux `addJoinedEntityFromClassMetadata` (« 3 is identical to 1 »),
    GREEN `OK (52 tests, 470 assertions)`, PHPStan/Rector OK, `check-claude-rules` conforme. **Contrôlé par le fil
    principal** : `git log` (HEAD `bafb0a05`, arbre propre) et lecture du test ; relances faites ensuite (voir
    « Contrôle de D fini » ci-dessous). ~~Question de la jointure isolée~~ : **close**, l'observation de l'agent
    était fausse (voir ci-dessous).
  - Pause demandée par l'utilisateur le 2026-11-20 (reboot) après D ; aucun agent ne tourne.
  - **Contrôle de D fini** (2026-11-20, après reboot) : `McpConnectionRepositoryTest` OK (52 tests, 470 assertions),
    règles 49 422 / 14 011 octets, `check-claude-rules.sh` conforme. **Question de la jointure isolée close** par
    expérience du fil principal (mutation puis `git checkout`) : retirer `k` seule → 3 requêtes (deux `SELECT … FROM
    mcp_client` paresseux), retirer `u` seule → 3 requêtes ; le test garde **chacune** des deux jointures.
    L'observation de l'agent était fausse (cause probable : bind-mount désynchronisé, mémoire du projet).
  - **Relecture de D faite** (`/code-review medium diff 1c0c4470...HEAD`, HEAD relu `45feabed`, point fixe de la
    suivante) : 5 constats faibles, **boucle close**. Décision de l'utilisateur (2026-11-20) : **micro-série E = 1+2**,
    3+4 en **Task 19** ; 5 corrigé par le fil principal (entrée D ci-dessus).
    - E1 : le test N+1 de la lecture native ne vérifie pas l'appariement ligne → client/compte (deux clients au même
      nom « Client de test ») : noms distincts, `assertSame` de la liste attendue dans l'ordre d'activité.
    - E2 : la garde N+1 ne couvre que la première page (`$after` nul) : rejouer avec un `ExchangedExpiredCursor` tiré
      de la première page, une requête, aucun chargement paresseux.
    - **Task 19** (avec R1-R6) : (D3) `mcp-oauth-purge.md` l. ~116, `notMonotonicInActivity()` jugée « right after
      positivity, before the two floors and the two orderings » (code : `ConnectionLifetimes.php:177-206`) ; (D4)
      `mcp-oauth-cycle-de-vie.md` l. ~51, renvoi « The administration's early stop… » à rendre greppable (citer
      « The administration's early stop reads by activity » ou le texte gras exact).
  - **Micro-série E faite** (2026-11-20, sonnet/high) : `8a3a3a8c` — `registerClient($clientId, $name = …)`, E1
    `assertSame(['Client John/john', 'Client Jane/jane'])`, E2 test séparé
    `testExchangedExpiredResumedPageHydratesClientAndAccountWithoutFurtherQueries` (curseur `after()` d'une page de 1).
    Mutations rouges (agent) : jointure client vers un id fixe (E1 seul rouge), jointures client puis compte faussées
    seulement si `$after` (E2 seul rouge), `k` non déclarée seulement si `$after` (2 requêtes au lieu de 1). Contrôlé
    par le fil principal après `restart backend` : 53 tests, 478 assertions OK, arbre propre. Annexe (PR, outillage,
    conf. haute, sév. basse) : `sed -i` / `git checkout --` changent l'inode et désynchronisent le bind-mount ; l'agent
    a muté par `cat copie > fichier`.
  - **Relecture de E faite** (`/code-review medium diff 45feabed...HEAD`, HEAD relu `3ccc05e7`) : 4 constats faibles,
    **boucle close**. Décision de l'utilisateur (2026-11-20) : **tout en Task 19**, dans `McpConnectionRepositoryTest`
    avec l'extraction du compteur vers `tests/Support` :
    - E-1 : page reprise lue en `LIMIT 1` avec une seule ligne restante → semer trois connexions, page suivante de
      limite ≥ 2, `assertSame` des paires (une jointure qui multiplie les lignes passerait selon le plan).
    - E-2 : vérifier que `$firstPage[0]` est John avant `ExchangedExpiredCursor::after()`.
    - E-3 (préexistant) : `testAdministrationListsHydrateClientAndAccountWithoutFurtherQueries` (l. ~1304, lectures
      DQL `forAdministration()`) : noms de clients distincts et `assertSame` des paires, comme E1.
    - E-4 : mise en place et closure de lecture recopiées entre les deux tests de la lecture native → helpers privés.
  - **S5 lancée**, point fixe de sa relecture : **`2dd76a7c`** (relue avec l'auditeur sécurité).
  - **Task 16 faite** (sonnet/high) : `55221253` (`BackofficeMcpClientResource` `final readonly` + `fromClient()`,
    `BackofficeMcpClientProvider` `GetCollection`/`Get`, test 4 méthodes). Contrôlé après `restart backend` :
    `BackofficeMcpClientResourceTest|ApiRouteExposureTest|RouterScopeTest` OK (21) ; agent : `make back-test` 6640 OK,
    qualité verte (lsp:check : 6 avertissements `ai.yaml` connus). Écarts :
    1. **accepté** : la garde ne couvre que `GetCollection`/`Get` ; le plan se trompait (une route absente répond
       **404**, le routeur passe avant le pare-feu). Chaque opération d'écriture entre dans le fournisseur de
       `testEveryOperationRefusesAnAccountWithoutSuper` avec sa tâche (17A-2 : `POST` ; 17B : `PUT`, `DELETE`).
    2. **Décision de l'utilisateur (2026-11-20)** : le `Get` lisait `all()` puis filtrait → **`findOneById(Uuid):
       ?McpClient`** (lecture sans verrou) + comptage des actives **pour ce seul client**, réutilisés par `PUT`/`DELETE`
       en 17B. Découpe (budget Mcp) : **17A-1** lecture d'item ; **17A-2** `POST` du plan.
    3. **accepté** : `id` du DTO = UUID de la ligne `mcp_client` (pas le `client_id` public), comme `BackofficeUserResource`.
    4. compteur `queriesDuring()` recopié dans le test (extraction en Task 19).
  - **Brief 17A-2** (rappels) : ne pas envelopper `register()` dans une transaction (audit après COMMIT) ; câbler
    `McpClientAdministratorInterface` si l'autowiring ne suffit pas ; `POST` dans la garde ; acteur du jeton dans
    l'audit `mcp-client-registered`. **Brief 17B** : `PUT`/`DELETE` dans la garde ; fixture `remove-mcp-client.php`
    assemblé à la main → par le conteneur si câblé ; F10 (supprimer un CIMD ne le bannit pas) va au frontend.
  - **17A-1 faite** (sonnet/high) : `f2a213d2` (`findOneById(Uuid)` en QueryBuilder, toujours une requête ;
    `countActiveOfClient(Uuid): int` ; `McpClientRepositoryFake` à jour), `af73ea77` (le `Get` lit le seul client :
    2 requêtes, 1 si inconnu, un seul `McpClient` géré). Contrôlé après `restart backend` : 94 tests OK (ressource, deux
    dépôts, `ApiRouteExposureTest`) ; agent : `make back-test` 6645 OK, qualité verte. Écart accepté : rouge de
    `findOneById` inobservable (méthode magique `EntityRepository::__call`), remplacé par deux rouges sur le `Get`
    (8 clients gérés au lieu de 1 ; 2 requêtes au lieu de 1 pour un inconnu).
  - **17A-2 faite** (sonnet/high) : `4d7f0617` (`BackofficeMcpClientInput`, `BackofficeMcpClientProcessor` sans
    transaction → `McpClientAdministratorInterface::register()` (autowiré), acteur lu dans le jeton ; `POST` 201 ;
    garde étendue au `POST` ; garde-fou source « pas de transaction » validé par mutation). Contrôlé après `restart
    backend` : 32 tests OK ; agent : `make back-test` 6650 OK, qualité verte. **Écarts acceptés par l'utilisateur
    (2026-11-20)** : (1) anonyme sur le `POST` → **403** (garde CSRF avant le pare-feu, comme l'invitation) ;
    (2) `@use`/`@implements` sur l'union `Input|Resource` + `\assert` dans `create()` — la 17B y ajoute
    `BackofficeMcpClientRevisionInput` ; (3) `rector.php` : `withSkip` provisoire de
    `RemoveUnusedPrivateMethodParameterRector` et `ReturnNeverTypeRector` sur ce seul Processor — **la 17B le
    retire** (obligatoire).
  - **17B faite** (sonnet/high) : `5d0755bb` (`BackofficeMcpClientRevisionInput` ; `Put` 200 / `Delete` 204,
    `provider:` explicite + `requirements` UUID ; Processor `revise()`/`remove()` sans transaction ; garde étendue à
    `PUT`/`DELETE`, avec état intact après refus ; aucun audit sur 404/409/422 ; course « supprimé entre Provider et
    `remove()` » → `McpClientNotFoundException` 404 ; `BackofficeMcpClientRemovalTest` à part, collision de traits
    `HoldsAnMcpConnection`/`DrivesTheOAuthFlow`), `3c33356a` (skip Rector retiré). Contrôlé après `restart backend` :
    42 tests OK, 5 routes `backoffice/mcp/clients` ; agent : `make back-test` 6660 OK, qualité verte.
    **Décisions de l'utilisateur (2026-11-20)** : la réponse du `PUT` **relit** `countActiveOfClient()` (une requête
    de plus, compte frais) ; **Task 19** : le fixture `tests/Mcp/Connection/Fixtures/remove-mcp-client.php` prend
    `McpClientAdministratorInterface` **au conteneur de test**, commentaire « aucune route ne le câble » corrigé.
  - **Task 18 faite** (sonnet/high) : `7adc2966` (`BackofficeMcpConnectionResource` `fromView()`, 12 clés exactes,
    `GetCollection` seule, `skip_null_values: false` ; Provider sur `AdministeredMcpConnectionsInterface::list()` ;
    5 tests dont coût constant : 7 instructions = instantané (3) + 4 SELECT, à 2 et à 8 connexions ; mutation
    `identifier: true` → rouge). Contrôlé après `restart backend` : 21 tests OK ; agent : `make back-test` 6665 OK,
    qualité verte. Questions de l'agent : pagination → **déjà tranchée** (plan D-D) ; `expiresAt` d'une révoquée →
    F1-F7 (déjà noté) ; accord abandonné rendu `expired` sans cause = vue Task 13. **Task 19** : le docblock de
    `AdministeredMcpConnectionsInterface::list()` dit « trois requêtes », il y en a quatre (cf.
    `AdministeredMcpConnectionsTest::testListReadsInFourQueries`).
  - **S5 implémentée** (Tasks 16-18) et **relue** (2026-11-20, `diff 2dd76a7c...139169f0`, `/code-review medium` +
    auditeur sécurité opus/high) : **aucun critique/haut/moyen**, critère d'arrêt atteint. **Décision de
    l'utilisateur** : micro-série **F** sur quatre constats faibles :
    - **F-a** (audit F1 / revue 8) : `POST` → **422** si `clientId` est une URL CIMD (`CimdClientId::tryFrom()`
      réussit) dont l'hôte est dans `AllowedClientDomains` (sinon il masque le vrai client CIMD avant sa
      première approbation et fausse le consentement) ;
    - **F-b** : (revue 9) test `DELETE` d'un client **pré-enregistré** avec connexions (204, cascade, audits, bearer
      refusé) ; (revue 2 / audit I5) pas de `countActiveOfClient` pour `PUT`/`DELETE` dans le Provider ; (revue 4)
      Provider → `McpClientNotFoundException` au lieu de `null` (une seule forme de 404) ; (revue 3) `clientId` dans
      le corps d'un `PUT` refusé (aujourd'hui ignoré, 200).
  - **F-a faite** (`f3260eba`, contrôlée : filtre `ValidMcpClientFields|BackofficeMcpClientResourceTest` 57 verts après
    restart ; agent : `make back-test` 6676 verts, `make back-quality` propre). Refus dans `ValidMcpClientFieldsValidator`
    (message fixe `ValidMcpClientFields::$verifiedDomainClientIdMessage`) ; sous-domaine, port, `http://`, majuscules,
    domaine non listé restent ordinaires (règle exacte de `CimdClientId`/`AllowedClientDomains`).
  - **Décisions de l'utilisateur (2026-11-20, après F-a)** :
    - **F-c** (avant F-b) : même refus dans `McpClientRegistrar::register()` (exception métier typée), pour couvrir le
      CLI `app:mcp:client:register` (`RegisterMcpClientCommand.php:81`) ; le validateur garde son 422 au chemin `clientId`.
    - **Task 19 + PR** : requête de contrôle en lecture seule à lancer en préprod/prod avant la release (ligne
      `registration = preregistered` dont le `client_id` est une URL `https://claude.ai/…` ou `https://chatgpt.com/…`) ;
      une ligne trouvée se supprime par `DELETE` du backoffice. Pas de migration.
    - Texte proposé par l'agent F-a pour `.claude/rules/mcp-oauth-cimd.md` : à reprendre en Task 19 (en retirant la
      mention « CLI non couvert » une fois F-c faite).
  - **F-c faite** (`0beaedd7`, contrôlée : filtre registrar/CLI/validateur/backoffice/`AllowedClientDomains`/mapping
    132 verts après restart ; agent : `make back-test` 6698 verts, `make back-quality` propre). Règle unique
    `AllowedClientDomains::isVerifiedClientId()` (validateur + `McpClientRegistrar`, avant la pré-lecture, sans écriture
    ni audit) ; `McpClientIdOfVerifiedDomainException` → 422 / `info` ; CLI : `FAILURE` + message. `CimdClientAdmission`
    non factorisée (ordre `tryFrom` → ligne preregistered → `allows`). Texte de règle `mcp-oauth-cimd.md` proposé par
    l'agent F-c (remplace celui de F-a) : à reprendre en Task 19.
    - Constat écrit (Task 19/PR, faible, conf. moyenne) : une future porte d'écriture `preregistered` hors registrar
      contournerait le refus ; garde possible dans `McpClient::register()` ou test d'architecture.
  - **F-b faite** (`971e83d5` test caractérisation DELETE pré-enregistré + cascade, prouvé par mutation ; `91e91b95`
    Provider : `countActiveOfClient` seulement si `$operation instanceof Get` ; `1f10558c` Provider lève
    `McpClientNotFoundException::forTheCaller()` → un seul 404 typé GET/PUT/DELETE ; `c9530b37` `Put` en
    `allow_extra_attributes: false` → 400 `MalformedRequestBodyException`, message fixe). Contrôlée : filtre
    `BackofficeMcp|ApiRouteExposure|RouterScope|ItemRouteRequirement|MalformedRequestBody` 115 verts après restart ;
    agent : `make back-test` 6706 verts, `make back-quality` propre. Note : `BackofficeMcpClientRemovalTest` portait
    déjà sur un client **pré-enregistré** (`RegisteredMcpClient::of()`) ; aucun test CIMD de bout en bout avec bearer
    détenu (écrit, PR). Textes de règle proposés par l'agent F-b (Provider d'item, 404 typé, `allow_extra_attributes`)
    : à reprendre en Task 19.
  - **Décisions de l'utilisateur (2026-11-21, après F-b)** : le `activeConnections = 0` non lu de la ressource rendue
    par le Provider à `PUT`/`DELETE` est **accepté, documenté** ; **F-d** avant la relecture : `allow_extra_attributes:
    false` aussi sur le `Post` (400, rien d'écrit).
  - **F-d faite** (`658e6337` : `Post` en `allow_extra_attributes: false` → 400 `MalformedRequestBodyException`, detail
    fixe, aucune ligne ni audit ; cas `registration`, `redirectUri`, `colour`). Contrôlée : filtre
    `BackofficeMcp|MalformedRequestBody|ApiRouteExposure|RouterScope` 115 verts après restart ; agent : `make back-test`
    6709 verts, `make back-quality` propre. Constat : 400 (désérialisation) passe avant 422 (validation), non testé.
    Écrits pour la Task 19 : aligner le test du `Put` (asserter le `detail`, une ligne) ; en S6, le corps envoyé par le
    frontend en POST/PUT doit coller exactement à `BackofficeMcpClientInput` / `…RevisionInput` (sinon 400).
  - **Relecture F faite** (point fixe `139169f0`, HEAD relu `7af12310`) : `/code-review medium` 1 préexistant + 4 nits ;
    auditeur (opus/high) 0 critique/haut/moyen, 2 faibles, 9 infos. **Critère d'arrêt atteint.**
    - Faible n° 1 (= préexistant de la revue) : le refus ne vaut qu'à l'écriture ; une ligne `preregistered` écrite avant
      que son domaine n'entre dans `allowed_domains` (ou liste vidée puis rétablie) masque le vrai client CIMD, et le
      `PUT` peut encore la réviser. Pistes : (A) admission fermée si ligne `preregistered` et `allows($id)` (amende D-C),
      (B) `revise()` refusé sur un identifiant vérifié, contrôle au déploiement.
    - Faible n° 2 : l'invariant vit dans le registrar, pas dans `McpClient::register()` ni la valeur par défaut SQL.
      Piste : test d'architecture (`McpClient::register(` appelé seulement depuis `McpClientRegistrar`).
    - Écrits (Task 19 / PR) : `@throws McpClientIdOfVerifiedDomainException` sur `McpClientAdministratorInterface` ;
      message de refus en une constante unique ; `instanceof Get` à inverser (Put/Delete) ; commentaire
      `api_platform.yaml` (« le CLI » faux) ; tentative refusée non auditée (info 5, arbitrage) ; sosies d'URL (info 3) ;
      nom d'attribut journalisé via `previous` (info 6, niveau `info` en prod à vérifier) ; garde
      `collect_extra_attributes_errors` (info 7) ; trois assertions de test (info 10 : message 422 sans URL, `detail`
      du PUT, ordre 404 avant 400) ; frontend S6 : corps exact, jamais de spread de la ressource lue.
  - **Décisions de l'utilisateur (2026-11-21, tri F)** : faible n° 1 → **micro-série F-e : A + B** (admission fermée
    sur une ligne `preregistered` d'un domaine admis, cause dédiée auditée, amendement D-C de `mcp-oauth-cimd.md` ;
    `PUT` refusé sur un identifiant vérifié) ; faible n° 2 → **test d'architecture en Task 19** ; info n° 5 (audit
    d'une tentative refusée) → **Task 19, à instruire**.
  - **F-e faite** (`91789511` A : admission fermée, cause `preregistered-row-shadows-verified-domain`, auditée au niveau
    `info` comme tout audit, 401 `invalid_client` au point d'autorisation, règle D-C amendée ; `3d9ff343` B :
    `McpClientAdministrator::revise()` reçoit `AllowedClientDomains`, `cannotBeRevised()` 422 sous `FOR UPDATE`, DELETE
    permis). Contrôlée : filtre `Cimd|BackofficeMcp|McpClient|AllowedClientDomains|ApiRouteExposure|RouterScope` 568
    verts après restart ; agent : `make back-test` 6715 verts, `make back-quality` propre. Seul appelant de `admit()` :
    `AuthorizationRequestValidator` (aussi utilisé par `ConsentService`). Écrits : connexions déjà accordées à une
    ligne masquante non coupées (seule l'autorisation suivante est refusée) ; niveau d'audit `info` non relevé ; cause
    absente de `security-authentication-audit.md` (~l.83) et `docs/rgpd/registre-traitements.md` (l.178) → Task 19.
  - **Relecture F-e faite** (point fixe `a0d0e611`, HEAD relu `f749878d`) : `/code-review medium` → 2 importants
    (moyen : connexions/codes d'une ligne masquante toujours servis au point de jeton — refresh, échange de code ;
    conf. basse : lignes masquantes déjà en préprod/prod au déploiement → requête de contrôle Task 19 déjà prévue)
    + 6 nits ; auditeur opus/high → 0 critique/haut/moyen, 4 faibles (résidu jeton, oracle temporel déjà existant,
    audit avant quota borné par nginx seul, doc périmée), positifs : consentement GET/POST fermé, B contrôle la
    ligne verrouillée, enum complet. Question auditeur n° 4 (`McpClientRegistrar`/CLI) : déjà fermée par F-c.
  - **Décisions de l'utilisateur (2026-11-21, tri F-e)** : résidu jeton → **micro-série F-f : refus au point de
    jeton** (`ClientRepository::getClientEntity()` rend `null` → `invalid_client` pour une ligne `preregistered`
    dont `isVerifiedClientId()` ; coupe échange de code et refresh) ; audit avant quota → **accepté, borne réelle
    écrite** (zone nginx `oauth`) dans la règle et le registre RGPD ; doc et tests faibles → **dans F-f** (cause dans
    `security-authentication-audit.md` et `docs/rgpd/registre-traitements.md`, borne fausse du registre, docblocks
    `mcpClientMetadataRefused` et du cas d'enum, `@throws` de `revise()` en fin de docblock, assertion vide « aucun
    code émis » de `CimdAuthorizationTest` ~l.398, cause exclue du provider `refusalsAfterTheList`, limites
    acceptées dans `mcp-oauth-cimd.md` : consentement revalidé, oracle temporel, audit borné par nginx) ;
    indicateur backoffice `shadowsVerifiedDomain` → **Task 19 / S6**.
  - **F-f1 faite** (`b27f4fbe`, agent sonnet/high) : `ClientRepository::getClientEntity()` rend `null` pour une ligne
    `preregistered` d'un domaine vérifié (`AllowedClientDomains` injecté) → `invalid_client` à l'échange de code et au
    refresh ; `MasqueradingPreregisteredRowTokenTest` (7 tests, rouge vérifié : 4 échecs « 200 is identical to 401 »),
    `LeagueRepositoriesTest` adapté. **Contrôlé par le fil principal** : `git log` (1 commit, 3 fichiers, arbre
    propre), 4 fichiers de test du parcours relancés `OK (69 tests, 1169 assertions)` ; suite complète 6723 verts et
    `back-quality` 0 selon l'agent. Mesures rendues : (a) `/api/oauth/revoke` d'une ligne masquante → 401
    `invalid_client`, rien révoqué ; (b) aucun audit du refus au point de jeton (client « inconnu ») ; (c) jeton
    d'accès déjà émis accepté à `/api/mcp` ≤ 1 h.
  - **Décisions de l'utilisateur (2026-11-21, tri F-f1)** : (a) révocation refusée → **acceptée, documentée** (F-f2,
    limites de `mcp-oauth-cimd.md`) ; (b) refus non audité → **micro-série F-f1b : auditer** (nouvelle cause
    `GrantRefusal`, hôte seul, comme à l'autorisation ; tient l'invariant de `mcp-oauth-audits.md` « tout refus d'un
    grant authentique au point de jeton est audité ») ; (c) jeton d'accès ≤ 1 h → **accepté, documenté** (F-f2 ;
    lignes héritées seules, contrôlées par la requête des notes de release).
  - **F-f1b faite** (`00ee6104`, agent sonnet/high) : cause `GrantRefusal::PreregisteredRowShadowsVerifiedDomain`
    (`preregistered-row-shadows-verified-domain`) ; échange de code et refresh d'une ligne masquante → une ligne
    `mcp-grant-refused` (mêmes clés/niveau que les autres causes) ; `client_id` inconnu ou grant forgé → aucune ligne ;
    prédicat unique `AllowedClientDomains::isShadowedBy()` ; `McpClientRepositoryInterface::registrationOf()` (DBAL
    natif, EM fermé après rollback) ; `LeagueGrantRefusalAudit::record()` ne sort plus sur `invalid_client` mais
    exige artefact authentique + ligne masquante. Rouge vérifié (2 échecs « actual size 0 matches expected size 1 »).
    **Contrôlé par le fil principal** : 1 commit, 9 fichiers, arbre propre ; `make back-quality` relancé en entier
    (PHPStan/Rector/Psalm OK, lsp 0 blocking) ; `tests/Mcp/Connection` `OK (1691 tests)` ; suite complète 6726 verts
    selon l'agent.
  - **Décision de l'utilisateur (2026-11-21, tri F-f1b)** : code authentique d'un client A présenté avec le
    `client_id` d'une ligne masquante B (cause masquante, `clientId` = A, `presentingClientId` = B) → **figer par un
    test dans F-f2**, et l'écrire dans `mcp-oauth-audits.md`.
  - **Liste F-f2 (doc + tests faibles)** : celle du tri F-e ci-dessus, plus (a) révocation 401 et (c) jeton d'accès
    ≤ 1 h dans les limites de `mcp-oauth-cimd.md` ; la nouvelle cause et le cas client croisé dans
    `mcp-oauth-audits.md`, `security-authentication-audit.md` et `docs/rgpd/registre-traitements.md` ; docblocks
    `ClientRepository::getClientEntity()`, `LeagueGrantRefusalAudit`, `GrantRefusalAuditInterface::record()`,
    `GrantRefusal`, `LeagueGrantRefusals` (`CAUSE_OF_HINT`) ; test client croisé. Plus de 4 fichiers → découper :
    **F-f2a** (tests + docblocks PHP), **F-f2b** (règles `.claude/rules` + registre RGPD).
  - **F-f2a faite** (`2f8218b1`, 3 fichiers de test) : assertion « aucun code émis » réelle (décompte
    `mcp_authorization_code` + `Location` nul), cause masquante exclue de `refusalsAfterTheList`, 2 tests client croisé
    (code, refresh). Mutation prouvée pour l'audit ; pour l'assertion de `CimdAuthorizationTest`, rouge seulement par
    inversion de l'attendu (muter `admit()` fait échouer le test plus loin, sur l'audit). **Contrôlé** : 1 commit, arbre
    propre, 3 fichiers relancés `OK (49 tests)` ; suite 6727 verts et `back-quality` vert selon l'agent.
  - **F-f2b découpée** (8 fichiers PHP de docblocks) : **F-f2b1** côté CIMD (`McpClientAdministrator::revise()`
    `@throws` en fin, `SecurityAuditLoggerInterface::mcpClientMetadataRefused`, cas de `ClientMetadataRefusal`,
    `ClientRepository::getClientEntity()`), **F-f2b2** côté jeton (`LeagueGrantRefusalAudit`,
    `GrantRefusalAuditInterface::record()`, `GrantRefusal`, `LeagueGrantRefusals`/`CAUSE_OF_HINT`) ; puis **F-f2c**
    (`mcp-oauth-audits.md`, `mcp-oauth-cimd.md`, `security-authentication-audit.md`, `docs/rgpd/registre-traitements.md`).
  - **F-f2b1 faite** (`f573a4fc`, 4 fichiers, commentaires seuls — contrôlé par diff) ; agent : back-quality,
    back-test 6727 verts. Signalé hors périmètre, **écrit pour la Task 19** (faible, doc) : docblock de
    `CimdClientAdmission` (~l.20, « refus du document ») et de `AuthorizationRequestValidator::admitted()` (« refus du
    document » incomplet pour la cause masquante).
  - **F-f2b2 faite** (`ffc614ba`, 4 fichiers, commentaires seuls — contrôlé par diff ; `CAUSE_OF_HINT` inchangée) ;
    agent : back-quality, back-test 6727 verts.
  - **F-f2c faite** (`c3bc3866`, 4 fichiers doc, `tools/check-claude-rules.sh` conforme — contrôlé ; règles sous
    50 000 o : audits 15205, cimd 24087, security-audit 30044). Signalé hors périmètre, à trier à la relecture :
    « Who judges what » de `mcp-oauth-cimd.md` (/token, refresh, /revoke « judge the row » sans l'exception masquante,
    couverte par un nouveau paragraphe) ; « To revisit with CIMD (#651) » de `mcp-oauth-audits.md` peut-être périmé ;
    historique en tête du registre RGPD sans entrée #652. La micro-série F-f est close (F-f1, F-f1b, F-f2a, F-f2b1,
    F-f2b2, F-f2c).
  - **Relecture de F-f faite** (point fixe relu `25bdc951` ; `/code-review medium` + security-auditor opus/high) :
    0 critique/haut/moyen à l'audit ; 1 « important » au code-review = limites acceptées (a)+(c) combinées
    (révocation refusée, jeton d'accès accepté 1 h ; recul face à RFC 7009) → **PR**, rien à corriger.
    Faibles à risque réel → **micro-série F-g** :
    - F-g1 (code, ≤ 3 fichiers) : dans `LeagueGrantRefusalAudit::record()`, sur `invalid_client`, trancher
      `isVerifiedClientId($presentingClientId)` en mémoire AVANT le déchiffrement et AVANT `registrationOf()`
      (un `client_id` mal formé `%FF%FE` + artefact authentique fait aujourd'hui échouer la requête SQL →
      `critical` « Grant refusal not audited » à volonté ; un anonyme déclenche 1 déchiffrement par requête) ;
      tests unitaires de la branche `invalid_client` (masquante, croisé, préregistered hors domaine, `cimd`,
      inconnu sans déchiffrement, mal formé sans SQL) ; docblock « Coût » (F6). Vérifier et rendre : questions
      Q2 (collation de `mcp_client.client_id`) et Q4 (`invalid_client` émis par `TokenEndpoint` avant league,
      `resource` étranger).
    - F-g2 (doc) : `mcp-oauth-audits.md` (phrase « Coût » l.126-127, lecture du client croisé `clientId` ≠
      `presentingClientId`, « To revisit with CIMD (#651) » à jour ?) ; `mcp-oauth-cimd.md` « Who judges what »
      l.31 (exception masquante) ; en-tête d'historique du registre RGPD (#652) si la convention le veut.
    Écrit pour la **Task 19** : prédicat D-C recopié dans `CimdClientAdmission.php:74-80` et
    `McpClientAdministrator.php:108` au lieu de `isShadowedBy()` (docblock « écrit une fois » faux) ;
    `registrationOf()` en SQL brut plutôt qu'en QueryBuilder DBAL ; borne de longueur de l'artefact avant tout
    déchiffrement (défense en profondeur, profite à league). Pour la **PR** : Q5 (fermer un jour la limite (c)
    dans l'authentificateur de `/api/mcp`), Q1/Q3 de l'audit (connexion DBAL après rollback, valeur brute vs
    validée).
  - **F-g1 faite** (`348e0eda`, 4 fichiers ; contrôlé : 35 tests ciblés verts, suite complète 6742 OK,
    back-quality vert selon l'agent). Rouge vérifié : déchiffrement tenté hors domaine, `registrationReads` = 1
    pour `\xFF\xFE`, `critical` « Grant refusal not audited » en intégration. Tout `client_id` qui passe
    `isVerifiedClientId()` a la forme de `McpClientId` (motif ASCII ancré) : sûr pour PostgreSQL. Q2 : aucune
    collation explicite dans les migrations (défaut de la base). Q4 : `TokenEndpoint::isKnownClient()`
    (`findOneByClientId()`, sans masquage) tient une ligne masquante pour connue → `resource` étranger =
    `invalid_target`, ligne d'audit ordinaire, pas la cause dédiée — **arbitrage posé à l'utilisateur**.
    Piège pour les docblocks et règles : `CommentedClassNamesExistTest` lit `\A…\z` comme un nom de classe.
  - **Décision Q4 (utilisateur, 2026-11-21)** : unifier — micro-série F-g1b, `isKnownClient()` applique
    `isShadowedBy()` comme `ClientRepository` (`invalid_client` + cause dédiée).
  - **F-g1b faite** (`6148d69b`, 2 fichiers ; contrôlé : 16 tests verts, suite complète 6745 OK selon l'agent).
    `TokenEndpoint::isKnownClient()` applique `isShadowedBy()` ; rouge vérifié (`invalid_target` 400 au lieu de
    401) ; un client CIMD non masqué avec `resource` étranger garde `invalid_target`.
  - **F-g2 faite** (`73e74d9d`, 4 fichiers doc, aucun PHP, `tools/check-claude-rules.sh` conforme — contrôlé ;
    audits 17544, cimd 24659, oauth 43917 o). « To revisit with CIMD (#651) » clos par lecture du code (motif
    `McpClientId` + garde `isVerifiedClientId()`) ; pas de test de bout en bout d'un `presentingClientId` URL dans
    `mcp-grant-refused` → **PR** (ou Task 19 si on veut la preuve exécutable). Micro-série F-g close (F-g1, F-g1b, F-g2).
  - **Relecture de F-g faite** (point fixe relu `25bdc951`, HEAD relu `1ace7177` = **point fixe suivant** ;
    `/code-review medium` + security-auditor opus/high) : **0 critique/haut/moyen**, boucle close. Audit : axes 1-4
    vérifiés (réordonnancement strictement équivalent, motif ASCII sûr, aucun oracle neuf — F-g en ferme un, témoin
    `OAuthEncryptionKey('')` probant ; 22 + 16 tests verts). **Décision utilisateur : micro-série F-h** (faibles à
    risque réel) :
    1. `mcp-oauth-audits.md:150-155` : garantie de forme attribuée à `isKnownClient()` → citer
       `ClientRepository::getClientEntity()` (league valide le client avant l'artefact), `isKnownClient()` pour
       l'`invalid_target`, `isVerifiedClientId()` pour `invalid_client` ;
    2. docblock de `testAForgedArtifactOnAShadowingRowIsNotAudited` (l.307) contraire à l'assertion (0 lecture) ;
       libellé `'client inconnu'` du jeu de données l.236 (une ligne existe) ;
    3. `isShadowingRow()` autonome : `isVerifiedClientId($id) && Preregistered === registrationOf($id)` ;
    4. tests : épingler la `connectionId` désignée (unitaire : UUID gardé et comparé ; fonctionnel : connexion
       détenue) ; cas manquant « domaine vérifié sans ligne + artefact authentique » → 1 lecture, aucune ligne.
    Écrit, non corrigé — **Task 19** : 3e copie du prédicat « client connu » (`TokenEndpoint::isKnownClient()` /
    `ClientRepository::getClientEntity()`, une méthode `findServable()` ou test de parité ; à joindre au prédicat D-C
    recopié) ; typer `registrationOf(McpClientId|CimdClientId)` + test dépôt avec `"\xFF"` ;
    `isKnownClient()` juge sur l'id stocké, l'audit sur l'id présenté (même chaîne aujourd'hui) ;
    `registrationOf()` : `tryFrom()` → null silencieux sur valeur inconnue (antérieur). **PR** : coût de
    déchiffrement résiduel sur domaine vérifié (documenté, borné nginx) ; course suppression admin / relecture de
    l'audit (limite) ; 400 → 401 sur `resource` étranger + ligne masquante (notes de release) ; oracle résiduel
    `cimd` découverte 400 / absente 401 (identifiants publics) ; identifiants proches d'un vérifié
    (`:443`, majuscules) préenregistrables — à décider ; question : test fonctionnel du `client_id` mal formé vu
    rouge sur `25bdc951` ? (à confirmer depuis le rapport F-g1).
  - **F-h faite** (`bd0568ea`, 4 fichiers, contrôlé ; + `beb4eead` fil principal : phrase « never call
    `isShadowingRow()` without that guard » (`mcp-oauth-audits.md:141`) devenue fausse, réécrite). Écart accepté :
    `isShadowingRow()` garde `isShadowedBy()` (définition unique de la règle) derrière son propre
    `isVerifiedClientId()`. Mutations rendues : autre connexion → 8 rouges ; garde de `record()` retiré → la garde de
    `isShadowingRow()` suffit seule à protéger PostgreSQL. back-quality vert, back-test 6746 verts.
  - **Relecture de F-h faite** (point fixe relu `1ace7177`, HEAD relu `0f536f77` = **point fixe suivant**) :
    `/code-review medium` → **1 important** (doc) + 5 nits ; audit opus/high → 0 critique/haut/moyen, 4 faibles,
    2 info ; ordre league 9.4.1 vérifié exact (validateClient avant l'artefact). L'important : la règle
    `mcp-oauth-audits.md:151-158` dit « `isKnownClient()` plays no part » — faux, l'`invalid_target` est levé par
    `TokenEndpoint` avant league et seule `isKnownClient()` borne son `presentingClientId` (sur-correction de F-h).
    Critère d'arrêt → **série de correction F-i** : (1) paragraphe réécrit, deux sources selon le chemin, « au point
    de jeton », ordre tenu par `LoopbackAwareAuthCodeGrant::respondToAccessTokenRequest()` ; (2) test fonctionnel
    `client_id` mal formé + `resource` étrangère + code authentique → `invalid_client`, aucune ligne (fige
    `isKnownClient()`) ; (3) `assertAuditedAsCrossed()` épingle `connectionId` ; (4) docblock du fournisseur
    « (inconnu, ou ligne ordinaire) » ; (5) `connectionIdsOf()` : `assertIsString` au lieu de `''`. Écrit, non
    corrigé — **PR/Task 19** : seconde garde de `isShadowingRow()` = mutant équivalent (défense en profondeur, non
    figée ; commit `bd0568ea` nommé `fix` alors que c'est du durcissement) ; test fonctionnel à deux connexions du
    même client (couvert strictement par l'unitaire).
  - **F-i faite** (`5991464d`, 3 fichiers, `backend/src` intact, contrôlé ; ordre `validateClient()` l.123 avant
    `payloadOfTheCode()` l.124 vérifié). Test `testAMalformedClientIdWithAForeignResourceIsRefusedAsInvalidClientAndNotAudited`
    (4 jeux) ; mutations rouges puis vertes ; back-test 6753 verts. Limite notée : la mutation de `isKnownClient()`
    échoue sur le statut (400 vs 401) avant l'assertion « aucune ligne ». **Décision utilisateur : relecture de F-i par
    `/code-review medium` seul** (doc + tests, pas d'audit sécu).
  - **Relecture de F-i faite** (point fixe relu `0f536f77`, HEAD relu `9ec236bd` = **point fixe suivant**,
    `/code-review medium` seul) : **0 important**, 1 antérieur + 5 nits → **boucle F-f…F-i close**. Écrit, non
    corrigé — **PR** (antérieur, conf. moyenne) : une ligne `foreign-resource` peut désigner la connexion de A avec
    un `presentingClientId` B connu, car `refuseForeignResource()` ne lie pas le code à son client (league le
    ferait) → dire dans la règle que cette ligne ne prouve pas que le présentant détient le code, ou auditer
    `invalid_target` après la liaison (à trancher). **Task 19** (nits tests/doc) : la règle « mirrors
    `getClientEntity()` » = forme et existence seulement, pas la liaison code/client ; cas `%FF%FE` et 256 car. du
    nouveau test non discriminants pour la garde de forme (seuls octets non UTF-8 / NUL le sont) ; le test n'affirme
    pas l'absence de ligne `foreign-resource` quel que soit le statut ; drapeau `registered` du fournisseur sans
    pouvoir discriminant (cas redondants) ; `connectionIdsOf()` suppose une seule connexion du client (comparer à la
    connexion de l'artefact).
  - **S6 démarrée** (2026-11-21) : **agent F1 lancé en `opus`/`xhigh`** (demande explicite de l'utilisateur, au-dessus
    du cran par défaut sonnet/high). **Point fixe de la relecture S6** : le commit de cette reprise. Questions à rendre
    par F1 (non tranchées) : `expiresAt` d'une révoquée (nullable ou non, la vue d'administration le rend toujours) ;
    indicateur `shadowsVerifiedDomain` (champ backend absent, S6 ou Task 19).
  - **F1 faite** (`d09b9ad6`, opus/xhigh, 4 fichiers ; contrôlé par le fil principal : arbre propre, `make front-lint`
    et `make front-build` rc=0 ; agent : `make front-test` 2786 verts). Types confrontés aux DTO backend par script
    (rouge puis vert, mutation rouge). Écarts : listes fermées `as const` (gardes en F2) ; types locaux, sans réutiliser
    `domain/mcp`. Pour F2 : 409 `McpClientAlreadyRegisteredException` non typé (tester `client-declared-by-document`
    par `hasProblemType`, sinon `client-id-taken`) ; `POST` rend `activeConnections: 0`. **Constat moyen** :
    `cannotBeRevised()` sort en 422 sans type ni `violations` → classé `validation`, message trompeur. Faible :
    `AdminMcpError` sans détail par champ (comme les autres tranches). Questions à l'utilisateur : Q1 `expiresAt`
    (recommandé : non nullable, libellé « échéance prévue » en F5) ; Q2 `shadowsVerifiedDomain` (recommandé :
    micro-tâche backend en S6 avant F2, avec le type du 422).
  - **Décisions de l'utilisateur (2026-11-21, après F1)** : Q1 → **`expiresAt` non nullable**, backend inchangé ; en F5,
    une ligne révoquée l'affiche en « Échéance prévue » à côté de `revokedAt`. Q2 → **micro-tâche backend F1b en S6,
    avant F2** : champ `shadowsVerifiedDomain` dans `BackofficeMcpClientResource` (prédicat
    `AllowedClientDomains::isShadowedBy()`, en mémoire, sans requête), calculé par le Provider et le Processor
    (`POST`/`PUT`), testé ; type de problème sur le 422 de `McpClientIdOfVerifiedDomainException::cannotBeRevised()`
    (par `HasProblemType`, comme `client-declared-by-document`), testé. Puis **F1c** (frontend) : champ
    `shadowsVerifiedDomain: boolean` dans `AdminMcpClient` et raison d'erreur dédiée dans `AdminMcpErrorReason`.
  - **Prochaine action** : agent **F1b** (backend, zone `Mcp` : budget de contexte, ≤ 4 fichiers sinon découper),
    puis F1c, puis F2.
  - **F1b découpée** (> 4 fichiers) : **F1b-1** type de problème du 422 `cannotBeRevised()` (exception + tests) ;
    **F1b-2** champ `shadowsVerifiedDomain` (ressource, Provider, Processor, test fonctionnel). Agents en
    **opus/xhigh** (demande de l'utilisateur). **F1b-1 lancée.**
  - **Prochaine action** : **S6** (frontend F1-F7, F10…, voir plus haut). Ensuite Task 19 (y noter : extraire le
    compteur de requêtes vers `tests/Support`), relecture complète avant la PR (`diff <merge-base>...HEAD`), étape 08
    (PR vers `feature/379-mcp-paliers`).
  - **C2, contenu** :
    - A1 : dans `ConsentService::grant()`, après `findOneByClientIdForShare()` / `saveDiscovered()`, refuser
      (refus typé, rien écrit) si `!$client->allowsRedirect($summary->redirectUri)` ; test sur le modèle de
      `testAGrantAfterTheDeletionIsRefusedAsNoLongerRegistered` (stub du validateur qui révise le client après
      `validate()`). Corriger le docblock de `findOneByClientIdForShare` (l. 137-139) pour dire ce que la relecture sert.
    - A6 : tests du refus hors transaction de `findOneByIdForUpdate` et de `findOneByClientIdForShare`
      (`TransactionRequiredException`) ; sonde `FOR NO KEY UPDATE NOWAIT` qui doit échouer (distingue `FOR SHARE`
      de `FOR KEY SHARE`).
    - A5 : `RevocationOutsideTransactionException::forClient(Uuid $client)`, `toRfc4122()` dans la fabrique.
    - Docblock de `findNotRevokedForAdministration` : « borné par nature » est inexact (A2), le corriger.
  - **Brief de la Task 11 (à reprendre tel quel)** :
    - T1 (moyen) : `remove()` peut s'interbloquer avec l'échange et la supersession → envelopper la transaction dans
      `DeadlockRetryInterface::retryOnce()`, test sur le modèle d'`AccountWideRevocationDeadlockTest`, limite documentée.
      Constat 2 de la relecture : `lockActiveOfClient` (`ORDER BY c.id FOR UPDATE OF c`) contre l'échange de code
      (connexion du code puis autres de la paire) → `40P01` possible ; c'est le même point.
    - T2 : cycles avec la purge (`deleteRevokedBefore`) et `CpgUserAdministrator::delete()` ; couverts par la relance de T1.
    - T3 : toujours `findOneByIdForUpdate()` d'abord (`null` → 404), idéalement une méthode qui prend les deux verrous ;
      transaction courte (`lock_timeout`, `55P03`).
    - T4 : audit après le COMMIT (`FailSafeAudit`) ; décider la cause des connexions échues non marquées.
    - T5 : DTO obligatoire, jamais sérialiser `McpConnection` ni `CpgUser` ; nom d'utilisateur, pas l'e-mail ;
      `ROLE_SUPER`, `ApiRouteExposureTest`, tests de parité.
    - T6 (décision utilisateur) : la révision ne coupe rien ; **revérifier à l'échange** dans `LoopbackAwareAuthCodeGrant`
      (un code dont la redirection n'est plus dans la liste du client → `invalid_grant`) ; auditer chaque révision ;
      réviser sous `findOneByIdForUpdate()`. Les connexions ouvertes restent actives (la suppression les coupe).
    - T7 : `Assert\Count(max: …)` sur l'entrée de la révision.
    - T8 : supprimer un client CIMD n'est pas un bannissement : le dire (ou refuser).
    - T9 : `McpClientDeclaredByDocumentException` en 409 problem+json ; borner `$limit` de `findTerminatedForAdministration()`.
    - T10 : `remove()` en dernier, sans réutiliser les entités périmées.
  - **Brief de la Task 12** : retrier les terminées par octroi (`usort`, comme `OwnMcpConnections::newestGrantFirst`).
  - **Brief de la Task 13** (décision utilisateur, relecture de C2, constat 2) : un accord **non échangé**
    (`code_exchanged_at IS NULL`) passé son délai `connection_unexchanged` (1 h, `ConnectionLifetimes::unexchangedDeadline()`)
    est filtré **en SQL** hors des « non révoquées » de `findNotRevokedForAdministration()` et rejoint la moitié bornée
    des terminées (`TERMINATED_LIMIT`) ; les actives restent servies en entier. Seuil typé `datetimetz_immutable`,
    calculé en PHP (heures écoulées, `add(new DateInterval(…))`), pas de `modify()`. Tests : un accord non échangé de
    59 min reste dans les non révoquées, de 61 min passe dans les terminées ; un accord échangé n'est jamais filtré par
    ce seuil. Corriger aussi D-D du plan (l. 106) et le docblock de l'interface/l'implémentation : la supplantation
    n'a lieu qu'à l'échange et l'expiration d'une heure n'est marquée qu'à la purge.
  - Brief : exiger explicitement `superpowers:verification-before-completion` (fait à la Task 2, suivi).
- Séries de relecture prévues (`/code-review medium` sur le seul diff de la série ; audit sécurité en
  plus pour S2, S3 et S5, qui touchent la révocation, la concurrence ou les routes protégées) :
  S1 = Tasks 1-4 (bornes et hygiène de T8) ; S2 = 5-8 ; S3 = 9-11 ; S4 = 12-15 ; S5 = 16-18 ;
  S6 = F1-F7 ; puis Task 19 et la relecture complète avant la PR.
- Risques relevés au contrôle : `FlushInterleaving` existe (`backend/tests/Shared/`) ; la sonde
  `pg_locks` existe (`tests/Support/ProbesLocks.php`, corrigé le 2026-11-19) ; compteur de requêtes à vérifier
  pour la Task 7A (sans N+1) ; `CompiledExceptionConfig` (Task 15) à vérifier.
- Report « borne du `client_id` 255 / 512 » : **déjà fait** (`McpClient.php:53`, `McpClientId.php:23`,
  migration `Version20261023120000.php:41` en 255) — noté par la Task 19.
- Point fixe de relecture : S1 relue depuis `bd61b215` jusqu'au commit « reprise … Task 4 faite, relecture S1 ».
  C3d relue depuis `7cdad714` jusqu'à `8597490a`. **Point fixe de S4** : le commit « reprise de la T13, C3d relue,
  S4 à lancer » (le `HEAD` avant l'agent de la Task 12).
- PR à l'étape 08 : base **`feature/379-mcp-paliers`**, jamais `develop`.

## Décisions de l'utilisateur (2026-11-19)

- Plan détaillé d'abord (étape 04).
- Reports retenus dans le périmètre, les quatre familles :
  1. **Audits** : ajout et retrait d'un client ; révocation en cascade de ses connexions (cause
     « client retiré »).
  2. **Clients CIMD** : lister aussi les clients déclarés au backoffice, et pouvoir les supprimer
     (ce qui coupe leurs connexions).
  3. **Robustesse T8** : bornes de `register()` et des redirections (longueur, `parse_str`) ; borne du
     `client_id` (255 à la commande, 512 en base) ; second `findOneByClientId()` du consentement et
     course avec une suppression (FK en 500).
  4. **Hygiène T8** : regex de l'index violé dupliquée (`CpgUserUniqueViolation`,
     `McpClientRegistrar`) ; valeurs non échappées dans `$io->success()` de la commande.
- Arbitrages du plan (2026-11-19) : **toutes les recommandations retenues**, plan inchangé — vraie
  suppression en cascade (pas de `removed_at`) ; course du consentement fermée par `FOR SHARE` ;
  cause `client-removed` au journal seulement ; `PUT` = nom + redirections, identifiant immuable,
  409 sur un client CIMD ; bornes 2 048 octets / 50 paramètres par redirection, 1 à 10 redirections ;
  pas de pagination, 200 dernières terminées ; audit de révision = nombre d'URI ; un seul port
  frontend `AdminMcpRepository` ; `AuditOrigin` dans `Security/Authentication/Application`.
- Moteur de l'étape 05 : `agent-skills:build` (pas `subagent-driven-development`).
- Relecture de C2 (2026-11-19) : constat 1 (`deny()` hors verrou) **noter dans la PR** ; constat 2 (accords non
  échangés non bornés) **traiter dans la Task 13**.
- Relecture S2 (2026-11-19) : auditeur sécurité opus/high **retenu** ; `$io->listing()` non échappé : **noter dans
  la PR**, pas de correction ; série C2 = **A1 + A6 + A5** (bornée à 3-4 fichiers) ; révision d'un client :
  **revérifier à l'échange** (Task 11), les connexions ouvertes restent actives.
- Relecture de C3c (2026-11-19) : **micro-série C3d courte**, relue par `/code-review medium` seul ; F1 et le reste
  dans la PR.
- Task 13 (2026-11-20) : accords abandonnés dans les terminées bornées → **deux requêtes bornées, fusion PHP** :
  révoquées `LIMIT 200` par `revokedAt DESC, id DESC`, abandonnées (`revokedAt IS NULL`, `codeExchangedAt IS NULL`,
  `grantedAt <= now − unexchanged`) `LIMIT 200` par `grantedAt DESC, id DESC`, dans le même instantané ; le service
  trie par vraie fin (`revokedAt` ou `unexchangedDeadline(grantedAt)`), garde 200, retrie par octroi.
- Task 12 (2026-11-20) : double calcul de l'échéance → **micro-correction Task 12' avant la Task 13** ; fuseau :
  **garder l'horloge injectée** dans `McpConnectionStatus` (le plan disait « fuseau déjà injecté », c'était `$now`).
- Relecture de C3d (2026-11-20) : **écrire, corriger en Task 19** — R1, R2, R3, R4, R6 en Task 19, R5 dans la PR ;
  S4 démarre aussitôt.
- Relecture S4 (2026-11-20) : série S4c = **moyens + faibles réels** (1, 2, 3, 9) ; constat 1 : **échéance réelle
  pour la cause `Expired`** (fin calculée par le domaine, `revokedAt` pour les autres causes, pré-sélection SQL
  cohérente) ; constat 2 : **migration d'index partiels** (+ empreinte `verify.sql`, #611).
- Constat 1, SQL non exact (2026-11-20) : l'échéance d'une échangée expirée diverge entre PHP et PostgreSQL (heure
  répétée d'automne, `+P1M` en fin de mois) → **option A retenue** : (1) révocations hors `Expired` triées
  `revoked_at DESC, id DESC` LIMIT 200 ; (2) jamais échangées (vivantes ou `Expired` à `code_exchanged_at IS NULL`)
  triées `granted_at DESC, id DESC` LIMIT 200 ; (3) échangées expirées lues par pages keyset `(revoked_at, id)`,
  fin calculée en PHP (`McpConnection::deadline()`), arrêt dès que `revoked_at` ≤ 200e fin connue ; filtres en
  `IS DISTINCT FROM 'expired'`. Rejetées : colonne `ended_at` (migration + remplissage PHP), borne avec marge (inexacte).
- Hors périmètre : retirer un domaine de la liste CIMD (paramètre versionné
  `app.mcp.cimd.allowed_domains`, pas une donnée du backoffice ; limite acceptée de l'ADR 0006 à
  documenter seulement) ; révocation d'une connexion par l'administrateur (ticket).

## Constats écrits non corrigés

Relecture S1 (pour la description de la PR) :
- (faible, conf. moyenne) `McpClient::allowsRedirect()` saute une redirection stockée qui dépasse désormais
  2 048 octets ou 50 paramètres : elle cesse de correspondre sans journal ni migration.
- (faible, conf. haute) `McpClientRegistrar` (Application) importe `Shared\Infrastructure\Doctrine\ViolatedUniqueIndex` ;
  la lecture du message du pilote irait dans `McpClientRepository::add()`.
- (faible, conf. basse) le compte `explode('&')` suppose `arg_separator.input = '&'` (défaut PHP, non modifié
  dans `docker/`).
- (faible, conf. haute) la commande compte après dédoublonnage, `ClientMetadataDocument` avant : 10 distinctes +
  1 doublon acceptées par l'une, refusées par l'autre.
- (faible, conf. haute) la commande analyse toutes les `--redirect-uri` avant que l'entité ne refuse le nombre.
- (C1, faible) `CimdClientAdmission.php:94` ne rattrape pas `InvalidMcpClientRedirectsException` levée par
  `discovered()` : inatteignable (`ClientMetadataDocument::fromJson()` borne déjà), un 500 si elle le devenait.
Relecture de C1 (pour la description de la PR) :
- (faible, conf. haute) `matches()` borne la valeur brute avant d'ignorer le port loopback : une redirection
  loopback enregistrée près de 2 048 octets ne correspond plus une fois le port éphémère ajouté (théorique).
- (faible) cycle d'import `McpClient` ↔ `InvalidMcpClientRedirectsException` (constante lue par l'exception) ;
  passer la borne en paramètre de `tooMany()` ou lire `ClientMetadataDocument::MAX_REDIRECT_URIS`.
- (faible, conf. haute) docblock de `InvalidMcpClientRedirectsException` : « de une à dix » encore en dur.
- (faible) borne de la redirection demandée vérifiée par redirection enregistrée, pas une fois à la frontière
  (`AuthorizationRequestValidator` / `LoopbackAwareAuthCodeGrant`) ; `allowsRedirect()` ré-analyse tout à chaque appel.
- (faible) `McpClientTest::documentWithRedirects()` : `getConstructor()?->invoke()` masquerait un constructeur disparu.
- (faible, conf. basse) `RegisterMcpClientCommandTest` cherche le message entier, que `SymfonyStyle` peut replier
  selon `COLUMNS`.
- (Task 4, question ouverte à l'utilisateur) `$io->listing()` n'échappe pas ; inatteignable (`RedirectUri`
  refuse `<`). Décision : ne pas échapper, le noter dans la PR.
Relecture S2 (pour la description de la PR) :
- (faible) `findOneByClientIdForShare` écrit sa relecture en DQL (`HINT_REFRESH`) au lieu de `RereadUnderLock` :
  pas de `detachVanished()` d'une instance disparue.
- (faible) `McpClientRepository::remove()` laisse des connexions gérées périmées ; son `flush` porte sur toute l'unité
  de travail.
- (faible) pas d'index sur `mcp_connection.revoked_at` pour les terminées de l'administration.
- (faible, A2) `findNotRevokedForAdministration` sans limite : un compte de confiance peut laisser ~14 000 connexions
  jamais échangées par jour et par IP (expirées après une heure, purgées la nuit).
- (faible) `McpClientRepository::all()` sans borne.
- (faible) le fake du dépôt de clients ne relit ni ne détache.
- (info, A3) `forAdministration()` hydrate `CpgUser` entier (empreinte, e-mail) : le DTO de la Task 11/12 n'en expose rien.
- (info, A4) `countActiveByClient` compte une connexion échue non marquée par la purge.
- (info, A7) famine possible de la suppression d'un client derrière des `FOR SHARE` successifs.
Relecture de C2 (pour la description de la PR) :
- (faible à moyen, conf. moyenne) `ConsentService.php:109` : `deny()` renvoie vers une redirection validée hors verrou
  (pas de `FOR SHARE` comme `grant()`) ; décision utilisateur : noter dans la PR.
- (faible) aucun audit ni journal du refus `redirectNoLongerRegistered` (`ConsentService.php:261`).
- (faible) seule la redirection est revérifiée sous le verrou, pas le nom du client affiché au résumé.
- (faible) `McpClientRepositoryTest.php:270` attrape `DriverException` au lieu de `LockWaitTimeoutException`.
- (faible) `McpClientRepositoryTest.php:262` : pas de rollback dans le `finally` de la sonde.
Relecture de C3 (pour la description de la PR) :
- (faible) comptes ajoutées/retirées de l'audit de révision littéraux (port loopback, casse non normalisés).
- (faible) `app:mcp:client:register` répond succès quand l'audit tombe (seul un `critical` en témoigne).
- (faible) `time()` lu avant l'attente du verrou : `redirect-no-longer-registered` au lieu de `code-expired` à la
  seconde près (refus dans les deux cas).
Relecture de C3c (pour la description de la PR) :
- (faible, conf. haute, F1) rejeu non détecté d'une charge à `expire_time` mal typé dont la redirection est retirée
  (refus `not-a-code` sans jugement de rejeu) : inatteignable avec league 9.4.1 (charge chiffrée par nous) ; limite
  acceptée.
- (faible, F6) pas de test d'audit de bout en bout pour la cause `not-a-code` sur ce chemin.
- (faible, F7) « jamais échangée » ne vaut qu'avec une redirection retirée (sinon league juge seule).
- (faible, CR1) pas de normalisation de casse des URI héritées dans la comparaison de révision.
- (faible, CR2) repli plus strict que league (une charge que league aurait lue est refusée `invalid_request`).
- (faible, CR4) un code refusé n'est pas consommé (limite 4 de la règle).
- (faible, CR6/CR7) assertions de tests à resserrer (messages, cause exacte).
Relecture de C3d (2026-11-20 ; **R1, R2, R3, R4, R6 → Task 19**, R5 → PR) :
- (R1, faible, conf. haute) le docblock de `respondToAccessTokenRequest()` (`LoopbackAwareAuthCodeGrant.php`,
  l. 99-105) contredit `mcp-oauth-cycle-de-vie.md:316` sur le moment du second appel de `isAuthCodeRevoked()`.
- (R2, faible, conf. haute) docblock de `GrantRefusal::NotACode` (l. 62) : « mal typés » sans « ou absents » ; même
  défaut dans le nom du test `…WithAMistypedPayload…`.
- (R3, faible, conf. haute) `array_unique($after)` mort (`McpClientAdministrator.php:106`) : l'entité dédoublonne déjà.
- (R4, faible, conf. moyenne) le test des 4 charges vérifie le hint par `assertStringContainsString` et pas la cause
  d'audit `NotACode`, alors que `causeOf()` exige le message exact : `assertSame` ou assertion de cause.
- (R5, faible, conf. basse) `is_int(expire_time)` rejette un float JSON : inatteignable avec league 9.4.1 (PR).
- (R6, très faible, conf. moyenne) commentaire `McpClientAdministrator.php:88` : justification fausse du déplacement
  (`array_unique` ne lève rien).
Relecture de la série A-C (2026-11-20, `6de1ba0e...1ffa3aef` ; (a) et (b) → micro-série D, le reste → PR) :
- (faible, conf. moyenne) couplage DDD : `ConnectionLifetimes` (domaine) refuse mois et années dans `idle` pour
  l'arrêt anticipé de la couche application ; alternative : marge dérivée de l'intervalle dans `endUpperBound()`.
- (faible, conf. moyenne) le message de `InvalidConnectionLifetimeException::notMonotonicInActivity()` décrit
  l'implémentation (« PHP déborde le mois », « l'arrêt anticipé… »).
- (faible, conf. basse) collision théorique des alias de `COLUMN_RENAMING_INCREMENT` (`x1`+1 contre `x`+11), latente.
- (très faible, conf. haute) `|| null === $revokedAt` mort dans `AdministeredMcpConnections::endedOf()`, docblock en désaccord.
- (faible, conf. moyenne) le test de régression C1 (`ConnectionLifetimesTest`, l. 411) ne vérifie que le refus de
  configuration, pas la ligne perdue au niveau d'`AdministeredMcpConnections` (recoupe C2 : pas de test de bord du majorant).
- Toujours en attente, relectures antérieures : C2 (bord du majorant), C5 (`ConnectionNeverRevokedException` morte),
  C6 (SQL natif contre la préférence QueryBuilder, justifié), C9 (`Version20261120120000Test::setUp()` recopie un `down()`).
Relecture S5 (2026-11-20, `2dd76a7c...139169f0`, revue + audit ; F-a/F-b → micro-série F, le reste ci-dessous) :
- **Task 19** (audit F2, faible, conf. haute, choix D-E) : dire dans `mcp-oauth-cycle-de-vie.md` (et l'UI F-tâches)
  qu'un retrait de `redirectUri` par `PUT` n'est **pas** une révocation (jetons émis valides ; code refusé non consommé,
  rééchangeable si la redirection revient sous 10 min) ; couper = `DELETE`.
- **Task 19** (revue 5, faible, conf. haute) : `countActiveOfClient`/`countActiveByClient` lèvent
  `UnexpectedRevocationRowException::forColumn('active')` sur un compte non entier : exception dédiée au décompte.
- PR (revue 1, nit, conf. moyenne) : la réponse du `PUT` relit le décompte après le COMMIT de `revise()`, hors verrou :
  un `DELETE` concurrent peut faire répondre 200 sur un client disparu (limite acceptée, décompte frais voulu).
- PR (revue 6, nit) : `countActiveOfClient` en SQL natif (comme `countActiveByClient`, cf. C6).
- PR (revue 7, faible, conf. basse) : Providers/Processor des clients lisent les dépôts directement (le Provider des
  connexions passe par un service applicatif) — à justifier dans la PR (motif API Platform du backoffice).
- PR (audit I1) pas de `security:` par opération, `access_control ^/api/backoffice` seul (cohérent backoffice) ;
  (I2) `account` = username, `ROLE_SUPER` seul, ne pas le journaliser côté frontend ; (I3) liste non paginée (D-D) ;
  (I4) le validateur parcourt toutes les `redirectUris` avant la borne de 10 (borné par le corps) ; (I6) `\assert`
  retirés en prod (`input:` imposé).


- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais un plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
- Pas de secret en dur dans un test (`TestKeys` / `TestCredentials`) ; une commande qui pose un secret ne se lance
  jamais dans la session.
