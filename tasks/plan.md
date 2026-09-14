# Plan — Clés primaires UUID (spec 0003) puis ordre des contenus par glisser-déposer (spec 0004)

## Overview

Deux specs, deux releases, dans cet ordre et pas autrement :

- **Phase A — spec 0003** (`.claude/specs/0003-uuid-primary-keys.md`) : toute entité porte une clé
  primaire UUID v7 posée au constructeur. Quatorze entités, une clé étrangère, des migrations
  irréversibles sur des tables peuplées en production. Livrée **seule** en `v0.11.0`, après passage
  préprod vérifié.
- **Phase B — spec 0004** (`.claude/specs/0004-content-ordering.md`) : glisser-déposer natif avec
  alternative clavier, bouton « Enregistrer l'ordre », champ `Position` supprimé, lien FR/EN explicite
  en base (`translation_group`) pour que l'ordre suive quelle que soit la langue, endpoint d'ordre à
  ensemble exact partagé par neuf ressources. Livrée en `v0.12.0`, construite sur des UUID.

Le plan précédent (spec 0002, assistant de traduction, entièrement livré au 2026-09-14) est remplacé
par celui-ci ; il reste lisible dans l'historique git de ce fichier.

**Tracker** : GitHub Issues (`docs/agents/issue-tracker.md`), labels `spec-0003` et `spec-0004`
(`gh issue list --label spec-0003`). Chaque tâche est une issue qui porte sa description détaillée
(extraits de code, SQL de migration), ses critères d'acceptation, ses vérifications, ses dépendances
et ses fichiers ; cette liste n'est qu'un index ordonné. Git flow : une branche `feature/uuid-<n>-<slug>`
par tâche depuis `develop`, **une PR par tâche, empilée** (cf. mémoire « Une PR par tâche, stack
empilée » : retargeter avant de supprimer une branche).

## Architecture Decisions

Reprises des specs (§2), rappelées ici parce qu'elles décident de l'ordre :

- **Une migration par tâche backend, pas une seule** — écart avec la rédaction initiale de la spec
  0003 (D4), amendée : la CI construit la base de test par `doctrine:migrations:migrate`, donc une
  tâche ne peut être verte que si elle embarque la migration de ses propres tables. Cinq migrations
  irréversibles jouées d'un bloc à la release valent exactement une seule pour le déploiement.
- **Un contexte = une tâche verticale et verte** (Tasks 2–6) : entité, repository, `Administrator`,
  ressource, migration, tests. Découper par couche laisserait la suite rouge entre deux PR (un DTO
  `?int $id` face à un `getId(): Uuid` ne compile pas).
- **`Security/User` en premier** (Task 2) : le seul contexte avec une clé étrangère et un message
  Messenger (`SendAccountInvitationMessage.userId` passe d'entier à chaîne, oubli de la spec
  consigné dans son journal). C'est là que le risque se lève ; les quatre suivants sont mécaniques.
- **`uriVariableInt()` coexiste avec `uriVariableUuid()`** jusqu'à la Task 6, qui la supprime avec
  son dernier consommateur.
- **Le placeholder UUID de `ApiRouteExposureTest` dès la Task 1** : avec les `requirements` UUID,
  `/api/backoffice/x/1` serait un 404 du routeur avant le firewall et le test perdrait sa portée.
  Posé avant que le premier `requirements` n'arrive, il est inoffensif avec des ids entiers.
- **Le frontend après tout le backend** (Task 7) : un seul contrat, un seul changement de types.
- **La phase B ne démarre pas avant `v0.11.0` en production** : ses issues seront ouvertes à ce
  moment-là, sur le code réellement migré.

## Task List — Phase A (spec 0003, `v0.11.0`)

