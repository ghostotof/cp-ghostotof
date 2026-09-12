# Plan — Suite de l'ADR 0003 (paliers d'accès)

## Overview

Le socle de l'ADR 0003 (D1, D2, D4, D7 — rôle `ROLE_TRUSTED`, `role_hierarchy`,
bascule `access_control` CV/`me`, octroi nominatif via l'invitation backoffice)
est **déjà implémenté et mergé** dans `develop` (PR #41, 2026-09-12). Ce plan
couvre ce qui reste : D6 (le mécanisme d'accès au palier de base, sans
identifiants), D5 (le contenu que ce palier doit porter) et l'état d'auth à
trois cas côté frontend.

**Tracker** : ce projet désigne GitHub Issues (`docs/agents/issue-tracker.md`)
— chaque tâche ci-dessous est un issue GitHub, pas une ligne dans
`tasks/todo.md`. La liste ci-dessous est un index ordonné vers ces issues.

## Architecture Decisions

- **Ordre retenu : D6 (mécanisme) avant D5 (contenu), mais la première tranche
  de D5 arrive immédiatement après** — livrer le bouton sans rien derrière
  serait exactement le défaut que l'ADR nomme (« un bouton qui ne donne
  rien »). Le checkpoint 2 est le premier moment où le parcours est complet
  de bout en bout.
- **D5 est scindé en 3 tâches de contenu indépendantes**, dans l'ordre de
  valeur donné par l'ADR : études de cas techniques d'abord (la plus
  actionnable), puis CV sans identité, puis parcours anonymisé — ce dernier
  n'est **pas** encore découpé en tâche (voir Open Questions), l'ADR le
  qualifie lui-même de « plus délicat » du lot.
- **Le texte réel des études de cas et du CV anonymisé reste la matière de
  Christophe**, pas quelque chose à inventer — même caveat que pour la
  rubrique Contributions (cf. mémoire projet). Les tâches d'ingénierie ne
  livrent que le squelette (entité, endpoint, backoffice, seed vide/exemple) ;
  la saisie réelle est un travail éditorial séparé, hors scope ingénieur.

## Open Questions

1. **Mécanique exacte d'émission du jeton D6** : `lexik_jwt_authentication`
   émet aujourd'hui un JWT à partir d'un `CpgUser` authentifié via le
   firewall `login`. D6 demande un jeton **sans compte matérialisé en
   base** — probable piste : un objet `UserInterface` léger, non-Doctrine,
   passé directement à `lexik_jwt_authentication.jwt_manager` (service
   `JWTTokenManagerInterface::create()`), en dehors du firewall `login`
   existant. À valider avant d'attaquer la tâche 2 — candidat pour une
   passe `doubt-driven-development` tant l'approche n'a pas de précédent
   dans ce code.
2. **Nom de la ressource/route D6** — proposé dans ce plan :
   `POST /api/account/base-access`, à confirmer (cohérence avec
   `/api/account/password-setup/*` déjà existant).
