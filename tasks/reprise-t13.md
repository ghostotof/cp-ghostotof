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
  - **Prochaine action** : lancer la Task 3 (`grep -n '### Task 3' tasks/plan-t13-backoffice-mcp.md`
    pour ses lignes), même brief que la Task 2.
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
- Point fixe de relecture : aucun encore (la première série se relira depuis `bd61b215`).
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

- (Task 2, faible, confiance moyenne) `RedirectUri::matches()` appelle `Uri::parse($requested)` sans
  borne de longueur ; seul appelant `McpClient.php:184`, valeur venue de `GET /authorize`, déjà bornée
  par la taille d'en-tête de nginx (~8 Ko), sans `parse_str()`. À reprendre dans la description de la
  PR, ou à borner (retour `false` si `strlen > MAX_LENGTH`) si la relecture S1 le juge utile.

## Interdits des briefs

- Jamais lire `.env.local`, `.env.test.local` ni aucun fichier de secret.
- Jamais relire à la main une règle `.claude/rules/mcp*.md` (chargées d'office, ~180 Ko) ; un seul
  `Read` par zone, le reste en `sed -n` / `grep -n` ; jamais un plan entier ; sorties coupées.
- Jamais `git checkout` / `switch` / `stash` ; un commit par tâche, fichiers nommés.
- PHPStan, Rector, PHPUnit dans le conteneur en `-u dev`.
