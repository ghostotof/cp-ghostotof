# Issue #353 — échec anticipé des rollouts

Branche : `feature/353-rollout-echec-rapide` (depuis `develop` à `717ce4b`). Plan détaillé : `tasks/plan.md`.

## Arbitrages (Christophe, 2026-10-06)

- [x] Architecture : script `tools/wait-rollout.sh` à tranches
- [x] Transitoires : délai par raison. `InvalidImageName` 0 s, `CreateContainerConfigError` 15 s,
      `ErrImagePull` et `ImagePullBackOff` 60 s comptés ensemble, **porté à 150 s le 2026-10-07**
- [x] Périmètre : Job `Failed=True` et initContainers inclus ; `CrashLoopBackOff` exclu
- [x] Plan écrit

## Tâches

- [x] **T1** — `tools/wait-rollout.sh` + `tools/tests/wait-rollout.test.sh` (banc rouge, script, vert,
      7 mutations détectées, shellcheck, commit)
- [x] **T2** — pipeline : 13 attentes remplacées, `tools-tests`, commentaires `timeout-minutes`, règle
      `deploiement.md` (vérifications : grep, check-workflow-timeouts, check-claude-rules, actionlint ;
      commit)
- [x] **T3** — expérience en préprod, par Christophe dans un terminal séparé (2026-10-09), Deployment
      jetable `wr353-probe`, script de la branche à `5c1c236`, kubectl 1.35 :
      - **clé absente** (`secretKeyRef` vers un Secret inexistant) : `rc=1` en 33 s au lieu de 120,
        `::error::… pod wr353-probe-6bb66b7ccf-rqc2f, conteneur busybox : CreateContainerConfigError
        observé depuis 17 s — secret "wr353-absent" not found` ;
      - **relevés** : une liste multi-types porte `kind` sur chaque item (`["Deployment","Pod","ReplicaSet"]`) ;
        `state.waiting` = `{"reason":"CreateContainerConfigError","message":"secret \"wr353-absent\" not found"}` ;
        annotation `deployment.kubernetes.io/revision` présente sur le Deployment et son ReplicaSet,
        `observedGeneration` à jour — les trois hypothèses des fixtures sont confirmées ;
      - **correction** (env retiré) : `rc=0`, rollout terminé dès la première tranche — ne prouve donc
        PAS le filtre de révision (aucune inspection n'a eu lieu ; il reste couvert par le banc, cas 4) ;
      - **nom d'image invalide** (`BusyBox:1.37`), deux révisions présentes : `rc=1` à la première
        inspection, seul le pod de la nouvelle révision (`…-567db5bb96-…`) est mis en cause ;
      - nettoyage fait (pod `Terminating` 30 s : `sleep` en PID 1 ignore SIGTERM).
      Non éprouvé en vrai : un Job (`backend-migrate`), le Job `Failed=True`, les échecs de pull — le
      premier vrai passage sera la release qui embarque cette branche.

- [x] **T4** — (décidé le 2026-10-09, relecture § Secret en volume) un Secret ou ConfigMap absent
      monté en VOLUME non optionnel (`jwt-keys`, `backend-nginx-conf`) : pod en `ContainerCreating`,
      cause dans un événement `FailedMount` seulement. Role déployeur : `get`/`list` sur `events`.
      Script : pour un pod de la révision en cours resté `Pending` (et sans
      `PodReadyToStartContainers=True`), lire ses seuls événements `FailedMount` ; fatal après 15 s si
      le message dit `secret|configmap "…" not found` ou `references non-existent … key` — jamais un
      `FailedMount` de PVC (rattachement d'un `Recreate`). Lecture refusée (Role pas réappliqué) :
      UN `::warning::`, l'attente continue (sinon chaque déploiement casserait). Banc, mutations,
      règle, en-tête, `k8s/README.md` §4.
      Relecture de T4 (2026-10-09, `/code-review` + Standards/Spec) corrigée dans le commit suivant :
      une lecture des événements par tour (plus une par pod), un compte par volume, condition
      `PodReadyToStartContainers` exigée présente ET `False` (absente : pas de jugement), comptes
      conservés sur panne de lecture + un `::warning::`, fixtures réalistes (initContainers en
      `PodInitializing`, clé `items`, Job, pod non placé, pod en suppression), doc des bornes (~35 s),
      commentaire du Role honnête sur la portée des événements. 11 mutations détectées.
- [ ] **T4 bis** — Christophe, terminal séparé : rejouer la boucle `k8s/README.md` §4 (preprod, prod),
      vérifier `auth can-i list events` pour le déployeur ; puis Deployment jetable montant un Secret
      absent en VOLUME — **nécessaire, plus facultatif** : la détection repose sur
      `PodReadyToStartContainers=False` pendant un FailedMount, à constater sur le cluster 1.36

## Critères d'acceptation (#353)

- [x] Un Deployment qui référence une clé absente fait échouer le job en quelques secondes, en
      nommant pod, conteneur et raison (T1 cas 2 ; T3 : 33 s au lieu de 120 en préprod)
- [x] Un Job (`backend-migrate`) dans le même cas échoue de la même façon, avant ses 300 s (T1 cas 10 —
      banc hors ligne seulement, pas éprouvé sur le cluster)
- [x] Aucune valeur de secret dans les journaux (T1 cas 16 ; T3 : seuls des noms dans la sortie)
- [x] `timeout-minutes` revu (T2 étapes 2e/3), `tools/tests` étendu (T1, T2 étape 4)

## Clôture

- [x] Étape 07 : `/code-review`, puis `mattpocock-skills:code-review` (2026-10-07). Corrigé dans
      `3b908ca` : erreurs kubectl avalées par les tranches, table unique des raisons, « observé depuis »,
      exemple `cv-pdf` faux, limite des volumes (FailedMount) documentée, doc incohérente. Laissés à
      l'arbitrage de Christophe : ~~délai de pull de 60 s~~ (150 s, tranché le 2026-10-07), `CreateContainerError`/
      `RunContainerError`, Secret en volume (verbe `events`), coût des listes du namespace, code commun
      avec wait-external-secrets.sh
- [ ] À la fusion de clôture : archiver `tasks/` sous `.claude/specs/archive/<date>-353-rollout-echec-rapide/`
      (feature sans spec : `tasks/` seul), et mettre à jour le README de l'archive
