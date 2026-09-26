# Spec 0005 — Assistant « interrogez mon parcours » : suivi des tâches

Spec : `.claude/specs/0005-career-assistant.md`. Branche mère : `feature/spec-0005-career-assistant`.
Une branche par tâche, tirée de la mère, PR vers la mère. Le détail de chaque tâche vit dans son issue.

- [ ] Tâche 1 — Socle Scaleway et appel réel en dev (#260)
- [ ] Tâche 2 — Une question en flux de bout en bout (#261) — bloquée par #260
- [ ] Tâche 3 — Coût borné : bornes D6 et quota (#262) — bloquée par #261
- [ ] Tâche 4 — CV nominatif dans le corpus (#263) — bloquée par #261
- [ ] Tâche 5 — Préparation du déploiement (#264) — bloquée par #261
- [ ] Tâche 6 — Page Assistant (#265) — bloquée par #261, #262

## Release de la spec (après la tâche 6)

- [ ] Clé Scaleway publiée en préprod et en prod **avant** de pousser la branche `release/*` (#264)
- [ ] Préprod : `nginx -T | grep assistant` sur le sidecar, puis une question réelle en flux depuis un compte de test `ROLE_TRUSTED`
- [ ] Merge de clôture vers `develop` : archive de la spec et de `tasks/` sous `.claude/specs/archive/`
