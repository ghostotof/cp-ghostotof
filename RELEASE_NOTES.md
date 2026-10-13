# v0.25.0 — Verrous, groupes de traduction et rôles PostgreSQL

Release de durcissement, suite de v0.24.0 (lot spec-0004 et ses suivis, 30 tickets). Toutes les écritures du
backoffice passent sous verrou et ne répondent plus 200 sur une ligne disparue, la base refuse un groupe de
traduction réparti sur deux catégories, les seeds deviennent atomiques, le login ferme deux écarts de temps,
et **l'application ne tourne plus sous le superutilisateur PostgreSQL** (#575). **Une migration**,
déploiement standard **sans fenêtre de maintenance**. **Un secret nouveau par environnement**
(`<env>-backend-migration-database-url`), **à créer avant le déploiement** par le wizard des rôles.
**PostgreSQL redémarre une fois** (keepalives TCP, #279).

## Prérequis humains — dans cet ordre

1. **Préprod, avant de pousser cette branche** : lancer le wizard des rôles PostgreSQL dans un terminal
   séparé (jamais via `!`), depuis la racine d'un checkout à jour :
   `~/dev/cp-ghostotof-wizards/575-roles-postgres.sh preprod`. Il crée `cpg_migrator`, `cpg_app` et
   `cpg_reader` (Job `postgres-roles`), publie les deux URL dans Secret Manager, synchronise et redémarre
   `backend` + `worker`. Sans lui, `deploy-preprod` s'arrête fail-closed sur le secret manquant.
2. **Prod, juste avant de fusionner la PR de release dans `main`** : `… 575-roles-postgres.sh prod`.
   Sans lui, `deploy-prod` s'arrête fail-closed au Job de migration ; l'ancienne release continue de servir.
3. Entre la bascule prod et la fusion, un hotfix parti de `main` échouerait à sa migration (ancien
   `migrate-job.yaml`) : appliquer le retour arrière affiché par le wizard le temps du hotfix.

## Comportements qui changent

| Cas | Avant | Après | Issue |
|---|---|---|---|
| Perdant de deux `DELETE` simultanés d'une même entrée (8 contenus, veille, technologies, comptes) | 204, et pour un compte deux lignes d'audit `user-deleted` | **404**, une seule ligne d'audit | #499, #545, #552 |
| `PUT` sur une entrée supprimée entre son chargement et son écriture (veille, technologie, rôles d'un compte) | 200 sur une ligne disparue (`role-changed` fantôme pour un compte) | **404**, aucun audit | #499, #545, #552 |
| Attente d'un verrou d'écriture backoffice (suppression, veille, technologie, administration de compte) | pas de verrou | **500 nommé** au-delà de 5 s | #499, #545, #551 |
| Super-admin rétrogradé ou supprimé pendant sa propre requête de suppression / changement de rôle | aboutissait | **403** `/errors/acting-account-no-longer-super` (erreur générique côté interface) | #551 |
| Deux super-admins qui se suppriment ou se rétrogradent l'un l'autre | pouvait ne laisser aucun `ROLE_SUPER` | la garde compte en SQL les **autres super-admins capables de se connecter** : 409 | #551 |
| `PUT` d'un contenu localisé qui change la langue (8 contextes) | 200, langue ignorée | **409** `/errors/locale-immutable` (sans effet sur l'interface, champ déjà désactivé) | #582 |
| `PUT` d'une carte « moi » qui change la catégorie | 200, catégorie ignorée ; depuis l'interface, la carte était détachée de sa traduction | **409** `/errors/me-card-category-immutable` ; catégorie désactivée en modification | #578 |
| Création, rattachement ou réordonnancement touchant un groupe de cartes « moi » réparti sur deux catégories | 500 brut (index unique) ou refus au COMMIT | **500 nommé avant écriture** ; « Détacher » répare | #501, #561 |
| Réordonnancement d'un périmètre contenant un groupe de traduction divergent | réparé en silence | **500 nommé avant écriture** (groupe, type, positions dans le journal) | #503 |
| Seed (`app:*:seed`) interrompu | contenu partiel, gardé par la relance sans `--force` (Job vert trompeur) | **rien n'est écrit** ; la relance repeuple | #567 |
| `app:*:seed --force` | purge hors verrou | purge et recréation sous le verrou d'ordre : une écriture backoffice concurrente attend (quelques centaines de ms ; jusqu'à 2 × 5 s pour About et Quality) | #567 |
| `app:about:seed` sans `--force`, base sans carte À propos | réécrivait les textes de section édités | les **conserve** (crée seulement ceux qui manquent) ; message de sortie « créés / conservés / remis » | #568 |
| Login avec un mot de passe de plus de 4096 octets | aucun hachage pour un compte existant (≈ 270 ms plus rapide qu'un inconnu) | refus avant tout chargement de compte, **même 401**, en quelques ms ; `login-failed` sans `userId` | #588 |
| Login raté sur une invitation dont le mot de passe a été posé au backoffice | deux dérivations (écart de temps) | une seule, comme les autres comptes | #560 |
| Hasher des comptes | `auto` (Sodium en secondaire : deux dérivations sur un hachage à rehacher) | **`bcrypt` coût 13 épinglé** ; aucun rehachage déclenché (tous les hachages sont `$2y$13$`, vérifié en préprod et en prod) | #590 |

Pendant le déploiement, un backoffice resté en cache affiche « Ce contenu a déjà une version dans cette
langue » sur le 409 de catégorie, au lieu de détacher la carte : recharger la page.

## Migration

- `Version20261012160000` (#501) : la fonction du trigger différé reçoit sa colonne de périmètre ; sur
  `about_me_card`, le trigger écoute aussi `category` et lève `23T91`. Aucune donnée écrite. Les groupes déjà
  répartis sur deux catégories sont signalés en `warning` sans être corrigés. **Lire les `warning` du Job de
  migration** : s'il y en a, au backoffice, « Modifier » la carte égarée, « Version de » → « — aucune — »,
  enregistrer (ou la supprimer puis la recréer).
- Le Job de migration tourne désormais sous **`cpg_migrator`** (propriétaire), via le Secret
  `backend-migration-secrets` (#575).

## PostgreSQL et Kubernetes

- **Rôles (#575)** : l'application (FPM, worker, CronJobs, Jobs de seed et d'exploitation) tourne sous
  `cpg_app` (lecture et écriture de données seulement : ne peut plus désactiver un trigger, faire du DDL ni
  lire les fichiers du serveur) ; les `psql` humains du runbook sous `cpg_reader` (lecture seule). Nouveau Job
  hors kustomize `postgres-roles` (rejouable : `… <env> refresh` après tout objet créé à la main ; `rotate`
  pour changer les mots de passe). Suivi : #593 (restreindre le superutilisateur au socket local).
- **Keepalives TCP (#279)** : `postgres.yaml` change, **PostgreSQL redémarre** (`Recreate`) : courte coupure
  de l'API pendant son redémarrage.
- Nouvel `ExternalSecret` `backend-migration-secrets` dans les deux overlays ; `make get-secret` le connaît.

## Sous le capot

- Squelette des verrous : ferme l'EntityManager sur un COMMIT refusé (#547) ou après une exception qui suit
  une écriture (#549), relecture et détachement communs (`RereadUnderLock`, #560), réentrance bornée du verrou
  d'ordre pour les seeds (`withLocks()`, #567). Une entité supprimée ou disparue ne peut plus être
  réinsérée en silence (#548, #557, #569). Connaissance des refus `23T90`/`23T91` dans une classe dédiée (#506).
- Dépendances : groupes Dependabot backend (PHPStan 2.3, Rector 2.7, Symfony 8.1.8) et frontend (#441, #377).
- Règles et runbook : pièges des migrations sur table à trigger différé (#505, #585), seeds (#562, #566),
  groupes répartis au backoffice (#573), `AboutSettings` sans verrou par décision (#553).

## À vérifier en préprod

- Wizard des rôles : étapes vertes, `0` erreur `42501` après la sonde, checklist humaine (page publique,
  login raté, écriture backoffice avec réordonnancement).
- `kubectl -n preprod rollout status deployment/postgres`, puis la commande `SHOW` **par TCP** du README,
  § « Sessions fantômes PostgreSQL » : attendu 60 / 10 / 3 / 90000 (#279 ; par socket Unix, `SHOW` lit 0).
- Journaux du Job de migration : exécuté sous `cpg_migrator`, `warning` de `Version20261012160000` lus.
- Smoke tests et audit verts.

## À vérifier en prod

- Wizard des rôles juste avant la fusion, mêmes contrôles qu'en préprod.
- `rollout status` de `postgres`, `backend` et `worker` ; journaux du Job de migration (`warning` lus).
- Après validation : désactiver dans Secret Manager les anciennes versions de `prod-backend-database-url`,
  sauf la plus récente (retour arrière), puis faire de même en préprod.
