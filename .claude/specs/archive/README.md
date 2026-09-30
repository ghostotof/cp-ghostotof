# Archive des plans de tâches

Chaque feature d'envergure (une spec, une ADR, une remédiation d'audit) est menée avec un plan de
tâches écrit par le skill de planification dans **`tasks/plan.md`** et **`tasks/todo.md`**, à la
racine du dépôt. Ces deux fichiers vivent **sur la branche de la feature, le temps de la feature**,
et ne doivent **jamais atteindre `develop`** : le dossier `tasks/` n'existe pas sur `develop` ni sur
`main`.

Règle, fixée le 2026-09-15 et élargie le 2026-09-16 (issue #192, voir `.claude/CLAUDE.md`, « Task plans and
spec archiving ») :

1. Pendant la feature, `tasks/` est committé sur la branche de la feature, au besoin.
2. Au merge dans `develop` qui **clôt complètement** la feature, un sous-dossier `AAAA-MM-JJ-<slug>/`
   daté du jour de clôture reçoit, par `git mv` :
   - **la spec elle-même**, déplacée depuis `.claude/specs/<NNNN>-<slug>.md` — `.claude/specs/` ne
     liste ainsi que les specs encore ouvertes ;
   - **le dossier `tasks/` en entier**, tel quel, dans un sous-dossier `tasks/` — et pas seulement
     `plan.md`/`todo.md` : tout ce que la feature y a rangé (checkpoints, notes) part avec.
   Ce déplacement supprime `tasks/` de l'arbre dans ce même merge. Mettre à jour le tableau
   ci-dessous et les liens qui visaient l'ancien chemin de la spec (`CLAUDE.md`, ADR, autres specs).
3. Un plan en cours sur une feature encore ouverte n'est pas archivé. Une feature sans spec
   (remédiation d'audit, ADR seule) archive `tasks/` seul.

Les cinq premiers plans (2026-09-03 → 2026-09-15) ont vécu dans `tasks/` sur `develop` avant que
cette règle n'existe ; ils ont été extraits de l'historique git (`git show <commit>:tasks/plan.md`)
à leur dernier état, et archivés ici en une fois. Le plan de l'ADR 0003 n'a pas de `todo.md` : à
l'époque le fichier n'avait pas été renouvelé et portait encore celui de l'audit. Le plan de la
spec 0001 est plus ancien encore : écrit avant `tasks/`, il vivait dans `.claude/plans/`, dossier
supprimé avec ce rangement.

| Dossier | Feature | Dernier commit dans `tasks/` |
|---|---|---|
| `2026-09-03-adr-0001-provisionnement-utilisateurs` | ADR 0001, invitation des comptes, réorganisation du backoffice | `7250b61` |
| `2026-09-04-remediation-audit-securite` | Deuxième audit de sécurité, points C1–C8 / I1–I9 | `1641cf1` |
| `2026-09-08-spec-0001-veille-technique` | Spec 0001, radar de veille technique (`Portfolio/Watch`, ADR 0002) ; la spec et son plan (ex-`.claude/plans/0001-tech-watch.md`), pas de `todo.md` | (spec et plan déplacés par `git mv` le 2026-09-26) |
| `2026-09-13-adr-0003-paliers-d-acces` | ADR 0003, paliers d'accès, suivis #76/#77/#78 | `82e06e4` |
| `2026-09-14-spec-0002-assistant-traduction` | Spec 0002, assistant de traduction (Symfony AI phase 1) ; la spec a rejoint le dossier le 2026-09-26 | `51d06b8` |
| `2026-09-15-specs-0003-0004-uuid-ordre-des-contenus` | Specs 0003 et 0004, UUID v7 puis ordre des contenus ; les deux specs ont rejoint le dossier le 2026-09-26 | `c4076d8` |
| `2026-09-16-spec-0006-flux-de-release` | Spec 0006, flux de release par branche `release/*` et pipeline en trois phases — **première archive dans la nouvelle disposition** : la spec elle-même + `tasks/` entier | (déplacés par `git mv`, PR #194) |
| `2026-09-16-remediation-audit-securite-3-lot-1` | Troisième audit de sécurité, lot 1 : phases 0–3 (A1/A25 en prod par v0.14.1, réglages GitHub et RBAC, journal de sécurité) ; phases 4–6 dans un lot 2 avec son propre `tasks/` | (déplacé par `git mv`, PR #219) |
| `2026-09-22-purge-pending-invitations` | Issue #238, purge automatique des invitations jamais activées (RGPD, minimisation) : `tasks/` seul, feature sans spec — design validé en session, 3 tâches par sous-agents, revue finale de branche qui a corrigé le plan (la relance repousse le délai, hachage vide requis, plancher d'un jour) | (déplacé par `git mv`, PR de clôture) |
| `2026-09-23-remediation-audit-securite-3-lot-2` | Troisième audit de sécurité, lot 2 : phases 4–5 (A6/A7 jeton d'invitation hors des URL, hygiène code et infra A10/A12/A14/A15/A16/A17/A23, registre RGPD) ; checkpoints préprod joués après ce merge ; phase 6 (sauvegardes Postgres) en spec séparée | (déplacé par `git mv`, PR de clôture du lot 2) |

Les dossiers antérieurs au 2026-09-16 gardent `plan.md` et `todo.md` à plat plutôt que dans un
sous-dossier `tasks/` ; leurs specs, restées un temps dans `.claude/specs/` avec un statut
« livrée », les ont rejoints le 2026-09-26 pour que `.claude/specs/` ne liste bien que les specs
ouvertes. Cette disposition à plat n'est pas migrée.

Ces fichiers sont figés : ils décrivent l'état du plan au moment de la clôture, avec ses cases
cochées, ses checkpoints et ses questions ouvertes. On ne les met pas à jour — seuls les liens vers
une spec déplacée sont réécrits, pour qu'ils ne cassent pas.