### Socle
- [x] Task 1 — `symfony/uid` direct, `uriVariableUuid()`, placeholder UUID de `ApiRouteExposureTest` — [#120](https://github.com/ghostotof/cp-ghostotof/issues/120)

### Backend, un contexte par PR (chacune avec sa migration)
- [x] Task 2 — `Security/User` : `CpgUser`, `PasswordSetupToken` (FK), message Messenger, 4 ressources — [#121](https://github.com/ghostotof/cp-ghostotof/issues/121)
- [x] Task 3 — `Experience` + `Quality` (comparaison d'ids par `equals()` dans l'unicité de nom) — [#122](https://github.com/ghostotof/cp-ghostotof/issues/122)
- [x] Task 4 — `About` : settings, cartes site, cartes moi — [#123](https://github.com/ghostotof/cp-ghostotof/issues/123)
- [x] Task 5 — `Contribution`, `Incident`, `AnonymousCv`, `CaseStudy` — [#124](https://github.com/ghostotof/cp-ghostotof/issues/124)
- [x] Task 6 — `Watch` (`WatchedProduct`, `WatchSnapshot`) + suppression de `uriVariableInt()` — [#125](https://github.com/ghostotof/cp-ghostotof/issues/125)

### Checkpoint A1 — contrat d'API figé
- [x] `make back-test` et `make back-quality` verts ; `grep -rn 'uriVariableInt\|?int \$id\|getId(): ?int' backend/src backend/tests` vide
- [x] `ApiRouteExposureTest` et `AccessControlAnchoringTest` verts, avec pour seule modification le placeholder
- [x] `doctrine:schema:validate` `[OK]` sur une base de dev migrée depuis un état seedé + une invitation ; ordre `ORDER BY id` conservé sur chaque table
- [x] `debug:router` : `requirements` sur chaque item, aucune route synthétisée

### Frontend
- [ ] Task 7 — `id: string` sur entités, repositories, composables, pages, 26 specs — [#126](https://github.com/ghostotof/cp-ghostotof/issues/126)

### Checkpoint A2 — parcours complet
- [ ] `make front-test`, `make front-lint`, `make front-build` verts
- [ ] Dans un vrai navigateur, stack dev migrée : lister / éditer / supprimer sur une ressource admin, URLs en UUID
- [ ] Revue avec Christophe avant la release : la migration est irréversible

### Documentation et release
- [ ] Task 8 — `CLAUDE.md`, spec, notes de release ; préprod vérifiée (rollout, Job `migrate`, backoffice, invitation, endpoints publics) **puis** prod — [#127](https://github.com/ghostotof/cp-ghostotof/issues/127)

### Checkpoint A3 — `v0.11.0` en production
- [ ] Pipeline verte de bout en bout, release GitHub publiée avec le corps des notes
- [ ] `messenger:failed:show` vide avant et après le déploiement prod
- [ ] Feu vert pour ouvrir les issues de la phase B

## Task List — Phase B (spec 0004, `v0.12.0`) — index prévisionnel, issues à ouvrir après le checkpoint A3

Ordre de construction de la spec (§3), une PR par tâche, label `spec-0004` :

### Backend
- [ ] Task B1 — Groupes de traduction : `translationGroup` sur les 8 entités localisées, interface `Orderable`, migration réversible avec appariement, seeds, `Assert\Choice` sur `Locale::cases()`
- [ ] Task B2 — Écriture : `position` retirée des DTO/`create`/`update`, `translationGroup` nullable, héritage de position, `TranslationAlreadyExistsException` (409)
- [ ] Task B3 — `OrderAssigner` (`Portfolio/Shared`), exceptions 422, `reorder()` sur les 9 `Administrator` + tests unitaires
- [ ] Task B4 — Les 9 ressources `Backoffice<X>OrderResource` (`PUT …/order`, 204) + tests fonctionnels (ordre relu sur backoffice **et** endpoints publics FR/EN)

### Checkpoint B1 — contrat d'ordre figé et cloisonné
- [ ] `ApiRouteExposureTest` inchangé et vert ; `debug:router | grep /order` : neuf routes, aucune synthétisée
- [ ] Un réordonnancement backoffice se lit sur `/api/<x>/fr` **et** `/api/<x>/en`

### Frontend
- [ ] Task B5 — Briques partagées : `domain/admin/shared/ordering/`, `useOrderDraft`, `useRowDragAndDrop`, `OrderHandle.vue`, `OrderToolbar.vue`, clés `admin.order.*`
- [ ] Task B6 — Page Incidents complète : tableau groupé, « Version de », « Créer la version », assistant rattaché, garde de route, axe

### Checkpoint B2 — première tranche verticale dans un vrai navigateur
- [ ] Glisser, ↑/↓ au clavier, enregistrer, 422 obsolète, « Créer la version EN », assistant → groupe
- [ ] Revue avec Christophe avant de dérouler les autres pages

### Les autres pages, une PR chacune
- [ ] Task B7 — Contributions
- [ ] Task B8 — CV sans identité
- [ ] Task B9 — Qualité (principes, traits ; le tableau affiche toutes les langues)
- [ ] Task B10 — À propos (cartes site ; cartes moi par catégorie)
- [ ] Task B11 — Watch (ids, sans groupe)
- [ ] Task B12 — Documentation (`CLAUDE.md`, spec 0002 amendée « le groupe est recopié »), release `v0.12.0`, préprod vérifiée FR et EN

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| La migration de `cpg_user` échoue en prod (contrainte, nom de PK différent de `<table>_pkey`) | High (déploiement bloqué, données intactes : rien n'est supprimé avant que la nouvelle colonne soit remplie) | Noms de contraintes vérifiés par `\d` en dev (Task 2), passage préprod avec logs du Job `migrate` (Task 8) ; l'ordre des `addSql` remplit puis remappe la FK avant toute suppression |
| Un `SendAccountInvitationMessage` en file au déploiement, avec un `userId` entier | Medium (une invitation perdue, à renvoyer) | `messenger:failed:show` vide avant de déployer, aucune invitation pendant la fenêtre (Task 8) |
| `===` entre deux `Uuid` laissé quelque part (comparaison d'objets) | Medium (un contrôle d'unicité ou d'auto-modification devient toujours vrai/faux) | Les trois sites connus sont nommés dans les issues #121 et #122 ; les tests « se supprimer soi-même → 409 » et « renommer sans changer de nom → 200 » sont la garde ; `grep -rn "=== \$.*getId()\|getId() ===" backend/src` au checkpoint A1 |
| `uuidv7(interval)` absent (image Postgres < 18 en dev ou en CI) | Medium | `POSTGRES_TAG=18.6-alpine` partout (dev, CI, k8s) ; la Task 2 échoue immédiatement en dev si ce n'est pas le cas |
| `ApiRouteExposureTest` perd sa portée sans qu'on le voie (404 routeur pris pour un refus) | High (le garde-fou de sécurité devient muet) | Placeholder UUID dès la Task 1, et le test assert des 401/403 précis, jamais « non 200 » |
| Rector réécrit les constructeurs ou le mapping | Low | `composer rector` à chaque PR ; `rector.php` skip ciblé si nécessaire, jamais de baseline PHPStan |
| Phase B : appariement de migration incorrect sur des données éditées en prod | Low (aucune perte, un lien faux) | Appariement seulement sur couple unique, sinon groupe propre ; « Version de » permet de corriger depuis l'admin |

## Open Questions

1. **Nom de la contrainte de clé primaire en prod** : PostgreSQL nomme `<table>_pkey` par défaut et
   Doctrine ne la renomme pas, mais la Task 2 le vérifie sur la base de dev (`\d cpg_user`) avant
   d'écrire la migration ; si un nom diffère, le `DROP CONSTRAINT` le reprend.
2. **Une seule migration ou une par tâche** : tranché (une par tâche, voir Architecture Decisions),
   spec 0003 amendée.
3. **Phase B, tables des cartes « moi »** : un tableau par catégorie (spec D8) ; à confirmer à
   l'ouverture de la Task B10 si la page À propos reste lisible avec trois tableaux plus celui des
   cartes site.

## Verification (avant de considérer ce plan prêt)

- [x] Chaque tâche de la phase A a des critères d'acceptation (dans son issue GitHub)
- [x] Chaque tâche de la phase A a une étape de vérification (dans son issue GitHub)
- [x] Dépendances identifiées et ordonnées (1 → 2 → 3 → 4 → 5 → 6 → 7 → 8, empilées)
- [x] Tâches enregistrées dans le tracker désigné (GitHub Issues #120–#127, label `spec-0003`)
- [ ] Aucune tâche ne touche plus de ~5 fichiers de logique — **faux ici et assumé** : une tâche = un contexte vertical, donc 10 à 25 fichiers mécaniques ; la Task 2 est la seule à contenir de la logique (FK, message, `equals()`)
- [x] Checkpoints entre les phases
- [ ] Revue humaine du plan (Christophe) — ordre et découpage
