# Plan de mise en œuvre — spec 0006, flux de release par branche `release/*`

**Spec** : `.claude/specs/0006-release-workflow.md` (validée le 2026-09-16, PR #194).
**Branche de travail** : `feature/release-workflow-spec` (spec + ce plan), puis une branche par
tâche, empilées, PR vers `develop` (une PR par tâche, cf. mémoire « stack empilée »).
**Suivi** : une issue GitHub par tâche, label `spec-0006` ; ce fichier est l'index, `todo.md` la
liste cochable. Les deux vivent sur les branches de la feature et s'archivent avec la spec au merge
de clôture (règle du 2026-09-16, issue #192 : `git mv` de la spec et de `tasks/` entier).

## Vue d'ensemble

Remplacer « `git tag` déclenche tout » par trois phases gardées par la branche : qualité partout,
images + préprod sur `release/**`, prod + finalisation sur `main`. La version se calcule
(`tools/next-version.sh`), les images sont `<version>-<sha>`, le merge dans `main` est le seul
stop humain, le tag et la release GitHub sont posés par la pipeline après la prod verte depuis
`RELEASE_NOTES.md`.

## Décisions d'implémentation (au-delà de la spec)

- **`dependabot/**` entre dans la liste `push.branches`** (écart à D5, à reporter dans la spec) :
  Dependabot cible `develop` et pousse ses branches dans le dépôt ; sans `pull_request` ni cette
  entrée, ses PR n'auraient plus aucun check. Ses runs tournent avec un token en lecture seule et
  sans secret : la phase 1 n'en a pas besoin (`.env.test.local` et le keypair sont générés).
- **Les gardes locales de D7 vivent dans `tools/verify-release-merge.sh`** (HEAD est un merge,
  `HEAD^2`, titre de `RELEASE_NOTES.md` cohérent, sortie `VERSION`/`RELEASE_SHA`/`IMAGE_TAG`) ;
  les deux vérifications distantes (manifestes GHCR, run vert) restent des étapes du job, elles
  ne se testent pas sur un dépôt temporaire.
- **Les jobs gardés par `refs/tags/` deviennent morts dès la Tâche 4** (plus de déclencheur tag)
  et sont réécrits un par un en Tâches 5 à 8 : entre-temps la pipeline reste cohérente, il n'y a
  juste plus de release possible — assumé, la spec le dit (§3).
- **Un nouveau job `tools-tests` en phase 1** porte les tests shell des scripts et `actionlint`
  (spec §8) : léger, sans PHP ni Node.
- **Phase 3 ne se teste qu'en vrai** (Tâche 11) : pousser un commit non-merge sur `main` pour
  vérifier la garde salirait `main`. Les gardes sont testées unitairement (Tâche 2), le job en
  entier l'est par la première release. Risque accepté, voir tableau.
- **La branche `release/*` de test de la Tâche 6** est coupée depuis la branche de la tâche (pas
  de `develop` incluant le code), n'a pas de PR vers `main`, et est supprimée après le run.

## Graphe de dépendances

```
T1 next-version.sh + test ─┐
T2 verify-release-merge.sh + test ─┤
T3 contrats externes vérifiés ─────┤
                                   ├─ T4 déclencheurs, concurrency, run-name, tools-tests
                                   │        │
                                   │        ├─ T5 release-version + build-images (release/**)
                                   │        │        │
                                   │        │        └─ T6 préprod + smoke + audit + rollback (release/**)
                                   │        │                 │
                                   │        │                 ├─ T7 deploy-prod gardé (main)
                                   │        │                 │        │
                                   │        │                 │        └─ T8 finalize-release
                                   │        │                 │                 │
                                   └────────┴─────────────────┴── T9 wizard réglages D9 ──┤
                                                                                     T10 docs + archivage (clôture)
                                                                                          │
                                                                                     T11 première release (M6)
```

## Liste des tâches

### Phase A — Socle testable hors CI (M1)
- [ ] Tâche 1 (#195) : `tools/next-version.sh` et son test
- [ ] Tâche 2 (#196) : `tools/verify-release-merge.sh` et son test
- [ ] Tâche 3 (#197) : contrats externes vérifiés et consignés (§2 « Contrats externes »)

### Checkpoint 1 — Socle
- [ ] Les deux scripts répondent aux cas du §4 M1 et aux gardes D7 sur un dépôt temporaire.
- [ ] Les quatre contrats externes ont une réponse écrite dans la spec §10 ; si le bypass
      `github-actions` est refusé, D9 (b) bascule sur son repli **avant** la Tâche 8.
- [ ] Revue humaine.

### Phase B — Phase 1 de la pipeline (M2)
- [ ] Tâche 4 (#198) : déclencheurs D5, `concurrency`, `run-name`, job `tools-tests` (+ `actionlint`)

### Phase C — Phase 2 sur `release/**` (M3)
- [ ] Tâche 5 (#199) : job `release-version` et `build-images` en `<version>-<sha>` sans réécriture
- [ ] Tâche 6 (#200) : préprod, smoke, audit, rollback gardés par `release/**`, run qui s'arrête

### Checkpoint 2 — Une release déploie la préprod et s'arrête
- [ ] `release/0.x.y` de test : run vert, quatre images sur GHCR, préprod sur `-preprod`, aucun
      job en attente ; branche de test supprimée.
- [ ] Un push de feature ne lance que la phase 1 ; un tag ne lance rien.
- [ ] Revue humaine.

### Phase D — Phase 3 sur `main` (M4)
- [ ] Tâche 7 (#201) : `deploy-prod` gardé (D7), plus de reconstruction, `create-release` retiré
- [ ] Tâche 8 (#202) : `finalize-release` (D8), six étapes idempotentes

### Phase E — Réglages et documentation (M5)
- [ ] Tâche 9 (#203) : wizard des réglages GitHub (D9) et exécution
- [ ] Tâche 10 (#204) : documentation (`CLAUDE.md`, `README.md`, `k8s/README.md`, en-tête de
      `pipeline.yml`) + archivage spec/`tasks/` (PR de clôture)

### Checkpoint 3 — Prêt pour la première release
- [ ] Squash/rebase impossibles, push direct sur `main`/`develop` refusé, reviewer retiré de
      `production`, ruleset tags `v*`.
- [ ] Plus aucun `git tag -a` comme geste humain dans les docs.
- [ ] Revue humaine, merge de clôture (spec + `tasks/` archivés).

### Phase F — Première release (M6)
- [ ] Tâche 11 (#205) : `release/0.14.0` (ou la version calculée), `RELEASE_NOTES.md`, PR, merge,
      contrôle des sept résultats de D8

### Checkpoint final
- [ ] Release publiée par la machine, tag sur `HEAD^2`, `docs/releases/` alimenté, branche
      supprimée, `develop == main`.
- [ ] Spec passée en « livrée », mémoire mise à jour.

## Risques

| Risque | Impact | Parade |
|---|---|---|
| Le ruleset de `main` refuse le push de `github-actions` (commit de copie D8) | Moyen : `finalize-release` s'arrête à l'étape 4 | Tâche 3 le vérifie avant ; repli D9 (b) : copie sur `develop` seulement |
| Phase 3 jamais jouée avant la première vraie release | Élevé si une garde est fausse : prod non déployée (jamais mal déployée, fail-closed) | Gardes testées unitairement (T2) ; première release faite un jour calme, `rollback-preprod` déjà validé en T6 ; l'ancien chemin `kustomize`/migration est inchangé |
| `concurrency` annule un run pendant `deploy-preprod` | Faible : préprod à moitié déployée quelques minutes | Le run suivant redéploie ; jamais d'annulation sur `main` |
| Deux releases ouvertes en même temps | Moyen : même version calculée, images en collision impossible (SHA différent) mais préprod écrasée | Règle documentée : une release à la fois ; le run le dit si une autre `release/*` existe (avertissement, pas blocage) |
| Dependabot sans check après le retrait de `pull_request` | Moyen : merge aveugle d'une dépendance | `dependabot/**` dans `push.branches` (décision ci-dessus) |
| Une release coupée sans `git fetch --tags` calcule une version déjà sortie | Faible : `release-version` rougit au premier push | Le script `fetch` lui-même `origin/main` et ses tags avant de calculer |

## Questions ouvertes

- Aucune bloquante. Les cinq points « à valider avant le plan » de la spec §10 sont considérés
  validés par le « ok » du 2026-09-16 ; l'écart Dependabot ci-dessus est à reporter dans la spec
  en Tâche 4.
