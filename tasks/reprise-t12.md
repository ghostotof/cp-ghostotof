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
- **Prochaine action** : F2 (sonnet/high), puis la relecture `diff f7c585a4...HEAD`.
- `HEAD` au 2026-11-16 : `849d43a4`, puis ce commit d'état.

## Point fixe de relecture

- Dernière relecture : série n° 8, au point `f7c585a4` (série `2e5f8d12...f7c585a4`).
- Prochaine série : `diff f7c585a4...HEAD (branche feature/spec-0007-t12-cimd)` — jamais un SHA nu.

## Décisions de l'utilisateur

- Voie des clients connus (D-F) : décidée le 2026-11-14, compteur qui se replie sur le plafond
  commun quand il est plein (décision du 2026-11-15).
- Régime des agents : table par défaut de `/cpg-dev` (pas de `-hme`).
- Ordre de l'admission (relecture Task 9) : docblock corrigé + limite acceptée, écrite dans la règle
  CIMD à la Task 12.
- Refus transitoire au consentement : traité dans la Task 10 — 503/429 typé avec `Retry-After`, pas
  le 400 générique de `ConsentService::validated()`.
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

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais le plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
