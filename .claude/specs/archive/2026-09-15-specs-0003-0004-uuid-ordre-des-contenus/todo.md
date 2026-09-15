# TODO — Clés primaires UUID (spec 0003) puis ordre des contenus (spec 0004)

Les tâches vivent dans **GitHub Issues**, pas ici : phase A, label `spec-0003`, #120 à #127
(`gh issue list --label spec-0003`) ; phase B, label `spec-0004`, issues à ouvrir une fois `v0.11.0`
en production. L'index ordonné, les checkpoints, les risques et les questions ouvertes sont dans
[`plan.md`](./plan.md). Git flow : une branche `feature/uuid-<n>-<slug>` par tâche depuis `develop`,
une PR par tâche, empilée.

Gates par checkpoint : backend `make back-quality && make back-test` ; frontend (si touché)
`make front-lint && make front-build && make front-test` ; migration `php bin/console
doctrine:schema:validate` sur une base migrée depuis un état seedé ; k8s (si touché)
`kubectl kustomize` preprod **et** prod.
