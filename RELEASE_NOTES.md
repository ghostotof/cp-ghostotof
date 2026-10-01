# v0.18.7 — Le report de main dans develop aboutit quand develop a avancé

Correctif de l'outillage de release seul : `tools/finalize-release.sh`, son test et
`CLAUDE.md`. Aucun changement de l'application, aucune migration, aucun secret nouveau. Les
images backend et frontend sont reconstruites à l'identique du code de la v0.18.6.

## La consigne de report donnait une PR impossible à merger (#293)

Quand `develop` avance pendant une release, `finalize-release` ne peut pas l'avancer en
fast-forward sur `main` et s'arrête proprement. Son résumé demandait alors une PR
`main` → `develop`. Cette PR ne pouvait jamais être mergée : sa tête est le commit de copie des
notes, qui saute la CI, donc aucun check exigé par le ruleset de `develop` ne tournait dessus.
La PR #291 (release v0.18.3) est restée bloquée ainsi.

- Le résumé donne maintenant les commandes qui reportent `main` par une branche
  `fix/sync-main-vX.Y.Z` coupée depuis `develop`. Son commit de tête est un merge testé par la
  pipeline, et la PR cible `develop` explicitement.
- La consigne explique la reprise après un conflit de merge, ou quand la branche de report
  existe déjà.
- Une fois le report mergé, un nouveau passage du script répond « déjà reporté » au lieu de
  redonner des commandes devenues inapplicables.
- Le test exécute réellement ces commandes dans un dépôt temporaire, puis vérifie la branche
  poussée.

## À vérifier

- Préprod : déploiement, smoke tests et audit verts, comme pour toute release. L'application ne
  change pas.
- Production : le job `finalize-release` utilise déjà le script corrigé. Si `develop` n'a pas
  bougé depuis la fusion de #305, son résumé dit « avancée en fast-forward ».
