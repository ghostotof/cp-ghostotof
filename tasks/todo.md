# Issue #353 — échec anticipé des rollouts

Branche : `feature/353-rollout-echec-rapide` (depuis `develop` à `717ce4b`). Plan détaillé : `tasks/plan.md`.

## Arbitrages (Christophe, 2026-10-06)

- [x] Architecture : script `tools/wait-rollout.sh` à tranches
- [x] Transitoires : délai par raison. `InvalidImageName` 0 s, `CreateContainerConfigError` 15 s,
      `ErrImagePull` et `ImagePullBackOff` 60 s comptés ensemble
- [x] Périmètre : Job `Failed=True` et initContainers inclus ; `CrashLoopBackOff` exclu
- [x] Plan écrit

## Tâches

- [x] **T1** — `tools/wait-rollout.sh` + `tools/tests/wait-rollout.test.sh` (banc rouge, script, vert,
      7 mutations détectées, shellcheck, commit)
- [x] **T2** — pipeline : 13 attentes remplacées, `tools-tests`, commentaires `timeout-minutes`, règle
      `deploiement.md` (vérifications : grep, check-workflow-timeouts, check-claude-rules, actionlint ;
      commit)
- [ ] **T3** — ⏸ en attente de Christophe — expérience en préprod, **par Christophe dans un terminal séparé** : Deployment jetable
      avec une clé absente, message relevé, nettoyage, report dans l'issue

## Critères d'acceptation (#353)

- [ ] Un Deployment qui référence une clé absente fait échouer le job en quelques secondes, en
      nommant pod, conteneur et raison (T1 cas 2, T3)
- [ ] Un Job (`backend-migrate`) dans le même cas échoue de la même façon, avant ses 300 s (T1 cas 10)
- [ ] Aucune valeur de secret dans les journaux (T1 cas 16)
- [ ] `timeout-minutes` revu (T2 étapes 2e/3), `tools/tests` étendu (T1, T2 étape 4)

## Clôture

- [x] Étape 07 : `/code-review`, puis `mattpocock-skills:code-review` (2026-10-07). Corrigé dans
      `3b908ca` : erreurs kubectl avalées par les tranches, table unique des raisons, « observé depuis »,
      exemple `cv-pdf` faux, limite des volumes (FailedMount) documentée, doc incohérente. Laissés à
      l'arbitrage de Christophe : délai de pull de 60 s après `apply -k` en prod, `CreateContainerError`/
      `RunContainerError`, Secret en volume (verbe `events`), coût des listes du namespace, code commun
      avec wait-external-secrets.sh
- [ ] À la fusion de clôture : archiver `tasks/` sous `.claude/specs/archive/<date>-353-rollout-echec-rapide/`
      (feature sans spec : `tasks/` seul), et mettre à jour le README de l'archive
