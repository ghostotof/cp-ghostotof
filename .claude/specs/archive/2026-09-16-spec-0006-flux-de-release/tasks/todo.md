# Todo — spec 0006, flux de release

Issues GitHub `spec-0006` : numéros reportés. Une branche et une PR par tâche, empilées : la PR
de chaque tâche vise la branche de la tâche précédente, T1 vise `feature/release-workflow-spec` ;
`develop` ne reçoit que la PR #194, à la clôture.

## Tâche 1 (#195) : `tools/next-version.sh` et son test

**Description :** Le calcul de version D2, seule implémentation : dernier tag `v*` joignable
depuis `origin/main`, commits `main..HEAD` hors merges, `BREAKING`/`!` → majeur (ramené à mineur
en `0.x`), `feat` → mineur, sinon correctif ; aucun commit → échec explicite. Le script fait son
`git fetch origin main --tags` sauf `--no-fetch` (pour le test).

**Critères d'acceptation :**
- [ ] Les cas de la spec §4 M1 passent (`0.13.3`, `0.14.0`, majeur ramené, `2.0.0` en `1.x`,
      « rien à livrer », merge ignoré, commit hors convention ignoré avec avertissement).
- [ ] `tools/next-version.sh` sur `develop` aujourd'hui répond `0.13.3` (un seul commit `docs` depuis `v0.13.2`).

**Vérification :**
- [ ] `tools/tests/next-version.test.sh` vert, sur un dépôt temporaire, sans toucher au dépôt courant.
- [ ] `shellcheck` propre sur les deux fichiers.

**Dépendances :** aucune. **Fichiers :** `tools/next-version.sh`, `tools/tests/next-version.test.sh`.
**Taille :** S.

## Tâche 2 (#196) : `tools/verify-release-merge.sh` et son test

**Description :** Les gardes locales de D7 : `HEAD` est un commit de merge (sinon échec nommant
le SHA), `RELEASE_SHA=HEAD^2`, `RELEASE_NOTES.md` lu à `RELEASE_SHA`, titre `# vX.Y.Z — …`
obligatoire, `VERSION` extraite, `IMAGE_TAG=<version>-<sha court>`. Sortie au format
`clé=valeur` pour `$GITHUB_OUTPUT`. Optionnellement `--check-branch release/<version>` pour la
Tâche 5 (même titre attendu).

**Critères d'acceptation :**
- [ ] Non-merge → échec ; merge sans `RELEASE_NOTES.md` → échec ; titre mal formé → échec ;
      cas nominal → les trois valeurs.
- [ ] Le message d'échec nomme toujours la valeur lue et la valeur attendue.

**Vérification :**
- [ ] `tools/tests/verify-release-merge.test.sh` vert sur un dépôt temporaire.
- [ ] `shellcheck` propre.

**Dépendances :** aucune. **Fichiers :** `tools/verify-release-merge.sh`,
`tools/tests/verify-release-merge.test.sh`. **Taille :** S.

## Tâche 3 (#197) : contrats externes vérifiés — fait le 2026-09-16, choix (1) deploy key

**Description :** Répondre aux quatre points « Contrats externes » de la spec §2, avec preuve
(commande `gh api` ou doc GitHub citée), et l'écrire dans la spec §10. Le plus important : un
ruleset accepte-t-il l'application `github-actions` (`actor_type: Integration`) en bypass sur ce
dépôt ? Test possible sans effet : `gh api --method POST repos/…/rulesets` avec
`enforcement: disabled`, puis suppression.

**Critères d'acceptation :**
- [ ] Les quatre réponses sont dans la spec §10 avec la date et la preuve.
- [ ] Si le bypass est refusé, D9 (b) est amendée vers son repli dans la même PR.

**Vérification :**
- [ ] Le ruleset de test n'existe plus (`gh api repos/…/rulesets` vide ou inchangé).

**Dépendances :** aucune. **Fichiers :** `.claude/specs/0006-release-workflow.md`. **Taille :** XS.

## Checkpoint 1 — Socle (après T1–T3)
- [ ] Tests shell verts en local ; contrats consignés ; revue humaine.

## Tâche 4 (#198) : déclencheurs, `concurrency`, `run-name`, job `tools-tests`

