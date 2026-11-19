# Spec 0007 — Tâche 13 (#652) : backoffice MCP, connexions et clients pré-enregistrés — plan

> **For agentic workers:** REQUIRED SUB-SKILL: exécuter ce plan tâche par tâche avec `agent-skills:build` (construire, tester, vérifier, committer) et `superpowers:test-driven-development` (rouge, vert, refactor). Tests : PHPUnit (backend), Vitest (frontend). Les étapes utilisent des cases (`- [ ]`) à cocher au fil de l'eau.

**Goal :** une personne `ROLE_SUPER` voit, dans le backoffice bilingue, toutes les connexions MCP du site (compte, client, octroi, dernier usage, état). Elle enregistre, modifie et supprime un client pré-enregistré, et supprime un client CIMD. Supprimer un client coupe ses connexions ; la page le dit avant de le faire, et le journal d'audit le consigne. Les reports de T8 et T12 sont soldés au passage : bornes des redirections, course du consentement, audit des clients, regex dupliquée, sortie non échappée.

**Architecture :** deux ressources API Platform sous `/api/backoffice/mcp/…`, gardées par l'`access_control` existant `^/api/backoffice(/|$)` → `ROLE_SUPER`. `BackofficeMcpConnectionResource` est en lecture seule ; `BackofficeMcpClientResource` porte le CRUD. Elles suivent le patron du backoffice : DTO plat, Provider, Processor avec `DispatchesWriteOperations`. Les écritures passent par un service applicatif `McpClientAdministrator`, qui réutilise `McpClientRegistrar` et les invariants du domaine (`McpClient`, `RedirectUri`, `McpClientId`, `McpClientName`), sans jamais les recopier. La suppression verrouille la ligne `mcp_client` (`FOR UPDATE`), puis les connexions actives du client, supprime la ligne (cascade en base), et audite après le COMMIT. Le consentement verrouille la ligne du client pré-enregistré en partage (`FOR SHARE`), ce qui ferme la course de la clé étrangère. Le frontend ajoute deux pages admin (couches `domain` → `infrastructure` → `application` → `presentation`), une entrée de navigation et les clés i18n FR/EN.

