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


- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais un plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
- Pas de secret en dur dans un test (`TestKeys` / `TestCredentials`) ; une commande qui pose un secret ne se lance
  jamais dans la session.
