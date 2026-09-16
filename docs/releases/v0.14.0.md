# v0.14.0 — Flux de release par branche `release/*`, pipeline en trois phases

Première version livrée par le nouveau flux : cette release a été préparée sur une branche
`release/0.14.0`, déployée en préprod à chaque push, et mise en production par le merge de sa
PR dans `main`. Le tag et cette page ont été posés par la pipeline, après la prod.

## Flux de release (spec 0006)

- La version se calcule (`tools/next-version.sh`, Conventional Commits) et la CI la confronte au
  nom de branche et au titre des notes ; un désaccord est un run rouge.
- Les images sont nommées `<version>-<sha court>`, immuables, jamais réécrites.
- Chaque push sur `release/*` construit, déploie la préprod, passe smoke tests et audit, et
  s'arrête là ; le merge dans `main` est le seul stop humain.
- Sur `main`, `deploy-prod` retrouve l'image validée en préprod (trois gardes avant tout accès
  au cluster) et ne construit jamais ; `finalize-release` pose le tag annoté, publie la release,
  copie les notes dans `docs/releases/`, supprime la branche et avance `develop`.
- Réglages GitHub : merge par commit de merge seul, rulesets sur `main`, `develop` et les tags
  `v*`, reviewer retiré de l'environnement `production`, deploy key `release-bot`.

## Documentation et outillage

- Règle d'archivage des specs : le dossier d'archive reçoit la spec et le dossier `tasks/` entier
  (#192) ; les tâches d'une spec s'empilent sur la branche de la spec, `develop` reçoit la
  clôture seule.
- Nouveau job `tools-tests` : tests des scripts de release sur dépôt temporaire, shellcheck,
  actionlint figé sur un digest.
- `CLAUDE.md`, `README.md` et `k8s/README.md` décrivent le nouveau flux.

## Sans changement applicatif

Le code du site est celui de v0.13.2 ; cette version ne modifie ni le backend ni le frontend.