**Tech Stack :** PHP 8.5, Symfony 8.1 (Validator, Console `OutputFormatter`), API Platform 4.4, Doctrine ORM 3 / PostgreSQL (`LockMode::PESSIMISTIC_READ` → `FOR SHARE`, `RereadUnderLock`), Monolog (journal d'audit `SecurityAuditLogger`), Vue 3 + TypeScript + vue-i18n, Vitest + `@vue/test-utils` + axe-core (`tests/support/axe.ts`), Bootstrap 5.

**Spec :** `.claude/specs/0007-mcp-server.md` (récits 41 et 42, l. 350-354 CIMD + pré-enregistrés sans DCR, l. 543 backoffice `ROLE_SUPER`, section Tests : Vitest au niveau des pages, backoffice compris ; « Out of Scope » : révocation par l'administrateur) ; `docs/adr/0006-serveur-mcp.md` (D3) ; ticket #652 et son commentaire (reports de T8) ; `tasks/todo.md` l. 109 et l. 205-216 (reports de T8 et de T12).

## Global Constraints

- **Branche** : `feature/spec-0007-t13-backoffice-mcp`, empilée sur la branche mère `feature/379-mcp-paliers`. La PR vise la mère, jamais `develop` ni `main`. Un commit par tâche (ou par partie A/B), message en français `type(mcp): … (#652)`, jamais `[skip ci]`, `[ci skip]`, `[no ci]`, `[skip actions]` ni `[actions skip]`. `git add` nomme les fichiers de la tâche, jamais `-A` ni `.`. Jamais `git checkout`, `git switch` ni `git stash`.
- **Taille d'une tâche** : quatre fichiers au plus, tests compris. Une tâche plus grosse est coupée en parties A/B, chacune avec son critère de fin et son commit.
- **Commandes, toujours dans les conteneurs** :
  - test ciblé backend : `docker compose exec -T -u dev backend php bin/phpunit --filter <Classe>` (toujours `-u dev`, jamais root : le cache PHPStan casse sinon) ;
  - test ciblé frontend : `docker compose exec -T frontend npx vitest run <chemin>` ;
  - vérifications de fin : `make back-quality`, `make back-test`, `make front-test`, `make front-lint`, `make front-build`.
- **TDD** (`superpowers:test-driven-development`) : chaque tâche écrit ses tests, les voit échouer **pour la raison attendue** (classe ou méthode absente, assertion fausse ; jamais une erreur de syntaxe ni de câblage), puis implémente. Si un test contredit le source, `docker compose restart backend` (ou `frontend`) avant de chercher plus loin : la vue du montage peut être désynchronisée.
- **Lire avant d'écrire** : les règles `.claude/rules/` ne se chargent que par l'outil `Read`, jamais par `cat`/`sed`/`grep`. Avant de modifier une zone, lire avec `Read` **un** fichier de cette zone, puis travailler avec `sed -n` / `grep -n` à sortie coupée.
  - Zone `backend/src/Mcp/` : environ 180 Ko de règles se chargent dès le premier `Read`. **Ne jamais relire à la main une règle `mcp*.md`** ; pour elles, `grep -n` ciblé seulement.
  - Autres règles à charger par un `Read` de leur zone : `backoffice-api.md`, `security-authentication-audit.md`, `backend-tests.md`, `frontend.md`, `frontend-backoffice.md`, `frontend-a11y.md`.
- **Sécurité, à la lettre** : jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni aucun fichier de secret (tout `grep -r` les exclut) ; jamais `git checkout`/`switch`/`stash` ; jamais nommer l'auteur du site ni un compte réel ; secrets de test via `TestKeys::material()` / `TestCredentials`.
- **Exposition des routes** :
  - `ApiRouteExposureTest` et `RouterScopeTest` ne sont jamais affaiblis ; aucune route nouvelle ne va dans `PUBLIC_PATHS` ni dans `BASE_TIER_PATHS`.
  - Aucune sécurité ni route déclarée pour un seul environnement (pas de `when@test` / `when@prod`) ; `SecurityConfigEnvironmentParityTest`, `RouteEnvironmentParityTest` et `SecurityServicesEnvironmentParityTest` restent verts.
  - Aucune garde `#[IsGranted]` ni `security:` d'API Platform : l'`access_control` `^/api/backoffice(/|$)` reste la seule garde, en première entrée.
  - Après chaque ressource, `docker compose exec -T -u dev backend php bin/console debug:router | grep -i mcp` ne liste que les routes déclarées, sans route synthétisée `/api/backoffice_mcp_…`.
- **Backoffice** :
  - DTO plat (jamais l'entité) sous `Presentation/ApiResource/`, Provider et Processor sous `Infrastructure/ApiPlatform/`, le Processor avec `@use DispatchesWriteOperations<…>`.
  - `Put` et `Delete` portent un `provider:` explicite, avec `requirements: ['id' => Requirement::UUID]` et `normalizationContext: ['skip_null_values' => false]`.
  - Une ressource sans identifiant Doctrine porte `#[ApiProperty(identifier: false)]` sur son `id`.
- **Doctrine** :
  - QueryBuilder, jointures explicites (`innerJoin` + `addSelect`), aucune N+1 ;
  - horodatages `datetimetz_immutable`, clés UUID v7 ;
  - **aucune migration dans cette tâche** (décision D-B) ;
  - ordre des verrous : `mcp_client` avant `mcp_connection`, toujours.
- **Code** :
  - `declare(strict_types=1)`, `final readonly` dès que possible, propriétés, paramètres et retours typés ;
  - une exception métier nommée par refus, avec des constructeurs nommés et un message qui ne recopie jamais une valeur reçue ;
  - `use` triés (`make back-style-fix`) ;
  - PHPStan niveau max, Rector, Psalm et `lsp:check` verts.
- **Exceptions** : toute nouvelle exception qui atteint l'API reçoit une entrée `exception_to_status` dans `api_platform.yaml`, **au-dessus** des deux défauts larges de fin de liste, et un `log_level` dans `framework.exceptions` (`info` pour une erreur du client). `ExceptionLogLevelCoverageTest` le vérifie.
- **Frontend** :
  - couches `domain/admin/mcp` → `infrastructure/admin/mcp` → `application/admin/mcp` → `presentation/pages/admin` ;
  - `BackofficeHttpClient` ;
  - `useAdminLoad` : `isLoaded` avant tout état vide, gardé par `emptyStateNeedsLoaded.spec.ts` ; aucun chargement dans `onMounted`, gardé par `noLoadInOnMounted.spec.ts` ;
  - `visibleMutationError`, et une mutation ne résout `true` qu'en cas de succès ;
  - des gardes, jamais un `as`, pour les champs fermés (`registration`, `state`) ;
  - `hasProblemType` (`infrastructure/http/problemJson.ts`) pour distinguer deux 409 ;
  - clés i18n présentes en FR et en EN ;
  - axe sans violation (`expectNoAccessibilityViolation`).
- **Hors périmètre** :
  - la révocation d'une connexion par l'administrateur (spec, « Out of Scope ») ;
  - le retrait d'un domaine de `app.mcp.cimd.allowed_domains`. On documente seulement la limite acceptée de l'ADR 0006 : les connexions existantes y survivent ;
  - la livraison (T14, #653).

## Review Focus

Les entrées et modes d'échec ci-dessous ne sont pas énoncés par le ticket, mais ce sont eux qui mordraient en premier. Chacun est épinglé par un test, dans la tâche qui possède le code.

1. **Un consentement approuvé pendant la suppression de son client** ne rend jamais de 500 (violation de clé étrangère). Soit la suppression attend la fin de l'accord, puis coupe la connexion neuve ; soit l'accord voit le client disparu et refuse proprement (Task 8, `testADeletionDuringTheGrantWaitsAndNoForeignKeyErrorEscapes`, `testAGrantAfterTheDeletionIsRefusedAsNoLongerRegistered`).
2. **Une redirection à la requête démesurée** (plus de 1 000 paramètres, ou plus de 2 048 octets) est refusée **avant** `parse_str()`. Le refus est nommé, sans avertissement `max_input_vars` et sans 500 (Task 2, `testAQueryWithTooManyParametersIsRefusedBeforeParsing`, `testAnOverlongRedirectIsRefusedBeforeParsing`).
3. **Le jeton d'une connexion du client supprimé** n'ouvre plus rien à `/api/mcp` dès la requête suivante (Task 17, `testAfterDeletionTheHeldBearerOpensNothing`).
4. **Un `PUT` sur un client CIMD** répond 409 typé `client-declared-by-document`, et la ligne reste inchangée. Sinon, le prochain consentement écraserait la modification (Task 17, `testRevisingACimdClientIsRefusedWithATypedConflict`).
5. **Un compte de confiance sans `ROLE_SUPER`, et un jeton du palier de base**, reçoivent 403 sur les cinq opérations ; un anonyme reçoit 401. La garde est l'`access_control`, pas le frontend (Task 16, `testEveryOperationRefusesAnAccountWithoutSuper`).

## Décisions et alternatives

Chaque décision qui touche le périmètre, un contrat publié ou la donnée de la personne est reprise sous « Questions ouvertes » dans le compte rendu du plan. Le plan s'exécute avec l'option recommandée.

**D-A — Supprimer un client : suppression réelle, cascade en base, audit des connexions coupées.** Dans une transaction, `McpClientAdministrator::remove()` :
1. verrouille la ligne `mcp_client` (`FOR UPDATE`, par `RereadUnderLock`) ;
2. verrouille les connexions **actives** du client (`SELECT … FOR UPDATE ORDER BY id`) et les garde ;
3. supprime la ligne. Les connexions, codes, jetons d'accès et de rafraîchissement suivent par `ON DELETE CASCADE`.

Après le COMMIT, il émet un `mcp-connection-revoked` de cause `client-removed` par connexion gardée (`RevokedConnectionAudit`, sans échec bloquant), puis un `mcp-client-removed`.
- *Avantages* :
  - aucune ligne fantôme : `client_id` est libéré, et un client CIMD peut revenir à la prochaine autorisation ;
  - les dépôts et la purge n'ont aucun filtre « retiré » à ajouter ;
  - le jeton est refusé à la requête suivante, puisque l'authentificateur Bearer relit la base (ADR 0006 D4) ;
  - aucune migration.
- *Inconvénients* : les connexions coupées disparaissent aussi de l'historique « Mes connexions MCP » de leur personne. Leur trace ne survit qu'au journal d'audit.
- *Écarté* :
  - une suppression logique (`removed_at`) : chaque requête devrait filtrer, `client_id` resterait pris, et l'upsert CIMD devrait réanimer la ligne ;
  - révoquer, puis garder les connexions sans client : la clé étrangère l'interdit.

**D-B — La cause `client-removed` vit au journal, pas en base.** Comme les connexions sont supprimées (D-A), aucune ligne ne porte jamais cette cause. Pas de nouveau cas `RevocationCause`, pas d'extension de `chk_mcp_connection_revocation_cause`. La chaîne est la constante `McpClientAdministrator::REMOVAL_CAUSE = 'client-removed'`.
- *Avantages* : aucune migration ; aucune valeur d'énumération que la base admettrait sans jamais la voir.
- *Inconvénients* : la cause du journal n'est pas une valeur de `RevocationCause`, et la règle d'audit doit le dire.
- *Écarté* : ajouter `RevocationCause::ClientRemoved` et sa migration (motif de `Version20261028120000`). Ce serait une migration pour une valeur jamais écrite.

**D-C — Course du consentement : verrou partagé sur la ligne du client pré-enregistré.** `ConsentService::grant()` lit le client pré-enregistré par `findOneByClientIdForShare()` (`LockMode::PESSIMISTIC_READ` → `FOR SHARE`, avec `HINT_REFRESH`), dans sa transaction. La branche CIMD est déjà sûre : l'upsert `INSERT … ON CONFLICT DO UPDATE` de `saveDiscovered()` verrouille la ligne qu'il touche, ou recrée la ligne supprimée entre-temps.
- *Avantages* :
  - le `FOR UPDATE` de la suppression attend la fin de l'accord, puis coupe et audite la connexion neuve ;
  - une suppression déjà validée fait rendre zéro ligne à la lecture, et l'accord est refusé par `clientNoLongerRegistered()`, un refus existant ;
  - deux consentements simultanés ne se bloquent pas (`FOR SHARE` est compatible avec lui-même) ;
  - l'ordre client avant connexion est respecté.
- *Inconvénients* : une lecture verrouillée de plus par approbation, négligeable à ce volume.
- *Écarté* :
  - attraper `ForeignKeyConstraintViolationException` et la traduire en refus : la transaction est déjà avortée, et la connexion accordée au même instant échapperait à l'audit de la coupure ;
  - une clé étrangère `DEFERRABLE` : la violation partirait au COMMIT, encore en 500.

**D-D — Pas de pagination API Platform.** La liste des connexions rend toutes les connexions non révoquées, plus les 200 dernières terminées (`AdministeredMcpConnections::TERMINATED_LIMIT = 200`), lues dans un seul instantané (`ReadSnapshotInterface`) avec le compte et le client joints. Le nombre de connexions actives est borné par comptes de confiance × clients, grâce à la supplantation (`revokeOtherActiveOf`) ; la purge quotidienne efface les terminées après six mois. La page dit en permanence : « les 200 connexions terminées les plus récentes ».
- *Avantages* :
  - pas de piège `?page=0` à fermer ;
  - pas de ressource singleton pour porter un drapeau ;
  - le même motif que `OwnMcpConnections` (actives entières, terminées bornées).
- *Inconvénients* : la page ne dit pas *si* elle tronque ; elle annonce seulement la borne.
- *Écarté* :
  - la pagination API Platform, qui exige une classe d'exception propre pour `?page=0` et un frontend paginé ;
  - une ressource singleton `{connections, truncated}`, qui quitte le format de collection des autres pages.

**D-E — `PUT` remplace le nom et les redirections ; l'identifiant est immuable ; un client CIMD est refusé.** `McpClient::revise(McpClientName, list<RedirectUri>)` applique les mêmes invariants que `register()`. Sur un client CIMD, elle lève `McpClientDeclaredByDocumentException`, un 409 typé `client-declared-by-document`.
- *Avantages* :
  - sémantique `PUT` complète sur les champs modifiables ;
  - le nom affiché au consentement peut être corrigé ;
  - les redirections d'un client CIMD viennent de son document, et `saveDiscovered()` écraserait toute modification au prochain accord.
- *Inconvénients* : changer une redirection est un levier de vol de code si un compte `ROLE_SUPER` est compromis. Le changement est donc audité (D-G).
- *Écarté* :
  - les redirections seules (le récit 42 nomme aussi le nom) ;
  - un identifiant modifiable : il est l'identité OAuth, et ses jetons le citent.

**D-F — La validation délègue aux objets valeur du domaine.** Une contrainte de classe `ValidMcpClientFields`, posée sur les deux DTO d'entrée, appelle `McpClientId::fromString()`, `McpClientName::fromString()`, `RedirectUri::fromString()` et la borne `McpClient::MAX_REDIRECT_URIS`. Chaque exception devient une violation au chemin du champ (`clientId`, `name`, `redirectUris[2]`), donc une réponse 422 par champ.
- *Avantages* : aucune règle recopiée ; un seul validateur pour les deux entrées ; les messages de la page désignent le champ.
- *Inconvénients* : l'entrée est analysée deux fois, par le validateur puis par le Processor qui reconstruit les objets valeur.
- *Écarté* :
  - des contraintes `Regex`/`Length` recopiées (deux sources pour une règle) ;
  - mapper les exceptions du domaine en 422 (pas de chemin de champ).

**D-G — Trois événements d'audit nouveaux, un acteur explicite pour la console.**
- `mcp-client-registered` : `clientId`, `registration`. Acteur : le jeton ou `console`.
- `mcp-client-revised` : `clientId`, `redirectUriCount`.
- `mcp-client-removed` : `clientId`, `registration`, `cutConnections`.

L'origine passe par un enum `AuditOrigin` { `Request`, `Console` }, posé à côté de `SecurityAuditLoggerInterface`. La commande passe `Console`, l'administrateur `Request`. C'est le motif de `userCreated()`, qui force `console`.
- *Avantages* : chaque changement de client a son auteur ; la commande n'est plus muette (report de T12) ; aucune URI au journal.
- *Inconvénients* : `mcp-client-revised` ne dit pas *quelles* redirections ont changé ; l'enquête lit la ligne en base.
- *Écarté* :
  - consigner les URI (une chaîne arbitraire saisie, longue de 2 Ko, dans chaque ligne de journal) ;
  - deviner la console par `PHP_SAPI` (les tests tournent en CLI).

**D-H — Un seul helper pour l'index violé.** `App\Shared\Infrastructure\Doctrine\ViolatedUniqueIndex::of(UniqueConstraintViolationException): ?string` porte la regex `QUOTED_IDENTIFIER`, aujourd'hui dupliquée dans `CpgUserUniqueViolation` et `McpClientRegistrar`.
- *Avantages* : un seul point à corriger si PostgreSQL change sa citation (`"` ou `«`).
- *Inconvénients* : `McpClientRegistrar` (couche Application) importe un helper d'Infrastructure. Ce n'est pas nouveau : il attrape déjà l'exception Doctrine.
- *Écarté* : déplacer la traduction dans `McpClientRepository::add()`. Le refus `takenMeanwhile()` changerait de couche, et `CpgUserUniqueViolation` garderait sa copie.

**D-I — Un seul port frontend `AdminMcpRepository`** pour les clients et les connexions, avec un adaptateur `HttpAdminMcpRepository`.
- *Avantages* : une injection, un adaptateur et une table d'erreurs pour un même contexte.
- *Inconvénients* : la page des connexions voit des méthodes d'écriture qu'elle n'appelle pas.
- *Écarté* : deux ports, deux adaptateurs et deux clés d'injection pour cinq méthodes.

## Reports déjà soldés dans le code

- **Borne de `client_id` « 255 à la commande, 512 en base »** : déjà alignée, rien à faire.
  - `backend/src/Mcp/Connection/Domain/Entity/McpClient.php:53` : `CLIENT_ID_MAX_LENGTH = McpClientId::MAX_LENGTH`.
  - `McpClient.php:63` : `#[ORM\Column(length: self::CLIENT_ID_MAX_LENGTH)]`.
  - `backend/src/Mcp/Connection/Domain/ValueObject/McpClientId.php:23` : `MAX_LENGTH = 255`.
  - `backend/migrations/Version20261023120000.php:41` : `client_id VARCHAR(255)`.
  - Le seul 512 du schéma MCP est `mcp_access_token.audience`, sans rapport.
  - La Task 19 le note dans `tasks/todo.md`.

## Fichiers

| Fichier | Rôle | Tâche |
|---|---|---|
| `backend/src/Shared/Infrastructure/Doctrine/ViolatedUniqueIndex.php` (créé) | nom de l'index violé | 1 |
| `backend/src/Security/User/Infrastructure/Doctrine/CpgUserUniqueViolation.php`, `backend/src/Mcp/Connection/Application/McpClientRegistrar.php` (modifiés) | utilisent le helper | 1, 10 |
| `backend/src/Mcp/Connection/Domain/ValueObject/RedirectUri.php`, `Domain/Exception/InvalidRedirectUriException.php` (modifiés) | bornes avant analyse | 2 |
| `backend/src/Mcp/Connection/Domain/Entity/McpClient.php` (modifié) | borne du nombre de redirections, `revise()` | 3, 5 |
| `backend/src/Mcp/Connection/Domain/Exception/InvalidMcpClientRedirectsException.php` (créé) | 0 ou plus de 10 redirections | 3 |
| `backend/src/Mcp/Connection/Domain/Exception/McpClientDeclaredByDocumentException.php` (créé) | 409 typé | 5 |
| `backend/src/Mcp/Connection/Presentation/Command/RegisterMcpClientCommand.php` (modifié) | sortie échappée, borne, origine | 4, 10 |
| `backend/src/Mcp/Connection/Domain/Repository/McpClientRepositoryInterface.php`, `Infrastructure/Doctrine/McpClientRepository.php` (modifiés) | `all`, verrous, `remove` | 6 |
| `backend/src/Mcp/Connection/Domain/Repository/McpConnectionRepositoryInterface.php`, `Infrastructure/Doctrine/McpConnectionRepository.php` (modifiés) | verrou par client, décompte, listes admin | 7A, 7B |
| `backend/src/Mcp/Connection/Application/ConsentService.php` (modifié) | lecture `FOR SHARE` | 8 |
| `backend/src/Security/Authentication/Application/AuditOrigin.php` (créé), `SecurityAuditLoggerInterface.php`, `Infrastructure/Log/SecurityAuditLogger.php` (modifiés) | trois événements | 9 |
| `backend/src/Mcp/Connection/Application/McpClientRegistrarInterface.php` (modifié) | paramètre `AuditOrigin` | 10 |
| `backend/src/Mcp/Connection/Application/McpClientAdministrator.php`, `McpClientAdministratorInterface.php`, `Domain/Exception/McpClientNotFoundException.php` (créés) | enregistrer, réviser, supprimer | 11 |
| `backend/src/Mcp/Connection/Application/McpConnectionStatus.php` (créé), `OwnMcpConnections.php` (modifié) | état et échéance partagés | 12 |
| `backend/src/Mcp/Connection/Application/AdministeredMcpConnections.php`, `AdministeredMcpConnectionsInterface.php`, `AdministeredMcpConnectionView.php` (créés) | lecture de la liste admin | 13 |
| `backend/src/Mcp/Connection/Infrastructure/Validator/ValidMcpClientFields.php`, `ValidMcpClientFieldsValidator.php` (créés) | validation déléguée au domaine | 14 |
| `backend/config/packages/api_platform.yaml`, `framework.yaml` (modifiés) | statuts et niveaux de journal | 15 |
| `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResource.php`, `BackofficeMcpClientInput.php`, `BackofficeMcpClientRevisionInput.php` (créés) | ressource clients | 16, 17 |
| `backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpClientProvider.php`, `BackofficeMcpClientProcessor.php` (créés) | lecture, écritures | 16, 17 |
| `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpConnectionResource.php`, `Infrastructure/ApiPlatform/BackofficeMcpConnectionProvider.php` (créés) | ressource connexions | 18 |
| `frontend/src/domain/admin/mcp/entities/AdminMcpClient.ts`, `AdminMcpConnection.ts`, `errors/AdminMcpError.ts`, `repositories/AdminMcpRepository.ts` (créés) | domaine | F1 |
| `frontend/src/infrastructure/admin/mcp/HttpAdminMcpRepository.ts` (créé), `frontend/src/main.ts` (modifié) | adaptateur, injection | F2 |
| `frontend/src/application/admin/mcp/useAdminMcpClients.ts`, `useAdminMcpConnections.ts` (créés) | composables | F3 |
| `frontend/src/presentation/mcp/revocationCauseLabelKeys.ts` (créé), `presentation/pages/McpConnectionsPage.vue` (modifié) | libellés de cause partagés | F4 |
| `frontend/src/presentation/pages/admin/AdminMcpClientsPage.vue`, `AdminMcpConnectionsPage.vue` (créés), `frontend/src/infrastructure/i18n/locales/{fr,en}.json` (modifiés) | pages, traductions | F5, F6 |
| `frontend/src/presentation/router/index.ts`, `presentation/layout/AdminLayout.vue` (modifiés) | routes, navigation | F7 |
| `.claude/rules/mcp-backoffice.md` (créé), `.claude/rules/mcp.md`, `.claude/rules/security-authentication-audit.md`, `.claude/CLAUDE.md`, `CONTEXT.md`, `tasks/todo.md` (modifiés) | règle, index, glossaire, notes | 19 |

Les tests de chaque tâche sont nommés dans la tâche. Ils vivent sous `backend/tests/` (même arborescence que le source) ou `frontend/tests/`.

---

## Partie backend

### Task 1 : un seul helper pour l'index violé (report de T8)

**Files :**
- Create : `backend/src/Shared/Infrastructure/Doctrine/ViolatedUniqueIndex.php`
- Modify : `backend/src/Security/User/Infrastructure/Doctrine/CpgUserUniqueViolation.php`, `backend/src/Mcp/Connection/Application/McpClientRegistrar.php`
- Test : `backend/tests/Shared/Infrastructure/Doctrine/ViolatedUniqueIndexTest.php`

**Interfaces :**
- Produces : `final class ViolatedUniqueIndex { public static function of(UniqueConstraintViolationException $exception): ?string }`. Rend le premier identifiant cité (`"nom"` ou `« nom »`) du message, `null` sinon. La regex `'/["«]\s*([A-Za-z0-9_]+)\s*["»]/u'` quitte les deux classes.

- [ ] **Step 1 : test rouge.** `ViolatedUniqueIndexTest`, avec un fournisseur de données sur des exceptions construites depuis un `DriverException` en doublure :
  - message anglais `duplicate key value violates unique constraint "uniq_mcp_client_client_id"` → `'uniq_mcp_client_client_id'` ;
  - message français `… contrainte unique « uniq_cpg_user_email »` → `'uniq_cpg_user_email'` ;
  - message sans citation → `null` ;
  - citation contenant un caractère hors `[A-Za-z0-9_]` (`"a-b"`) → `null`.
- [ ] **Step 2 : vérifier l'échec** — `--filter ViolatedUniqueIndexTest` : classe absente.
- [ ] **Step 3 : implémenter** le helper. Remplacer la constante privée et `violatedIndex()` des deux classes par l'appel au helper ; aucune autre logique ne change.
- [ ] **Step 4 : vérifier** — `--filter 'ViolatedUniqueIndexTest|CpgUserUniqueViolationTest|McpClientRegistrarTest|InviteUniqueViolationTest'` vert.
- [ ] **Step 5 : commit** — `refactor(mcp): une seule lecture de l'index unique violé (#652)`.

### Task 2 : bornes d'une redirection avant son analyse (report de T8)

**Files :**
- Modify : `backend/src/Mcp/Connection/Domain/ValueObject/RedirectUri.php`, `backend/src/Mcp/Connection/Domain/Exception/InvalidRedirectUriException.php`
- Test : `backend/tests/Mcp/Connection/Domain/ValueObject/RedirectUriTest.php`

**Interfaces :**
- Produces :
  - `RedirectUri::MAX_LENGTH = 2048` (octets, `strlen`) et `RedirectUri::MAX_QUERY_PARAMETERS = 50` ;
  - `InvalidRedirectUriException::tooLong(): self` et `InvalidRedirectUriException::tooManyQueryParameters(): self`, avec un message constant qui ne recopie pas l'URI.

- [ ] **Step 1 : tests rouges** dans `RedirectUriTest` :
  - `testAnOverlongRedirectIsRefusedBeforeParsing` : `'https://a.example.test/cb?x='.str_repeat('a', 2048)` lève `tooLong()`. Assertion sur le message exact de la fabrique.
  - `testARedirectOfExactlyTheMaximumLengthIsAccepted` : une URI https valide de 2 048 octets passe.
  - `testAQueryWithTooManyParametersIsRefusedBeforeParsing` : une requête de 1 001 paramètres `p0=1&p1=1…` lève `tooManyQueryParameters()`. Le test installe un `set_error_handler` qui échoue sur tout avertissement, pour prouver que `parse_str()` n'a pas été appelé.
  - `testFiftyQueryParametersAreAccepted` : 50 paramètres passent ; 51 lèvent `tooManyQueryParameters()`.
- [ ] **Step 2 : vérifier l'échec** — `--filter RedirectUriTest` : constantes et fabriques absentes (le test de 1 001 paramètres échoue sur l'avertissement `Input variables exceeded`).
- [ ] **Step 3 : implémenter** — dans `fromString()`, la longueur est vérifiée **avant** `Uri::parse`. Dans `carriesIss()`, le compte `\count(explode('&', $query))` est vérifié **avant** `parse_str()`. Docblock : pourquoi 50 (une redirection OAuth réelle porte rarement plus de quelques paramètres), et pourquoi le compte précède l'analyse (`max_input_vars` émet un avertissement, que dev et test changent en exception).
- [ ] **Step 4 : vérifier** — `--filter 'RedirectUriTest|McpClientTest|ClientMetadataDocumentTest'` vert.
- [ ] **Step 5 : commit** — `fix(mcp): une redirection est bornée avant d'être analysée (#652)`.

### Task 3 : le nombre de redirections d'un client, invariant du domaine (report de T8)

**Files :**
- Create : `backend/src/Mcp/Connection/Domain/Exception/InvalidMcpClientRedirectsException.php`
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpClient.php`
- Test : `backend/tests/Mcp/Connection/Domain/Entity/McpClientTest.php`

**Interfaces :**
- Consumes : `ClientMetadataDocument::MAX_REDIRECT_URIS` (10).
- Produces :
  - `McpClient::MAX_REDIRECT_URIS = ClientMetadataDocument::MAX_REDIRECT_URIS`, une référence et non une copie ;
  - `InvalidMcpClientRedirectsException::none(): self` et `InvalidMcpClientRedirectsException::tooMany(): self` (`extends \InvalidArgumentException`) ;
  - `McpClient::register()` lève l'une ou l'autre **après** déduplication.

- [ ] **Step 1 : tests rouges** dans `McpClientTest` :
  - `testRegisteringWithoutRedirectIsRefused` → `none()` ;
  - `testRegisteringElevenDistinctRedirectsIsRefused` → `tooMany()` ;
  - `testTenDistinctRedirectsAreAccepted` ;
  - `testElevenRedirectsWithOneDuplicateAreAcceptedAfterDeduplication` : 10 distinctes plus un doublon donnent 10 redirections.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpClientTest` : classe d'exception absente.
- [ ] **Step 3 : implémenter** — une méthode privée statique `distinctBounded(array $redirectUris): list<string>`, appelée par `register()`. `discovered()` reste inchangée : le document est déjà borné.
- [ ] **Step 4 : vérifier** — `--filter 'McpClientTest|McpClientRegistrarTest|McpClientRepositoryTest'` vert.
- [ ] **Step 5 : commit** — `fix(mcp): un client porte de une à dix redirections (#652)`.

### Task 4 : la commande échappe sa sortie et rend la borne lisible (report de T8)

**Files :**
- Modify : `backend/src/Mcp/Connection/Presentation/Command/RegisterMcpClientCommand.php`
- Test : `backend/tests/Mcp/Connection/Presentation/Command/RegisterMcpClientCommandTest.php`

**Interfaces :**
- Consumes : `InvalidMcpClientRedirectsException` (Task 3).

- [ ] **Step 1 : tests rouges** dans `RegisterMcpClientCommandTest` (`CommandTester`, décoré) :
  - `testAClientNameWithFormattingTagsIsPrintedLiterally` : `--name '<error>x</error>'` ; la sortie contient `<error>x</error>` littéralement.
  - `testElevenRedirectsAreRefusedWithAReadableError` : 11 `--redirect-uri` distinctes donnent un code `Command::FAILURE`, un message d'erreur, aucune trace d'exception, et aucune ligne en base.
- [ ] **Step 2 : vérifier l'échec** — `--filter RegisterMcpClientCommandTest` : la balise est interprétée, et l'exception remonte.
- [ ] **Step 3 : implémenter** — `OutputFormatter::escape()` sur chaque valeur reçue de `$io->success()` et sur chaque élément de `$io->listing()`. Attraper `InvalidMcpClientRedirectsException` au même endroit que `McpClientAlreadyRegisteredException`, puis `$io->error($exception->getMessage())`.
- [ ] **Step 4 : vérifier** — `--filter RegisterMcpClientCommandTest` vert.
- [ ] **Step 5 : commit** — `fix(mcp): la commande d'enregistrement échappe ce qu'elle affiche (#652)`.

### Task 5 : réviser un client pré-enregistré

**Files :**
- Create : `backend/src/Mcp/Connection/Domain/Exception/McpClientDeclaredByDocumentException.php`
- Modify : `backend/src/Mcp/Connection/Domain/Entity/McpClient.php`
- Test : `backend/tests/Mcp/Connection/Domain/Entity/McpClientTest.php`

**Interfaces :**
- Produces :
  - `McpClient::revise(McpClientName $name, array $redirectUris): void` (`@param list<RedirectUri>`), avec les mêmes bornes que `register()` (`distinctBounded()`) ;
  - `McpClientDeclaredByDocumentException::cannotBeRevised(): self`, qui `implements ProblemExceptionInterface` et `use HasProblemType` : `problemType()` = `'client-declared-by-document'`, `problemStatus()` = 409.

- [ ] **Step 1 : tests rouges** :
  - `testRevisingReplacesNameAndRedirects` (dédupliquées) ;
  - `testRevisingKeepsTheClientIdAndTheRegistrationDate` ;
  - `testRevisingToNoRedirectIsRefused` → `none()` ;
  - `testRevisingACimdClientIsRefused` : client issu de `discovered()`, `cannotBeRevised()`, nom et redirections inchangés.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpClientTest` : méthode absente.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter McpClientTest` vert.
- [ ] **Step 5 : commit** — `feat(mcp): un client pré-enregistré se révise, un client CIMD non (#652)`.

### Task 6 : dépôt des clients — lister, verrouiller, supprimer

**Files :**
- Modify : `backend/src/Mcp/Connection/Domain/Repository/McpClientRepositoryInterface.php`, `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpClientRepository.php`, `backend/tests/Mcp/Connection/Application/McpClientRepositoryFake.php`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpClientRepositoryTest.php`

**Interfaces :**
- Consumes : `RereadUnderLock::row(EntityManagerInterface, string, mixed, bool): ?object`.
- Produces :
  - `all(): list<McpClient>`, triée par `registeredAt` décroissant, puis `id` décroissant ;
  - `findOneByIdForUpdate(Uuid $id): ?McpClient` (`FOR UPDATE` par `RereadUnderLock`, exige une transaction) ;
  - `findOneByClientIdForShare(string $clientId): ?McpClient` (DQL, `LockMode::PESSIMISTIC_READ` + `Query::HINT_REFRESH`, exige une transaction) ;
  - `remove(McpClient $client): void` (`remove` + `flush`).
  - Le fake implémente les quatre méthodes en mémoire, sans notion de verrou.

- [ ] **Step 1 : tests rouges** (`KernelTestCase`, base de test) :
  - `testAllListsBothRegistrationsMostRecentFirst` ;
  - `testFindOneByIdForUpdateReturnsNullForAVanishedRow` ;
  - `testFindOneByClientIdForShareTakesASharedLock` : la sonde `ProbesLocks` voit un verrou `RowShareLock` sur `mcp_client` pendant la transaction ;
  - `testRemovingAClientCascadesToItsConnectionsAndTokens` : un client avec une connexion, un code, un jeton d'accès et un jeton de rafraîchissement ; après `remove()`, les quatre tables n'ont plus de ligne pour ce client.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpClientRepositoryTest` : méthodes absentes.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter 'McpClientRepositoryTest|ConsentServiceTest|McpClientRegistrarTest'` vert.
- [ ] **Step 5 : commit** — `feat(mcp): le dépôt des clients liste, verrouille et supprime (#652)`.

### Task 7A : dépôt des connexions — verrou des actives d'un client, décompte par client

**Files :**
- Modify : `backend/src/Mcp/Connection/Domain/Repository/McpConnectionRepositoryInterface.php`, `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepository.php`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepositoryTest.php`

**Interfaces :**
- Produces :
  - `lockActiveOfClient(Uuid $client): list<RevokedConnection>`. SQL natif : `SELECT c.id, c.account_id, cl.client_id FROM mcp_connection c INNER JOIN mcp_client cl ON cl.id = c.mcp_client_id WHERE c.mcp_client_id = :client AND c.revoked_at IS NULL ORDER BY c.id FOR UPDATE OF c`. Hors transaction, lève `RevocationOutsideTransactionException`, comme `revokeAllActiveOfAccount`. Le type rendu est la référence que `RevokedConnectionAudit::record()` consomme.
  - `countActiveByClient(): array<string, int>` : une seule requête `GROUP BY mcp_client_id`, clé = UUID RFC 4122 de la ligne `mcp_client`.

- [ ] **Step 1 : tests rouges** :
  - `testLockActiveOfClientReturnsOnlyItsActiveConnectionsInIdOrder` (une révoquée et une d'un autre client sont exclues) ;
  - `testLockActiveOfClientOutsideATransactionIsRefused` ;
  - `testCountActiveByClientCountsActiveConnectionsInOneQuery` : compteur de requêtes DBAL (`DebugStack` ou middleware de test existant) = 1, et un client sans connexion est absent du tableau.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpConnectionRepositoryTest` : méthodes absentes.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter McpConnectionRepositoryTest` vert.
- [ ] **Step 5 : commit** — `feat(mcp): verrouiller et compter les connexions actives d'un client (#652)`.

### Task 7B : dépôt des connexions — listes de l'administration

**Files :**
- Modify : `backend/src/Mcp/Connection/Domain/Repository/McpConnectionRepositoryInterface.php`, `backend/src/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepository.php`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Doctrine/McpConnectionRepositoryTest.php`

**Interfaces :**
- Produces :
  - `findNotRevokedForAdministration(): list<McpConnection>` ;
  - `findTerminatedForAdministration(int $limit): list<McpConnection>`.
  - Même QueryBuilder privé `forAdministration()` : `createQueryBuilder('connection')->addSelect('client', 'account')->innerJoin('connection.client', 'client')->innerJoin('connection.account', 'account')`. Ordre `grantedAt DESC, id DESC`. Les terminées sont les révoquées.

- [ ] **Step 1 : tests rouges** :
  - `testAdministrationListsSpanEveryAccount` (deux comptes, deux clients) ;
  - `testTerminatedForAdministrationIsBoundedAndMostRecentFirst` (`limit` 2 sur 3 révoquées) ;
  - `testAdministrationListsHydrateClientAndAccountWithoutFurtherQueries` : après la lecture, lire `getClient()->getName()` et le nom du compte de chaque connexion n'ajoute aucune requête.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpConnectionRepositoryTest` : méthodes absentes.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter McpConnectionRepositoryTest` vert.
- [ ] **Step 5 : commit** — `feat(mcp): lister toutes les connexions pour l'administration (#652)`.

### Task 8 : course du consentement contre la suppression du client (report de T8) — **sensible** (concurrence, consentement)

**Files :**
- Modify : `backend/src/Mcp/Connection/Application/ConsentService.php`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Doctrine/ConsentClientRemovalRaceTest.php` (créé)

**Interfaces :**
- Consumes :
  - `McpClientRepositoryInterface::findOneByClientIdForShare()` (Task 6) ;
  - `FlushInterleaving::releaseBeforeNextFlush()`, `ChildPhpProcess`, `ProcessBarrier`, `SessionLockWait` (harnais à processus fils de `backend-tests.md`).

- [ ] **Step 1 : tests rouges** (`KernelTestCase`, base de test) :
  - `testADeletionDuringTheGrantWaitsAndNoForeignKeyErrorEscapes` : un client pré-enregistré et un résumé de consentement valide.
    1. `FlushInterleaving::releaseBeforeNextFlush()` relâche un fils qui exécute `DELETE FROM mcp_client WHERE id = :id` ;
    2. `grant()` doit se terminer sans exception ;
    3. le fils termine après l'accord ;
    4. à la fin, le client et la connexion neuve n'existent plus (cascade).
  - `testAGrantAfterTheDeletionIsRefusedAsNoLongerRegistered` : la ligne est supprimée en SQL avant `grant()`, qui lève `AuthorizationRequestRejectedException` construite par `clientNoLongerRegistered()`, sans aucune écriture.
- [ ] **Step 2 : vérifier l'échec** — `--filter ConsentClientRemovalRaceTest` : le premier test échoue sur `ForeignKeyConstraintViolationException` (le fils supprime pendant la pause) ; le second passe déjà, ce qui est son rôle de garde.
- [ ] **Step 3 : implémenter** — dans `grant()` (ligne 238), remplacer `findOneByClientId` par `findOneByClientIdForShare`. Docblock : pourquoi la branche CIMD n'en a pas besoin (l'upsert verrouille ou recrée la ligne), et l'ordre des verrous (client avant connexion).
- [ ] **Step 4 : vérifier** — `--filter 'ConsentClientRemovalRaceTest|ConsentServiceTest|ConsentControllerTest'` vert, trois passages de suite (verdict stable).
- [ ] **Step 5 : commit** — `fix(mcp): le consentement verrouille son client contre une suppression (#652)`.

### Task 9 : trois événements d'audit des clients — **sensible** (audit)

**Files :**
- Create : `backend/src/Security/Authentication/Application/AuditOrigin.php`
- Modify : `backend/src/Security/Authentication/Application/SecurityAuditLoggerInterface.php`, `backend/src/Security/Authentication/Infrastructure/Log/SecurityAuditLogger.php`
- Test : `backend/tests/Security/Authentication/Infrastructure/Log/SecurityAuditLoggerTest.php`

**Interfaces :**
- Produces :
  - `enum AuditOrigin { case Request; case Console; }` ;
  - `mcpClientRegistered(string $clientId, string $registration, AuditOrigin $origin): void` — événement `mcp-client-registered`, message `MCP client registered.`, contexte `{clientId, registration}`, acteur `console` quand `Console`, sinon celui du jeton ;
  - `mcpClientRevised(string $clientId, int $redirectUriCount): void` — `mcp-client-revised`, `MCP client revised.` ;
  - `mcpClientRemoved(string $clientId, string $registration, int $cutConnections): void` — `mcp-client-removed`, `MCP client removed.`.
  - Aucune URI ni aucun nom de client au contexte.

- [ ] **Step 1 : tests rouges** — trois tests, un par événement, sur le motif des tests `userCreated` et `mcpConnectionRevoked` du même fichier : nom de l'événement, contexte exact, acteur (`console` pour `AuditOrigin::Console`, le nom du compte pour `Request` avec un jeton posé), et absence de toute clé `redirectUris` ou `name`.
- [ ] **Step 2 : vérifier l'échec** — `--filter SecurityAuditLoggerTest` : méthodes absentes.
- [ ] **Step 3 : implémenter** — `self::CONSOLE` passé à `record()` pour `Console`.
- [ ] **Step 4 : vérifier** — `--filter 'SecurityAuditLoggerTest|SecurityEventsSubscriberTest'` vert.
- [ ] **Step 5 : commit** — `feat(mcp): auditer l'enregistrement, la révision et la suppression d'un client (#652)`.

### Task 10 : l'enregistrement audite son origine (report de T12)

**Files :**
- Modify : `backend/src/Mcp/Connection/Application/McpClientRegistrarInterface.php`, `backend/src/Mcp/Connection/Application/McpClientRegistrar.php`, `backend/src/Mcp/Connection/Presentation/Command/RegisterMcpClientCommand.php`
- Test : `backend/tests/Mcp/Connection/Application/McpClientRegistrarTest.php`

**Interfaces :**
- Consumes : `AuditOrigin`, `SecurityAuditLoggerInterface::mcpClientRegistered()` (Task 9).
- Produces : `register(McpClientId $clientId, McpClientName $name, array $redirectUris, AuditOrigin $origin): McpClient`. L'audit part après `add()`, une fois l'écriture faite ; un refus n'audite rien. La commande passe `AuditOrigin::Console`.

- [ ] **Step 1 : tests rouges** :
  - `testARegistrationIsAuditedWithItsOrigin` : un logger en doublure (`createMock`) attend `mcpClientRegistered('claude-code', 'preregistered', AuditOrigin::Console)` une fois ;
  - `testARefusedRegistrationAuditsNothing` : identifiant déjà pris, `never()`.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpClientRegistrarTest` : quatrième argument inconnu.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter 'McpClientRegistrarTest|RegisterMcpClientCommandTest'` vert, et `make back-quality` vert (aucun autre appelant).
- [ ] **Step 5 : commit** — `feat(mcp): l'enregistrement d'un client est audité, console comprise (#652)`.

### Task 11 : `McpClientAdministrator` — enregistrer, réviser, supprimer en coupant — **sensible** (révocation, audit, concurrence)

**Files :**
- Create : `backend/src/Mcp/Connection/Application/McpClientAdministratorInterface.php`, `backend/src/Mcp/Connection/Application/McpClientAdministrator.php`, `backend/src/Mcp/Connection/Domain/Exception/McpClientNotFoundException.php`
- Test : `backend/tests/Mcp/Connection/Application/McpClientAdministratorTest.php`

**Interfaces :**
- Consumes :
  - `McpClientRegistrarInterface::register(…, AuditOrigin)` (Task 10) ;
  - `findOneByIdForUpdate`, `remove` (Task 6) ;
  - `lockActiveOfClient` (Task 7A) ;
  - `McpClient::revise` (Task 5) ;
  - `TransactionInterface::run(callable)` ;
  - `RevokedConnectionAudit::record(McpConnectionReference, string)` ;
  - `mcpClientRevised`, `mcpClientRemoved` (Task 9) ;
  - `ClockInterface`.
- Produces :
  - `McpClientAdministratorInterface` :
    - `register(McpClientId $clientId, McpClientName $name, array $redirectUris): McpClient` (délègue avec `AuditOrigin::Request`) ;
    - `revise(Uuid $id, McpClientName $name, array $redirectUris): McpClient` ;
    - `remove(Uuid $id): int` (nombre de connexions coupées).
  - `McpClientAdministrator::REMOVAL_CAUSE = 'client-removed'` ;
  - `McpClientNotFoundException::withId(): self` (message constant).

- [ ] **Step 1 : tests rouges** (`KernelTestCase`, base de test, logger d'audit lu par `ReadsSecurityAuditLog`) :
  - `testRemovingCutsEveryActiveConnectionAndAuditsEach` : deux connexions actives, une révoquée. `remove()` rend 2 ; deux `mcp-connection-revoked` de cause `client-removed`, puis un `mcp-client-removed` avec `cutConnections: 2`.
  - `testRemovingAnUnknownClientIsNotFoundAndAuditsNothing`.
  - `testRemovingACimdClientIsAllowed` : `registration: 'cimd'` dans l'événement.
  - `testTheAuditFollowsTheCommit` : une transaction qui avorte (`RollingBackTransaction`) n'émet aucun événement.
  - `testRevisingAuditsTheRedirectCount` et `testRevisingAnUnknownClientIsNotFound`.
  - `testRevisingACimdClientIsRefusedAndAuditsNothing`.
- [ ] **Step 2 : vérifier l'échec** — `--filter McpClientAdministratorTest` : classes absentes.
- [ ] **Step 3 : implémenter.** Ordre dans `remove()` : verrou du client, verrou des connexions, `remove()`, fin de transaction, puis audit. Docblock : D-A (pourquoi supprimer plutôt que révoquer) et D-B (cause au journal seulement).
- [ ] **Step 4 : vérifier** — `--filter McpClientAdministratorTest` vert ; `make back-quality` vert.
- [ ] **Step 5 : commit** — `feat(mcp): supprimer un client coupe et audite ses connexions (#652)`.

### Task 12 : l'état d'une connexion, calculé à un seul endroit

**Files :**
- Create : `backend/src/Mcp/Connection/Application/McpConnectionStatus.php`
- Modify : `backend/src/Mcp/Connection/Application/OwnMcpConnections.php`, `backend/tests/Mcp/Connection/Application/OwnMcpConnectionsTest.php` (construction seulement)
- Test : `backend/tests/Mcp/Connection/Application/McpConnectionStatusTest.php`

**Interfaces :**
- Consumes : `McpConnection::deadline(DateTimeZone, ConnectionLifetimes): DateTimeImmutable`, `OwnMcpConnectionState`.
- Produces : `final readonly class McpConnectionStatus`, construite avec `ConnectionLifetimes` et le fuseau déjà injectés dans `OwnMcpConnections` :
  - `expiresAt(McpConnection $connection): DateTimeImmutable` ;
  - `stateOf(McpConnection $connection, DateTimeImmutable $now): OwnMcpConnectionState`.

- [ ] **Step 1 : test rouge** — `McpConnectionStatusTest`, avec un fournisseur de données reprenant les cas de l'état actuel : active avant l'échéance, expirée à l'échéance exacte, révoquée même avant l'échéance, et code jamais échangé (échéance de l'accord abandonné).
- [ ] **Step 2 : vérifier l'échec** — classe absente.
- [ ] **Step 3 : implémenter** en déplaçant `stateOf()` et le calcul d'échéance hors de `OwnMcpConnections`, qui reçoit `McpConnectionStatus` par son constructeur.
- [ ] **Step 4 : vérifier** — `--filter 'McpConnectionStatusTest|OwnMcpConnectionsTest|OwnMcpConnectionsControllerTest'` vert, sans aucune assertion modifiée dans `OwnMcpConnectionsTest`.
- [ ] **Step 5 : commit** — `refactor(mcp): l'état d'une connexion a un seul calcul (#652)`.

### Task 13 : la liste des connexions pour l'administration

**Files :**
- Create : `backend/src/Mcp/Connection/Application/AdministeredMcpConnectionsInterface.php`, `AdministeredMcpConnections.php`, `AdministeredMcpConnectionView.php`
- Test : `backend/tests/Mcp/Connection/Application/AdministeredMcpConnectionsTest.php`

**Interfaces :**
- Consumes :
  - `findNotRevokedForAdministration`, `findTerminatedForAdministration` (Task 7B) ;
  - `McpConnectionStatus` (Task 12) ;
  - `ReadSnapshotInterface`, `ClockInterface`.
- Produces :
  - `AdministeredMcpConnectionsInterface::list(): list<AdministeredMcpConnectionView>` ;
  - `AdministeredMcpConnections::TERMINATED_LIMIT = 200` ;
  - `final readonly class AdministeredMcpConnectionView`, aux propriétés publiques :
    - `Uuid $id`, `string $accountUsername`, `string $clientId`, `string $clientName`, `ClientRegistration $clientRegistration` ;
    - `DateTimeImmutable $grantedAt`, `bool $codeExchanged`, `?DateTimeImmutable $lastUsedAt`, `DateTimeImmutable $expiresAt` ;
    - `OwnMcpConnectionState $state`, `?DateTimeImmutable $revokedAt`, `?string $revocationCause`.
  - Les deux moitiés sont lues dans un seul instantané, puis triées par `grantedAt` décroissant, puis `id` décroissant.

- [ ] **Step 1 : tests rouges** (`KernelTestCase`) :
  - `testListsActiveExpiredAndRevokedOfEveryAccount` ;
  - `testTerminatedAreBoundedToTheLimit` : la constante est abaissée par une sous-classe de test, ou 201 révoquées sont insérées en SQL ; seules les 200 plus récentes restent ;
  - `testTheViewNamesTheAccountAndTheClientMode`.
- [ ] **Step 2 : vérifier l'échec** — classes absentes.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter AdministeredMcpConnectionsTest` vert.
- [ ] **Step 5 : commit** — `feat(mcp): lire toutes les connexions pour l'administration (#652)`.

### Task 14 : validation des champs d'un client, déléguée au domaine

**Files :**
- Create : `backend/src/Mcp/Connection/Infrastructure/Validator/ValidMcpClientFields.php`, `ValidMcpClientFieldsValidator.php`
- Test : `backend/tests/Mcp/Connection/Infrastructure/Validator/ValidMcpClientFieldsValidatorTest.php`

**Interfaces :**
- Consumes :
  - `McpClientId::fromString`, `McpClientName::fromString`, `RedirectUri::fromString` ;
  - `McpClient::MAX_REDIRECT_URIS` ;
  - leurs exceptions `InvalidMcpClientIdException`, `InvalidMcpClientNameException`, `InvalidRedirectUriException`.
- Produces :
  - une contrainte de classe `#[ValidMcpClientFields]` (`Attribute::TARGET_CLASS`) qui lit les propriétés publiques `?string $clientId` (absente sur la révision), `string $name` et `list<string> $redirectUris` de l'objet validé ;
  - une violation par champ fautif, à son chemin (`clientId`, `name`, `redirectUris[i]`, `redirectUris` pour le nombre), avec le message de l'exception ;
  - nombre de redirections distinctes : messages `Au moins une redirection est requise.` et `Dix redirections au plus.`.

- [ ] **Step 1 : tests rouges** (`ConstraintValidatorTestCase`) :
  - entrée valide → aucune violation ;
  - `clientId` hors alphabet → violation à `clientId` ;
  - nom vide → `name` ;
  - troisième redirection en `http://evil.example` → `redirectUris[2]` ;
  - onze redirections distinctes → `redirectUris` ;
  - entrée de révision sans `clientId` → aucune violation sur `clientId`.
- [ ] **Step 2 : vérifier l'échec** — classes absentes.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter ValidMcpClientFieldsValidatorTest` vert.
- [ ] **Step 5 : commit** — `feat(mcp): valider un client par les règles du domaine (#652)`.

### Task 15 : statuts et niveaux de journal des refus de client

**Files :**
- Modify : `backend/config/packages/api_platform.yaml`, `backend/config/packages/framework.yaml`
- Test : `backend/tests/Mcp/Connection/Infrastructure/ApiPlatform/McpClientExceptionMappingTest.php` (créé)

**Interfaces :**
- Produces :
  - `exception_to_status`, placé au-dessus des deux défauts de fin : `McpClientNotFoundException: 404`, `McpClientAlreadyRegisteredException: 409`, `InvalidMcpClientRedirectsException: 422`. `McpClientDeclaredByDocumentException` porte son statut par `HasProblemType`.
  - `framework.exceptions` : `log_level: info` pour les quatre classes.

- [ ] **Step 1 : test rouge** — `McpClientExceptionMappingTest`, avec le support `CompiledExceptionConfig` : les trois statuts sont présents et précèdent `Serializer\ExceptionInterface`, et chacune des quatre classes a `log_level: info`.
- [ ] **Step 2 : vérifier l'échec** — entrées absentes.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter 'McpClientExceptionMappingTest|ExceptionLogLevelCoverageTest'` vert.
- [ ] **Step 5 : commit** — `feat(mcp): statuts et niveaux de journal des refus de client (#652)`.

### Task 16 : ressource des clients — lecture et garde

**Files :**
- Create : `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResource.php`, `backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpClientProvider.php`
- Test : `backend/tests/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResourceTest.php`

**Interfaces :**
- Consumes : `McpClientRepositoryInterface::all()` (Task 6), `McpConnectionRepositoryInterface::countActiveByClient()` (Task 7A).
- Produces :
  - DTO aux propriétés `string $id`, `string $clientId`, `string $name`, `list<string> $redirectUris`, `string $registration` (`preregistered` ou `cimd`), `string $registeredAt` (ATOM), `int $activeConnections` ;
  - opérations `GetCollection /backoffice/mcp/clients` et `Get /backoffice/mcp/clients/{id}` (`Requirement::UUID`), `normalizationContext: ['skip_null_values' => false]` ;
  - la liste coûte deux requêtes, quel que soit le nombre de clients.

- [ ] **Step 1 : tests rouges** (`WebTestCase`) :
  - `testSuperListsBothRegistrationsWithTheirActiveConnectionCount` ;
  - `testGetReturnsOneClientAndUnknownIdIs404` ;
  - `testEveryOperationRefusesAnAccountWithoutSuper` : fournisseur de données sur les cinq opérations prévues. Un compte de confiance reçoit 403, un jeton `POST /api/account/base-access` 403, un anonyme 401. Les opérations d'écriture répondent déjà 401/403 avant l'existence du Processor, car la garde précède le routage API Platform ; une route encore absente répond 403/401 par l'`access_control`.
  - `testListingTakesTwoQueriesWhateverTheClientCount`.
- [ ] **Step 2 : vérifier l'échec** — routes absentes (404).
- [ ] **Step 3 : implémenter** ; `debug:router | grep -i mcp` ne liste que les deux routes.
- [ ] **Step 4 : vérifier** — `--filter 'BackofficeMcpClientResourceTest|ApiRouteExposureTest|RouterScopeTest'` vert.
- [ ] **Step 5 : commit** — `feat(mcp): le backoffice liste les clients MCP (#652)`.

### Task 17A : ressource des clients — enregistrer

**Files :**
- Create : `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientInput.php`, `backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpClientProcessor.php`
- Modify : `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResource.php`
- Test : `backend/tests/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResourceTest.php`

**Interfaces :**
- Consumes : `McpClientAdministratorInterface::register()` (Task 11), `#[ValidMcpClientFields]` (Task 14).
- Produces :
  - `BackofficeMcpClientInput { public string $clientId; public string $name; public array $redirectUris; }` portant `#[ValidMcpClientFields]` ;
  - `Post /backoffice/mcp/clients` (`input: BackofficeMcpClientInput`, 201) ;
  - le Processor `@use DispatchesWriteOperations<BackofficeMcpClientResource>`, avec `create()` seul à ce stade. `update()` et `delete()` lèvent `UnsupportedOperationException` jusqu'à la Task 17B.

- [ ] **Step 1 : tests rouges** :
  - `testSuperRegistersAPreregisteredClient` : 201, corps conforme, `mcp-client-registered` au journal avec l'acteur du jeton ;
  - `testAnInvalidRedirectAnswers422AtItsPath` (`violations[0].propertyPath === 'redirectUris[0]'`) ;
  - `testATakenClientIdAnswers409`.
- [ ] **Step 2 : vérifier l'échec** — `Post` absent (405).
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter 'BackofficeMcpClientResourceTest|DispatchesWriteOperationsTest'` vert.
- [ ] **Step 5 : commit** — `feat(mcp): le backoffice enregistre un client MCP (#652)`.

### Task 17B : ressource des clients — réviser et supprimer — **sensible** (révocation)

**Files :**
- Create : `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientRevisionInput.php`
- Modify : `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResource.php`, `backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpClientProcessor.php`
- Test : `backend/tests/Mcp/Connection/Presentation/ApiResource/BackofficeMcpClientResourceTest.php`

**Interfaces :**
- Consumes :
  - `McpClientAdministratorInterface::revise()`, `remove()` (Task 11) ;
  - `HoldsAnMcpConnection::holdMcpConnection()`, `bearer()`.
- Produces :
  - `BackofficeMcpClientRevisionInput { public string $name; public array $redirectUris; }` portant `#[ValidMcpClientFields]` ;
  - `Put /backoffice/mcp/clients/{id}` (`provider:` explicite, `input:` révision, 200) ;
  - `Delete /backoffice/mcp/clients/{id}` (`provider:` explicite, 204).

- [ ] **Step 1 : tests rouges** :
  - `testSuperRevisesNameAndRedirects` ;
  - `testRevisingACimdClientIsRefusedWithATypedConflict` : 409, `type` se termine par `/errors/client-declared-by-document`, ligne inchangée en base ;
  - `testDeletingAnUnknownClientIs404` ;
  - `testAfterDeletionTheHeldBearerOpensNothing` : une connexion tenue par `holdMcpConnection()`. `DELETE` sur son client répond 204, puis `POST /api/mcp` avec `bearer()` répond 401.
  - `testDeletingACimdClientCutsItsConnections`.
- [ ] **Step 2 : vérifier l'échec** — `Put`/`Delete` absents (405).
- [ ] **Step 3 : implémenter** `update()` et `delete()` du Processor.
- [ ] **Step 4 : vérifier** — `--filter 'BackofficeMcpClientResourceTest|ApiRouteExposureTest'` vert ; `debug:router | grep -i mcp` liste exactement cinq routes `backoffice/mcp/clients`.
- [ ] **Step 5 : commit** — `feat(mcp): le backoffice révise et supprime un client MCP (#652)`.

### Task 18 : ressource des connexions, en lecture seule

**Files :**
- Create : `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcpConnectionResource.php`, `backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpConnectionProvider.php`
- Test : `backend/tests/Mcp/Connection/Presentation/ApiResource/BackofficeMcpConnectionResourceTest.php`

**Interfaces :**
- Consumes : `AdministeredMcpConnectionsInterface::list()` (Task 13).
- Produces :
  - DTO aux propriétés `#[ApiProperty(identifier: false)] string $id`, `string $account`, `string $clientId`, `string $clientName`, `string $clientRegistration`, `string $grantedAt`, `bool $codeExchanged`, `?string $lastUsedAt`, `string $expiresAt`, `string $state` (`active`, `expired` ou `revoked`), `?string $revokedAt`, `?string $revocationCause` ;
  - une seule opération, `GetCollection /backoffice/mcp/connections`, avec `skip_null_values: false`.

- [ ] **Step 1 : tests rouges** :
  - `testSuperSeesEveryConnectionOfEveryAccount` (deux comptes) ;
  - `testNullFieldsAreAlwaysPresent` ;
  - `testAnAccountWithoutSuperGets403AndAnonymous401` ;
  - `testNoItemRouteIsSynthesised` : `/api/backoffice_mcp_connections/{uuid}` répond 404, et le routeur ne compile aucune route de ce nom.
- [ ] **Step 2 : vérifier l'échec** — route absente.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `--filter 'BackofficeMcpConnectionResourceTest|ApiRouteExposureTest|RouterScopeTest'` vert.
- [ ] **Step 5 : commit** — `feat(mcp): le backoffice liste toutes les connexions MCP (#652)`.

---

## Partie frontend

Lire avec `Read`, avant F1, `frontend/src/domain/admin/users/errors/AdminUserError.ts`, ce qui charge `frontend.md` et `frontend-backoffice.md`. Avant F5, lire `frontend/src/presentation/pages/admin/AdminWatchPage.vue`, ce qui charge `frontend-a11y.md`.

### Task F1 : domaine admin MCP

**Files :**
- Create : `frontend/src/domain/admin/mcp/entities/AdminMcpClient.ts`, `frontend/src/domain/admin/mcp/entities/AdminMcpConnection.ts`, `frontend/src/domain/admin/mcp/errors/AdminMcpError.ts`, `frontend/src/domain/admin/mcp/repositories/AdminMcpRepository.ts`

**Interfaces :**
- Produces :
  - `AdminMcpClient { id, clientId, name, redirectUris: readonly string[], registration: 'preregistered' | 'cimd', registeredAt, activeConnections: number }` ;
  - `AdminMcpConnection { id, account, clientId, clientName, clientRegistration, grantedAt, codeExchanged, lastUsedAt: string | null, expiresAt, state: 'active' | 'expired' | 'revoked', revokedAt: string | null, revocationCause: string | null }` ;
  - `AdminMcpClientInput { clientId, name, redirectUris }` et `AdminMcpClientRevision { name, redirectUris }` ;
  - `AdminMcpErrorReason = 'not-found' | 'client-id-taken' | 'declared-by-document' | 'validation' | 'unknown'`, et `class AdminMcpError extends Error { readonly reason }` ;
  - `interface AdminMcpRepository { listClients(); registerClient(input); reviseClient(id, revision); removeClient(id); listConnections() }`.

- [ ] **Step 1 : écrire les types** (aucune logique, aucun test propre ; F2 les exerce).
- [ ] **Step 2 : vérifier** — `make front-lint` et `make front-build` verts.
- [ ] **Step 3 : commit** — `feat(mcp): le domaine admin des clients et connexions MCP (#652)`.

### Task F2 : adaptateur HTTP et injection

**Files :**
- Create : `frontend/src/infrastructure/admin/mcp/HttpAdminMcpRepository.ts`
- Modify : `frontend/src/main.ts`
- Test : `frontend/tests/infrastructure/admin/mcp/HttpAdminMcpRepository.spec.ts`

**Interfaces :**
- Consumes : `BackofficeHttpClient`, `violationsMessage`, `hasProblemType`.
- Produces :
  - `HttpAdminMcpRepository implements AdminMcpRepository`, sur `/api/backoffice/mcp/clients` et `/api/backoffice/mcp/connections` ;
  - gardes `isRegistration()` et `isConnectionState()` (une valeur inconnue fait lever `AdminMcpError('unknown')`, jamais de `as`) ;
  - erreurs : 404 → `not-found` ; 409 avec le type `client-declared-by-document` → `declared-by-document` ; autre 409 → `client-id-taken` ; 422 → `validation`, avec `violationsMessage` ; sinon → `unknown`.
  - `main.ts` fournit `ADMIN_MCP_REPOSITORY` (exporté par `useAdminMcpClients.ts` en F3 ; F2 déclare la clé dans l'adaptateur et F3 la réexporte, pour garder F2 autonome).

- [ ] **Step 1 : tests rouges** — un `fetch` en doublure, un cas par méthode et par erreur listée, plus `testAnUnknownRegistrationIsRefused`.
- [ ] **Step 2 : vérifier l'échec** — `docker compose exec -T frontend npx vitest run tests/infrastructure/admin/mcp/HttpAdminMcpRepository.spec.ts` : module absent.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — même commande, vert ; `make front-lint` vert.
- [ ] **Step 5 : commit** — `feat(mcp): l'adaptateur HTTP du backoffice MCP (#652)`.

### Task F3 : composables

**Files :**
- Create : `frontend/src/application/admin/mcp/useAdminMcpClients.ts`, `frontend/src/application/admin/mcp/useAdminMcpConnections.ts`
- Test : `frontend/tests/application/admin/mcp/useAdminMcpClients.spec.ts`, `frontend/tests/application/admin/mcp/useAdminMcpConnections.spec.ts`

**Interfaces :**
- Produces :
  - `useAdminMcpClients(): { clients, errorMessage, load, register, revise, remove } & AdminLoadState`. Les mutations rendent `Promise<boolean>`, `true` seulement en cas de succès, et rechargent la liste après une suppression (le décompte des connexions a changé).
  - `useAdminMcpConnections(): { connections, errorMessage, load } & AdminLoadState`.
  - Les deux passent par `useAdminLoad` et `visibleMutationError`.

- [ ] **Step 1 : tests rouges** :
  - `isLoaded` reste faux avant la fin de `load()` ;
  - un échec de `remove()` rend `false` et pose `errorMessage` ;
  - un succès de `remove()` relit la liste.
- [ ] **Step 2 : vérifier l'échec** — modules absents.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `npx vitest run tests/application/admin/mcp` vert.
- [ ] **Step 5 : commit** — `feat(mcp): les composables du backoffice MCP (#652)`.

### Task F4 : les libellés de cause de révocation, partagés

**Files :**
- Create : `frontend/src/presentation/mcp/revocationCauseLabelKeys.ts`
- Modify : `frontend/src/presentation/pages/McpConnectionsPage.vue`

**Interfaces :**
- Produces : `export const REVOCATION_CAUSE_LABEL_KEYS: Readonly<Record<string, string>>`. C'est la table actuelle de `McpConnectionsPage.vue` (l. 69-78), déplacée, plus `'client-removed': 'mcpConnections.causes.clientRemoved'`. Elle sert aux deux pages ; la clé `clientRemoved` n'apparaît qu'au journal (D-B), mais elle reste lisible si une ligne la porte un jour.

- [ ] **Step 1 : déplacer la table** ; la page l'importe, sans autre changement.
- [ ] **Step 2 : vérifier** — `npx vitest run tests/presentation/pages/McpConnectionsPage.spec.ts` vert, sans modification du test ; `make front-lint` vert. La clé `clientRemoved` est ajoutée aux locales en F6.
- [ ] **Step 3 : commit** — `refactor(mcp): une seule table des libellés de cause (#652)`.

### Task F5 : page des clients MCP — **accessibilité**

**Files :**
- Create : `frontend/src/presentation/pages/admin/AdminMcpClientsPage.vue`
- Modify : `frontend/src/infrastructure/i18n/locales/fr.json`, `frontend/src/infrastructure/i18n/locales/en.json`
- Test : `frontend/tests/presentation/pages/admin/AdminMcpClientsPage.spec.ts`

**Interfaces :**
- Consumes : `useAdminMcpClients` (F3).
- Produces : clés `admin.mcpClients.*`, `admin.nav.mcpClients`, `seo.adminMcpClients.{title,description}`. Textes FR fixés :
  - `admin.mcpClients.removalWarning` : « Supprimer un client coupe toutes ses connexions : leurs jetons n'ouvrent plus rien, et elles disparaissent de « Mes connexions MCP » de leur personne. » ;
  - `admin.mcpClients.cimdNote` : « Un client CIMD supprimé revient à la prochaine autorisation tant que son domaine reste autorisé. Ses redirections viennent de son document : elles ne se modifient pas ici. » ;
  - `admin.mcpClients.confirmRemove` : « Supprimer le client {name} et couper ses {count} connexions actives ? ».
  - EN : traduction fidèle.

- [ ] **Step 1 : tests rouges** :
  - le tableau a un `<caption>` et des en-têtes `scope="col"` ; la colonne « Mode » affiche `preregistered` ou `cimd` traduit ;
  - l'avertissement de suppression est visible en permanence au-dessus du tableau ;
  - `window.confirm` reçoit le texte avec le nom et le décompte ; un refus n'appelle pas `removeClient` ;
  - le bouton « Modifier » est absent sur une ligne CIMD ;
  - formulaire : `BaseTextInput` pour l'identifiant et le nom, `BaseTextarea` pour les redirections (une par ligne) ; une 422 affiche les violations près des champs ;
  - aucun état vide avant `isLoaded` ;
  - `expectNoAccessibilityViolation(wrapper)` passe.
- [ ] **Step 2 : vérifier l'échec** — module absent.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `npx vitest run tests/presentation/pages/admin/AdminMcpClientsPage.spec.ts tests/presentation/pages/admin/emptyStateNeedsLoaded.spec.ts tests/presentation/pages/admin/noLoadInOnMounted.spec.ts` vert.
- [ ] **Step 5 : commit** — `feat(mcp): la page admin des clients MCP (#652)`.

### Task F6 : page des connexions MCP — **accessibilité**

**Files :**
- Create : `frontend/src/presentation/pages/admin/AdminMcpConnectionsPage.vue`
- Modify : `frontend/src/infrastructure/i18n/locales/fr.json`, `frontend/src/infrastructure/i18n/locales/en.json`
- Test : `frontend/tests/presentation/pages/admin/AdminMcpConnectionsPage.spec.ts`

**Interfaces :**
- Consumes :
  - `useAdminMcpConnections` (F3) ;
  - `REVOCATION_CAUSE_LABEL_KEYS` (F4) ;
  - `formatInstant` (`presentation/format/instant`).
- Produces : clés `admin.mcpConnections.*`, `admin.nav.mcpConnections`, `seo.adminMcpConnections.{title,description}`, `mcpConnections.causes.clientRemoved`. La note fixe FR `admin.mcpConnections.terminatedBound` : « Les connexions terminées affichées sont les 200 plus récentes. ».

- [ ] **Step 1 : tests rouges** :
  - colonnes compte, client (nom, identifiant, mode), accordée le, dernier usage (« jamais » si `null`), échéance, état, cause ;
  - une cause inconnue tombe sur le libellé générique ;
  - la note de borne est visible ;
  - `<caption>` présent ;
  - aucun état vide avant `isLoaded` ;
  - axe sans violation.
- [ ] **Step 2 : vérifier l'échec** — module absent.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `npx vitest run tests/presentation/pages/admin/AdminMcpConnectionsPage.spec.ts` vert.
- [ ] **Step 5 : commit** — `feat(mcp): la page admin des connexions MCP (#652)`.

### Task F7 : routes et navigation

**Files :**
- Modify : `frontend/src/presentation/router/index.ts`, `frontend/src/presentation/layout/AdminLayout.vue`
- Test : `frontend/tests/presentation/router/protectedRoutesCarryNoSecret.spec.ts`, `frontend/tests/presentation/layout/AdminLayout.spec.ts`

**Interfaces :**
- Produces :
  - routes enfants de l'admin `mcp/clients` (`admin-mcp-clients`) et `mcp/connections` (`admin-mcp-connections`), chargées à la demande, avec `meta` `titleKey` et `descriptionKey` ;
  - deux liens de navigation à côté de « Utilisateurs », sur le même motif (`btn-gradient` et `aria-current="page"` sur la route courante) ;
  - deux entrées dans `REVIEWED_PROTECTED_ROUTES` : « aucune requête, aucun fragment : la liste se lit par la session ».

- [ ] **Step 1 : tests rouges** :
  - `AdminLayout.spec.ts` : les deux liens existent, et `aria-current="page"` est posé sur le lien de la route courante (les routes de test sont ajoutées au routeur de stub du spec) ;
  - `protectedRoutesCarryNoSecret.spec.ts` échoue tant que les routes ne sont pas déclarées et revues.
- [ ] **Step 2 : vérifier l'échec.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : vérifier** — `make front-test`, `make front-lint` et `make front-build` verts.
- [ ] **Step 5 : commit** — `feat(mcp): le backoffice MCP dans la navigation admin (#652)`.

---

### Task 19 : règle, glossaire, notes de release, vérifications de fin

**Files :**
- Create : `.claude/rules/mcp-backoffice.md`
- Modify : `.claude/rules/mcp.md` (index : dix règles), `.claude/rules/security-authentication-audit.md` (trois événements, `AuditOrigin`), `.claude/CLAUDE.md` (une ligne au tableau des règles), `CONTEXT.md`, `tasks/todo.md`

Cette tâche dépasse quatre fichiers, mais ne contient que de la documentation, sans test : elle est coupée en deux commits.

- [ ] **Step 1 : règle `mcp-backoffice.md`.**
  - En-tête `paths:` :
    - `backend/src/Mcp/Connection/Application/McpClientAdministrator*.php`
    - `backend/src/Mcp/Connection/Application/AdministeredMcpConnection*.php`
    - `backend/src/Mcp/Connection/Application/McpConnectionStatus.php`
    - `backend/src/Mcp/Connection/Presentation/ApiResource/BackofficeMcp*.php`
    - `backend/src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcp*.php`
    - `backend/src/Mcp/Connection/Infrastructure/Validator/ValidMcpClientFields*.php`
    - `frontend/src/presentation/pages/admin/AdminMcp*.vue`
    - `frontend/src/**/admin/mcp/**`
  - Contenu :
    - D-A à D-I en une ligne chacune ;
    - l'ordre des verrous (client puis connexion, `FOR SHARE` au consentement, `FOR UPDATE` à la suppression) ;
    - la cause `client-removed`, présente au journal seulement ;
    - les limites acceptées ci-dessous.
- [ ] **Step 2 : index et audit.**
  - `mcp.md` : une ligne d'index vers `mcp-backoffice.md`, le fichier restant sous 50 000 octets.
  - `security-authentication-audit.md` : les trois événements, leurs contextes, l'acteur `console`, et la cause `client-removed` hors `RevocationCause`.
  - `.claude/CLAUDE.md` : la ligne du tableau.
  - Vérifier par `bash tools/check-claude-rules.sh` : vert.
- [ ] **Step 3 : commit** — `docs(mcp): règle du backoffice MCP et événements d'audit (#652)`.
- [ ] **Step 4 : `CONTEXT.md`** — sous **Client pré-enregistré**, remplacer « futur backoffice » par « backoffice `/admin/mcp/clients` ». Ajouter l'entrée **Suppression d'un client MCP** : « retrait d'un client du site par un compte `ROLE_SUPER` ; ses connexions sont coupées et effacées, l'audit en garde la trace ; un client CIMD revient à la prochaine autorisation tant que son domaine est autorisé ».
- [ ] **Step 5 : `tasks/todo.md`.**
  - Cocher la Tâche 13 et la ligne 109 (audit de la suppression en cascade).
  - Notes de release : **aucune migration**, **aucune variable d'environnement** ; nouvelles routes `ROLE_SUPER` `/api/backoffice/mcp/clients` (5 opérations) et `/api/backoffice/mcp/connections` ; trois événements d'audit ; bornes nouvelles d'une redirection (2 048 octets, 50 paramètres) et d'un client (1 à 10 redirections). Un client existant hors de ces bornes reste lisible, mais ne se révise qu'en les respectant. Avant la livraison, vérifier en préprod par une requête en lecture seule qu'aucune ligne ne les dépasse.
  - Report de la borne de `client_id` : déjà soldé (preuves de la section « Reports déjà soldés »).
- [ ] **Step 6 : vérifications de fin, sorties lues** — `make back-quality`, `make back-test`, `make front-test`, `make front-lint` et `make front-build` tous verts ; `git status` propre hors des fichiers de la tâche.
- [ ] **Step 7 : commit** — `docs(mcp): glossaire et notes de release du backoffice MCP (#652)`. La PR vise `feature/379-mcp-paliers`. L'issue #652 se ferme à la main après la fusion : `Closes` ne ferme rien hors de la branche par défaut.

## Limites acceptées (à recopier dans la règle)

- **Une connexion coupée par la suppression de son client disparaît de « Mes connexions MCP »** de sa personne ; seul le journal d'audit en garde la trace (D-A).
- **Un client CIMD supprimé revient** à la prochaine autorisation d'une personne de confiance, tant que son domaine est dans `app.mcp.cimd.allowed_domains`. Supprimer n'est pas bannir ; bannir passe par le retrait du domaine, hors périmètre.
- **Retirer un domaine de la liste n'arrête pas les connexions déjà accordées** (ADR 0006, limite acceptée par T12) ; le backoffice permet de les couper en supprimant le client.
- **La page des connexions ne dit pas si elle tronque** les terminées : elle annonce la borne de 200 (D-D).
- **`mcp-client-revised` ne consigne pas les redirections** (D-G).
- **Un client existant hors des bornes nouvelles** reste en service ; seule sa révision exige de les respecter.

## Auto-relecture du plan

- **Couverture du ticket** :
  - liste complète des connexions (Tasks 7B, 13, 18, F6) ;
  - 403 sans `ROLE_SUPER` (Tasks 16, 18) ;
  - CRUD des clients pré-enregistrés (Tasks 11, 16, 17A, 17B, F5) ;
  - plus rien d'ouvert après la suppression (Task 17B) ;
  - pages Vitest et accessibles (F5, F6, F7) ;
  - cinq cibles `make` (Task 19).
  - Reports de T8 : bornes (Tasks 2, 3), `client_id` (déjà soldé), course de la clé étrangère (Task 8), regex (Task 1), sortie (Task 4).
  - Reports de T12 : lister et supprimer un CIMD (Tasks 16, 17B), audit (Tasks 9, 10, 11), retrait d'un domaine documenté (Task 19).
- **Cohérence des types** : `findOneByClientIdForShare` (6) → `ConsentService` (8) ; `lockActiveOfClient` → `list<RevokedConnection>` (7A) → `RevokedConnectionAudit::record` (11) ; `AuditOrigin` (9) → `register(…, AuditOrigin)` (10) → `McpClientAdministrator::register` (11) ; `McpConnectionStatus` (12) → `AdministeredMcpConnectionView` (13) → DTO (18) ; `AdminMcpRepository` (F1) → adaptateur (F2) → composables (F3) → pages (F5, F6).
- **Taille des tâches** : toutes à quatre fichiers au plus, tests compris. La Task 19 (documentation seule, six fichiers) est coupée en deux commits.
- **Review Focus** : les cinq lignes ont chacune leur test nommé, dans la tâche qui possède le code (8, 2, 17B, 17B, 16).
