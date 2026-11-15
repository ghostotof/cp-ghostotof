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
- **Prochaine action** : relecture de cette série, `/code-review medium` sur
  `diff 68771e24...HEAD (branche feature/spec-0007-t12-cimd)`, audit sécurité opus/high à proposer
  (la série touche l'identification du client). Puis Tasks 10 à 12.
- `HEAD` au 2026-11-15 : `dccdb715`, puis ce commit d'état.

## Point fixe de relecture

- Dernière relecture : Task 9, au point `68771e24` (série `f6ab033e...68771e24`).
- Prochaine série : `diff 68771e24...HEAD (branche feature/spec-0007-t12-cimd)` — jamais un SHA nu.

## Décisions de l'utilisateur

- Voie des clients connus (D-F) : décidée le 2026-11-14, compteur qui se replie sur le plafond
  commun quand il est plein (décision du 2026-11-15).
- Régime des agents : table par défaut de `/cpg-dev` (pas de `-hme`).
- Ordre de l'admission (relecture Task 9) : docblock corrigé + limite acceptée, écrite dans la règle
  CIMD à la Task 12.
- Refus transitoire au consentement : traité dans la Task 10 — 503/429 typé avec `Retry-After`, pas
  le 400 générique de `ConsentService::validated()`.
- Refus des blancs : `client_id` seul (défaut retenu par le fil principal, livré en `dccdb715`,
  confirmation de l'utilisateur attendue) ; la
  `redirect_uri` rognée reste acceptée, `AuthorizationRequestValidatorTest::paddedRedirects` intact.

## À injecter dans le brief de la Task 10

- `ConsentService::grant()` utilise `discoveredClient` et persiste via `saveDiscovered()` (il exige
  aujourd'hui `findOneByClientId`) ; la page n'affiche plus « preregistered » en dur
  (`ConsentController::REGISTRATION`).
- Refus transitoire au consentement : 503/429 typé, `Retry-After` (décision ci-dessus).

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

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais le plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
