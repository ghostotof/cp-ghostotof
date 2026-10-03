# Spec 0005 — Assistant « interrogez mon parcours » : suivi des tâches

Spec : `.claude/specs/0005-career-assistant.md`. Branche mère : `feature/spec-0005-career-assistant`.
Une branche par tâche, tirée de la mère, PR vers la mère. Le détail de chaque tâche vit dans son issue.

- [x] Tâche 1 — Socle Scaleway et appel réel en dev (#260) — PR #268
- [x] Tâche 2 — Une question en flux de bout en bout (#261) — PR #270
- [x] Tâche 3 — Coût borné : bornes D6 et quota (#262) — PR #282
- [ ] Tâche 4 — CV nominatif dans le corpus (#263) — bloquée par #261
- [x] Tâche 5 — Préparation du déploiement (#264)
- [x] Tâche 6 — Page Assistant (#265) — PR #327

## Release de la spec (après la tâche 6) — checklist de référence : #324 (survit à l'archivage de `tasks/`)

- [ ] Clé et projet Scaleway publiés en préprod et en prod **avant** de pousser la branche `release/*` (#264) — recette : `k8s/README.md` §2, « Assistant de parcours »
- [ ] Préprod : les quatre points de `k8s/README.md`, « Assistant de parcours : vérification pendant la release » (Secret, `nginx -T`, question réelle en flux depuis un compte de test `ROLE_TRUSTED`, journal `ai_usage`)
- [ ] Merge de clôture vers `develop` : archive de la spec et de `tasks/` sous `.claude/specs/archive/`
