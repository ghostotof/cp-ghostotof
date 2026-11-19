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
  - **Prochaine action** : contrôler C1, la relire (`/code-review medium` sur `diff 8a44a8a0...HEAD`), puis Task 5.
  - Brief : exiger explicitement `superpowers:verification-before-completion` (fait à la Task 2, suivi).
- Séries de relecture prévues (`/code-review medium` sur le seul diff de la série ; audit sécurité en
  plus pour S2, S3 et S5, qui touchent la révocation, la concurrence ou les routes protégées) :
  S1 = Tasks 1-4 (bornes et hygiène de T8) ; S2 = 5-8 ; S3 = 9-11 ; S4 = 12-15 ; S5 = 16-18 ;
  S6 = F1-F7 ; puis Task 19 et la relecture complète avant la PR.
- Risques relevés au contrôle : `FlushInterleaving` existe (`backend/tests/Shared/`) ; **aucun
  compteur de requêtes ni sonde `pg_locks`** dans `backend/tests` — Task 6 (`FOR SHARE`) et Task 7A
  (sans N+1) devront en construire un ; `CompiledExceptionConfig` (Task 15) à vérifier.
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
- (Task 4, question ouverte à l'utilisateur) `$io->listing()` n'échappe pas ; inatteignable (`RedirectUri`
  refuse `<`). Recommandation : ne pas échapper, le noter dans la PR.

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais un plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
