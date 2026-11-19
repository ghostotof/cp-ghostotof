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
  - **Prochaine action** : S3, relecture `/code-review medium diff 8c5d8586...HEAD` + auditeur sécurité opus/high
    (série touchant le point de jeton), puis tri. HEAD relu : `b4846612`.
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

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais un plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
- Pas de secret en dur dans un test (`TestKeys` / `TestCredentials`) ; une commande qui pose un secret ne se lance
  jamais dans la session.
