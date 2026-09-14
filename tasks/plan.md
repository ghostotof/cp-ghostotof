# Plan — Assistant de traduction FR/EN du backoffice (spec 0002, Symfony AI phase 1)

## Overview

Mettre en œuvre `.claude/specs/0002-ai-translation-assistant.md` : depuis un formulaire du
backoffice (`ROLE_SUPER`), un bouton demande à `claude-sonnet-5`, via `symfony/ai-bundle` pinné en
`0.13.0`, la traduction de tous les champs de prose d'une entrée, puis pré-remplit un **nouveau**
formulaire dans la locale cible. Rien n'est persisté sans le bouton d'enregistrement habituel. Le
socle posé (contexte `src/Ai/`, plateforme configurée, ADR 0004) est celui de la phase 2 (serveur MCP
réservé à `ROLE_TRUSTED`, spec distincte à venir).

Le plan précédent (suite de l'ADR 0003, entièrement livré au 2026-09-13) est remplacé par celui-ci ;
il reste lisible dans l'historique git de ce fichier.

**Tracker** : GitHub Issues (`docs/agents/issue-tracker.md`), label `spec-0002`
(`gh issue list --label spec-0002`). Chaque tâche ci-dessous est une issue qui porte ses critères
d'acceptation, ses vérifications, ses dépendances et ses fichiers ; cette liste n'est qu'un index
ordonné. Git flow : branche `feature/ai-translation-assistant` depuis `develop` (la spec y est déjà
committée), **une PR par tâche, empilée** (cf. mémoire « Une PR par tâche, stack empilée » :
retargeter avant de supprimer une branche).

## Architecture Decisions

Reprises de la spec (§2), rappelées ici parce qu'elles décident de l'ordre :

- **Le socle d'abord, la logique ensuite** (Task 1 → 3) : le bundle est en 0.x ; savoir tôt s'il
  passe PHPStan `max` + Rector sur un projet Symfony 8.1 est le risque n°1, donc il se lève avant
  d'écrire une ligne de domaine.
- **Backend complet avant le frontend** (Task 3–5 → 7–9) : le contrat d'API est figé par les tests
  fonctionnels de la Task 4 ; le frontend s'y adosse sans aller-retour.
- **Le déploiement du secret est indépendant** (Task 6, dépend seulement de Task 1) et comporte une
  action humaine (créer les secrets dans Scaleway Secret Manager) qui doit précéder le premier
  déploiement preprod de la tranche, sinon `backend-secrets` reste incomplet et le Deployment ne
  démarre pas. Elle est signalée tôt pour ne pas bloquer le checkpoint 3.
- **Incidents d'abord, puis une page par PR** (Task 9, puis 10–13) : la page la plus riche valide
  la mécanique ; les suivantes ne sont que du câblage, et deux d'entre elles ont une question de
  sémantique (About : singleton de réglages ; Quality : traits à champ unique) à trancher au moment
  de les brancher, pas avant.
- **`HasProblemType` quitte `Security/User` pour `Shared/`** dans la Task 4, amélioration ciblée
  justifiée par un troisième consommateur — pas un refactor d'opportunité.

## Task List

### Phase 1 — Socle (bundle, configuration, ADR)
- [ ] Task 1 — Socle Symfony AI : bundle 0.13.0 pinné, plateforme Anthropic, agent `translator`, `ANTHROPIC_API_KEY` hors dépôt — [#91](https://github.com/ghostotof/cp-ghostotof/issues/91)
- [ ] Task 2 — ADR 0004 « Assistance IA » (D1–D7, dont la phase 2 `ROLE_TRUSTED`) + `CLAUDE.md` — [#92](https://github.com/ghostotof/cp-ghostotof/issues/92) (parallélisable avec la Task 1)

### Checkpoint 1 — le bundle tient dans le projet
- [ ] `make back-quality` vert avec le bundle installé (PHPStan max, Rector, Psalm), `make back-test` vert
- [ ] `debug:container ai.agent.translator` et `ai.http_client` existent ; kernel `test` sans clé réelle
- [ ] ADR 0004 relue par Christophe
- [ ] Un appel `ai:agent:call translator` réussi en dev (une fois, payant)

### Phase 2 — Backend : traducteur, ressource, quota
- [ ] Task 3 — Traducteur : VO, `ContentTranslatorInterface`, `SymfonyAiContentTranslator` (sortie structurée validée, jetons journalisés) + tests unitaires — [#93](https://github.com/ghostotof/cp-ghostotof/issues/93)
- [ ] Task 4 — Ressource `POST /api/backoffice/translations` (DTO validé, processeur, 503 `translation-unavailable`, `HasProblemType` → `Shared/`) + tests fonctionnels — [#94](https://github.com/ghostotof/cp-ghostotof/issues/94)
- [ ] Task 5 — Quota 30/h par compte : limiteur, 429 + `Retry-After`, consommé après validation et avant l'appel + tests — [#95](https://github.com/ghostotof/cp-ghostotof/issues/95)

### Checkpoint 2 — contrat d'API figé et cloisonné
- [ ] `make back-test` vert, dont 401 / 403 palier de base / 403 sans CSRF / 200 / chaque 422 / 429 / 503
- [ ] `ApiRouteExposureTest` vert **sans** entrée d'allow-list ; `debug:router` : une seule route
- [ ] Aucun test ne sort sur le réseau (clé factice dans `phpunit.dist.xml`)
- [ ] Revue avec Christophe du contrat avant d'écrire le frontend

### Phase 3 — Déploiement du secret (indépendante, à faire avant le premier déploiement preprod)
- [ ] Task 6 — `ANTHROPIC_API_KEY` dans Scaleway Secret Manager (**action humaine**) + `ExternalSecret` preprod/prod + `k8s/README.md` ; timeouts ingress/nginx vérifiés ≥ 40 s — [#96](https://github.com/ghostotof/cp-ghostotof/issues/96)

### Phase 4 — Frontend : première tranche verticale (Incidents)
- [ ] Task 7 — Tranche `admin/translation` : domaine, `HttpAdminTranslationRepository`, `useAdminTranslation` + specs — [#97](https://github.com/ghostotof/cp-ghostotof/issues/97)
- [ ] Task 8 — `TranslateEntryButton.vue` + clés i18n `admin.translation.*` + spec — [#98](https://github.com/ghostotof/cp-ghostotof/issues/98)
- [ ] Task 9 — Branchement sur `AdminIncidentsPage.vue` (brouillon en création, champs non prose conservés, bannière, erreurs) + `main.ts` + spec + axe — [#99](https://github.com/ghostotof/cp-ghostotof/issues/99)

### Checkpoint 3 — parcours complet de bout en bout
- [ ] Suites backend et frontend vertes, `make front-lint` et `make front-build` verts
- [ ] **Dans un vrai navigateur (Chrome), stack dev, clé dans `.env.local`** : un incident FR → bouton → brouillon EN relu → enregistré → visible sur `/en/incidents`
- [ ] Déploiement preprod : `ExternalSecret` en `SecretSynced`, un appel réel réussi depuis le backoffice preprod
- [ ] Revue avec Christophe avant de brancher les autres pages

### Phase 5 — Les autres pages, une PR chacune (câblage seulement)
- [ ] Task 10 — Contributions (`title`, `summary`, `body` — vérifier que les `` `backticks` `` survivent) — [#100](https://github.com/ghostotof/cp-ghostotof/issues/100)
- [ ] Task 11 — CV sans identité (`title`, `skills`, `achievements` ; palier de base, rien de nominatif) — [#101](https://github.com/ghostotof/cp-ghostotof/issues/101)
- [ ] Task 12 — About : réglages (singleton par locale, sémantique à trancher), cartes site, cartes moi — [#102](https://github.com/ghostotof/cp-ghostotof/issues/102)
- [ ] Task 13 — Quality : principes, traits (champ unique, à trancher) — [#103](https://github.com/ghostotof/cp-ghostotof/issues/103)

### Checkpoint 4 — phase 1 livrée
- [ ] Toutes les pages admin localisées disposent du bouton (hors études de cas, voir Open Questions)
- [ ] `CLAUDE.md` et ADR 0004 à jour de ce qui a réellement été livré
- [ ] Prêt pour la spec de la phase 2 (serveur MCP `ROLE_TRUSTED`)

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Le bundle 0.13 ne passe pas PHPStan `max` + strict-rules, ou Rector le réécrit | Medium | Task 1 isolée et en premier ; un éventuel `ignoreErrors` scopé à `Infrastructure/SymfonyAi/` (Task 3), jamais de baseline ; `rector.php` skip ciblé si besoin |
| Le remplacement de la plateforme dans le conteneur de test ne fonctionne pas (service déjà instancié, alias) | Low | Repli prévu dans la Task 4 : `ai.http_client` remplacé par un `MockHttpClient` au format Anthropic |
| Le bridge Anthropic n'honore pas `response_format` comme attendu (sortie non structurée) | Medium | La validation serveur de la Task 3 transforme le cas en 503 explicite ; l'essai réel du checkpoint 1 le révèle avant le frontend |
| Coût : boucle UI ou usage abusif d'un compte `ROLE_SUPER` | Low | Quota 30/h par compte, `max_tokens` 4096, timeout 40 s (Task 5 / Task 1) |
| Timeout mural : nginx ou ingress coupent avant les 40 s | Low | Vérifié dans la Task 6 (défauts à 60 s, aucune annotation) ; le 503 reste le comportement dégradé |
| Secrets Scaleway non créés avant le déploiement preprod | Medium (Deployment bloqué) | Action humaine signalée dans la Task 6 et au checkpoint 3 ; la faire dès la Task 1 mergée |
| Injection de prompt via le contenu à traduire | Low (auteur = super-admin, humain relit) | Prompt système explicite, sortie structurée, aucune persistance automatique ; risque résiduel accepté dans l'ADR 0004 |
| Une page admin (About, Quality) n'a pas la même sémantique de « création » | Low | Questions posées dans les issues #102 / #103, à trancher au moment du branchement |

## Open Questions

1. **Il n'existe pas de page admin pour les études de cas** (routes admin : technologies, about,
   quality, contributions, incidents, anonymous-cv, watch, users) alors que l'ADR 0003 prévoit leur
   saisie par le backoffice et que le contenu rédigé attend d'y être saisi. Hors périmètre de cette
   spec. **Confirmé par Christophe le 2026-09-14 → issue [#104](https://github.com/ghostotof/cp-ghostotof/issues/104)**
   (label `adr-0003`, hors de ce plan). L'assistant s'y branchera ensuite (Task 14, même forme que
   la Task 9, issue à ouvrir quand la page existera).
2. **Modèle par environnement** : `claude-sonnet-5` partout, ou un modèle moins cher en preprod ?
   Parti pris : le même partout (preprod doit reproduire prod, y compris la qualité de la sortie
   structurée) ; à revoir si le coût preprod devient visible.
3. **Une clé Anthropic par environnement ou une seule** : à décider par Christophe à la Task 6
   (recommandation : une par environnement, révocable séparément, comme les clés mailer scopées).

## Verification (avant de considérer ce plan prêt)

- [x] Chaque tâche a des critères d'acceptation (dans son issue GitHub)
- [x] Chaque tâche a une étape de vérification (dans son issue GitHub)
- [x] Dépendances identifiées et ordonnées (1 → 3 → 4 → 5 → 7 → 8 → 9 → 10–13 ; 2 et 6 en parallèle)
- [x] Tâches enregistrées dans le tracker désigné (GitHub Issues #91–#103, pas `tasks/todo.md`)
- [x] Aucune tâche ne touche plus de ~5 fichiers de logique (la Task 1 en touche 9, tous de configuration ; la Task 12 est annoncée M et découpable)
- [x] Checkpoints entre les phases
- [x] Revue humaine du plan (Christophe) — ordre et découpage validés le 2026-09-14
