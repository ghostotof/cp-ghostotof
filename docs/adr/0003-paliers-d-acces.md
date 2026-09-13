# ADR 0003 — Trois paliers d'accès : anonyme, invité, approuvé

- Statut : **accepté** (2026-09-12) — socle D1/D2/D4/D7 mergé (PR #41), D6 (PR #46-48), D5 1/3
  études de cas (PR #49-53) et D5 2/3 CV sans identité (PR #69, #71) mergés les 2026-09-12/13.
  **D5 3/3 (parcours anonymisé) est abandonné, décision du 2026-09-13** — voir D5. Suivi dans
  `tasks/plan.md` et les issues `adr-0003`.
- Date : 2026-09-10
- Portée : `config/packages/security.yaml`, `src/Security/User`, `src/Security/Authentication`,
  `tests/Security/ApiRouteExposureTest.php`, frontend `presentation/pages/LoginPage.vue` + garde de
  routeur, objectif n°9 du projet, ADR 0001 (section « Risque opérationnel du compte invité générique »)

## Contexte

L'objectif n°9 impose que rien d'identifiant ne soit exposé avant authentification. Aujourd'hui, un
seul rôle porte cette frontière : `ROLE_USER` ouvre `GET /api/cv` et `GET /api/me`, donc l'identité
réelle, les employeurs et le parcours.

L'ADR 0001 a documenté le prix de ce choix : **il n'existe aucun palier entre « visiteur anonyme » et
« accès complet aux données personnelles »**. La fuite des identifiants du compte invité générique
équivaut donc à publier le CV. Sa note finale envisageait déjà la sortie, en recommandant « un rôle
dédié (ex. `ROLE_GUEST`) donnant moins que `ROLE_USER` ». La présente décision retient l'inverse, et
la section « Alternatives écartées » dit pourquoi.

Deux constats ont motivé la reprise du sujet.

**Le compte invité générique n'a jamais été créé, et la page « À propos » le promet en production.**
Sa carte « Confidentialité » affirme qu'un compte invité permet de découvrir le site. Rien ne permet
aujourd'hui de s'en servir : aucun identifiant n'est affiché, et la page de connexion n'en dit rien.
Le site annonce une possibilité qui n'existe pas.

**Le palier unique est trop coûteux pour le visiteur légitime.** Obtenir l'accès suppose un échange
avec l'auteur du site. Un lecteur professionnel intéressé mais pressé renonce avant, et repart avec
la seule partie publique, qui ne parle jamais de travail réel.

## Décisions

### D1 — Trois paliers, et ce qui distingue le deuxième

| Palier | Nature réelle | Obtention |
|---|---|---|
| Anonyme | Publiable **et** indexable | Aucune action |
| `ROLE_USER` | Publiable, **non indexable** | Un clic, sans identifiants |
| `ROLE_TRUSTED` | Identifiant, **jamais publiable** | Accordé nominativement par `ROLE_SUPER` |
| `ROLE_SUPER` | Administration | Inchangé, amorçage CLI |

Le palier intermédiaire **n'est pas un contrôle d'accès et ne doit jamais être présenté comme tel.**
Un script obtient le jeton aussi facilement qu'un humain, en une requête. Ce qui est placé derrière
est donc, de fait, public.

Ce qu'il apporte réellement est autre chose, et cela mérite d'être nommé parce que c'est un objectif
atteignable là où la confidentialité ne l'est pas : ce qui exige une requête authentifiée n'est pas
indexé par un moteur, pas archivé, pas collecté par les aspirateurs ordinaires. C'est de la
**discrétion**, pas du secret. Ne pas remonter dans une recherche sur son nom est possible ;
empêcher un lecteur déterminé de lire ne l'est pas.

Conséquence directe et structurante : **la règle d'admission au palier intermédiaire est « accepterais-je
de publier ceci ? »**. Si la réponse est non, le contenu appartient au palier supérieur.

### D2 — `ROLE_USER` change de sens, et c'est le cœur de la décision

`ROLE_USER` devient le palier le plus bas, celui de quiconque a cliqué. Le droit qu'il portait
jusqu'ici passe à `ROLE_TRUSTED`.

Le motif est un détail du code qui décide de tout. `CpgUser::getRoles()` ajoute `ROLE_USER` à **tout**
compte, sans condition. Introduire un rôle *en dessous* aurait donc obligé le jeton invité à ne pas
passer par cette entité, donc à ouvrir **un second chemin d'authentification** à côté de l'existant.
Deux chemins, c'est deux fois la surface d'autorisation, et celui qu'on écrit en second reçoit
toujours moins d'attention que celui qu'on relit chaque jour.

L'inversion garde **un seul chemin** et rend cette ligne exacte au lieu d'en faire un piège : tout
compte authentifié a bien le palier de base, ce qu'elle affirme déjà. Elle remet aussi le nommage en
accord avec Symfony, où `ROLE_USER` signifie « authentifié, sans plus » — sens que le projet
détournait discrètement.

### D3 — Le nom : `ROLE_TRUSTED`

Critère retenu : **un rôle nomme ce qu'il autorise, ou la relation qui fonde l'octroi ; jamais la
façon dont on l'obtient, ni qui est la personne.** Le comment et le qui changent avec le temps, le
droit reste.

`ROLE_TRUSTED` nomme la relation, qui est exactement ce qui fonde l'octroi ici : une décision
nominative après un échange. Il se lit correctement dans une règle d'accès, et ne se périmera pas si
le contenu réservé s'élargit un jour au-delà de l'identité, vers des dépôts privés ou des références.

### D4 — Le CV ne descend jamais au palier intermédiaire

Non négociable, et c'est la contrainte qui borne tout le reste. `GET /api/cv` et `GET /api/me` exigent
`ROLE_TRUSTED`. Y placer le CV reviendrait à publier son identité en croyant l'avoir protégée, ce qui
est pire que de la publier franchement : la fausse protection empêche d'y repenser.

### D5 — Le palier intermédiaire justifie du contenu à créer, pas du contenu déplacé

Il serait vide si l'on se contentait de redistribuer l'existant. Par ordre de valeur :

1. **Études de cas techniques** — un problème, ses contraintes, la solution retenue, ses compromis, un
   résultat mesuré. C'est le contenu qui convainc un lecteur technique, et il n'est pas identifiant
   tant que le client n'est pas nommé. La page « Incidents » prouve que le format est déjà maîtrisé.
2. **CV sans identité** — compétences, années par technologie, réalisations ; ni nom, ni employeurs,
   ni coordonnées. De quoi décider si l'on veut demander le vrai.
3. **Parcours anonymisé** — durées, tailles d'équipe, secteurs, technologies par période. **Le plus
   délicat du lot** : un parcours suffisamment détaillé se ré-identifie par recoupement, surtout sur
   un marché étroit. Rester large sur les dates et les secteurs, ou s'en abstenir.

   **Amendement du 2026-09-13 : on s'en abstient.** Une fois les deux premiers contenus en place,
   ce que ce troisième ajouterait se réduit à une seule chose, la *séquence* dans le temps — et
   c'est précisément la séquence qui sert d'empreinte : une suite « n ans dans tel secteur, puis
   m ans avec telle stack » se recoupe avec un profil public en quelques minutes, même sans nom ni
   date, et le rendre assez large pour ne plus l'être le vide de son intérêt. Le classement des
   technologies par années cumulées est déjà public ; l'ancienneté par domaine et les réalisations
   sont au palier de base. La chronologie est exactement ce que le palier nominatif apporte en
   plus, et c'est là qu'elle reste. **D5 se compose donc de deux contenus, pas trois.**

### D6 — Mécanique du palier intermédiaire

Un endpoint public émet un jeton portant **exactement** `ROLE_USER`, sans passer par un compte en
base. Trois contraintes, chacune pour une raison distincte :

- **Limité en débit par adresse**, sinon c'est un distributeur de jetons. Le projet a déjà deux
  limiteurs de ce type, pour le contact et la définition de mot de passe.
- **Jeton court, sans renouvellement.** Il ne protège rien, mais un jeton de longue durée circule et
  se retrouve collé dans des endroits imprévus.
- **Jamais de compte matérialisé en base.** Un compte invité réel hériterait de `ROLE_USER` par
  `getRoles()`, ce qui est désormais correct, mais il ajouterait un secret partagé à gérer sans rien
  apporter.

### D7 — Une hiérarchie de rôles est déclarée

Il n'en existe aucune aujourd'hui, et cela ne fonctionne que parce que le rôle de base est ajouté à
tout le monde. Dès qu'un palier intermédiaire apparaît, l'implication doit être écrite :

```yaml
role_hierarchy:
    ROLE_TRUSTED: [ROLE_USER]
    ROLE_SUPER: [ROLE_TRUSTED]
```

Sans elle, un compte d'administration se voit refuser le CV, ce qui est absurde et ne se découvre
qu'à l'usage.

### Ce qui n'est jamais négociable

- **Le palier intermédiaire n'est pas une protection.** Aucune donnée identifiante derrière, jamais,
  quel que soit l'argument d'ergonomie.
- **Le test d'exposition des routes s'écrit avant la bascule**, pas après. Voir les conséquences.
- **La migration des comptes existants se fait par une migration Doctrine**, jamais à la main.

## Conséquences

- **Deux lignes de `access_control` changent de sens sans changer de texte.** C'est le seul vrai
  danger de cette décision, et il est silencieux : le CV passerait de « réservé » à « accessible en un
  clic » sans qu'aucun test ne rougisse ni qu'aucun diff ne le montre. Même famille que la ConfigMap
  nginx qui n'atteignait pas son conteneur et que l'image périmée servie sous le bon nom. L'ampleur
  reste faible : hors tests, `ROLE_USER` n'apparaît que **cinq fois**, dont deux dans les règles
  d'accès et une dans `getRoles()`.
- **`ApiRouteExposureTest` gagne un troisième profil.** Il vérifie aujourd'hui l'anonyme et le compte
  sans droits d'administration ; il devra prouver qu'un compte du palier de base se voit refuser
  `/api/cv` et `/api/me`. **Écrit d'abord**, il échoue, et c'est lui qui garantit ensuite que la
  bascule a bien eu lieu.
- **Une migration Doctrine attribue `ROLE_TRUSTED` à tous les comptes existants**, faute de quoi ils
  perdent l'accès au CV en silence.
- **L'objectif n°9 est reformulé.** Sa rédaction actuelle fait de `ROLE_USER` le seuil de l'accès aux
  données personnelles ; ce seuil devient `ROLE_TRUSTED`. La phrase « il n'y a pas de palier entre
  anonyme et accès complet » cesse d'être vraie.
- **L'ADR 0001 est amendée**, section « Risque opérationnel du compte invité générique » : le compte
  partagé qu'elle décrit n'a plus de raison d'exister, et l'hygiène opérationnelle qu'elle imposait
  disparaît avec lui. C'est le principal gain de sécurité de cette décision : **un secret partagé qui
  ne protégeait rien restait un passif** — à stocker, tourner, retirer d'un dépôt s'il y tombait, et
  réutilisé ailleurs tôt ou tard.
- **La carte « Confidentialité » de la page « À propos » doit être réécrite.** Elle promet un compte
  invité qui n'existera pas.
- **Le frontend gagne un état d'authentification intermédiaire.** La garde de routeur et l'en-tête
  distinguent déjà connecté et non connecté ; il faudra trois cas au lieu de deux.
- **Coût assumé : le contenu du palier intermédiaire n'existe pas encore.** Livrer le mécanisme sans
  le contenu produirait un bouton qui ne donne rien, ce qui est pire que l'absence de bouton.

## Alternatives écartées

- **`ROLE_GUEST` en dessous de `ROLE_USER`**, comme le recommandait l'ADR 0001. Rejeté pour la raison
  de D2 : `getRoles()` accordant `ROLE_USER` à tout compte, le jeton invité aurait dû contourner
  l'entité utilisateur, ouvrant un second chemin d'authentification. Le contournement d'un piège vaut
  moins que sa suppression.
- **Statu quo : créer le compte invité partagé et publier ses identifiants** sur la page de connexion.
  Strictement équivalent en pratique — tout le monde entre — mais moins honnête, puisque la forme
  suggère un contrôle d'accès, et plus coûteux, puisqu'il faut gérer un secret. Publier un mot de
  passe dans un dépôt public le grave par ailleurs dans l'historique pour toujours.
- **Compte invité partagé aux identifiants communiqués sur demande.** Restaure un vrai contrôle, mais
  ramène exactement le coût que l'ADR 0001 documentait, et conserve la friction que cette décision
  cherche à lever.
- **`ROLE_INVITED`** : nomme le chemin d'obtention. Le jour où ce droit s'accorde autrement qu'en
  invitant, le nom mentirait.
- **`ROLE_RECRUITER`** : nomme la personne, et exclut d'emblée un pair, un client ou un ancien
  collègue à qui le même accès serait légitime.
- **`ROLE_VERIFIED`** : collision avec un sens très répandu, celui de l'adresse de courriel confirmée.
  Un lecteur extérieur se tromperait, et ce site a vocation à être lu par des lecteurs extérieurs.
- **`ROLE_MEMBER`** : n'exprime pas l'octroi et suggère une communauté qui n'existe pas.
- **Ne rien changer et rendre le CV public.** Cohérent et défendable, mais contraire à l'objectif n°9,
  qui est une contrainte du projet et non une préférence d'implémentation.
