# Consignes communes aux tâches R de #748 (frontend)

Dépôt /home/ghostotof/dev/cp-ghostotof, branche `feature/spec-0007-748-a11y`. Réponds en français.

## Skills (obligatoires, via l'outil Skill)
- `agent-skills:build` pour le déroulé ; **le cycle rouge/vert est régi par `superpowers:test-driven-development`** ;
  ignore `agent-skills:test-driven-development` si `build` la charge.
- `superpowers:verification-before-completion` avant d'annoncer la fin.

## Lecture préalable
- Dans `tasks/plan-748.md` : « Global Constraints », puis, pour les tâches **R23 et suivantes**, la section
  « Corrections de la troisième relecture (2026-10-29) » en entier jusqu'à la première tâche (réponses de l'auteur,
  vérification des constats, choix de conception, Review Focus, contraintes d'exécution, table des fichiers), puis
  **ta tâche**. (R12–R22 : « Corrections de la deuxième relecture » ; R1–R11 : « Corrections de relecture ».)
  Suis-la ; si elle est fausse sur le code réel (les tâches R précédentes ont pu le changer), adapte au plus près de
  son intention et note l'écart, ou rends la question.
- Règles : avec l'outil **Read** (jamais cat/sed/grep, qui ne chargent pas les règles path-scoped), celles que ta
  tâche cite ou modifie, plus `.claude/rules/frontend.md` et `.claude/rules/frontend-a11y.md`, et au moins un fichier
  de chaque zone de `frontend/src` avant d'y écrire. Lis une règle avant de la modifier. Une règle ne dépasse pas
  50 000 octets : `tools/check-claude-rules.sh` vert si une règle ou `.claude/CLAUDE.md` est touché.
  **`frontend-session.md` approche du plafond (42 433 octets après R18)** : sois concis, remplace plutôt qu'ajouter,
  et indique sa taille dans ton rendu ; ne la découpe pas toi-même (c'est R34). Si ton ajout la ferait passer au-dessus
  de 49 500 octets, mets ce texte dans `tasks/regle-session-en-attente.md` (que R34 intégrera), comme le plan le dit.

## Tests
- Dans le conteneur uniquement : ciblé `docker compose exec -T frontend npm test -- <spec>`, puis avant le commit
  `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`, tous à 0.
  Jamais npm sur l'hôte.
- Après un remplacement de masse sur l'hôte (sed/perl) ou un fichier créé : `docker compose restart frontend` avant
  de croire un test (montage désynchronisé = tests menteurs).
- Test rouge vu **pour la bonne raison** avant la correction. Pour un refactoring pur, le filet est la suite
  existante, verte avant et après ; dis quels tests de garde changent et pourquoi.
- Vitest 5 vide les imports CSS (même `?raw`) : lire un fichier source via `node:fs` (voir
  `frontend/tests/presentation/styleSheet.spec.ts`). `ResizeObserver` absent de jsdom. Faux minuteurs : rétablis en
  `afterEach` ; axe-core se bloque sous faux minuteurs. Tout état partagé (`authState`…) remis en
  `beforeEach`/`afterEach`. Toute chaîne nouvelle en FR et EN.

## Commit
Un commit, fichiers nommés (`git add <fichiers>`, jamais `-A` ni `.` ; `git rm` pour un fichier supprimé), message
en français `fix(frontend): … (#748)` ou `refactor(frontend): … (#748)`, terminé par la ligne `Co-Authored-By` de
**ton** modèle (celle que ton harnais te donne) puis exactement :

Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt

## Interdits
Ne jamais lire `.env.local`, `.env.test.local` ni un fichier de secrets (exclus-les de tout grep -r). Ne jamais
nommer un compte réel. Aucun git checkout/switch/stash/reset. Pas de push, pas de PR, pas de commentaire GitHub. Ne
touche pas aux autres tâches ni à `tasks/` (seule exception : `tasks/regle-session-en-attente.md`, voir plus haut) ;
ne coche rien. Ne modifie un test existant que là où le comportement
change exprès. Bloqué, ou décision qui revient à l'utilisateur : arrête-toi et rends la question.

## Rendu
Fichiers touchés, SHA du commit, preuve : commande et extrait du test rouge (raison de l'échec), lignes de résumé et
codes de sortie des trois cibles make (et de check-claude-rules si règle touchée), écarts au plan, questions ouvertes.
