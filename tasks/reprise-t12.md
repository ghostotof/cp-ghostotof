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
- **Prochaine action** : agent B2 de la micro-série n° 4 (sonnet/high), puis relecture
  `diff 121415fe...HEAD` (demander pour l'audit), puis Tasks 10 à 12.
- `HEAD` au 2026-11-15 : `1bda12aa`, puis ce commit d'état.

## Point fixe de relecture

- Dernière relecture : série n° 3, au point `121415fe` (série `0d90fea3...121415fe`).
- Prochaine série : `diff 121415fe...HEAD (branche feature/spec-0007-t12-cimd)` — jamais un SHA nu.

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

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais le plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
