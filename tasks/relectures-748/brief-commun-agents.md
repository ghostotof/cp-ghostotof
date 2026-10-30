# Consignes communes aux tâches R de #748

Dépôt /home/ghostotof/dev/cp-ghostotof, branche `feature/spec-0007-748-a11y`. Réponds en français.

## Skills (obligatoires, via l'outil Skill)
- `agent-skills:build` pour le déroulé ; **le cycle rouge/vert est régi par `superpowers:test-driven-development`** ;
  ignore `agent-skills:test-driven-development` si `build` la charge.
- **`superpowers:verification-before-completion` invoquée réellement** (outil Skill) avant d'annoncer la fin : la citer
  ne suffit pas.

## Lecture préalable
- Dans `tasks/plan-748.md` : « Global Constraints », puis, pour les tâches **R88 et suivantes**, la section
  « Corrections de la huitième relecture (2026-10-30) » en entier jusqu'à la première tâche (décisions de l'auteur,
  vérification des constats, options comparées, choix tranchés, questions, Review Focus, contraintes d'exécution, table
  des fichiers), puis **ta tâche**. (R73–R87 : « Corrections de la septième relecture » ; R59–R72 : « … sixième
  relecture » ; R46–R58 : « … cinquième relecture » ; R35–R45 : « … quatrième relecture » ; R23–R34 : « … troisième
  relecture » ; R12–R22 : « … deuxième relecture » ; R1–R11 : « Corrections de relecture ».) Constats et décisions de
  la série : `tasks/relectures-748/relecture-8-constats.md`. Les sections passées
  sont l'archive de leur moment : une note « *(rectifié le …)* » y dit ce que les séries ont rendu faux.
  Suis-la ; si elle est fausse sur le code réel (les tâches R précédentes ont pu le changer), adapte au plus près de
  son intention et note l'écart, ou rends la question.
- Règles : avec l'outil **Read** (jamais cat/sed/grep, qui ne chargent pas les règles path-scoped), celles que ta
  tâche cite ou modifie, plus, pour le frontend, `.claude/rules/frontend.md` et `.claude/rules/frontend-a11y.md`, et au
  moins un fichier de chaque zone touchée (`frontend/src`, `backend/src`, `backend/tests`, `tools/`) avant d'y écrire.
  Lis une règle avant de la modifier ; **remplace** une phrase plutôt que d'en ajouter. **Une docstring ou une règle
  qui décrit un comportement que ta tâche modifie se corrige dans le même commit que le comportement** (St6-2 et Sp6-1
  sont nés de docs laissées en arrière) : avant le commit, cherche (`grep`) le nom de chaque fonction ou état touché
  dans `.claude/rules/` et dans les docblocks voisins. Une règle ne dépasse pas
  50 000 octets (`security-user.md` en fait 47 914 : n'y ajoute rien ; `backoffice-api.md` 39 834 : n'y ajoute rien sans
  retirer d'abord) ; donne dans ton rendu la taille de chaque règle
  touchée.
- **Un fichier créé ou déplacé qui entre dans les `paths:` d'une règle est indexé (`git add <fichier>`) avant
  `tools/check-claude-rules.sh`**, qui ne voit que les fichiers suivis (sinon : « glob sans fichier suivi »).

## Tests
- **Chaque code de sortie se lit sans pipe** : `make front-test; echo "exit=$?"` — jamais `make … | tail`, qui rend le code
  de `tail` et masque l'échec. De même pour chaque cible, test hors ligne et `tools/check-claude-rules.sh`.
- Frontend, dans le conteneur uniquement : ciblé `docker compose exec -T frontend npm test -- <spec>`, puis avant le
  commit `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`, tous à 0.
  Jamais npm sur l'hôte.
- Backend, toujours `-u dev` : ciblé `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis
  `make back-test; echo "exit=$?"` et `make back-quality; echo "exit=$?"` ; `rm -rf var/cache/test` après un changement de
  service ou de configuration. Jamais de secret littéral dans un test (`TestCredentials`, `TestKeys::material()`, une
  valeur aléatoire).
- Outillage : `bash tools/tests/<script>.test.sh; echo "exit=$?"` hors ligne, et shellcheck dans l'image épinglée du job
  `tools-tests` (commande exacte dans les contraintes de la section du plan).
- Partout, si une règle ou `.claude/CLAUDE.md` est touché : `tools/check-claude-rules.sh; echo "exit=$?"`.
- Après un remplacement de masse sur l'hôte (sed/perl), un fichier créé ou déplacé : `docker compose restart frontend`
  (ou `backend`) avant de croire un test (montage désynchronisé = tests menteurs).
- Test rouge vu **pour la bonne raison** avant la correction ; un test de couverture vert d'emblée est vu rouge par la
  mutation que la tâche nomme. **Une mutation se restaure depuis une copie du fichier faite avant elle et vérifiée par
  `cmp`** (`cp f "$scratch/f.orig"`, muter, mesurer, `cp "$scratch/f.orig" f`, `cmp f "$scratch/f.orig"`), puis
  `git diff` vide sur le fichier muté ; **jamais `git checkout-index`, `git restore`, `git reset` ni aucune commande git
  qui réécrit l'index ou l'arbre**. **Une mutation s'écrit en place avec l'outil Edit, jamais par `sed -i` ni
  `perl -pi`** : ils remplacent le fichier par un nouvel inode, que le montage du conteneur ne voit pas — la mutation ne
  serait pas mesurée. Pour un refactoring pur, le
  filet est la suite existante, verte avant et après, nombre de tests noté ; dis quels tests de garde changent et pourquoi.
- **Aucun travail en arrière-plan au rendu** : ni processus détaché, ni conteneur lancé à part, ni `vitest --watch` ;
  tout ce que tu as lancé est terminé quand tu rends.
- Vitest 5 vide les imports CSS (même `?raw`) : lire un fichier source via `node:fs` (voir
  `frontend/tests/presentation/styleSheet.spec.ts`). `ResizeObserver` absent de jsdom. Faux minuteurs : rétablis en
  `afterEach` ; axe-core se bloque sous faux minuteurs. Tout état partagé (`authState`…) remis en
  `beforeEach`/`afterEach`. Toute chaîne nouvelle en FR et EN.

## Commit
Un commit, fichiers nommés (`git add <fichiers>`, jamais `-A` ni `.` ; `git rm` / `git mv` pour un fichier supprimé ou
déplacé), message en français au préfixe que donne la tâche, terminé par la ligne `Co-Authored-By` de **ton** modèle
(celle que ton harnais te donne) puis exactement :

Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt

## Interdits
Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (exclus-les de tout grep -r).
Ne jamais nommer un compte réel. **Git : seules `git add <fichiers>` et `git commit` modifient l'index ou l'arbre**
(`git mv` / `git rm` pour un fichier déplacé ou supprimé) ; jamais `git reset` — **pas même sans argument** —, ni
`add -N`, `checkout`, `switch`, `stash`, `restore`, `checkout-index`. Jamais `sed -i` ni `perl -pi` pour une mutation. Pas de push, pas de PR, pas de commentaire
GitHub. Ne touche pas aux autres tâches ni à `tasks/` ; ne coche rien. Ne modifie un test existant que là où le
comportement change exprès. Bloqué, ou décision qui revient à l'utilisateur : arrête-toi et rends la question.

## Rendu
Fichiers touchés, SHA du commit, preuve : commande et extrait du test rouge (raison de l'échec, ou mutation), lignes de
résumé et codes de sortie des cibles make, des tests hors ligne et de check-claude-rules, taille des règles touchées,
écarts au plan, questions ouvertes.
