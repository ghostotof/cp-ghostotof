# Consignes communes aux tâches R de #748

Dépôt /home/ghostotof/dev/cp-ghostotof, branche `feature/spec-0007-748-a11y`. Réponds en français.

## Skills (obligatoires, via l'outil Skill)
- `agent-skills:build` pour le déroulé ; **le cycle rouge/vert est régi par `superpowers:test-driven-development`** ;
  ignore `agent-skills:test-driven-development` si `build` la charge.
- **`superpowers:verification-before-completion` invoquée réellement** (outil Skill) avant d'annoncer la fin : la citer
  ne suffit pas.

## Lecture préalable
- Dans `tasks/plan-748.md` : « Global Constraints », puis, pour les tâches **R196 et suivantes**, la section « Corrections de la
  seizième relecture (2026-11-01) » en entier jusqu'à la première tâche (ce que la série précédente a réellement fait, décisions D1 à
  D4, vérification des constats, ordre, choix tranchés, questions Q1 à Q12, Review Focus, contraintes d'exécution, table des
  fichiers), puis **ta tâche**. (R182–R195, R187b, R189b : « … quinzième relecture » ; R168–R181, R173b : « … quatorzième
  relecture » ; R152–R167, R157b : « … treizième relecture » ; R137–R151, R141b, R148b : « … douzième relecture » ; R121–R136 :
  « … onzième relecture » ; R112–R120 et R114b : « … dixième relecture » ; R102–R111 : « … neuvième relecture » ; R88–R101 :
  « Corrections de la huitième relecture » ; R73–R87 : « Corrections de la septième relecture » ; R59–R72 : « … sixième relecture » ;
  R46–R58 : « … cinquième relecture » ; R35–R45 : « … quatrième relecture » ; R23–R34 : « … troisième relecture » ; R12–R22 :
  « … deuxième relecture » ; R1–R11 : « Corrections de relecture ».) Constats et décisions de la série :
  `tasks/relectures-748/relecture-16-constats.md` (R182–R195 : `relecture-15-constats.md` ; R168–R181 : `relecture-14-constats.md` ;
  R152–R167 : `relecture-13-constats.md` ; R137–R151 : `relecture-12-constats.md` ; R121–R136 : `relecture-11-constats.md`). Les
  sections passées sont l'archive de leur moment : une note « *(rectifié le …)* » y dit ce que les séries ont rendu faux. Suis-la ; si
  elle est fausse sur le code réel (les tâches R précédentes ont pu le changer), adapte au plus près de son intention et note l'écart,
  ou rends la question.
- **Une décision de l'auteur listée au plan ou ici ne se contourne pas par une interprétation** : R166 a coupé des `echo`
  que l'auteur avait décidé de garder d'un seul tenant. Si une décision te semble contredire ta tâche, arrête-toi et rends
  la question.
- **Un balayage ne réécrit pas un docblock qu'une tâche précédente de la série a rétabli** (Sp14-17 : celui de
  `CompiledNestedReading.php`, rétabli par R161, réécrit par R167) : un écart s'y signale au rendu, il ne se corrige pas.
  **Un balayage ne refactore pas** (Sp15-10) : ce qu'il trouve à restructurer se rend en question.
- **Un balayage qui déclare un manque le vérifie par une mutation ou un `grep` cité** (Sp15-14 : le bilan de R181 a déclaré
  absent un cas `CDPATH` présent dans les quatre tests) : la commande et sa sortie vont dans le bilan.
