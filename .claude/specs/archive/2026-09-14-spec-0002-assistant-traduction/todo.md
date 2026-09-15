# TODO — Assistant de traduction FR/EN du backoffice (spec 0002)

Les tâches vivent dans **GitHub Issues** (label `spec-0002`, #91 à #103), pas ici :
`gh issue list --label spec-0002`. L'index ordonné, les checkpoints, les risques et les questions
ouvertes sont dans [`plan.md`](./plan.md). Git flow : branche `feature/ai-translation-assistant`
depuis `develop`, une PR par tâche, empilée.

Gates par checkpoint : backend `make back-quality && make back-test` ; frontend (si touché)
`make front-lint && make front-build && make front-test` ; k8s (si touché) `kubectl kustomize`
preprod **et** prod.