3. **« Parcours anonymisé » (3e contenu D5)** — pas encore découpé en
   tâche : la question du niveau de détail acceptable (dates, secteurs,
   tailles d'équipe) sans permettre la ré-identification par recoupement
   est à trancher par Christophe avant toute décomposition.
4. **`CreateCpgUserCommand::ALLOWED_ROLES`** reste `[ROLE_SUPER]` — reste
   correct une fois D6 en place (ROLE_TRUSTED reste nominatif via
   l'invitation, jamais via CLI) ? Tranché en tâche 12 plutôt que supposé.

## Task List

Chaque tâche est un issue GitHub labellé `adr-0003` (`gh issue list --label adr-0003`).

### Phase 1 — D6 : mécanisme d'accès au palier de base
- [x] Task 1 — Rate limiter dédié à l'endpoint d'accès de base — [#46](https://github.com/ghostotof/cp-ghostotof/issues/46) (`feature/adr0003-base-access-rate-limiter`, commit `54bb642`)
- [x] Task 2 — `POST /api/account/base-access` : jeton `ROLE_USER` sans compte — [#47](https://github.com/ghostotof/cp-ghostotof/issues/47) (`feature/adr0003-base-access-rate-limiter`, commit `04c1b2c`)
- [x] Task 3 — Test de régression : le jeton n'ouvre jamais `/api/cv`/`/api/me` — [#48](https://github.com/ghostotof/cp-ghostotof/issues/48) (`feature/adr0003-base-access-regression-test`, commit `b7ec52a`)

### Checkpoint 1
- [ ] Rate limiter actif et testé
- [ ] Endpoint pose un cookie BEARER exploitable par le frontend
- [ ] `/api/cv` et `/api/me` refusent toujours ce jeton (403)
- [ ] Suite complète (backend) verte, PHPStan max + Rector verts

### Phase 2 — D5 (1/3) : études de cas techniques — première tranche verticale complète
- [x] Task 4 — Bounded context `Portfolio/CaseStudy` (entité + migration + repository) — [#49](https://github.com/ghostotof/cp-ghostotof/issues/49) (`feature/adr0003-case-study-entity`, commit `ffd4590`)
- [x] Task 5 — Ressource publique `GET /api/case-studies/{locale}` (`ROLE_USER`) — [#50](https://github.com/ghostotof/cp-ghostotof/issues/50) (`feature/adr0003-case-study-public-resource`, commit `4a25438`)
- [ ] Task 6 — Ressource backoffice CRUD (`ROLE_SUPER`) — [#51](https://github.com/ghostotof/cp-ghostotof/issues/51)
- [ ] Task 7 — Commande `app:case-studies:seed` (contenu placeholder, `GuardsExistingContent`) — [#52](https://github.com/ghostotof/cp-ghostotof/issues/52)
- [ ] Task 8 — Tranche frontend (page + composable + garde d'accès palier de base) — [#53](https://github.com/ghostotof/cp-ghostotof/issues/53)

### Checkpoint 2 — parcours complet de bout en bout
- [ ] Un visiteur anonyme obtient le jeton (Phase 1) et atteint une vraie page de contenu (Phase 2)
- [ ] axe-core sur la nouvelle page, suite frontend + backend vertes
- [ ] Revue avec Christophe avant de poursuivre

### Phase 3 — État d'auth à trois cas (frontend)
- [ ] Task 9 — `hasRole`/garde de routeur/`AppHeader` : 3e cas (palier de base), CTA vers Task 2 — [#54](https://github.com/ghostotof/cp-ghostotof/issues/54)

### Phase 4 — D5, contenus restants (priorité plus basse, indépendants)
- [ ] Task 10 — CV sans identité (même forme que Task 4–7, second type de contenu) — [#55](https://github.com/ghostotof/cp-ghostotof/issues/55)
- [ ] (non découpé) Parcours anonymisé — voir Open Questions §3

### Phase 5 — Housekeeping
- [ ] Task 12 — Trancher `CreateCpgUserCommand::ALLOWED_ROLES` (voir Open Questions §4) — [#56](https://github.com/ghostotof/cp-ghostotof/issues/56)

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Mécanique JWT sans compte (Task 2) plus complexe que prévu, aucun précédent dans le code | Medium | Résoudre l'Open Question #1 avant d'écrire du code ; envisager une passe doubt-driven-development |
| Jeton de base mal scoppé accorde plus que `ROLE_USER` par erreur | High (sécurité) | Task 3 écrite et rouge **avant** Task 2 (même discipline que la PR #41) |
| Contenu Task 7/10 jamais fourni par Christophe (précédent : Contributions à 1/3) | Low (produit, pas sécurité) | Seed en placeholder explicite, ne bloque pas le merge des tâches d'ingénierie |
| Chaque tâche part de `develop` mais celui-ci évolue vite (cf. session du 12/09) | Low | Rebase avant PR si `develop` a bougé, comme fait pour la PR #41 |

## Verification (avant de considérer ce plan prêt)

- [x] Chaque tâche a des critères d'acceptation (dans son issue GitHub)
- [x] Chaque tâche a une étape de vérification (dans son issue GitHub)
- [x] Dépendances identifiées et ordonnées (Phase 1 → 2 → 3 → 4 → 5)
- [x] Tâches enregistrées dans le tracker désigné (GitHub Issues, pas `tasks/todo.md`)
- [x] Aucune tâche ne touche plus de ~5 fichiers (le « Parcours anonymisé » n'est justement pas découpé faute d'info)
- [x] Checkpoints entre les phases à risque
- [ ] Revue humaine du plan (Christophe) — **en attente**