**Description :** `on.push.branches` = `main`, `develop`, `feature/**`, `fix/**`, `hotfix/**`,
`release/**`, `dependabot/**` ; plus de `tags`, plus de `pull_request`. `concurrency` par
`github.ref` avec `cancel-in-progress` sauf sur `main`. `run-name` avec la branche (la version
et le SHA sur `release/**` viennent en T5, `run-name` ne peut pas exécuter le script : afficher
`release/<version>` suffit, le nom de branche la porte). Nouveau job `tools-tests` : les tests
shell de T1/T2 + `actionlint` (figé sur un SHA). L'en-tête de `pipeline.yml` réécrit. Spec D5
amendée pour `dependabot/**`.

**Critères d'acceptation :**
- [x] Un push sur la branche de la tâche lance exactement sept jobs (six + `tools-tests`) — run 35091593978, un seul événement `push`, plus de `pull_request`.
- [x] Un second push annule le premier run (35091593978 `cancelled` au push de 1b05e96) ; un tag de
      test (`test-t4` sur e81691d, supprimé ensuite) n'a lancé aucun run.
- [x] Les jobs gardés par `refs/tags/` sont toujours présents et jamais lancés (`skipped` sur le run
      35091734168 ; état transitoire assumé jusqu'à T8).

**Vérification :**
- [x] `gh run list --branch <branche>` et `gh run view` consignés dans la PR #209.
- [x] `actionlint` propre en local sur le fichier (image v1.7.12 figée par digest).

**Dépendances :** T1, T2 (pour `tools-tests`). **Fichiers :** `.github/workflows/pipeline.yml`,
`.claude/specs/0006-release-workflow.md`. **Taille :** S.

## Tâche 5 (#199) : `release-version` et `build-images` sur `release/**`

**Description :** Job `release-version` (`if: startsWith(github.ref, 'refs/heads/release/')`,
`needs` la phase 1) : `tools/next-version.sh` vs nom de branche vs titre de `RELEASE_NOTES.md`,
sorties `version`, `image_tag`. `build-images` `needs: [release-version]`, gardé pareil, `TAG=<version>-<sha>` ;
avant le build, `docker manifest inspect` sur les quatre noms : tous présents → no-op vert,
certains présents → échec (état incohérent), aucun → build + push. Résumé du run avec les quatre
noms.

**Critères d'acceptation :**
- [x] `release/<bonne version>` + titre cohérent : vert, images poussées (run 35093215245 sur
      `release/0.14.0` @ 2c86b65, quatre images `0.14.0-2c86b65`).
- [x] Nom de branche ou titre en désaccord : `release-version` rouge, message nommant les
      trois valeurs, `build-images` non lancé (run 35093217771 sur `release/0.13.3`).
- [x] Re-run du même commit : `build-images` vert sans push (tentative 2 du run 35093215245,
      quatre « présente », étapes de build `skipped`).

**Vérification :**
- [x] `gh api` sur les packages GHCR montre les quatre tags `<version>-<sha>`.
- [x] Runs consignés dans la PR #210 ; branches `release/*` de test supprimées, images de test
      retirées de GHCR.

**Dépendances :** T4. **Fichiers :** `.github/workflows/pipeline.yml`. **Taille :** S.

## Tâche 6 (#200) : préprod, smoke, audit, rollback sur `release/**`

**Description :** `deploy-preprod`, `smoke-test-preprod`, `audit-preprod`, `rollback-preprod`
regardés sur `refs/heads/release/`, `TAG` lu depuis la sortie de `release-version`. Les étapes
`kustomize`/seed/migration inchangées. Le run se termine après `audit-preprod`. Avertissement
(pas d'échec) si une autre branche `release/*` existe sur le dépôt.

**Critères d'acceptation :**
- [x] Sur une `release/*` de test : préprod déployée avec `0.14.0-243e3b0-preprod` (run 35094176950 :
      `kustomize edit set image`, migration puis `rollout` réussis dans le journal — pas de kubectl
      local sur le cluster), smoke et audit verts, `deploy-prod` skipped, aucun job en attente.
- [x] Un smoke test forcé en échec (commit 1de8fcb sur la branche de test) déclenche
      `rollback-preprod` (run 35094908186 : smoke `failure`, rollback `success`).

**Vérification :**
- [x] Runs consignés dans la PR #211 ; branche de test supprimée ; la préprod reste sur l'image de
      test `0.14.0-243e3b0-preprod` après le rollback (même code applicatif que v0.13.2 + #192).

**Dépendances :** T5. **Fichiers :** `.github/workflows/pipeline.yml`. **Taille :** S.

## Checkpoint 2 — Une release déploie la préprod et s'arrête (après T4–T6)
- [ ] Critères §4 M2 et M3 de la spec cochés avec preuves ; revue humaine.

## Tâche 7 (#201) : `deploy-prod` gardé sur `main`

**Description :** `deploy-prod` `if: github.ref == 'refs/heads/main'`, `needs` la phase 1,
sans `environment.reviewers` (réglage côté GitHub en T9), étapes : `tools/verify-release-merge.sh`
→ `docker manifest inspect` ×4 → `gh run list --branch release/<version> --commit <sha> --status
success --workflow pipeline.yml` non vide → déploiement inchangé avec `$IMAGE:<image_tag>`.
`audit-prod` suit, non bloquant. `create-release` supprimé (remplacé en T8).

**Critères d'acceptation :**
- [x] Toute garde en échec sort **avant** `Configure kubectl` (le kubeconfig n'est même pas écrit) :
      gardes 1–3 puis setup-kustomize puis kubeconfig, dans cet ordre.
- [x] Les messages d'échec nomment la garde et les valeurs (script T2 ; `::error::absente : <image>` ;
      « aucun run vert … sur release/<version> pour le commit <sha> »).

**Vérification :**
- [x] `actionlint` propre ; lecture croisée du job avec la spec D7 ; gardes distantes testées en local
      sur les traces de T6 (run retrouvé après suppression de la branche, **SHA complet obligatoire**
      pour `--commit`, image supprimée = absente) ; pas de run réel possible avant T11.

**Dépendances :** T6, T2. **Fichiers :** `.github/workflows/pipeline.yml`. **Taille :** S.

## Tâche 8 (#202) : `finalize-release`

**Description :** Job `needs: [deploy-prod]`, `permissions: contents: write`, `fetch-depth: 0`,
les six étapes de D8 dans l'ordre, chacune idempotente, chacune écrivant une ligne dans
`$GITHUB_STEP_SUMMARY`. Étape 6 en `--ff-only`, échec de fast-forward = arrêt propre **vert**
avec la demande de PR `main` → `develop`. Les push (tag, copie, `develop`) se font par SSH avec
la deploy key (`RELEASE_DEPLOY_KEY`, `webfactory/ssh-agent` figé sur un SHA ou `GIT_SSH_COMMAND`
avec un fichier temporaire à droits 600) : **`[skip ci]` obligatoire** sur le commit de copie,
ses push déclenchent les workflows. L'artefact
`social-preview` reste produit par `build-images` (T5) et rattaché ici.

**Critères d'acceptation :**
- [x] Un re-run après succès complet ne fait rien et reste vert (chaque étape a son test
      d'existence) — cas « re-run » du test.
- [x] Un tag `vX.Y.Z` existant sur un autre commit fait échouer l'étape 1 — cas « tag ailleurs ».

**Vérification :**
- [x] Les six étapes sont testables sur un dépôt temporaire avec le script extrait
      `tools/finalize-release.sh` (le job ne fait que l'appeler, comme T2) ;
      `tools/tests/finalize-release.test.sh` vert : 20 cas (nominal, re-run, develop divergé →
      code 0, tag ailleurs, copie différente, version ≠ notes), exécuté par `tools-tests`.

**Dépendances :** T7. **Fichiers :** `.github/workflows/pipeline.yml`, `tools/finalize-release.sh`,
`tools/tests/finalize-release.test.sh`. **Taille :** M.

## Tâche 9 (#203) : réglages GitHub (D9) — script idempotent `tools/github-settings.sh`, exécuté

**Description :** `tools/github-settings-wizard.sh` (skill `wizard`) guide, dans l'ordre : merge
commit seul ; ruleset `main` (PR, checks `smoke-test-preprod` + `audit-preprod`, pas de
suppression ni force-push, bypass `github-actions` ou repli de T3) ; ruleset `develop` (PR,
checks phase 1) ; retrait du reviewer de `production` ; ruleset tags `v*` (création, suppression,
déplacement interdits) ; vérifie `delete_branch_on_merge: false`. **Avant les rulesets** : générer la
paire ed25519 `release-bot` hors dépôt, déclarer la publique en deploy key avec écriture, poser la
privée en secret `RELEASE_DEPLOY_KEY`, effacer le fichier local ; bypass `DeployKey` sur les trois
rulesets. Chaque étape est idempotente et vérifiable par `gh api`.

**Critères d'acceptation :**
- [x] Après exécution, `gh api repos/…` montre `allow_squash_merge: false`,
      `allow_rebase_merge: false` ; trois rulesets actifs (main 23545297, develop 23545300, tags
      23545301, bypass `DeployKey`) ; `production` sans règle de protection. Deploy key
      `release-bot` 163489899 en écriture, secret `RELEASE_DEPLOY_KEY` posé, fichiers `shred`.
- [x] Un push direct sur `main` est refusé par GitHub (vrai push fast-forward d'un commit vide :
      GH013 « Changes must be made through a pull request », « 2 of 2 required status checks are
      expected ») ; idem `develop` (« repository rule violations ») ; un tag `v0.0.0-test` manuel :
      « Cannot create ref due to creations being restricted ».

**Vérification :**
- [x] Sorties `gh api` consignées dans la PR.

**Dépendances :** T3, T8. **Fichiers :** `tools/github-settings-wizard.sh`. **Taille :** S.

## Tâche 10 (#204) : documentation et archivage (PR de clôture)

**Description :** `CLAUDE.md` : objectif 10 réécrit (branches D1), « Deployment invariants »
(le bloc `git tag -a` remplacé par le cycle D10, les leçons gardées : image périmée, notes
Markdown et `--cleanup=verbatim` désormais dans le job, titre sans `#`), « Commands »
(`TAG=1.2.3` → `TAG=0.14.0-abc1234`). `README.md` ligne « Livraison ». `k8s/README.md` lignes 4–5.
En-tête de `pipeline.yml` si pas déjà fait en T4. **À écrire aussi** (appris en T8) : GitHub saute
le run `push` d'un commit dont le message contient `[skip ci]`, `[ci skip]`, `[no ci]`,
`[skip actions]` ou `[actions skip]` — ne jamais citer ces marqueurs en toutes lettres dans un
message de commit ou de merge, seul le commit de copie de `finalize-release` doit le porter.
Spec 0006 : statut « livrée » (sauf M6),
puis `git mv` de la spec et de `tasks/` dans `.claude/specs/archive/2026-MM-JJ-spec-0006-flux-de-release/`
avec mise à jour du README de l'archive.

**Critères d'acceptation :**
- [x] `grep -rn 'git tag' .claude/CLAUDE.md README.md k8s/README.md` ne montre plus de geste humain.
- [x] `tasks/` n'existe plus sur la branche ; l'archive contient la spec et `tasks/`.

**Vérification :**
- [ ] Relecture humaine des trois docs — à la revue de la PR de clôture #194.

**Dépendances :** T9. **Fichiers :** `.claude/CLAUDE.md`, `README.md`, `k8s/README.md`,
`.claude/specs/archive/…`. **Taille :** M.

## Checkpoint 3 — Prêt pour la première release (après T7–T10)
- [ ] Critères §4 M4 (partie statique) et M5 cochés ; merge de clôture dans `develop`.

## Tâche 11 (#205) : première release sous le nouveau flux (M6)

**Description :** Depuis `develop` à jour : `tools/next-version.sh`, `release/<version>`,
`RELEASE_NOTES.md` (les changements depuis v0.13.2 : #192, spec 0006, le flux lui-même), PR vers
`main` en brouillon, attendre le run vert (préprod contrôlée à la main), passer en prête, merger
en commit de merge. Surveiller `deploy-prod` puis `finalize-release`.

**Critères d'acceptation :**
- [ ] Tag `v<version>` sur `HEAD^2` ; release GitHub avec titre sans `#` et corps ;
      `docs/releases/v<version>.md` sur `main` ; `release/<version>` supprimée ; `develop == main`.
- [ ] `audit-prod` vert ; `DEPLOY_MAINTENANCE_WINDOW` resté à `false`.

**Vérification :**
- [ ] Les sept résultats consignés dans l'issue ; spec passée en « livrée », mémoire mise à jour.

**Dépendances :** T10 mergée. **Fichiers :** `RELEASE_NOTES.md`, `docs/releases/`. **Taille :** S (procédure).

## Checkpoint final
- [ ] Tout M6 vert ; issue de la tâche 11 fermée ; label `spec-0006` sans issue ouverte.
