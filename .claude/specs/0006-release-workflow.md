# SPEC — Flux de release par branche `release/*` et pipeline en trois phases

> Statut : **validée** (rédigée le 2026-09-16, design puis texte validés en session le même jour,
> points « à valider avant le plan » du §10 compris ; plan de tâches à suivre).
> Remplace le flux « tag = déclencheur » en place depuis la première release : la mise en prod
> devient le merge d'une branche `release/<version>` dans `main`, le tag une conséquence posée par
> la pipeline, jamais plus un geste manuel ni un déclencheur.
>
> ⚠️ **Ce fichier vit dans un dépôt public** (`.claude/` est versionné, hors `CLAUDE.local.md`).
> Aucun secret, aucune adresse réelle, aucun nom de personne. Voir le journal d'audit en §10.

---

## 1. Objectif

Séparer clairement trois choses que le flux actuel confond dans un seul geste (`git tag` +
`push`) : **préparer** une mise en prod (une branche, des images, une préprod, des retouches),
**décider** de la faire (un merge dans `main`), et **la nommer** (un tag annoté, une release
GitHub). Aujourd'hui le tag fait les trois à la fois, ce qui a coûté : un tag recréé sert une
image périmée (v0.7.0, deux runs perdus), des notes tapées au moment du tag et mutilées par le
nettoyage git (v0.7.1), six titres de release à renommer à la main (v0.11.0 à v0.13.1), et une
pipeline qui construit quatre images pour chaque tentative.

### Ce que le nouveau flux garantit

- **`main` est exactement ce qui tourne en prod**, à tout moment : elle n'avance que par le merge
  d'une release, et ce merge est le seul stop humain.
- **Aucune image n'est jamais réécrite** : chaque push de release produit `<version>-<sha>`,
  immuable, et la prod déploie l'image déjà validée en préprod, jamais une reconstruction.
- **Le tag est posé une fois, après la prod verte**, sur le commit déployé, avec des notes relues
  dans une PR (`RELEASE_NOTES.md`) plutôt que tapées dans une annotation.
- **La version se calcule**, depuis Conventional Commits, et la CI tient le nom de branche
  honnête : un désaccord est un run rouge, pas une surprise en prod.
- **Une branche de feature ne construit rien** : la phase 1 seule (tests, analyse statique),
  moins de minutes de CI, moins d'images orphelines sur GHCR.

### Hors périmètre

- Le contenu des jobs de qualité (phase 1), du build (`preprod` toujours `FROM production`), du
  déploiement (migration avant rollout, seed préprod, fenêtre de maintenance), des smoke tests,
  des audits et du rollback préprod : **inchangés**, seuls leurs déclencheurs et leurs `needs`
  bougent.
