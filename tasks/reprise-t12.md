# T12 (#651) — Reprise

État tenu par `/cpg-dev` : lu en premier par `/cpg-dev reprise spec-0007-t12`.

## Où en est la tâche

- Branche : `feature/spec-0007-t12-cimd` (locale, non poussée), empilée sur la mère
  `feature/379-mcp-paliers` (`origin`, tête `0c0733fb`).
- Étape : **05**, Task 9 du plan `tasks/plan-t12-cimd.md`, découpée en trois parties après deux
  agents morts sur « Autocompact is thrashing » (mémoire `feedback_agents_budget_regles_mcp.md`) :
  - **9A** faite : `ea6d6554` — `CimdClientAdmission` et son test unitaire ;
  - **9B1** faite : `9cd80e39` — `AuthorizationRequestSummary` (`discoveredClient`, `registration()`,
    `verifiedDomain()`), `AuthorizationRequestValidator` (appel de `admit()`, conversion des refus,
    attribut de requête) et son test, `LoopbackAwareAuthCodeGrant::getClientEntityOrFail()` ;
  - **9B2** faite : `135d4a22` — `CimdAuthorizationTest` (fonctionnel), `Retry-After` du 429 dans
    `AuthorizeController::refusal()`, docblock de `OAuthServerMetadata` (l. 30).
- Task 9 close, relue (`/code-review medium` + audit sécurité, 2026-11-15).
- Série de correction de la relecture Task 9 **faite** : `dccdb715` (sonnet/high), preuve contrôlée
  par le fil principal (PHPUnit `tests/Mcp/Connection` 1365 tests OK, PHPStan et Rector verts) :
  1. `client_id` entouré de blancs refusé dans `AuthorizationRequestValidator::wellFormedParameters()`
     (`invalid_request`, sans redirection, avant l'admission et league) ;
  2. `registration()` / `verifiedDomain()` du résumé tirés de `judgedDiscoveredClient()` (attribut
     retenu seulement si son `client_id` est celui du client jugé par league) ;
  3. docblock de l'ordre de l'admission corrigé, limite acceptée écrite ;
  4. tests de régression : 4 lignes de `refusedBeforeLeague()`, test du résumé, deux tests
     fonctionnels (dont `ConsentService::decide()`, rouge avant : 200 et un `code` délivré —
     défaut de gravité **haute**).
- Relecture de `dccdb715` faite (2026-11-15, `/code-review medium` + audit sécurité opus/high) :
  audit sans constat critique/haut/moyen ; `/code-review` rend **deux moyens** convergents → série de
  correction n° 2 (sonnet/high) :
  1. garde au niveau du grant : au **point d'autorisation seulement**, une ligne `ClientRegistration::Cimd`
     qu'aucun client admis du même `client_id` ne couvre est refusée `invalid_client` (le point de jeton
     continue de servir la ligne en base) — ferme le défaut par toute autre porte que les blancs ;
  2. `registration()` / `verifiedDomain()` du résumé tirés du client **jugé** (`OAuthClient::mcpClient()`),
     plus du canal `discoveredClient` ; la propriété devient privée, accesseur unique pour la Task 10 ;
  3. tests : assertion du refus au consentement sur le `client_id` (pas le seul type générique), blancs
     `\t` `\r` `\0` `\x0B` ajoutés aux fournisseurs, test du résumé dans sa propre classe `TestCase`.
