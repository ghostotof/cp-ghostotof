# Archive des plans de tâches

Chaque feature d'envergure (une spec, une ADR, une remédiation d'audit) est menée avec un plan de
tâches écrit par le skill de planification dans **`tasks/plan.md`** et **`tasks/todo.md`**, à la
racine du dépôt. Ces deux fichiers vivent **sur la branche de la feature, le temps de la feature**,
et ne doivent **jamais atteindre `develop`** : le dossier `tasks/` n'existe pas sur `develop` ni sur
`main`.

Règle, telle que fixée le 2026-09-15 (voir `.claude/CLAUDE.md`, « Task plans ») :

1. Pendant la feature, `tasks/` est committé sur la branche de la feature, au besoin.
2. Au merge dans `develop` qui **clôt complètement** la feature, le plan et la todo sont copiés ici,
   dans un sous-dossier `AAAA-MM-JJ-<slug>/` daté du jour de clôture, puis `tasks/` est supprimé
   dans ce même merge.
3. Un plan en cours sur une feature encore ouverte n'est pas archivé.

Les cinq premiers plans (2026-09-03 → 2026-09-15) ont vécu dans `tasks/` sur `develop` avant que
cette règle n'existe ; ils ont été extraits de l'historique git (`git show <commit>:tasks/plan.md`)
à leur dernier état, et archivés ici en une fois. Le plan de l'ADR 0003 n'a pas de `todo.md` : à
l'époque le fichier n'avait pas été renouvelé et portait encore celui de l'audit.

| Dossier | Feature | Dernier commit dans `tasks/` |
|---|---|---|
| `2026-09-03-adr-0001-provisionnement-utilisateurs` | ADR 0001, invitation des comptes, réorganisation du backoffice | `7250b61` |
| `2026-09-04-remediation-audit-securite` | Deuxième audit de sécurité, points C1–C8 / I1–I9 | `1641cf1` |
| `2026-09-13-adr-0003-paliers-d-acces` | ADR 0003, paliers d'accès, suivis #76/#77/#78 | `82e06e4` |
| `2026-09-14-spec-0002-assistant-traduction` | Spec 0002, assistant de traduction (Symfony AI phase 1) | `51d06b8` |
| `2026-09-15-specs-0003-0004-uuid-ordre-des-contenus` | Specs 0003 et 0004, UUID v7 puis ordre des contenus | `c4076d8` |

Ces fichiers sont figés : ils décrivent l'état du plan au moment de la clôture, avec ses cases
cochées, ses checkpoints et ses questions ouvertes. On ne les met pas à jour.