- CodeQL et Dependabot : inchangés.
- Le préfixe `__Host-` des cookies (issue #87, second temps) : sans rapport, ne pas profiter du
  premier rollout préprod du nouveau flux pour le glisser.
- Un environnement de recette par branche de feature : non, la préprod reste unique et réservée
  aux releases.

## 2. Décisions structurantes

- **D1 — Branches.** `main` et `develop` sont permanentes. `feature/<slug>`, `fix/<slug>` et
  `hotfix/<slug>` partent de `develop` à jour et y reviennent par PR. `release/<X.Y.Z>` part de
  `develop` à jour et se termine dans `main`. Un `hotfix/*` ne diffère d'un `fix/*` que par ce
  qui suit : sa release est coupée sans attendre le reste de `develop`. Si `develop` porte alors
  des features non prêtes, la branche `release/*` du hotfix est coupée depuis `main` et reçoit le
  hotfix en cherry-pick — **seule exception** à « une release part de `develop` », à écrire dans
  `CLAUDE.md` le jour où elle se présente, pas avant.
- **D2 — La version se calcule, le nom de branche la porte, la CI les confronte.**
  `tools/next-version.sh` lit le dernier tag `v*` joignable depuis `main` (`git describe
  --tags --abbrev=0 --match 'v*' origin/main`) et les commits `main..HEAD` (hors merges) :
  `BREAKING CHANGE` dans le corps ou `!` après le type → majeur ; `feat` → mineur ; tout autre
  type → correctif ; aucun commit → échec explicite (« rien à livrer »). Tant que le projet est en
  `0.x`, un majeur est ramené à un mineur (convention semver pour les versions initiales, le
  passage à `1.0.0` est un geste délibéré, pas un `!`). Le script est **la seule** implémentation
  du calcul : la CI l'exécute, l'humain l'exécute pour nommer sa branche. Sur `release/*`, un job
  `release-version` compare le résultat au nom de branche et au titre de `RELEASE_NOTES.md`
  (§D4) ; un désaccord est un échec qui bloque tout le reste du run.
- **D3 — Images nommées `<version>-<sha court>` et `<version>-<sha court>-preprod`.** Le SHA est
  celui du commit poussé sur `release/*` (7 caractères, `git rev-parse --short`). Une image n'est
  jamais reconstruite sous un nom existant : le job `build-images` échoue si le manifeste existe
  déjà sur GHCR (un re-run d'un commit déjà construit passe en no-op, pas en réécriture). Le
  `Makefile` ne change pas : `TAG=` reçoit `<version>-<sha>` au lieu de `vX.Y.Z`.
- **D4 — `RELEASE_NOTES.md` à la racine, sur la branche de release.** Première ligne `# vX.Y.Z —
  <titre>`, corps Markdown libre (sections `##`), exactement ce qui allait dans l'annotation du
  tag. Le fichier est **revu dans la PR** comme du code et retouché avec les retouches. Après la
  release, il reste en place (écrasé par la release suivante) **et** est copié en
  `docs/releases/vX.Y.Z.md` par le job de finalisation (§D8) : le dépôt accumule son changelog,
  le tag et la release GitHub restent l'archive de référence.
- **D5 — Déclencheurs.** Le workflow écoute `push` sur `main`, `develop`, `feature/**`, `fix/**`,
  `hotfix/**`, `release/**`, **`dependabot/**`** et **plus aucun tag** (un tag `v*` ne déclenche
  rien : il est une conséquence). Le déclencheur `pull_request` est retiré : chaque PR est
  interne au dépôt et les checks d'un push sur la branche source s'affichent sur la PR par SHA
  de tête, donc le conserver doublerait chaque run de phase 1 (constaté en T3 : deux runs par
  commit). `dependabot/**` est là pour la même raison (amendement T4) : Dependabot cible
  `develop` et pousse ses branches dans le dépôt, sans cette entrée ses PR n'auraient plus
  aucun check ; ses runs tournent avec un token en lecture seule et sans secret, ce dont la
  phase 1 n'a pas besoin. Un `concurrency` par `github.ref` annule le run
  précédent sur `feature/*`, `fix/*`, `hotfix/*`, `develop` et `release/*` ; **jamais sur
  `main`** : un déploiement prod en cours va au bout. `run-name` affiche la branche (qui porte
  la version sur `release/*`) et le SHA du commit.
- **D6 — Trois phases, gardées par la branche.**
  - *Phase 1, toujours* : `test-backend`, `test-frontend`, `sast-backend`, `phpstan-backend`,
    `rector-backend`, `lsp-check-backend`, inchangés.
  - *Phase 2, `release/**` seulement* : `release-version` (D2) → `build-images` (D3) →
    `deploy-preprod` → `smoke-test-preprod` + `audit-preprod` → `rollback-preprod` sur échec.
    **Le run s'arrête là.** Plus de job prod en attente d'approbation.
  - *Phase 3, `main` seulement* : `deploy-prod` (D7) → `finalize-release` (D8) + `audit-prod`
    (non bloquant, inchangé).
- **D7 — Le job prod ne construit rien et vérifie avant de toucher au cluster.** Sur `main`,
  `HEAD` doit être un commit de merge ; `RELEASE_SHA=$(git rev-parse HEAD^2)` désigne le HEAD de
  la release. Le job recalcule la version depuis `RELEASE_NOTES.md` de ce commit, forme
  `<version>-<sha court>`, puis exige : (a) que les quatre manifestes d'images existent sur
  GHCR ; (b) qu'un run du workflow sur `release/<version>` pour `RELEASE_SHA` se soit conclu
  `success` (`gh run list --commit … --status success`). L'une des deux manque → échec, cluster
  intact, résumé qui dit laquelle. Un push sur `main` qui n'est pas un merge (le commit de copie
  de D8 arrive avec `[skip ci]` ; un push direct par erreur) échoue à la première garde. Le
  déploiement lui-même (`kustomize edit set image`, `backend-config`, migration en Job, fenêtre
  de maintenance, `apply -k`, `rollout status`) est celui d'aujourd'hui, avec
  `$IMAGE:<version>-<sha>` à la place de `$IMAGE:vX.Y.Z`.
- **D8 — `finalize-release` après `deploy-prod` vert, étapes ordonnées et idempotentes.**
  1. Tag annoté `vX.Y.Z` sur `RELEASE_SHA`, annotation = `RELEASE_NOTES.md` de ce commit,
     `--cleanup=verbatim` ; existe déjà sur le même commit → rien, existe ailleurs → échec.
  2. `git push origin vX.Y.Z`.
  3. Release GitHub : titre = première ligne sans `#`, corps = le reste, `--verify-tag
     --latest` ; déjà publiée → rien. L'artefact `social-preview` est rattaché comme aujourd'hui.
  4. Copie `RELEASE_NOTES.md` → `docs/releases/vX.Y.Z.md`, commit sur `main` avec `[skip ci]` ;
     fichier identique déjà présent → rien.
  5. Suppression de `release/<version>` ; déjà absente → rien.
  6. `git merge --ff-only main` dans `develop`, push ; fast-forward impossible → **arrêt propre**,
     le résumé demande une PR `main` → `develop`. Aucune étape n'annule les précédentes ; un
     re-run reprend là où ça s'est arrêté.
  Le job pousse **avec une clé de déploiement dédiée** (`RELEASE_DEPLOY_KEY`, clé privée SSH en
  secret du dépôt, sa moitié publique déclarée deploy key en écriture), seul acteur de bypass
  des rulesets de D9 — un ruleset de dépôt personnel refuse l'application `github-actions`
  (vérifié le 2026-09-16, §10). Il n'a donc pas besoin de `contents: write` pour pousser, mais
  le garde pour `gh release create`. Les push d'une deploy key **déclenchent les workflows**,
  contrairement à ceux de `GITHUB_TOKEN` : le `[skip ci]` de l'étape 4 est **indispensable**
  (sans lui, le commit de copie relance la phase 3, que D7 refuserait, mais un run rouge pour
  rien), le push de l'étape 6 relance une phase 1 sur `develop` (sain), et le push du tag ne
  lance rien puisque plus aucun tag n'est un déclencheur.
- **D9 — Réglages GitHub, manuels, avant la première release.** (a) Merge de PR : **commit de
  merge seulement**, squash et rebase désactivés (sinon `HEAD^2` n'existe pas et D7 ne retrouve
  plus l'image). (b) `main` protégée par un ruleset : PR obligatoire, check requis =
  `audit-preprod` et `smoke-test-preprod` sur le SHA de tête, `allowed_merge_methods: [merge]`
  (la règle `pull_request` d'un ruleset le porte, en plus du réglage global), suppression
  interdite, force-push interdit, **bypass : les deploy keys** (`actor_type: DeployKey`, seul
  acteur accepté sur un dépôt personnel — `github-actions` est refusé, §10). (c) `develop`
  protégée : PR obligatoire, check requis = la phase 1, même bypass. (d) Environnement
  `production` : les secrets restent, le **reviewer requis est retiré** — le merge est le stop,
  deux stops pour une personne c'est un de trop. (e) `delete_branch_on_merge` reste à `false` :
  c'est D8 qui supprime la branche, après la prod, pas le merge. (f) Un ruleset sur les tags
  `v*` : création, suppression et déplacement interdits à tous, bypass deploy keys — seul le job
  pose un tag. (g) La deploy key `release-bot` : paire ed25519 générée hors dépôt, publique en
  deploy key **avec écriture**, privée en secret `RELEASE_DEPLOY_KEY` ; à faire tourner comme
  les kubeconfigs, jamais réutilisée ailleurs.
- **D10 — Le flux de fin, vu de l'humain, tient en six gestes.** `git switch develop && git
  pull` ; `git switch -c release/$(tools/next-version.sh)` ; écrire `RELEASE_NOTES.md` ; ouvrir
  la PR vers `main` en brouillon ; retoucher jusqu'au vert ; passer la PR en prête et merger. Tout
  le reste est la machine. Il n'y a plus de `git tag` dans `CLAUDE.md`.

### Contrats externes (vérifiés le 2026-09-16, T3)

- **Push `GITHUB_TOKEN`** : « events triggered by the `GITHUB_TOKEN` will not create a new
  workflow run », exceptions `workflow_dispatch`, `repository_dispatch` et les PR créées par un
  workflow (docs GitHub, « Trigger a workflow »). Sans objet pour D8 depuis le choix de la deploy
  key, mais vrai pour tout ce que le job ferait encore avec ce token. `[skip ci]` s'applique aux
  événements `push` et `pull_request` (docs « Skip workflow runs »).
- **Bypass de ruleset** : `actor_type: Integration` avec l'application `github-actions` (id
  15368) → **422 « Actor GitHub Actions integration must be part of the ruleset source or owner
  organization »** sur ce dépôt personnel. `actor_type: DeployKey` → accepté, sur un ruleset de
  branche comme de tag (créés en `enforcement: disabled`, relus, supprimés). D'où D8/D9.
- **`gh run list --workflow pipeline.yml --branch <b> --commit <sha> --status success`** :
  fonctionne (gh 2.46 et suivants) ; un run `in_progress` ou `cancelled` n'est pas compté
  (vérifié sur un commit en cours et sur un run annulé). Un commit a aujourd'hui deux runs, `push`
  et `pull_request` : le doublon que D5 retire.
- **`docker manifest inspect ghcr.io/…:<tag>`** : code 0 si le manifeste existe, « manifest
  unknown » sinon, **sans login** puisque les paquets sont publics (k8s/README §1bis).

## 3. Carte des capacités (ordre de construction)

| M | Livrable | Dépend de | Vérifiable par |
|---|---|---|---|
| M1 | `tools/next-version.sh` + son test, contrats externes vérifiés | — | test shell vert en phase 1, réponses consignées en §10 |
| M2 | Phase 1 : déclencheurs D5, `concurrency`, `run-name`, plus aucun `if: refs/tags` | M1 | un push de feature ne lance que six jobs |
| M3 | Phase 2 sur `release/**` : `release-version`, images D3, préprod, rollback | M2 | une `release/0.x.y` déploie la préprod et s'arrête |
| M4 | Phase 3 sur `main` : gardes D7, déploiement, `finalize-release` D8 | M3 | merge → prod → tag, release, copie, branche supprimée, `develop` avancée |
| M5 | Réglages D9 (wizard), docs : `CLAUDE.md` (objectif 10, « Deployment invariants »), `README.md` (ligne « Livraison »), `k8s/README.md` | M4 | plus aucune mention de `git tag -a` comme geste humain |
| M6 | Première release réelle sous le nouveau flux | M5 | release publiée par la machine, `main == prod`, `develop` fast-forwardée |

M2 à M4 se livrent en **trois PR empilées** sur la branche de la spec (une branche par milestone, chacune sur la précédente, `develop` ne recevant que la PR de clôture) :
la pipeline reste cohérente à chaque PR (une phase de plus, jamais une phase cassée). Entre M4 et
M6, l'ancien flux (tag manuel) n'existe plus : la première release **est** le test de M6, elle se
fait en préprod d'abord par construction.

## 4. Critères d'acceptation

### M1 — Calcul de version

- Depuis `v0.13.2` et des commits `fix`, `docs`, `ci` : `0.13.3`. Avec un `feat` : `0.14.0`. Avec
  `feat!` ou `BREAKING CHANGE` en `0.x` : `0.14.0` (majeur ramené) ; en `1.x` : `2.0.0`.
- Aucun commit depuis le tag : sortie non nulle et message explicite.
- Un commit de merge est ignoré ; un commit hors convention est ignoré avec avertissement (il ne
  compte ni pour ni contre).
- Le test crée un dépôt temporaire, ne touche pas au dépôt courant, et tourne en phase 1.

### M2 — Phase 1

- Un push sur `feature/x` lance exactement les six jobs de qualité ; `gh run view` ne montre
  aucun job `build-images`, `deploy-*`.
- Un second push sur la même branche annule le premier run (`cancelled`), pas sur `main`.
- Un push de tag `v*` ne lance **rien**.

### M3 — Phase 2

- Sur `release/0.14.0` avec un titre `# v0.14.0 — …` : `release-version` vert ; avec
  `release/0.13.3` et le même contenu : rouge, message nommant les trois valeurs.
- Les quatre images `0.14.0-<sha>` existent sur GHCR après le run ; un re-run ne les réécrit pas.
- La préprod tourne avec `0.14.0-<sha>-preprod` (vérifié par `kubectl get deploy -o
  jsonpath`), les smoke tests et l'audit passent, le run se termine sans job en attente.
- Un smoke test en échec déclenche `rollback-preprod`, comme aujourd'hui.

### M4 — Phase 3

- Un push direct sur `main` (non-merge) échoue à la garde « commit de merge », cluster intact.
- Un merge dont `HEAD^2` n'a pas de run vert échoue à la garde (b), cluster intact.
- Un merge valide déploie `0.14.0-<sha>` en prod ; ensuite le tag `v0.14.0` pointe sur `HEAD^2`,
  la release GitHub porte le titre sans `#` et le corps des notes, `docs/releases/v0.14.0.md`
  existe sur `main`, `release/0.14.0` est supprimée, `develop` pointe sur `main`.
- Un re-run de `finalize-release` après succès ne fait rien et reste vert.
- `develop` ayant bougé : les étapes 1 à 5 sont faites, l'étape 6 s'arrête avec un résumé
  explicite, le job est **vert** (l'arrêt est un état attendu, pas une erreur — même raisonnement
  que le seed qui décline en code 0).

### M5 — Réglages et documentation

- Un squash ou un rebase depuis l'interface GitHub est impossible sur le dépôt.
- Un push direct sur `main` ou `develop` est refusé par GitHub (pas seulement par la CI).
- `CLAUDE.md` ne contient plus le bloc `git tag -a … --cleanup=verbatim` comme geste humain ;
  la section « Deployment invariants » explique le nouveau cycle et **garde** les leçons qui
  restent vraies (image périmée, notes dans le tag → notes dans le fichier, `--cleanup=verbatim`
  désormais dans le job).

## 5. Structure du projet

```
tools/next-version.sh                 # D2, seule implémentation du calcul
tools/tests/next-version.test.sh      # M1, dépôt temporaire
tools/github-settings-wizard.sh       # M5, D9 : guide les réglages manuels (skill wizard)
.github/workflows/pipeline.yml        # D5–D8 ; les jobs existants gardent leur nom
RELEASE_NOTES.md                      # D4, sur chaque release/* puis sur main/develop
docs/releases/vX.Y.Z.md               # D4, copie par D8, un fichier par release
```

### Ce qui est touché en dehors

- `.claude/CLAUDE.md` : objectif 10 (git flow), « Deployment invariants » (release notes, tag
  réutilisé, `create-release`), « Commands » (plus de `TAG=1.2.3` en exemple humain).
- `README.md` : la ligne « Livraison » du tableau (« déclenché par tag » → « déclenché par le
  merge d'une release »).
- `k8s/README.md` : si le §4 RBAC mentionne le déclenchement par tag.
- `Makefile` : rien.

## 6. Commandes

```bash
# Nommer une release (depuis develop à jour, tags à jour)
git fetch --tags origin && tools/next-version.sh          # → 0.14.0
git switch -c release/0.14.0 && git push -u origin HEAD

# Vérifier ce qui tourne en préprod
kubectl -n preprod get deploy backend -o jsonpath='{.spec.template.spec.containers[0].image}'

# Retrouver le run vert d'un commit de release (ce que fait D7)
gh run list --branch release/0.14.0 --commit <sha> --status success --workflow pipeline.yml
```

## 7. Style

- Bash des scripts et des `run:` : `set -euo pipefail`, pas de `[ -z "$VAR" ]` sans `:-`,
  messages d'erreur qui nomment les valeurs comparées (D2, D7).
- Chaque job de la pipeline garde son commentaire d'en-tête expliquant **pourquoi** il est
  gardé par telle branche, comme aujourd'hui pour `migrate-job` ou `real_ip`.
- Actions figées sur un SHA, comme le reste du fichier.

## 8. Stratégie de test

- **`next-version.sh`** : test shell sur un dépôt temporaire (cas de §4 M1), en phase 1, donc
  bloquant.
- **La pipeline** ne se teste qu'en la jouant : chaque milestone M2–M4 est validé par un push réel
  sur une branche du bon type et un `gh run view` consigné dans la PR. `actionlint` en phase 1
  attrape les erreurs de syntaxe et d'expressions avant le push.
- **Les gardes de D7** se testent négativement en M4 avant le premier vrai merge : un push
  direct sur `main` **depuis une branche jetable de test n'est pas possible** (D9 l'interdit) ;
  on teste donc les gardes en les extrayant dans `tools/verify-release-merge.sh`, testable sur
  un dépôt temporaire comme `next-version.sh`, et le job ne fait que l'appeler.

## 9. Limites

### Toujours

- Une image porte `<version>-<sha>` ; jamais de tag Docker mobile (`latest`, `0.14.0` nu).
- Le tag git est posé par `finalize-release`, jamais à la main, jamais déplacé.
- `preprod` construite `FROM production` ; la prod déploie l'image validée en préprod.
- `RELEASE_NOTES.md` relu dans la PR avant le merge.

### Demander avant

- Ajouter un déclencheur (un `schedule`, un `workflow_dispatch`) : chacun doit dire quelle phase
  il lance et pourquoi.
- Réintroduire un reviewer requis sur `production`.
- Étendre le bypass du ruleset de `main` à quelqu'un d'autre que `github-actions`.
- Passer en `1.0.0`.

### Jamais

- Reconstruire une image sur `main`.
- Un `push --force` sur `main` ou `develop`, y compris par un job.
- Un secret, un nom de domaine réel ou un nom de personne dans `RELEASE_NOTES.md` (il devient
  une release publique) — même règle que pour les specs.

## 10. Journal des validations

**2026-09-16** — Design validé en session, dans cet ordre : version dans le nom de branche plutôt
qu'un tag mobile (le tag mobile réintroduisait l'image périmée de v0.7.0) ; CI qui confronte nom
de branche et calcul plutôt qu'une branche sans version ; merge dans `main` comme stop humain
plutôt que l'approbation d'environnement (plus d'approbations en attente empilées par push) ;
`merge --ff-only` dans `develop` plutôt qu'un rebase (pas de réécriture d'une branche partagée) ;
commit de merge + `HEAD^2` plutôt qu'un fast-forward local (la PR reste la trace) ; notes dans
un fichier relu, copié en `docs/releases/` **et** gardé à la racine. Constat qui rend D2
possible : 200 derniers commits 100 % Conventional Commits.

**Audit de sensibilité avant publication** : aucun secret, aucune adresse, aucun nom de
personne ; les noms de domaine cités sont ceux déjà présents dans `pipeline.yml` public.

**Validés le 2026-09-16** (avant le plan) : le retrait du déclencheur `pull_request` (D5),
l'exception hotfix (D1), `actionlint` en phase 1 (§8), l'extraction des gardes dans
`tools/verify-release-merge.sh` (§8).

**2026-09-16, T3** — Contrats externes vérifiés (§2). Le bypass `github-actions` étant refusé,
trois sorties ont été comparées : (1) deploy key en bypass, tout automatique et tout verrouillé
au prix d'un secret ; (2) aucun push du robot sur une branche protégée, copie des notes par
l'humain et PR `main` → `develop` ouverte par le job ; (3) rulesets sans « PR obligatoire »,
la garde D7 seule refuse un push direct. **Choix : (1)**, D8 et D9 amendées en conséquence
(deploy key `release-bot`, secret `RELEASE_DEPLOY_KEY`, `[skip ci]` désormais indispensable).