- Série de correction n° 2 **faite** : `3c364bd6` (sonnet/high), preuve contrôlée par le fil principal
  (PHPUnit `tests/Mcp/Connection` 1386 tests OK, PHPStan vert ; l'agent : Rector, Psalm verts). Garde par
  l'attribut `mcp.authorization_point` posé dans `validateAuthorizationRequest()` ; échange du code `cimd`
  au point de jeton couvert par un test neuf (rouge si la garde devient inconditionnelle).
- Relecture de `3c364bd6` faite (2026-11-15, `/code-review medium` + audit opus/high) : aucun défaut
  critique/haut/moyen de la série (les deux moyens du `/code-review` sont la Task 10) → boucle close.
  Micro-série retenue par l'utilisateur (sonnet/high), découpée en deux agents (budget de contexte) :
  - **M1** (côté grant) : audit du refus du garde (nouvel évènement, sans `client_id` brut, sur le
    modèle de `mcp-client-metadata-refused`) ; tests du garde (témoin positif : même ligne, admission qui
    rend le client, acceptée ; variante sans `code_challenge` → `invalid_client` sans redirection) ;
    commentaire du pont PSR-7 corrigé (il copie tous les attributs) ;
  - **M2** (côté résumé) : port DDD — une interface d'`Application` expose le `McpClient` jugé, plus
    d'import d'`Infrastructure` dans le résumé ; `registration()` et domaine dérivés une seule fois ;
    accord des libellés des fournisseurs (« d'une octet nul »).
- **M1 faite** : `e4e62b47` (sonnet/high), évènement `mcp-client-row-not-admitted` (hôte seul, via
  `FailSafeAudit`), témoin positif et variante sans `code_challenge`, commentaire du pont corrigé ;
  preuve contrôlée par le fil principal (PHPUnit `tests/Mcp/Connection tests/Security` 2554 tests OK,
  PHPStan vert).
- **M2 faite** : `6f2efc88` (sonnet/high), port `Application/JudgedClientInterface` (implémenté par
  `OAuthClient`), dérivation unique `judgedMcpClient()`, `judgedDiscoveredClient()` compare l'objet servi
  (`===`), libellés accordés, règle d'audit « 32 today » ; preuve contrôlée (2555 tests OK, PHPStan vert).
- Relecture de M1 + M2 faite (2026-11-15, `diff a9a7671f...HEAD`, `/code-review medium` + audit opus/high) :
  audit sans critique/haut/moyen ; `/code-review` rend **deux moyens** (hôte audité toujours `unreadable` ;
  identité `===` fragile) et des faibles → **série de correction n° 3** (sonnet/high), huit points :
  1. contrat de `mcpClientRowNotAdmitted()` (interface l. 145-153) et règle `security-authentication-audit.md`
     l. 81-86 : seul déclencheur réel, une divergence entre le `client_id` jugé par l'admission et celui lu par
     league (défense en profondeur) ; le retrait de la liste n'est pas audité (D-G) ; acteur `anonymous` au
     point d'autorisation, `userId` à la revalidation du consentement (F1, F2 de l'audit) ;
  2. test de la branche `unreadable` ;
  3. test de bout en bout `validate()` → serveur → grant → résumé, `assertSame($admis, judgedDiscoveredClient())` ;
  4. commentaire dans `OAuthClient::mcpClient()` interdisant toute copie (I4) ;
  5. test PKCE (sans `code_challenge`) qui vérifie l'audit et le nombre d'enregistrements ;
  6. témoin positif qui exerce vraiment la branche gardée ;
  7. `McpClient::cimdHost()` factorisant la dérivation de l'hôte (grant et résumé) ;
  8. `FailSafeAudit::attempt()` blinde `logger->critical()` : le refus reste un 400, jamais un 500 (I1).
- **Série n° 3, agent A fait** (points 7, 2, 8 ; sonnet/high) : `81c756de` (FailSafeAudit blindé),
  `f7d4f28f` (`McpClient::cimdHost()`), `98c0e37a` (test de la branche `unreadable`) ; preuve contrôlée
  (`tests/Mcp/Connection` : 1395 tests OK, arbre propre). Ses commits signent « Sonnet 5.5 » (signalé).
- **Série n° 3, agent B fait** (points 1, 4, 5, 6 ; sonnet/high) : `a87c74a7` (contrat de
  `mcpClientRowNotAdmitted()` ; « 32 today » vérifié exact), `395138ed` (`OAuthClient::mcpClient()` sans copie),
  `5dae419e` (refus PKCE audité une seule fois), `b00263f3` (docblock du témoin corrigé). Point 3 sans commit :
  `testADiscoveredClientIsJudgedByLeagueAndSummarisedAsCimd` fait déjà le `assertSame` de bout en bout (deux
  mutations « clone » rouges, annulées). Point 6 : le garde n'est pas atteignable par une ligne admise au point
  d'autorisation (branche `new OAuthClient($discovered)`) ; le vrai témoin du garde est
  `CimdAuthorizationTest::testACimdClientExchangesItsCodeAtTheTokenEndpointFromItsStoredRow` (mutation rouge).
  Preuve contrôlée : `tests/Mcp/Connection` 1396 tests OK, suite complète 6401 OK, PHPStan/Rector verts,
  commits signés Opus 5.5, arbre propre.
- **Relecture de la série n° 3** (`/code-review medium` + audit sécurité opus/high, au point `121415fe`) :
  aucun critique/haut/moyen, boucle close. Micro-série n° 4 retenue par l'utilisateur (les quatre groupes) :
  - **agent A (code)** : H1 l'audit `mcp-client-metadata-refused` de `CimdClientAdmission` passe par
    `FailSafeAudit::attempt()` (une panne d'audit ne fait plus un 500), avec test ; F5/CR3 `error_log()` de
    dernier recours dans `FailSafeAudit` quand le logger lui-même lève (message constant + classe, sans
    contexte) ; CR4 `FailSafeAuditTest` : mock `expects(once())->method('critical')` sur le logger qui lève ;
  - **agent B (textes + test PKCE)**, après A : F1 deux déclencheurs (divergence du `client_id`, ou ligne
    `cimd` à identifiant non CIMD → `unreadable`) dans l'interface et `security-authentication-audit.md` ;
    F2/CR1 docblocks « domaine retiré » (`LoopbackAwareAuthCodeGrant::getClientEntityOrFail()`, test
    `testAStoredCimdRowTheAdmissionDidNotVouchForIsNeverServed`) ; F3 « impossible » du garde ; F4
    `mcp-oauth-audits.md` (panne du logger avalée + trace de dernier recours, compte des appelants) ; F8
    `McpClient::cimdHost()` au lieu de `CimdClientId::host()` ; F7/CR2 docblock décalé de `McpClientTest` ;
    F6/CR5 test PKCE fusionné dans `testAStaleCimdRowIsRefusedWithoutRedirectOnThePkceRefusalPathToo`
    (`invalid_client`, pas de redirection, audit une fois).
- **Micro-série n° 4, agent A fait** : `d1eb38f6` (H1, `CimdClientAdmission` reçoit `FailSafeAudit`),
  `aa57c73f` (F5, `error_log()` de dernier recours, classe seule), `1bda12aa` (CR4, mock `critical()`) ;
  preuve contrôlée (`tests/Mcp/Connection` 1398 OK, suite complète 6403 OK, PHPStan/Rector verts, signés
  Opus 5.5, arbre propre). À ajouter pour B : docblocks de `CimdClientAdmission` et de
  `SecurityAuditLoggerInterface::mcpClientMetadataRefused()` (l'audit passe par `FailSafeAudit`).
- Agent B (7 fichiers) mort sur « Autocompact is thrashing », rien laissé : découpé en **B1** (textes hors
  zone `Mcp` : F1, F8, F4, docblock `mcpClientMetadataRefused` ; code `Mcp` lu par `sed` seulement) puis
  **B2** (4 fichiers `Mcp` : F2, F3, F7, F6, docblock de `CimdClientAdmission`).
- **B1 fait** : `3d48c7c8` (F1 deux déclencheurs, F8 `cimdHost()`, `FailSafeAudit` dans les docblocks
  d'audit, F4 `mcp-oauth-audits.md` : sept sites réels de `FailSafeAudit::attempt()` et `error_log()` de
  dernier recours) ; contrôlé (signé Opus 5.5, messages constants conformes, arbre propre).
- **B2 fait** : `740108d2` (F2/F3 docblocks du garde, `FailSafeAudit` dans `CimdClientAdmission`), `043970cd`
  (F7), `1978d77a` (F2 test + F6 fusion PKCE, mutation `false && …` rouge annulée) ; ses suites complètes
  rouges venaient de PHPUnit concurrents sur la même base (agent arrêté, conteneur libre) ; relancée seule
  par le fil principal : 6402 OK, PHPStan vert, signés Opus 5.5, arbre propre. **Leçon pour les briefs** :
  ne jamais lancer PHPUnit en arrière-plan ni deux PHPUnit à la fois (base de test partagée).
- Relecture de la micro-série n° 4 faite (2026-11-15, `/code-review medium` + audit sécurité opus/high,
  `diff 121415fe...HEAD`, tête relue `e0544af7`) : **aucun critique/haut/moyen** ; l'utilisateur retient
  les quatre regroupements de faibles en **micro-série n° 5** (sonnet/high, agents successifs) :
  1. **A1, messages et tests de `FailSafeAudit`** : les deux messages CIMD renommés au motif
     « … not audited. » (`Refused CIMD client metadata not audited.`, `Refused stored CIMD client row not
     audited.`, constantes privées) ; `CimdClientAdmissionTest` prouve le `critical` (TestHandler : niveau,
     message, `exceptionClass`) ; le garde aussi ; `FailSafeAuditTest` : `LogicException` côté logger
     (classe distincte de l'audit), `tearDown` robuste à un `setUp` raté, fichier `error_log` posé par le
     seul test qui en a besoin ;
  2. **A2, test d'intégration du déclencheur (b)** : `AuthorizationRequestValidatorTest`, vraie
     admission (pas de `admit()` simulé), ligne `cimd` sous un identifiant valide `McpClientId` hors forme
     CIMD → `invalid_client`, pas de redirection, audit `unreadable` ;
  3. **A3, textes** : `backend-outillage.md` + `monolog.yaml` (`error_log` reçoit aussi le dernier
     recours de `FailSafeAudit` ; `jq -R 'fromjson? // empty'`) ; docblock de `FailSafeAudit`
     (appelants et codes complets, dernier recours borné : même descripteur 2) ; docblock de
     `CimdClientAdmission` (panne de l'audit → `critical`, panne du journal → `error_log`) ;
     `mcp-oauth-audits.md` (message constant de chaque site) ; déclencheur (b) précisé (identifiant
     encore valide selon `McpClientId`, sinon client inconnu sans audit) dans `SecurityAuditLoggerInterface`,
     `LoopbackAwareAuthCodeGrant` et les règles ; commentaire « `with()` avalé » de
     `LoopbackAwareAuthCodeGrantTest` l. 68-69 corrigé (PHPUnit 13.4.6 relance l'échec en fin de test).
- **A1 fait** : `0f0e4a49` (messages `Refused CIMD client metadata not audited.` / `Refused stored CIMD
  client row not audited.`), `6b4464c5` (témoin `critical` + message + `exceptionClass` aux deux sites, rouge
  par mutation « catch muet »), `50930ecf` (`LogicException` côté logger, `captureErrorLog()` à la demande au
  lieu de `setUp`/`tearDown`) ; contrôlé par le fil principal (`tests/Mcp/Connection` 1397 OK ; l'agent :
  suite complète 6402 OK, PHPStan/Rector verts ; signés Opus 5.5, arbre propre). Reste pour A3 :
  `mcp-oauth-audits.md` l. 96 cite encore l'ancien message du garde.
- **A2 fait** : `9c49d208` (`CimdAuthorizationTest::testAStoredCimdRowOutsideTheCimdFormIsRefusedAndAuditedAsUnreadable`,
  deux cas `https://claude.ai` et port 8443, vraie admission, rouge par deux mutations ; docblocks des trois
  tests simulés renvoient au nouveau) ; contrôlé (15 tests CIMD OK ; l'agent : 6404 OK, PHPStan/Rector verts).
  Décisions : ligne hors forme fabriquée par réflexion sur `clientId`, **gardée** (même limite que CR6) ;
  signé « Claude Sonnet 5.5 » (modèle réel), **laissé** (s'ajoute à CR10 ; les briefs demandent désormais
  la signature du modèle réel).
- **A3 fait** : `80392857` (T1 `error_log` + `jq -R 'fromjson? // empty | …'`), `478fcc52` (T2-T6 :
  dernier recours tempéré, sites renvoyés au grep `->attempt(`, messages exacts dans la règle, déclencheur (b)
  borné à un identifiant valide `McpClientId`, commentaire `with()` corrigé) ; contrôlé (texte seul dans
  `src`/`tests`, arbre propre ; l'agent : `tests/Mcp/Connection` 1399 OK, PHPStan, `lint:yaml`,
  `check-claude-rules.sh`, `CommentedClassNamesExistTest` verts ; signés Sonnet 5.5, modèle réel).
  Question rendue : phrase du dernier recours un peu longue dans `mcp-oauth-audits.md` (à voir en relecture).
- **Micro-série n° 5 close**, relue (2026-11-15, `/code-review medium` + audit sécurité opus/high,
  `diff e0544af7...HEAD`, tête relue `9389cac9`) : **un moyen** (code-review ; faible pour l'audit, moyen si
  une ligne d'audit dépasse 1 Kio) — `log_limit` absent de `www.prod.conf` (défaut FPM 1024) : une ligne
  coupée est jetée sans bruit par `jq -R 'fromjson? // empty'`. → **série de correction n° 6** (sonnet/high,
  agents successifs), décisions de l'utilisateur :
  1. **C1, `log_limit` + jq visible** : `log_limit = 8192` au `[global]` de `www.prod.conf` et
     `www.dev.conf`, vérifié en dev par une ligne > 1 Kio ; variante `jq` qui garde visibles les lignes non
     JSON (« unparsed ») dans `monolog.yaml`, `backend-outillage.md`, `McpCallOutcome.php` (docblock sans
     `-R`) ; lignes NOTICE/WARNING du maître FPM ajoutées à la liste des lignes non JSON ;
  2. **C2, textes exacts** : `FailSafeAudit` (401/429/503 et non 400 ; commande `grep` exacte qui ne se
     trouve pas elle-même ; « liste » du commentaire du catch ; descripteur « fermé, ou bloqué : le worker
     attend ») ; `CimdClientAdmission` (classe de la panne **du journal**) ; `LoopbackAwareAuthCodeGrantTest`
     (relance sous `once()`, pas sous `any()`/`never()`/`atMost()`) ;
  3. **C3, cohérence** : `McpConnectionPurger` en constante privée `EXPIRED_NOT_AUDITED` ; appel inutile au
     résolveur (et son commentaire) retiré du test `unreadable` de `CimdAuthorizationTest` ; paragraphe
     recopié dans trois docblocks d'`AuthorizationRequestValidatorTest` réduit à un `@see` court ; réflexion
     sur `clientId` en une aide de fixture commune (avec `LoopbackAwareAuthCodeGrantTest`).
- **C1 fait** (`68dd540f` conf FPM, `00b33727` textes jq ; Sonnet 5.5) : **prémisse du constat moyen
  infondée** — l'image officielle fixe déjà `log_limit = 8192` (`php-fpm.d/docker.conf`, hérité par
  `production`/`preprod`, aucun stage ne le retire) ; `php-fpm -tt` en dev : 8192. Valeur désormais
  explicite (défense en profondeur), défaut mesuré 1024 sans `docker.conf` (ligne de 3031 o → 3 morceaux).
  jq retenu : `jq -cR '. as $l | (try fromjson catch null) as $r | if ($r | type) == "object" then $r |
  select(…) else {unparsed: $l} end'` (vérifié par le fil principal). Lignes du maître FPM et `access.log`
  du pool listées parmi les non-JSON. Pas de rebuild (valeur effective déjà 8192). Écrit, non corrigé :
  `k8s/README.md` ~l. 824/2064/2079, `grep '"channel":…' | jq` sans `-R` (morceau coupé → jq s'arrête,
  improbable à 8192). À reprendre dans la PR : constat moyen fondé sur une valeur que l'image surcharge.
- **C2 fait** (`93899ba0`, Sonnet 5.5) : FailSafeAudit (401 `invalid_client` définitif, 429/503 passager ;
  `grep -rn -- '->attempt(' src | grep -v '^src/Mcp/Connection/Application/FailSafeAudit.php'` depuis
  `backend/`, 7 sites vérifiés par le fil principal ; catch aligné ; fd 2 = tube vers le maître, bloqué
  borné par `request_terminate_timeout = 65s`) ; CimdClientAdmission (classe de la panne du journal) ;
  LoopbackAwareAuthCodeGrantTest (`with()` relu sous `once()` seulement, `Matcher::verify()` l. 245-257).
- **C3 fait** (`b66afa23` constante `EXPIRED_NOT_AUDITED`, `898021ce` `@see` courts, `8b46c42a`
  `ClaudeCodeMetadata::storedRowWhoseClientIdIs()` partagé + `array{non-empty-string}` ; Sonnet 5.5 ;
  mutation du helper : 2 + 1 tests rouges). **Point « résolveur inutile » infondé, non fait** : l'appel
  `$this->resolver()` remplace le service avant l'instanciation de l'admission par la précondition
  `admit()` ; retiré, 2 tests en erreur (`TestContainer.php:86`). Commentaire juste, gardé. Écrit, non
  corrigé : `cimdRow()` (test du grant) et `aDiscoveredRowFromAnEarlierAuthorization()` reconstruisent
  encore la même ligne valide.
- **Série n° 6 close**, relue (2026-11-16, `/code-review medium` seul, au choix de l'utilisateur ;
  `diff 9389cac9...HEAD`, tête relue `cd5aa8d0`) : **un moyen**, vérifié — le `jq` documenté filtre
  `.channel == "security"` alors que `SecurityAuditLogger` écrit sur `security_audit` (défaut d'origine
  `1cbd3f3b`, recopié par la série) : aucun audit rendu. → **série n° 7** (sonnet/high), décisions :
  1. **D1, script `tools/` testé** : `tools/logs-select.sh '<prédicat jq>'` (logique `-R`/`try fromjson`,
     non-JSON montré seulement s'il commence par `{` — début d'un enregistrement coupé ; lignes d'accès et
     NOTICE du maître écartées), test dans `tools/tests/` (`tools-tests`), les trois copies
     (`monolog.yaml`, `backend-outillage.md`, `McpCallOutcome.php`) y renvoient, canal `security_audit` ;
  2. **D2, faibles** : FailSafeAudit exact (au-delà de 65 s le maître tue le worker bloqué : trace perdue,
     502 ; la purge tourne en CLI dans un CronJob : ni tube ni borne, journaux du pod du Job) ;
     commentaires de tests (`with()` relu par `once()`/`exactly()`/`atLeast*()`, pas par
     `any()`/`never()`/`atMost()` ; docblock de `ClaudeCodeMetadata` : la réflexion contourne la
     validation) ; fixture sans doublon (`ClaudeCodeMetadata::storedRow()`, `cimdRow()` bâti dessus).
- Écrit, non corrigé (relecture série n° 6) : `log_limit = 8192` ne fait que remonter le seuil — un
  enregistrement plus long reste coupé (correctif profond : Monolog hors du tube du worker, ou plafond
  par processeur).
- **D1 fait** (`49d3ca8d` script + test + `tools-tests`, `71883e1a` renvois, `23cb7283` règle resserrée ;
  Sonnet 5.5 ; RED 127 puis 9 échecs, GREEN 14 cas, shellcheck et actionlint 1.7.12 propres). Les trois
  copies et les trois commandes de `k8s/README.md` passent par `tools/logs-select.sh`. Choix gardés :
  erreur d'exécution du prédicat = code 5 (ne rien perdre sans bruit). Écrits, non corrigés : un début
  coupé sort une ligne de `null` dans les pipes `@tsv` de `k8s/README.md` ; `audit-prod.test.sh` deux fois
  dans `tools-tests` (préexistant, l. 431 et 451) ; `backend-outillage.md` à 49 982 octets (18 de marge :
  le prochain ajout le découpe).
- **D2 fait** (`ef87fb76` FailSafeAudit : worker tué à 65 s, trace perdue, 502 ; contexte CLI du Job de
  purge ; `with()` relu sauf `any()`/`never()`/`atMost()` ; docblock `ClaudeCodeMetadata` ; `ffbe2776`
  `ClaudeCodeMetadata::storedRow()`, `cimdRow()` supprimée, `aDiscoveredRowFromAnEarlierAuthorization()`
  bâtie dessus ; Sonnet 5.5 ; `tests/Mcp/Connection` 1399 tests verts avant et après ; mutation du
  `client_id` : 7 erreurs). Écrits, non corrigés : trois autres copies de la ligne `cimd`
  (`AuthorizationRequestSummaryTest::declaredClient()`, `AuthorizationRequestValidatorTest::discoveredClient()`,
  `CimdClientAdmissionTest` en ligne) ; `monolog.yaml` l. 98 et `backend-outillage.md` l. 108 ne citent
  que `deploy/backend -c php-fpm`, alors que la purge écrit dans les journaux du pod du Job.
- **Série n° 7 close.** Relecture `diff cd5aa8d0...HEAD` faite au point `2e5f8d12` (`/code-review medium`,
  sans audit sécurité) : 10 constats, 3 moyens, tous sur `tools/logs-select.sh`.
  - Moyens :
    - toute ligne qui ne commence pas par `{` est écartée, y compris la trace `[date] Audit failure…`, les
      fatales de PHP et le WARNING du maître ;
    - `--timestamps`/`--prefix` ne donne rien, en code 0 ;
    - les projections `jq` de `k8s/README.md` (l. 823, 2063, 2079) effacent `unparsed`.
  - Faibles : le prédicat lève sur un contexte en liste (code 5) ; `#` ou une virgule dans le prédicat ;
    FailSafeAudit dit 502 alors que c'est 504 à 60 s ; Job de purge borné à 300 s (`activeDeadlineSeconds`) ;
    README sans « depuis la racine du dépôt » ; coupure avant un objet imbriqué (morceau qui commence par `{`) ;
    `backend-outillage.md` à 18 octets du plafond.
- **Série n° 8 (décisions de l'utilisateur, 2026-11-16)** :
  - logs-select : « liste de bruit ». Seuls la ligne d'accès du pool et les NOTICE du maître sont
    écartés, tout le reste sort en `unparsed`. Le préfixe `--timestamps`/`--prefix` est retiré. Le README
    ne perd plus les `unparsed`.
  - faibles joints : prédicat robuste, textes exacts, découpage de la règle (elle portera aussi
    « journaux du pod du Job »).
  - E1 : script, test et README. E2 : textes de FailSafeAudit, découpage de `backend-outillage.md`,
    mention du Job dans `monolog.yaml`.
  - Point fixe de la prochaine relecture : `2e5f8d12`.
  - **E1 fait** (2026-11-16) : `e63e365c` pour le script et son test (liste de bruit : accès du pool, NOTICE
    du maître, ligne vide ; préfixes kubectl retirés dans les deux ordres ; prédicat `((\n…\n))` + `any` ;
    erreur de prédicat sur stderr, flux conservé, code 4 ; `unparsed` garde la ligne complète, préfixe
    compris ; pas de `--raw`), `a71fcdc2` pour le README (projections `if has("unparsed")`, « depuis la
    racine du dépôt »). Vérifié : test vert, shellcheck propre, `check-claude-rules.sh` vert.
  - Textes rendus par E1 pour E2 : `backend-outillage.md` l.~109-111 (« any line starting with `{` »,
    obsolète) ; `monolog.yaml` l.104-107 (même affirmation) ; `McpCallOutcome.php` l.~10-12 (exemple sans
    `.channel == "mcp_usage"`, parenthèse sur l'enregistrement coupé). Hors du 1, on documente aussi les
    codes 3 et 4.
  - Question ouverte d'E1, écrite et non corrigée : un morceau de ligne coupée qui serait lui-même un objet
    JSON complet passerait pour un enregistrement (cas théorique).
  - **E2 fait** (2026-11-16) : `b4459ef8` (FailSafeAudit : 504 à 60 s, worker tué à 65 s, Job borné par
    `activeDeadlineSeconds: 300`), `7812584c` (`monolog.yaml` avec le pod du Job, `McpCallOutcome.php`
    gardé par `mcp_usage`), `82925a84` (découpage : `backend-tests.md` reçoit #420, #403, #629 et #743 mot
    pour mot, `paths:` `backend/tests/**` + `backend/phpunit.dist.xml` ; `backend-outillage.md` à
    23 496 octets avec un index ; table de `.claude/CLAUDE.md` et 9 renvois corrigés). Vérifié :
    `check-claude-rules.sh` vert, sections identiques mot pour mot, 5 fichiers PHPUnit touchés verts.
  - Écrit, non corrigé : les 60 s de nginx et de l'ingress sont des valeurs par défaut, pas des directives
    (seul `www.prod.conf:35` les cite). À fixer explicitement ? Question pour la description de la PR.
- **Série n° 8 relue** (`/code-review medium diff 2e5f8d12...HEAD`, tête relue `f7c585a4`, sans audit) :
  - **moyen**, reproduit : le message d'erreur du prédicat (`logs-select.sh` l.~100, `\(.)`) cite des
    valeurs de l'enregistrement (`string ("secretvalu...)`, `while parsing '…'`) alors que l'en-tête
    l.~64 promet « jamais le contenu ». Le test l.~164 ne couvre que `Cannot index`.
  - faibles :
    - performance ~20× (`sub`/`test` sur chaque ligne, même JSON ; mesuré : 1,75 s contre 0,085 s pour
      100 000 lignes) ;
    - commande du Job dans `monolog.yaml` l.~111 et dans la règle l.~130 : le stdout humain des trois
      commandes sort en `unparsed`, et `job/<nom>` ne lit qu'un seul pod, pas ceux des reprises
      (`backoffLimit: 2`) ;
    - lignes d'accès 5xx écartées comme bruit ;
    - NOTICE d'arrêt ou de rechargement du maître écartés ;
    - code 2 de jq (entrée-sortie) confondu avec le code 2 d'usage ;
    - `backend-tests.md` ne se charge plus sur `.github/workflows/**` ni `docker/php/**`, alors que #420
      décrit `ini-file` et `php.prod.ini` ;
    - FailSafeAudit : les 300 s couvrent tout le Job (trois commandes et les redémarrages), pas la seule
      purge.
- **Série n° 9 (décisions de l'utilisateur, 2026-11-16)** :
  - le moyen est traité par un message générique : stderr ne porte que le numéro de ligne et un libellé
    fixe, aucun texte de jq. Tests avec `+ 1` et `tonumber`.
  - les faibles retenus sont tous les sept :
    - « bruit plus étroit » : on n'écarte que les lignes d'accès 2xx/3xx et les NOTICE de routine (pas
      `Terminating`, `exiting`, `Reloading` ni `Finishing`), et le code 2 de jq devient un code distinct ;
    - « paths et textes exacts » : `backend-tests.md` reçoit en plus `.github/workflows/**` et
      `docker/php/**`, FailSafeAudit dit que les 300 s couvrent tout le Job, et la commande du Job
      prévient du stdout humain et lit toutes les tentatives ;
    - « performance » : les lignes qui commencent par `{` passent directement par `fromjson`, `sub` et
      `test` ne s'appliquent qu'aux autres.
  - F1 traite `tools/logs-select.sh`, son test et l'en-tête. F2 traite `backend-tests.md`, FailSafeAudit,
    `monolog.yaml`, la règle et, si besoin, le README.
  - **F1 fait** (2026-11-16), commit `849d43a4` :
    - stderr ne porte plus qu'un libellé fixe et le numéro de ligne, le bruit écarté compris dans la
      numérotation ;
    - seuls les accès 2xx/3xx sont écartés, ainsi que les NOTICE de routine (`fpm is running`,
      `ready to handle connections`, `child N started`) ;
    - codes de sortie 0/2/3/4/5 (5 couvre toute autre sortie de jq) ;
    - chemin rapide pour les lignes qui commencent par `{`. Banc sur 100 000 lignes : 2,02 s avant,
      1,25 s après, contre 0,22 s pour `fromjson?` nu ; la regex reste le coût.
    - Vérifié : test vert, shellcheck propre, aucune valeur sur stderr (`+ 1`, `tonumber`, `test("[")`).
  - Écrit, non corrigé :
    - `child N started` n'est pas vérifié dans les sources de php-fpm ;
    - `exiting, bye-bye!` et `Finishing ...` sortent en `unparsed` à chaque arrêt de pod, comme décidé ;
    - un prédicat qui fait `halt_error(3|4)` se confond avec les codes du script (prédicat de confiance).
  - Textes rendus pour F2 :
    - `backend-outillage.md` l.113-127 : accès 2xx/3xx, NOTICE de routine, libellé fixe, code 5 ;
    - `monolog.yaml` l.103-106.
  - **F2 fait** (2026-11-16) :
    - `30114546` : `backend-tests.md` se charge aussi sur `.github/workflows/**`, `docker/php/**` et
      `backend/symfony.lock` (#743) ;
    - `8e840654` : textes de logs-select dans la règle et dans `monolog.yaml`. La commande du Job devient
      `kubectl logs -l job-name=<nom> -c purge --prefix`, avec `--previous` pour un redémarrage
      `OnFailure` ; elle signale aussi le stdout humain ;
    - `8ea08362` : FailSafeAudit, les 300 s couvrent le Job entier.
    - Vérifié : `check-claude-rules.sh`, `lint:yaml`, PHPStan et le test logs-select sont verts.
  - Écrit, non corrigé :
    - « `--previous` ne lit que le dernier redémarrage » n'est pas recoupé par une page de doc ;
    - le label court `job-name` n'est pas testé sur un cluster (Kubernetes pose aussi
      `batch.kubernetes.io/job-name`).
- **Série n° 9 relue** (`diff f7c585a4...HEAD`, tête relue `4765a221`, sans audit) :
  - moyens :
    - commande du Job `kubectl logs -l …` : avec un sélecteur, `--tail` vaut 10 par défaut (vérifié dans
      `kubectl logs --help`), donc la trace est tronquée en silence (règle l.~133, `monolog.yaml` l.~114).
      Correctif : `--tail=-1`.
    - les accès en 4xx sortent en `unparsed` sans passer par le prédicat : chaque 401/404 (`/api/me`
      anonyme, scanners, client MCP sans jeton) noie les événements recherchés.
  - faibles :
    - SIGPIPE (141) ou Ctrl-C (130) produisent « jq a échoué » et le code 5 ;
    - un JSON précédé d'un blanc n'est plus décodé ;
    - une erreur jq hors du `try` (code 5) peut encore citer une valeur ;
    - `--previous` avec `-l` échoue en bloc ;
    - lignes trop longues (FailSafeAudit l.52, en-tête du script l.30) ;
    - le glob `.github/workflows/**` est trop large (`pipeline.yml` suffit) ;
    - le test de l'accès 401 IPv6 avec utilisateur a été perdu.
- **Série n° 10 (décisions de l'utilisateur, 2026-11-16)** :
  - accès 4xx : les 2xx/3xx/4xx sont écartés comme bruit, seuls les 5xx restent visibles (les refus ont leur
    propre audit JSON) ;
  - `--tail=-1` ajouté à la commande du Job (dans la règle et dans `monolog.yaml`) ;
  - robustesse du script : 141 et 130 rendus tels quels, JSON précédé d'un blanc de nouveau décodé, erreur
    jq hors `try` capturée et remplacée par un libellé, test 4xx IPv6 avec utilisateur restauré ;
  - doc et glob : `--previous` sur `pod/<pod>`, glob `.github/workflows/pipeline.yml`, lignes recoupées.
  - un seul agent G (script et test, puis textes), deux commits au moins.
  - **G fait** (2026-11-16) :
    - `4fbd2e2a` :
      - accès 4xx écartés (`[234][0-9]{2}$`), seuls les 5xx sortent ;
      - un code ≥ 128 est rendu tel quel ;
      - `ltrim` des blancs de tête ;
      - le stderr de jq va dans un fichier privé et n'est jamais recopié, sauf `jq: N compile error(s)`
        (code 3) ;
      - les messages par ligne du code 4 sortent en fin de flux ;
      - banc : environ 1,3 s.
    - `5c701fae` : `--tail=-1`, `--previous` sur `pod/<pod>`, glob réduit à `pipeline.yml`, textes sur le
      bruit et les codes.
    - `de3fc177` : FailSafeAudit, coupures seules.
    - Vérifié : test vert, shellcheck, règles conformes ; cas blanc, 401 et 502 rejoués ; aucune fuite.
  - Écrit, non corrigé :
    - `backend-tests.md` a été édité sans `Read` préalable (seul le glob du frontmatter a changé) ;
    - aucune erreur réelle hors du `try` n'a pu être provoquée (elle est simulée par `halt_error`).
- **Série n° 10 relue** (`diff 4765a221...HEAD`, tête relue `b2c0798e`, sans audit) :
  - moyens :
    - les messages par ligne du code 4 ne sortent qu'en fin de flux : avec `kubectl logs -f` interrompu par
      Ctrl-C (130), ils ne sortent jamais, et rien ne signale que des lignes ont été écartées (script l.~183) ;
    - avec un code 141 (`| head`) ou 5 après des erreurs de prédicat, le fichier des erreurs est supprimé
      sans être lu, et le code 4 est masqué (l.~200) ;
    - 4xx écartés : la justification « un refus 401/403 a son propre audit JSON » est fausse. Vérifié par le
      fil principal : `SecurityAuditLoggerInterface` n'audite que `backofficeAccessDenied`, `csrfRejected`
      et les refus MCP. Un jeton de base qui tente `GET /api/cv` (403) ne laisse plus de trace ; les
      400/405/409/413/422 non plus (l.~129, règle, `monolog.yaml`).
  - faibles :
    - la raison donnée pour garder les 5xx est inexacte : les 502/504 viennent de nginx, un worker tué est
      un WARNING de FPM, pas une ligne d'accès (l.~53) ;
    - une ligne faite seulement de blancs devient `""` après `ltrim` et est écartée comme ligne vide, ce que
      l'en-tête ne dit pas (l.~145) ;
    - le fichier temporaire des erreurs grossit sans limite pendant un `-f` (l.~179) ;
    - le test SIGPIPE suppose l'action par défaut de SIGPIPE (sinon jq sort en 2, le script en 5) ;
    - un échec de `mktemp` sort en 1, code non documenté, et le `umask 077` est redondant ;
    - `monolog.yaml` l.~106 : ligne de 123 caractères ;
    - en-tête l.~110 : « 128 et plus  un signal tué jq » (verbe manquant, double espace).
- **Série n° 11 (décisions de l'utilisateur, 2026-11-16)** : « Série 11 complète » ; pour les 4xx,
  « 403 visible, autres 4xx écartés » (2xx, 3xx et 4xx sauf 403 écartés ; le 403 sort en `unparsed`, en
  double s'il est déjà audité ; le texte dit que les 401, 404 et autres 4xx sont perdus).
  - exigences : les erreurs de prédicat sont émises au fil de l'eau, sans jamais recopier de valeur ;
    elles restent visibles après Ctrl-C ou `| head` ; le code 4 n'est pas masqué par un 5 ; plus de
    fichier qui grossit sans limite ; les faibles de la série 10 sont traités, sauf décision contraire
    argumentée de l'agent ;
  - un seul agent H (sonnet/high).
  - **H fait** (2026-11-16) :
    - `17cadc90` fix(tools) :
      - les libellés fixes sont émis par jq avec `stderr` ;
      - le stderr de jq passe par un filtre awk à liste blanche sur le descripteur 3, avec compteur et
        `fflush` ;
      - plus de fichier temporaire ;
      - 403 et 5xx sortent en `unparsed` ;
      - une ligne de blancs seuls sort en `unparsed` ;
      - le code 4 l'emporte sur un 5, jamais sur un code ≥ 128 ;
      - les tests SIGPIPE sont sautés si SIGPIPE est ignoré ;
      - banc : de 1,28 s à 0,89 s.
    - `feab7275` docs(rules) : 403 conservé, 4xx perdus dits, raison des 5xx corrigée, coupures de
      `monolog.yaml`.
    - Vérifié par le fil principal :
      - test vert, shellcheck et règles conformes ;
      - rejeu avec une valeur sentinelle : rc 4, libellé fixe, aucune fuite ;
      - 403 sort, 401 écarté.
  - Écrit, non corrigé (questions de l'agent, jugées acceptables par le fil principal) :
    - sur un vrai Ctrl-C au terminal, le SIGINT tue aussi le filtre awk : les libellés déjà émis restent,
      mais le total final est perdu (documenté dans l'en-tête) ;
    - un `halt_error(4)` du prédicat sort désormais en 5 (seul le filtre décide du 4) ;
    - un prédicat de confiance qui imite `jq: N compile error(s)` ferait relayer le tampon (200 lignes au
      plus), non testé.
- **Série n° 11 relue** (`diff 6ba5f243...HEAD`, tête relue `a513768b`, sans audit) :
  - moyens :
    - le filtre awk décide seul de relayer son tampon dès que la dernière ligne a la forme
      `jq: N compile error`, sans la condition « jq est sorti en 3 » de l'ancien script. Reproduit : un
      prédicat qui fait écho d'une valeur par `stderr` relaie cette valeur, avec un code 0 (l.~199) ;
    - « 4 l'emporte sur 5 » : un flux que jq a abandonné (erreur d'E/S, `halt_error`) rend 4, alors que le
      code 4 promet que le reste du flux est rendu, ce qui cache la troncature (l.~243).
  - faibles :
    - un `halt_error(3)` après des erreurs rend 3 (« prédicat invalide ») ;
    - un code du filtre awk hors de {0, 3, 4} (signal, échec) n'est pas traité : les erreurs sont perdues
      du code, qui peut valoir 0 ;
    - `$!` après `exec 3> >(…)` exige bash ≥ 4.4 (bash 3.2 de macOS sort en 1) ;
    - sur une interruption, l'awk orphelin peut écrire son total après le retour du prompt ;
    - le test « au fil de l'eau » repose sur des délais fixes (fragile sur un runner chargé) ;
    - le test SIGINT n'envoie pas le signal au groupe (`setsid` + `kill -INT -- -PGID`) ;
    - le `\n` de tête du libellé envoie une ligne vide à awk par erreur (tampon et compteur gonflés) ;
    - redondances dans `$kept` (une regex de trop sur les lignes sans préfixe).
- **Série n° 12 (décision de l'utilisateur, 2026-11-16)** : « Série 12 étroite ». Seul le bloc des codes
  est corrigé :
  - le relais du stderr de jq n'a lieu qu'en compilation (code 3), décidé hors d'awk ;
  - un flux tronqué garde le code 5 ;
  - `halt_error(3)` donne 5 ;
  - un code d'awk hors de {0, 3, 4} donne un libellé et un code non nul.
  Les autres faibles de la série 11 (bash 4.4, tests à délais fixes, SIGINT de groupe, awk orphelin,
  `\n` de tête, redondances de `$kept`) vont dans la PR. Un seul agent I (sonnet/high).
  - **I fait** (2026-11-16) :
    - `c98b0178` fix(tools) :
      - jq tourne d'abord sans donnée (`jq -cn … </dev/null`) ; s'il sort en 3, son stderr est relayé, le
        libellé suit et le script sort en 3 ; tout autre code donne un libellé fixe et 5 (ou ≥ 128) ;
      - la passe principale ne relaie plus aucune ligne brute : le tampon de 200 lignes a disparu ;
      - le code 4 n'est rendu que si jq est sorti en 0, un flux tronqué donne 5 ;
      - `halt_error(3)` donne 5 ;
      - un échec du filtre awk donne un libellé fixe et 5 (ou ≥ 128), testé avec un faux `awk` dans le
        `PATH` ;
      - banc : 0,88 à 0,90 s.
    - `89a81e81` docs(rules) : table des codes révisée.
    - Vérifié par le fil principal : test vert, shellcheck et règles conformes ; fuite rejouée (rc 0, aucune
      valeur sur stderr) ; `halt_error(3)` après erreur (rc 5) ; prédicat invalide (rc 3, relais).
  - Écrit, non corrigé : un échec de l'exécution à vide autre que 3 ne relaie pas le stderr de jq (choix
    prudent de l'agent, gardé).
- **Série n° 12 relue** (`diff b99f6be7...HEAD`, tête relue `6cb33233`, sans audit ; sévérités estimées
  par le fil principal, la relecture n'en donnait pas) :
  - moyen :
    - le `END` d'awk écrit « N ligne(s) en erreur de prédicat (code 4) » même quand le script sort en 5 ou
      ≥ 128. Reproduit par le relecteur. Le test « flux abandonné : code 5 » ne vérifie pas l'absence de
      cette ligne (script l.~213, test l.~393).
  - faibles :
    - le libellé d'échec du filtre sort même quand N ≥ 128 (contrat « sans message »), et le test avec un
      faux awk à 143 le grave (l.~233) ;
    - le pré-contrôle écrit « jq a échoué (code N) » pour N ≥ 128 (l.~201) ;
    - `halt` ou `halt_error(0)` du prédicat arrête jq en 0 : le script rend 0 ou 4 pour un flux tronqué
      (l.~252) ;
    - `halt_error(130|141)` du prédicat est pris pour un signal, et le test « code 130 » le grave (l.~240) ;
    - quand le filtre et jq échouent tous les deux, le libellé de jq est perdu (l.~230) ;
    - le pré-contrôle tourne en `-cn` et non en `-cnR` (l.~196) ;
    - la règle ne dit pas que « erreur de prédicat + échec de jq » est passé de 4 à 5, ni ce que devient la
      ligne de total.
- **Micro-correctif, puis arrêt (décision de l'utilisateur, 2026-11-16)** :
  - `9866529c` (fil principal) : le total ne dit plus « (code 4) » ; le test du flux abandonné vérifie
    qu'aucun « (code 4) » n'est annoncé (rouge vérifié avant) ; la règle dit que le total ne promet aucun
    code. Moins de 20 lignes, la relecture est sautée (proportionnalité). Test vert, shellcheck et règles
    conformes.
  - **La boucle logs-select est close.** Les faibles de la série 12 vont dans la PR.
- **Tâche 10 faite** (agent J, sonnet/high) : `255550e4` — `ConsentController` rend `registration` et
  `domain` ; `grant()` persiste par `saveDiscovered($summary->judgedDiscoveredClient())` dans la
  transaction, avant `connections->add()` (sinon `findOneByClientId`), audit après le COMMIT.
  `CimdConsentFlowTest` (7 tests, dont 2 gardes vertes sans l'implémentation : aucune ligne avant
  l'approbation, redirection perdue), +2 dans `ConsentServiceTest`, `ConsentControllerTest` → `domain: null`.
  Preuve contrôlée par le fil principal : `make back-test` sur `255550e4` OK (6413 tests, 121879
  assertions) ; `make back-quality` vert selon l'agent (6 avertissements lsp préexistants sur `ai.yaml`).
  **Écart** : le 429/503 au consentement n'a pas été fait (l'agent l'a rendu en question).
- **Série 429/503 au consentement (décision de l'utilisateur, 2026-11-16, « Série dédiée »)** :
  `ConsentService::validated()` emballe toute `OAuthServerException` de revalidation en 400 ; un refus
  passager (`temporarily_unavailable`, 429 quota avec `Retry-After`, 503 hôte) doit répondre comme au point
  d'autorisation (Task 9 : `AuthorizeController`, `RetryAfterListener`), à l'affichage comme à
  l'approbation. La relecture couvre tâche 10 et cette série ensemble.
- **Série 429/503 faite** (agent K, sonnet/high) : `468238c1` — `ConsentService::validated()` lève
  `ConsentTemporarilyUnavailableException` (abstraite, problème+json `/errors/consent-temporarily-unavailable`,
  detail constant) via deux finales, `ConsentRevalidationThrottledException` (429, log `info`) et
  `ConsentRevalidationUnavailableException` (503, log `warning`) : le statut ne dépend que de la classe, les
  garde-fous instancient sans constructeur. `RetryAfterListener` (**partagé**, `Shared/Infrastructure/Http`)
  parcourt désormais la chaîne des `previous` — à vérifier en relecture : aucune autre exception (400, 500)
  qui enveloppe un quota ne doit recevoir `Retry-After`. +6 tests dans `CimdConsentFlowTest`.
  Preuve contrôlée : `make back-test` sur `468238c1` OK (6419 tests, 122339 assertions) ; back-quality vert
  selon l'agent. Non fait : `.claude/rules/mcp-oauth.md` dit encore « the consent's refusal of a revalidated
  request is its 400 » → Task 12.
- **Relecture faite** (2026-11-18) sur `diff 6cb33233...84159847` : `/code-review medium` (2 moyens, 8 faibles)
  + audit sécurité opus/high (0 critique/haut/moyen, 9 faibles, 3 infos). **Point fixe suivant : `84159847`.**
  Moyens : M1 `RetryAfterListener` remonte la chaîne des `previous` sans test, boucle en double avec
  `AuthorizeController::refusal()` (l.119), `Retry-After` existant écrasé, `backoffice-api.md:334` périmée ;
  M2 le front refuse `registration: 'cimd'` (`HttpOAuthConsentRepository.ts:184`) → **Task 11**.
- **Série de correction décidée** (« M1 + faibles réels ») : agent L, sonnet/high — M1 (3 tests de l'écouteur :
  enveloppe 429, enveloppe non-429 sans en-tête, première cause gagnante ; méthode partagée qui remplace les
  deux boucles ; un `Retry-After` déjà posé jamais écrasé ; règle `backoffice-api.md` à jour), F5 (409 « autre
  compte » jugé avant `validated()`), F4 (consentement : `registration === Cimd` et `judgedDiscoveredClient()
  === null` → 400 générique), CR7 (Yoda `null !== $discovered`). Puis relecture `/code-review medium
  diff 84159847...HEAD`.
- **Série de correction faite** (agent L) : `de52d01c` — M1 `RetryAfterListener::firstRetryAfterAware()` seule
  boucle (écouteur + `AuthorizeController::refusal()`), en-tête jamais écrasé, règle `backoffice-api.md` à jour ;
  F5 409 jugé avant `validated()` (règle `mcp-oauth-revocation-personne.md` à jour) ; F4 garde
  `AuthorizationRequestRejectedException::cimdRowWithoutDocument()` (400 générique) — **défense en profondeur
  seulement** : le test HTTP est vert d'emblée, la revalidation passe par `validateAuthorizationRequest()` qui
  pose déjà `AUTHORIZATION_POINT_ATTRIBUTE` (le F4 de l'audit était donc infondé au niveau HTTP), audit
  `mcpClientRowNotAdmitted` non doublé ; CR7 fait. Preuve contrôlée : `make back-test` sur `de52d01c` OK
  (6432 tests, 122460 assertions) ; `check-claude-rules.sh` conforme ; back-quality vert selon l'agent.
- **Relecture de la série de correction faite** (2026-11-18, `/code-review medium diff 84159847...0ec72dfe`) :
  2 moyens (CR1 commentaire de la garde F4 faux, CR2 test HTTP F4 vert sans la garde), 8 faibles.
  **Point fixe suivant : `0ec72dfe`.**
- **Série de correction n°2 décidée** (agent M, sonnet/high) : **retirer la garde F4** (`cimdRowWithoutDocument()`
  et son test unitaire ; la règle vit dans la seule garde de league, qui audite) ; le test HTTP
  `testAStoredCimdRowOutsideTheCimdFormIsRefusedAtTheConsentToo` renommé en épingle de la garde de league au
  consentement + assertion de l'audit `mcp-client-row-not-admitted` au GET et au POST (CR3) ; CR6 revenir à
  l'écrasement (#743 : l'échéance arrondie au-dessus gagne toujours) ; CR7 recherche de la première échéance
  déplacée dans `Shared/Domain/Exception` ; CR8 docblock de `chain()` ; CR10 phrase à deux parenthèses de
  `backoffice-api.md` ; CR9 assertion « aucune récupération » du cas quota épuisé (retirée ou commentaire
  corrigé) ; CR5 test HTTP du cas combiné (demande invalide montrée à un autre compte → 409). Puis relecture
  `/code-review medium diff 0ec72dfe...HEAD`, puis Task 11.
- **Série de correction n°2 faite** (agent M) : `a6dd3236` — garde F4 retirée (fabrique et test unitaire
  compris), test HTTP renommé `testLeaguesGuardRefusesAStoredCimdRowOutsideTheFormAtTheConsentToo` + audit
  `mcp-client-row-not-admitted` au GET et au POST ; CR6 écrasement rétabli (#743) ; CR7
  `Shared/Domain/Exception/RetryAfterChain::firstIn()` (+ `RetryAfterChainTest`) ; CR8, CR9, CR10 ; CR5
  `ConsentControllerTest::testAnInvalidRequestShownToAnotherAccountIsConflictNotBadRequest`. Preuve contrôlée :
  `make back-test` sur `a6dd3236` OK (6433 tests, 122578 assertions), `check-claude-rules.sh` conforme,
  back-quality vert selon l'agent.
- **Relecture de la série n°2 faite** (2026-11-18, `/code-review medium diff 0ec72dfe...9db0b692`) :
  **point fixe suivant `9db0b692`**. Moyens : CR1 l'écouteur écrase sans comparer (un Retry-After plus long
  serait raccourci) ; CR3 garde F4 retirée → **décision de l'utilisateur, non retenue** (league couvre, test HTTP
  + audit). Faibles écrits, à reprendre dans la PR : CR4 doublure `judgedRequest()` sans client (propriété
  league non initialisée si un test lit `registration()`) ; CR5 plus de test unitaire « ligne cimd » au niveau
  service ; CR6 « aucune récupération » plus observée qu'avec l'hôte qui cale (variante quota disponible
  possible) ; CR8 le contrôleur combine encore `RetryAfterChain` + `REQUEST_ATTRIBUTE` à la main ; CR9 le 400
  d'une ligne cimd au consentement ne se distingue que par l'audit (FailSafe).
- **Micro-série « maximum » décidée** (agent N, sonnet/high) : sur un 429, l'en-tête = max(en-tête déjà posé
  s'il est numérique, échéance **la plus tardive** de la chaîne) — CR1 + CR2 (`firstIn()` → la plus tardive) ;
  CR7 commentaire « proxy » faux. Puis relecture `/code-review medium diff 9db0b692...HEAD` ; **la boucle se
  clôt ensuite sur ce point** (le reste écrit), puis Task 11.
- **Micro-série « maximum » faite** (agent N) : `ed5a82f1` — `RetryAfterChain::latestIn()` (échéance la plus
  tardive, le premier trouvé à égalité), en-tête = max(calculé arrondi au-dessus, en-tête déjà posé s'il passe
  `ctype_digit`), sinon remplacé (pas de parsing de date HTTP) ; commentaire « proxy » corrigé ; règle
  `backoffice-api.md` à jour. Rouges : 3 (dont `'99'` vs `'10'`, `'120'` vs `'42'`). Preuve contrôlée :
  `make back-test` sur `ed5a82f1` OK (6441 tests, 122586 assertions), `check-claude-rules.sh` conforme.
- **Relecture de la micro-série faite** (2026-11-18, `/code-review medium diff 9db0b692...e6448d17`) : aucun
  critique/haut/moyen → **boucle close** (tâche 10 + 429/503 + corrections). **Point fixe suivant : `e6448d17`.**
  Faibles écrits, à reprendre dans la PR : RA1 `latestIn()` laisse une cause profonde d'un autre limiteur
  allonger l'annonce (trop tard plutôt que trop tôt ; aucune chaîne de prod n'a deux quotas) ; RA2 `ctype_digit`
  + `(int)` sature à `PHP_INT_MAX` sur un en-tête énorme ; RA3 un en-tête ` 120` (espaces) échoue `ctype_digit`
  et est raccourci (`trim()` manquant) ; RA4 la branche « garder un en-tête plus long » est morte en prod
  (aucun producteur de `Retry-After` sur un 429 dans `src/`, `RateLimiterLockFailureListener` répond 503) —
  question de proportion à trancher en relecture finale : simplifier ou garder ; RA5 une date HTTP plus longue
  est raccourcie (exception non mise en avant dans la règle) ; RA6 `backoffice-api.md:335` se lit « l'exception
  levée gagne » ; RA7 (= CR8) le contrôleur combine encore `latestIn()` + `REQUEST_ATTRIBUTE` à la main ; RA8 pas
  de test chaîne multi-quota au niveau écouteur (extérieur plus tardif, égalité) ni par `AuthorizeController`.
- **Prochaine action** : **Task 11** (page de consentement front : `registration`/`domain` dans
  `HttpOAuthConsentRepository.ts:184` qui refuse aujourd'hui `'cimd'`, affichage du domaine vérifié, « réessayer »
  sur 429/503 via `/errors/consent-temporarily-unavailable`, `Retry-After` exploité). Agent sonnet/high ; lire
  d'abord la section Task 11 du plan (`grep -n "Task 11" tasks/plan-t12-cimd.md`, puis `sed -n`), jamais le plan
  entier. Puis Task 12 (règles dont `mcp-oauth.md` 429/503 et F6, ADR, registre, notes de release).
- `HEAD` au 2026-11-18 : `ed5a82f1`, puis ce commit d'état.
- **Task 11 lancée** (2026-11-18), découpée en deux agents successifs (budget de contexte) :
  - **11A** (sonnet/high) : le plan tel qu'écrit (`registration`/`domain` dans `parseSummary()`, domaine
    vérifié avant le nom déclaré, textes i18n du plan, tests du dépôt et de la page, axe) ;
  - **11B** (sonnet/high), après 11A : 429/503 `/errors/consent-temporarily-unavailable` → erreur de domaine
    dédiée, `Retry-After` lu par `infrastructure/http/retryAfterSeconds.ts`, page « réessayer » (pas un refus
    définitif), à l'affichage comme à l'approbation.
  - Puis relecture `/code-review medium diff 3328cef8...HEAD` (zone front, pas d'auth/session touchée a priori).
  - **11A faite** : `7bf76a4d` (Sonnet 5.5) — `parseRegistration()` (cimd ⇔ domaine chaîne non vide,
    preregistered ⇔ `null`), page : domaine vérifié puis nom déclaré puis `registration.cimd`, 5 clés i18n.
    Preuve contrôlée par le fil principal : 127 tests ciblés verts, arbre propre ; l'agent : `make front-test`
    2739 OK, lint et build verts. Gardé : apostrophe typographique `’` dans `domainNotice` (comme tout `en.json`).
    Écrit : les cas « cimd sans domaine » étaient verts dès le rouge (le dépôt refusait tout `cimd`).
  - **11B faite** : `e135f715` (`ConsentTemporarilyUnavailableError`, 429/503 typés au GET et au POST, sinon
    `ConsentFailedError`), `f5f85b41` (composable : GET → `error` + bouton `consent-retry` → `reload()` ; POST →
    `ready`, demande gardée, Autoriser/Refuser = réessai ; message dans le `role="alert"` existant, minutes via
    `retryAfterMinutes`, 3 clés i18n). Sonnet 5.5. Preuve contrôlée : 265 tests mcp + page verts, arbre propre ;
    l'agent : `make front-test` 2773 OK, lint et build verts.
  - Choix de l'agent rendus en question (2026-11-18) : pas de ré-essai automatique ; « Refuser » actif pendant
    le refus passager ; boutons non désactivés jusqu'à `Retry-After` ; un seul message 429/503.
  - **Décisions de l'utilisateur (2026-11-18)** : boutons actifs pendant le délai `Retry-After` ; « Refuser »
    actif (cohérent avec F6) ; un seul message pour 429 et 503. Pas de ré-essai automatique (implicite).
  - **Relecture Task 11 faite** (2026-11-18, `/code-review medium diff 3328cef8...HEAD`, tête relue `260b53af`,
    sans audit) : 10 constats. Un seul moyen annoncé, **infondé après vérification** : le domaine affiché est
    l'hôte seul, mais `app.mcp.cimd.allowed_domains` = `['claude.ai', 'chatgpt.com']` (aucun hôte partagé
    entre comptes) → faible résiduel, à écrire dans la règle CIMD (Task 12) : n'ajouter à la liste aucun hôte
    partagé entre plusieurs comptes. Faibles : T2 `domainNotice` dit « à cette adresse » sans montrer l'adresse ; T3 boutons
    actifs pendant `Retry-After` (**décidé**, non retenu) ; T4 429 nginx `rate-limited` / 503 non typé →
    erreur générique ; T5 délai figé, sans décompte ; T6 `Retry-After` lu sur le 503 (le backend ne le pose
    que sur le 429) ; T7 `Retry-After` non exposé par CORS (`expose_headers: ['Link']`) : invisible en dev
    (cross-origin), lisible en prod (même origine), préexistant (Login, Assistant) ; T8 `registration.cimd`
    sans point final ; T9 `retryAfterMinutes()` appelé deux fois ; T10 bouton `consent-retry` doublon de
    `consent-reload`. **Boucle close** (aucun critique/haut/moyen). Point fixe suivant : `260b53af`.
  - **Micro-série Task 11 décidée** (2026-11-18, l'utilisateur retient T2, T4, T6, T8-T10) : agent O
    (sonnet/high). T2 : l'adresse complète du document (`client.id`) affichée en `<code>` sous le domaine (le
    texte du plan reste vrai) ; T4 : un 429 `rate-limited` de nginx → « réessayez » ; T6 : `Retry-After` lu sur
    le 429 seul ; T8 point final ; T9 un seul appel ; T10 un seul bouton de relecture. Puis relecture
    `/code-review medium diff d6771388...HEAD`, puis Task 12.
  - **Micro-série faite** (agent O, Sonnet 5.5) : `39749763` (T4 429 `rate-limited` → réessayez, 503
    `rate-limited` générique ; T6 délai lu sur le 429 seul), `56e48902` (T2 `consent-client-document`,
    clé `documentLabel` ; T8), `dc535aab` (T9 ; T10 bouton unique `consent-reload`, libellé selon l'échec).
    Preuve contrôlée : 278 tests mcp + page verts, `make front-lint` code 0, arbre propre ; l'agent : 2786
    tests, build vert. Choix gardés par le fil principal : testid unique, `rate-limited` au 429 seul. À la
    Task 12 : documenter le 429/503 du consentement côté front (aucune règle front n'en parle).
  - **Relecture de la micro-série faite** (`/code-review medium diff d6771388...HEAD`, tête relue
    `aabb050b`) : 9 constats, **aucun critique/haut/moyen** après vérification (le « moyen » de l'adresse du
    document est tempéré par la liste fermée claude.ai/chatgpt.com) → **boucle close**.
  - **Micro-correctif fait** dans le fil principal (décision de l'utilisateur, relecture sautée par
    proportionnalité) : `dca006d8` (docblock exact : nginx ne pose pas de `Retry-After` ; `case
    'temporarily-unavailable'` entre accolades ; titres des deux tests « rate-limited avec Retry-After » =
    un intermédiaire). Preuve : 217 tests (3 fichiers consentement) verts, `make front-lint` code 0.
  - **Prochaine action : Task 12** (plan `tasks/plan-t12-cimd.md` à partir de la l. 452 : règles, dont
    `mcp-oauth.md` et une règle front sur le 429/503 du consentement, ADR, registre, notes de release,
    vérifications de fin), agent sonnet/high. Puis relecture complète d'avant la MR, puis étape 08.

## Point fixe de relecture

- Dernière relecture : micro-série de la Task 11, tête relue `aabb050b` ; micro-correctif `dca006d8` non relu
  (décidé), puis ce commit d'état.
- Prochaine série (Task 12, docs) : `diff <SHA de ce commit d'état>...HEAD` — jamais un SHA nu ; la relecture
  complète d'avant la MR part de la merge-base avec la branche mère.

## Décisions de l'utilisateur

- Voie des clients connus (D-F) : décidée le 2026-11-14, compteur qui se replie sur le plafond
  commun quand il est plein (décision du 2026-11-15).
- Régime des agents : table par défaut de `/cpg-dev` (pas de `-hme`).
- Ordre de l'admission (relecture Task 9) : docblock corrigé + limite acceptée, écrite dans la règle
  CIMD à la Task 12.
- Refus transitoire au consentement : traité dans la Task 10 — 503/429 typé avec `Retry-After`, pas
  le 400 générique de `ConsentService::validated()`.
  Non fait par la Task 10 ; reconfirmé le 2026-11-16 comme **série dédiée** après elle. À injecter aussi
  dans la Task 11 : la page affiche « réessayer » sur 429/503, pas un refus définitif.
- Corps du 429/503 au consentement : type dédié `/errors/consent-temporarily-unavailable` gardé (2026-11-18) ;
  la Task 11 le traduit et affiche « réessayer ».
- Audit d'un refus passager au consentement : la trace à la source (`mcp-client-metadata-refused`) suffit,
  aucun événement propre au consentement (2026-11-18).
- Relecture de la tâche 10 + série 429/503 : `/code-review medium` + audit sécurité opus/high (2026-11-18).
- F6 (un refus passager empêche aussi « Refuser ») : **accepté**, à documenter dans `mcp-oauth.md` en Task 12 —
  le refus redirige vers `redirect_uri`, qui doit rester revalidée (2026-11-18).
- Constats écrits, non corrigés, à reprendre dans la PR (relecture du 2026-11-18) :
  F1 mode d'enregistrement changé entre GET et POST (pré-enregistrement ou suppression admin) → accord sur un
  client non montré ; F2 nom déclaré changé entre GET et POST, persisté sans être montré (élargissent la limite
  « POST non lié au GET ») ; F3/CR10 ligne `cimd` partagée réécrite par chaque approbation (libellé changé chez
  les autres comptes, `redirect_uris` simple instantané jamais comparé — à épingler), lignes `cimd` jamais
  purgées (voie « client connu » qui s'élargit) ; F7 la `TransportException` chaînée sous le 503 entre
  peut-être dans le journal principal (non vérifié) ; CR8 `after()` mappe tout non-429 en 503 (un 500 de
  câblage deviendrait 503/`warning`) ; CR9/F10 `\assert($connection …)` après la transaction ; F11 `client_id`
  au chemin libre dans l'audit d'accord (info) ; F12 quota consommé deux fois seulement au-delà du TTL (info).
- Refus des blancs : `client_id` seul (livré en `dccdb715`, confirmé par l'utilisateur le 2026-11-15) ;
- Domaine retiré de la liste fermée : ne coupe que les nouvelles autorisations ; limite acceptée, à
  écrire dans la règle CIMD (Task 12) et la PR (2026-11-15).
- Micro-série M1 + M2 après la relecture de `3c364bd6` : les quatre lots proposés retenus (2026-11-15).
- Relecture de la série n° 2 (`3c364bd6`) : `/code-review medium` + audit sécurité opus/high ; le 400
  du consentement reste générique, sans indice de cause (2026-11-15).
- Relecture de la série de correction : `/code-review medium` + audit sécurité opus/high (2026-11-15) ; la
  `redirect_uri` rognée reste acceptée, `AuthorizationRequestValidatorTest::paddedRedirects` intact.

- Relecture de M1 + M2 (2026-11-15) : garde gardé, contrat corrigé ; identité prouvée par un test de bout en
  bout (pas de comparaison par `client_id`) ; faibles retenus : test PKCE complet, témoin positif réel, hôte
  factorisé, `FailSafeAudit` blindé.

## À injecter dans le brief de la Task 10

- `ConsentService::grant()` utilise `discoveredClient` et persiste via `saveDiscovered()` (il exige
  aujourd'hui `findOneByClientId`) ; la page n'affiche plus « preregistered » en dur
  (`ConsentController::REGISTRATION`).
- Refus transitoire au consentement : 503/429 typé, `Retry-After` (décision ci-dessus).
- `saveDiscovered()` refuse d'écraser une ligne `Preregistered`, et tourne dans la même transaction que
  `connections->add()` ; une ligne `cimd` périmée est rafraîchie (nom, redirections) par le document
  admis (audit de `3c364bd6`).
- `grant()` ne lit le client à persister que par l'accesseur du résumé (client déclaré **jugé**), jamais
  une propriété publique (audit de `dccdb715`).

## Constats écrits non corrigés

- 9B1 : league n'émet pas `CLIENT_AUTHENTICATION_FAILED` pour un refus d'admission ; sans objet, aucun
  écouteur n'est branché sur l'émetteur de league dans le projet, et le refus a son audit
  `mcp-client-metadata-refused` (sauf hors liste, voulu, D-G).
- 9B1 : `CimdClientAdmission` est résolue par alias automatique, privée dans le conteneur de test ;
  les tests du validateur le construisent à la main.
- 9B2 : `Retry-After` du 429 posé par l'attribut `RetryAfterListener::REQUEST_ATTRIBUTE` (rendu public),
  le contrôleur rendant le 429 sans lever l'exception ; couplage implicite par attribut, documenté.
- 9B2 : 9 des 10 tests de `CimdAuthorizationTest` étaient verts dès l'écriture (comportement livré par
  9A/9B1) ; seul le test `Retry-After` a été rouge.
- La règle `mcp-oauth.md` dit encore « until CIMD (#651) lands » : prévu à la Task 12.
- Relecture Task 9, écrits pour la PR ou la règle CIMD (Task 12) : une panne de l'audit
  `mcp-client-metadata-refused` donne un 500 (pas de `FailSafeAudit`) ; double lecture SQL pour un
  client pré-enregistré de forme CIMD ; pas de `Retry-After` sur le 503 ; logique `Retry-After`
  dupliquée entre `AuthorizeController` et `RetryAfterListener` ; repli silencieux du grant sur une
  incohérence d'identifiant ; oracle de latence sur l'état du cache (F7) ; le `Retry-After` révèle la
  saturation du plafond (I1) ; 401 et 503 distinguables (I2) ; URL du transport dans la chaîne des
  `previous`, journalisée en `info` (I4) ; tests manquants (I6 : point de jeton avec un client CIMD ;
  l'attribut d'un autre `client_id` est couvert depuis `dccdb715`).
- `dccdb715` : `judgedDiscoveredClient()` lèverait sur un `AuthorizationRequest` sans client mais
  porteur d'un client déclaré (cas impossible en production : league pose toujours le client) ; le
  test du résumé construit le Value Object directement.

- Relecture de `dccdb715`, écrits (faibles/info) : garde des blancs dupliquée avec
  `FormEncodedRequest::refuseSurroundingBlanks()` (#725), messages divergents ; `state`, `scope`,
  `code_challenge` toujours rognés par league (décision : `client_id` seul) ; la limite acceptée de
  l'admission laisse un anonyme déclencher une récupération sans PKCE (vérifier `code_challenge` avant
  l'admission ne coûterait rien — à reproposer à la Task 12) ; révocation (`LeagueRevocationEndpoint`)
  non relue par l'audit.
- `3c364bd6` : le test de décision du consentement aux blancs ne redevient plus rouge si l'on retire la
  garde des blancs (la garde du grant refuse alors, même 400 générique : défense en profondeur) ; la
  preuve de la garde des blancs reste le test GET (400 contre 401). `Application` importe `OAuthClient`
  d'`Infrastructure/League` (résumé). Le 400 du consentement ne nomme pas sa cause (voulu, décision du 2026-11-15).

- Relecture de `3c364bd6`, écrits : aucun test sur `getClient()` non initialisé dans le résumé (Error
  plutôt que Preregistered) ; repli sur un type inattendu → libellé le plus rassurant (inatteignable,
  `LogicException` possible) ; un attribut `mcp.authorization_point` posé par erreur au point de jeton
  ferait refuser tout échange `cimd` (convention non imposée) ; révocation (`LeagueRevocationEndpoint`)
  sert la ligne périmée, sans danger (révoquer réduit les droits).

- Relecture de M1 + M2, écrits : le port `JudgedClientInterface` ne retire qu'une partie de la dépendance
  à league (le résumé lit encore `AuthorizationRequest`, I3) ; repli `Preregistered` d'un client non jugé
  (fail-open de libellé, inatteignable) ; altitude du refus (grant plutôt que validateur), rejetée.
- Relecture de la série n° 3, écrits : CR6 le test `unreadable` pose `clientId` par réflexion (fragile si
  la propriété devient `readonly`) ; CR7 `McpClient::cimdHost()` répond aussi pour une ligne
  `preregistered` à forme CIMD (chaque appelant filtre `registration()`, API fragile) ; CR8 / F9 l'acteur
  `userId` à la revalidation du consentement et le cas « vrai » de l'identité côté `ConsentService` sans
  témoin ; CR9 l'identité `===` tenue par convention (commentaire), pas par un marqueur ; CR10 trois
  commits de l'agent A signés « Sonnet 5.5 » (`81c756de`, `f7d4f28f`, `98c0e37a`), pas de réécriture sans
  accord ; F10 fenêtre théorique entre les deux lectures de la ligne (refus, rien ne s'ouvre).

- Relecture de la micro-série n° 4, écrits (info) : `error_log` en texte brut partage le descripteur 2
  avec le gestionnaire `main` (une panne de stderr même reste muette) ; classe anonyme dans `::class`
  (chemin interne, octet NUL ; théorique) ; règle « sept sites » tenue à la main, non imposée ; un
  `Throwable` de programmation (`TypeError`) dans la closure d'audit devient un `critical` au lieu d'un
  échec visible (voulu par `FailSafeAudit`).

- Relecture de la micro-série n° 5, écrits (info) : type du fournisseur `array{string}` contre
  `non-empty-string` dans `CimdAuthorizationTest` (PHPStan vert, sans objet) ; commentaire PHPUnit lié à
  une version précise (13.4.6, à revoir à une montée) ; fd 2 du worker = tube vers le maître FPM, non
  stderr du conteneur (mise en tampon, coupure à `log_limit`).

- Relecture de la micro-série de la Task 11 (`aabb050b`), faibles écrits pour la PR : n° 3 l'adresse du
  document affichée sans mise en garde (hameçonnage par le chemin, tempéré par la liste fermée) ; n° 4
  `domainNotice` « à cette adresse » placé avant la ligne d'adresse ; n° 5 `temporaryRefusal` duplique la
  lecture du corps de `hasType()` ; n° 6 tout 429 `rate-limited` traité comme réessayable ; n° 7
  `rereadLabel` couplé implicitement à `offersRetry` ; n° 8 test du point final (formulation) sans
  l'exception dite au commit ; n° 9 pas de test de page pour le 429 nginx au GET. Plus T3 (boutons actifs
  pendant `Retry-After`, décidé), T5 (délai figé), T7 (`Retry-After` non exposé par CORS en dev, préexistant).

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais le plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