- **Un écart au plan qui écarte un cas de test se rend en question, ne se décide pas** (R172 : le cas des réglages, écarté
  par l'agent seul, Sp15-1). Un cas qui reste vert d'emblée s'écrit quand même et se prouve par la mutation que la tâche nomme.
- **Une décision prise en cours de série est écrite au plan par le fil principal avant que la tâche parte** (Sp15-3) ; l'agent
  de la tâche n'écrit pas au plan ce qu'il applique.
- **Un correctif d'une classe de défaut s'applique à toutes les formes voisines du même mécanisme dans le périmètre** (C16-1 :
  `-b '@a=b'` passait le jugement de `-b` ; C16-2 : la garde des vidages ne voyait ni guillemet ni accent grave ; Sp16-17 :
  `bodyOf` corrigé ailleurs, `arguments()` lisait encore `,` par le texte) : cherche-les (le mécanisme, pas la ligne citée),
  nomme-les dans ton commit, et dis celles que tu laisses et pourquoi.
- **Une procédure écrite dans une règle se suit à la lettre** : si tu en ajoutes une ou en modifies une, liste **chaque**
  endroit à toucher (St16-25 : « Pour ajouter une option… Rien d'autre » omettait le jugement des valeurs, et la suivre à la
  lettre rouvrait la fuite) ; un endroit oublié est un défaut de la procédure, pas de qui la suit.
- Règles : avec l'outil **Read** (jamais cat/sed/grep, qui ne chargent pas les règles path-scoped), celles que ta tâche
  cite ou modifie, plus, pour le frontend, `.claude/rules/frontend.md` et `.claude/rules/frontend-a11y.md`, et au moins
  un fichier de chaque zone touchée (`frontend/src`, `backend/src`, `backend/tests`, `tools/`) avant d'y écrire. Lis une
  règle avant de la modifier ; **remplace** une phrase plutôt que d'en ajouter. **Une docstring ou une règle qui décrit
  un comportement que ta tâche modifie se corrige dans le même commit que le comportement** (St6-2 et Sp6-1 sont nés de
  docs laissées en arrière) : avant le commit, cherche (`grep`) le nom de chaque fonction ou état touché dans
  `.claude/rules/` et dans les docblocks voisins. **Une tâche qui modifie une règle la nomme dans ses fichiers et dans
  son rendu** (Sp15-17 : R180 a modifié `security-authentication-sessions.md` sans le dire). Un identifiant de constat
  (`CR11-3`, `A3-10`) est permis dans un commentaire, jamais dans un nom de test (décision 4 de la onzième relecture).
  **Un nom de test se raccourcit sans perdre un terme qui porte le sens** (l'état vérifié — « désactivé », pas
  « inactif » —, le palier, la tâche citée) ; s'il ne tient pas sous 140, le littéral va seul sur sa ligne, ou la
  référence en commentaire au-dessus du `it`. Une règle ne dépasse pas 50 000 octets (`security-user.md` en fait
  47 914 : n'y ajoute rien ; `backoffice-api.md` 39 834 : n'y ajoute rien sans retirer d'abord) ; donne dans ton rendu la
  taille de chaque règle touchée.
- **Une règle se coupe ou se recolle sans moignon ni mot changé** : un balayage de moignons recolle sans en créer ;
  après chaque coupe ou recollage, relis `awk 'length<=45 && NF' <règle>` et `grep -nE '^[[:space:]]*[,;:.)»]' <règle>`
  (une ligne courte ou qui commence par une ponctuation est un moignon, sauf fin de paragraphe ou élément de liste), puis
  ce que la ponctuation de tête ne voit pas — un code en ligne coupé (`perl -ne 'print "$.\n" if (() = /\x60/g) % 2'
  <règle>` : dans une règle, un code en ligne ne se coupe jamais) et un guillemet orphelin
  (`grep -nE '«[[:space:]]*$|^[[:space:]]*»' <règle>`) —, et
  compare la suite de mots avant et après (`tr -s '[:space:]' '\n'`). Une continuation de puce garde l'indentation du
  fichier.
- **Une affirmation sur un outil** (une version, une option, un comportement de curl, bash, npm, Vue) se vérifie sur la
  version **du runner** du job qui l'exécute (`ubuntu-latest` = Ubuntu 24.04 : curl 8.5.0), jamais sur celle de l'hôte
  ou d'un conteneur de dev ; si elle ne se mesure pas hors ligne, dis la méthode employée ou écris la limite.
- **Un fichier créé ou déplacé qui entre dans les `paths:` d'une règle est indexé (`git add <fichier>`) avant
  `tools/check-claude-rules.sh`**, qui ne voit que les fichiers suivis (sinon : « glob sans fichier suivi »).

## Tests
- **Chaque code de sortie se lit sans pipe** : `make front-test; echo "exit=$?"` — jamais `make … | tail`, qui rend le code
  de `tail` et masque l'échec. De même pour chaque cible, test hors ligne et `tools/check-claude-rules.sh`.
- Frontend, dans le conteneur uniquement : ciblé `docker compose exec -T frontend npm test -- <spec>`, puis avant le
  commit `make front-test; echo "exit=$?"`, `make front-lint; echo "exit=$?"`, `make front-build; echo "exit=$?"`, tous à 0.
  Jamais npm sur l'hôte.
- Backend, toujours `-u dev` : ciblé `docker compose exec -u dev backend php bin/phpunit <chemin>`, puis
  `make back-test; echo "exit=$?"` et `make back-quality; echo "exit=$?"` ; après un changement de service ou de
  configuration, le cache de test se vide **dans le conteneur** (`docker compose exec -u dev backend rm -rf
  var/cache/test`), jamais par `rm -rf` sur l'hôte, qui désynchronise le montage (R108). Jamais de secret littéral dans un test (`TestCredentials`, `TestKeys::material()`, une
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
  qui réécrit l'index ou l'arbre**. **Une mutation s'écrit en place par Python** (`open(p, 'r+')` ou `open(p, 'w')`
  sur le même chemin, qui garde l'inode), **jamais par `sed -i`, `perl -pi` ni l'outil Edit** : tous trois remplacent le
  fichier par un nouvel inode, que le montage du conteneur ne voit pas — la mutation ne serait pas mesurée et un test
  resterait vert à tort (R99 : une mutation faite par Edit est restée verte dans le conteneur backend). On restaure
  depuis la copie, puis on vérifie par `cmp`. En cas de doute sur ce que voit le conteneur : `docker compose restart
  backend` ou `docker compose restart frontend`. **Chaque mutation s'écrit dans le corps de ton message de commit**, avec sa
  commande et la ligne de sortie qui rougit (décision de l'auteur du 2026-11-01, Sp16-1 : rendues aux seuls rendus, elles ne se
  lisaient ni dans le diff ni au plan) ; format dans « Commit ». Pour un refactoring pur, le filet est la suite existante, verte
  avant et après, nombre de tests noté au même endroit ; dis quels tests de garde changent et pourquoi.
- **Aucun travail en arrière-plan au rendu** : ni processus détaché, ni conteneur lancé à part, ni `vitest --watch` ;
  tout ce que tu as lancé est terminé quand tu rends.
- **« ≤ 140 » est la longueur d'une ligne en caractères** (`awk 'length>140'`), jamais la taille d'un diff ni un nombre
  de lignes changées (R121 l'a mal lu) ; une ligne de table Markdown ou de JSON ne se coupe pas (le dire au rendu).
- Vitest 5 vide les imports CSS (même `?raw`) : lire un fichier source via `node:fs` (voir
  `frontend/tests/presentation/styleSheet.spec.ts`). `ResizeObserver` absent de jsdom. Faux minuteurs : rétablis en
  `afterEach` ; axe-core se bloque sous faux minuteurs. Tout état partagé (`authState`…) remis en
  `beforeEach`/`afterEach`. Toute chaîne nouvelle en FR et EN.

## Commit
Un commit, fichiers nommés (`git add <fichiers>`, jamais `-A` ni `.` ; `git rm` / `git mv` pour un fichier supprimé ou
déplacé), message en français au préfixe que donne la tâche, terminé par la ligne `Co-Authored-By` de **ton** modèle
(celle que ton harnais te donne) puis exactement :

Claude-Session: https://claude.ai/code/session_01SexJivTrKeHyUYAtAYLBDt

**Le corps du message porte les mutations** (R196 et suivantes), un bloc par commit, après le paragraphe qui dit ce que fait le
commit et avant les deux lignes de fin, lignes ≤ 140 (une ligne rouge plus longue se cite entière, sur sa ligne) :

```
Mutations :
- M1 <fichier>:<ligne> : <ce qui change, en une phrase ou « avant » → « après »>
  commande : <la commande exacte, telle que lancée>
  rouge : <la ligne de sortie qui rougit, copiée> (<nombre> échec(s))
- M2 …
Filet : <commande> — <n> avant, <n> après   (pour un refactoring ; « Filet : aucun » sinon)
```

Une mutation qui reste verte s'y écrit aussi (« rouge : aucune — <raison> ») et se rend en question. Le balayage de la série les
relit par `git log` : une mutation annoncée par le plan et absente du corps est « non attestée ».

## Interdits
**Aucun vidage d'environnement non filtré dans ta session**, ni pour mesurer ni pour déboguer : ni `ps e`/`ps eww`, ni
`env`, `printenv`, `set`, `declare -p`, `export -p`, `cat /proc/*/environ`, ni rien qui liste les variables du shell de ta
session (incident de R197, 2026-11-01 : un `ps e` sans filtre a imprimé l'environnement du shell parent, jeton de Claude Code
compris, dans le transcript). Une mesure de ce genre se fait **dans un `bash -c` lancé par `env -i`** avec une seule variable
factice, et sa sortie ne se lit que par `grep -c` sur la valeur factice.
**Jamais `git add -N`** (ni `--intent-to-add`), **pas même en tête d'une commande, par réflexe, pour « voir » un fichier
nouveau dans `git diff`** : quatre agents de la quatorzième série l'ont lancé ainsi. Un fichier nouveau se voit par
`git status --short` et se lit par `Read` ; il entre dans l'index par `git add <fichier>` au moment du commit (ou juste
avant `tools/check-claude-rules.sh`, voir plus haut), jamais autrement.
Ne jamais lire `.env.local`, `.env.test.local`, `config/jwt/*` ni un fichier de secrets (exclus-les de tout grep -r).
Ne jamais nommer un compte réel. **Git : seules `git add <fichiers>` et `git commit` modifient l'index ou l'arbre**
(`git mv` / `git rm` pour un fichier déplacé ou supprimé) ; jamais `git reset` — **pas même sans argument** —, ni
`add -N`, `checkout`, `switch`, `stash`, `restore`, `checkout-index`, ni `git commit --amend` (une erreur de commit se
corrige par un commit suivant). Jamais `sed -i` ni `perl -pi` pour une mutation. Pas de push, pas de PR, pas de commentaire
GitHub. Ne touche pas aux autres tâches ni à `tasks/` (seule exception : R208 écrit la sous-section « Ce que la dix-septième
série a réellement fait » du plan, avec les commandes et les nombres de son balayage) ; ne coche rien —
**aucune case `- [ ]` de `tasks/plan-748.md`**,
même celles de ta tâche (R127b l'a fait). Ne modifie un test existant que là où le
comportement change exprès. Bloqué, ou décision qui revient à l'utilisateur : arrête-toi et rends la question.

## Rendu
Fichiers touchés, SHA du commit, preuve : commande et extrait du test rouge (raison de l'échec, ou mutation — la même que
celle du corps du commit), lignes de
résumé et codes de sortie des cibles make, des tests hors ligne et de check-claude-rules, taille des règles touchées,
**écarts au plan, chacun nommé** (ce que la tâche disait, ce que le code fait, pourquoi : R181 les reporte au plan —
la treizième relecture a reproché au plan de les avoir perdus), questions ouvertes.
