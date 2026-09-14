# SPEC — Ordre des contenus par glisser-déposer et groupes de traduction (phase B)

> Statut : **design validé en session le 2026-09-14**, à découper en tâches.
> Dépend de la spec 0003 (clés primaires UUID), livrée et en production **avant** le début de cette
> phase. Livraison prévue : `v0.12.0`.
>
> ⚠️ **Ce fichier vit dans un dépôt public** (`.claude/` est versionné, hors `CLAUDE.local.md`).
> Aucun secret, aucune adresse réelle, aucun nom de compte. Voir le journal d'audit en §10.

---

## 1. Objectif

Sur les pages du backoffice, l'ordre d'affichage des entrées (principes et traits Qualité, cartes
« site » et « moi » de la page À propos, Contributions, Incidents, sections du CV sans identité,
Études de cas, produits surveillés) se règle aujourd'hui par un champ numérique `Position` dans le
formulaire, sans unicité ni aide, et le tableau affiche ce nombre brut. L'admin raisonne en
chiffres, séparément pour le FR et pour l'EN, et deux entrées à la même position se départagent
par leur id.

Cette spec remplace ce mécanisme par un **glisser-déposer** (avec alternative clavier) dans le
tableau, un bouton **« Enregistrer l'ordre »**, et surtout un **lien explicite entre une entrée et
ses traductions** : un déplacement suit le contenu **quelle que soit la langue**. Le champ
`Position` disparaît des formulaires ; la position n'est plus jamais saisie.

### Le vrai sujet de démonstration

Un lien FR/EN posé en base là où il n'existait pas, avec une migration qui apparie l'existant sans
rien casser et une voie de sortie vers un modèle plus riche (§2, D1) ; un endpoint d'ordre dont la
règle de validation sert aussi de contrôle de concurrence ; un glisser-déposer natif, sans
dépendance, accessible au clavier et testable dans jsdom. Et neuf ressources qui partagent une
seule logique de domaine plutôt que neuf copies.

### Public visé

Le compte `ROLE_SUPER` qui administre le contenu. Les endpoints publics ne changent pas de contrat :
ils continuent de servir chaque locale triée par `position`.

### Hors périmètre

- La page admin des études de cas (issue #104) : le backend (groupe, endpoint d'ordre) est livré
  ici, la page l'utilisera à sa création.
- Tout rapport ou repli sur les traductions manquantes (« afficher le FR si l'EN manque ») : rendu
  possible par le groupe, pas construit.
- Le support tactile du glisser-déposer : le clavier est l'alternative, le backoffice est un outil
  de poste de travail.
- `ExperienceTechnology` (pas de position) et `AboutSettings` (singleton par locale) : rien à
  ordonner.
- L'ajout d'une troisième langue : cette spec n'y met aucun obstacle (§2, D2) mais ne la construit
  pas.

## 2. Décisions structurantes

- **D1 — Le groupe de traduction est un identifiant partagé, pas une entité.** Les 8 entités
  localisées à position reçoivent `translation_group uuid NOT NULL` et un index unique
  `(translation_group, locale)`. Deux lignes de même groupe sont le même contenu dans deux langues.
  Alternative écartée : une table de contenu par contexte portant la position, que les lignes de
  traduction référenceraient — plus normalisée (position stockée une fois, requêtes « traductions
  manquantes » naturelles), mais huit tables et une jointure sur chaque lecture publique pour un
  besoin qui n'existe pas. **Voie de migration prévue** : le jour où un besoin *sur le groupe*
  apparaît, l'UUID de groupe devient la clé primaire de la nouvelle table et les lignes existantes
  la référencent déjà ; la migration A → B est mécanique.
- **D2 — Rien ne câble le couple FR/EN.** Le champ « Version de » liste les entrées de **toute autre
  locale** dont le groupe n'a pas la locale du formulaire ; le tableau rend ses badges de langue
  depuis `SUPPORTED_LOCALES` ; les `#[Assert\Choice(choices: ['fr', 'en'])]` des DTO backoffice
  pointent sur `Locale::cases()`. Une troisième langue est une troisième ligne par groupe.
- **D3 — La position n'est jamais saisie ; l'endpoint d'ordre est son seul écrivain.** `position`
  sort des DTO d'écriture et des signatures `create`/`update`. Une entrée rattachée à un groupe
  **hérite de la position du groupe** ; une entrée sans groupe prend `max(position) + 1` de son
  périmètre. Même esprit que « aucune version n'est jamais tapée » du contexte Watch.
- **D4 — Règle d'ensemble exact.** `PUT …/order` exige exactement les clés du périmètre : inconnue →
  422 `unknown-order-entry`, manquante → 422 `incomplete-order`. Ce n'est pas que de la validation :
  si une entrée a été créée ou supprimée entre le chargement de la page et l'enregistrement,
  l'ensemble diffère, le serveur refuse, le frontend recharge et le dit. Contrôle de concurrence
  optimiste sans version ni horodatage. Les positions sont renumérotées `0…n-1` à chaque
  enregistrement.
- **D5 — Une logique, neuf usages.** `Portfolio/Shared/Domain/Service/OrderAssigner` est un service
  pur : il reçoit les entités du périmètre (interface `Orderable` : `orderingKey(): string`,
  `moveToPosition(int)`) et la liste ordonnée des clés, vérifie D4 et écrit l'index de chaque clé
  sur **toutes** les entités qui la portent. `orderingKey()` est le groupe pour une entité localisée
  (FR, EN et toute langue future reçoivent la même position), l'id pour `WatchedProduct`.
- **D6 — Enregistrement explicite, pas au dépôt.** Le brouillon d'ordre vit dans la page ; « Ordre ·
  modifié, non enregistré », Annuler, Enregistrer l'ordre. Tant qu'il est modifié, les mutations
  (Modifier, Supprimer, Créer la version, envoi du formulaire) sont désactivées avec une aide :
  elles rechargeraient la liste et perdraient le brouillon. Quitter la route demande confirmation
  (`onBeforeRouteLeave` + `window.confirm`, cohérent avec les suppressions) ; `beforeunload` couvre
  la fermeture de l'onglet.
- **D7 — Glisser-déposer natif HTML5, sans dépendance, clavier obligatoire.** La poignée est un
  `<button>` (`aria-label` « Déplacer : {titre} »), ↑/↓ déplacent la ligne, le focus reste sur la
  poignée, une zone `role="status"` annonce la nouvelle position. La clé glissée est gardée dans
  l'état du composable, pas dans `dataTransfer` (absent de jsdom), ce qui rend le flux testable avec
  de simples événements. Alternative écartée : `vuedraggable`/SortableJS — tactile et animations,
  mais une dépendance de plus à épingler et un composant qui ne se teste pas sans vraie mise en
  page.
- **D8 — Tableau : une ligne par groupe, langues empilées dans la cellule** (option A des
  maquettes). Poignée sur le groupe ; chaque langue a sa ligne interne avec ses actions ; une langue
  absente est signalée avec un bouton « Créer la version XX » qui ouvre le formulaire en création
  déjà rattaché au groupe. Les colonnes de date sont `text-nowrap`. Sur Qualité et À propos, le
  tableau affiche désormais **toutes les langues** : le sélecteur de locale de ces pages ne pilote
  plus que le formulaire et l'assistant de traduction. Les cartes « moi » gardent un tableau par
  catégorie, chacun avec son propre ordre.
- **D9 — L'assistant de traduction rattache son brouillon.** Le brouillon (spec 0002) garde le
  `translationGroup` de l'entrée source ; enregistrer la version proposée la lie sans geste
  supplémentaire. Le backend de l'assistant reste agnostique (spec 0002, D2) : la phrase « `position`
  est recopiée » de cette spec devient « le groupe est recopié ».

### Contrats vérifiés (2026-09-14, dans le code)

- Tri des lectures publiques : `orderBy position ASC` (+ `addOrderBy id` en repli sur About) par
  locale, et par `(locale, category)` pour les cartes « moi » — le périmètre d'ordre des cartes
  « moi » est donc la **catégorie**, toutes langues confondues.
- Les seeds (`app:{about,quality,contributions,incidents,case-studies,anonymous-cv}:seed`) créent FR
  puis EN par **index aligné** (`foreach ($cards as $position => $card)`) : le groupe de l'entrée FR
  se passe à l'entrée EN du même index sans restructurer le contenu.
- `BackofficeUserInvitationResource` (`Post`, `read: false`) est le modèle d'une opération sans
  lecture préalable sur un DTO non Doctrine ; `HasProblemType` (dans `Shared/`) le modèle des 422 à
  `problemType`.
- Les opérations d'item portent `requirements: ['id' => Requirement::UUID]` depuis la spec 0003 :
  `PUT /backoffice/incidents/order` ne peut pas être capturé par `PUT /backoffice/incidents/{id}`.
- Aucune dépendance frontend hors `bootstrap`, `vue`, `vue-i18n`, `vue-router` : D7 n'en ajoute
  aucune.

## 3. Carte des capacités (ordre de construction)

| # | Capacité | Dépend de | Livrable |
|---|---|---|---|
| B1 | Groupes : `translationGroup` sur les 8 entités (constructeur `?Uuid`, `attachToTranslationGroup`, `detachFromTranslationGroup`), interface `Orderable`, migration réversible avec appariement (§5), seeds, `Assert\Choice` sur `Locale::cases()` | spec 0003 | Migration jouée, `schema:validate` vert, seeds verts sur base vide |
| B2 | Écriture : `position` retirée des DTO/`create`/`update`, `translationGroup` nullable dans les DTO d'écriture, héritage de position (D3), `TranslationAlreadyExistsException` (409), DTO de lecture avec `translationGroup` | B1 | Tests fonctionnels CRUD des 8 ressources adaptés + cas 409 |
| B3 | `OrderAssigner` + exceptions 422 + `reorder()` sur les 9 `Administrator` (transaction unique) | B1 | Tests unitaires du service |
| B4 | Les 9 ressources `Backoffice<X>OrderResource` (`PUT …/order`, 204), Processors, `exception_to_status` | B3 | Tests fonctionnels par ressource (§4) |
| B5 | Frontend, briques partagées : `domain/admin/shared/ordering/`, `useOrderDraft`, `useRowDragAndDrop`, `OrderHandle.vue`, `OrderToolbar.vue`, clés `admin.order.*` | B4 | Tests unitaires et de composant |
| B6 | Première page complète : Incidents (entité, repository `reorder`, tableau groupé, « Version de », « Créer la version », assistant rattaché, garde de route) | B5 | Spec Vitest + axe, lint, build |
| B7+ | Déroulé page par page, une PR chacune : Contributions, CV sans identité, Qualité (principes, traits), À propos (cartes site, cartes « moi » par catégorie), Watch (ids, sans groupe) | B6 | Mêmes critères que B6 |
| B8 | Documentation et release : `CLAUDE.md`, spec 0002, `.gitignore` (`.superpowers/`), préprod vérifiée FR **et** EN, prod | B7 | `v0.12.0` en production |

B1 à B6 forment la première tranche verticale complète ; B7 n'ajoute que du câblage.

## 4. Critères d'acceptation

### B1 — Groupes et migration

- Après migration, pour chacune des 8 tables : `translation_group uuid NOT NULL`, index unique
  `(translation_group, locale)`.
- Appariement : une ligne FR et une ligne EN de même `position` (et même `category` pour les
  cartes « moi ») partagent un groupe **si et seulement si** ce couple est unique de chaque côté ;
  toute autre ligne reçoit son propre groupe. Aucune ligne n'est supprimée ni modifiée au-delà de
  cette colonne. `down()` supprime la colonne et l'index.
- Sur base vide, chaque seed produit, pour chaque index, un groupe partagé par FR et EN ; le nombre
  de groupes distincts est le nombre d'entrées par locale.

### B2 — Écriture

- `POST` sans `translationGroup` → nouvelle entrée, nouveau groupe, `position = max + 1` du
  périmètre (0 sur une table vide).
- `POST` avec le groupe d'une entrée d'une autre locale → l'entrée créée porte ce groupe et **sa
  position** ; la réponse 201 l'expose.
- `POST` avec un groupe qui a déjà cette locale → 409, `type: /errors/translation-already-exists`.
- `POST` avec un groupe inconnu du périmètre → 422.
- `PUT` avec `translationGroup: null` sur une entrée groupée → l'entrée reçoit un groupe frais, sa
  position est conservée. `PUT` avec un autre groupe → rattachement, position héritée, 409 si la
  locale y existe déjà.
- Un corps contenant `position` est ignoré (champ absent du DTO), jamais 400.

### B3/B4 — Ordre

- `OrderAssigner` : nominal (index écrit sur chaque entité de chaque clé) ; clé inconnue → exception
  ; clé manquante → exception ; doublon → exception ; liste vide sur périmètre vide → aucun effet,
  aucune exception ; un groupe à deux locales reçoit une même position ; une entité hors périmètre
  n'est jamais touchée.
- `PUT /api/backoffice/incidents/order` avec les clés inversées → 204 ; la collection backoffice
  et **les deux endpoints publics** `/api/incidents/fr` et `/api/incidents/en` reflètent le nouvel
  ordre. Même test pour chacune des 8 ressources localisées ; Watch sur `GET /api/watch` n'expose
  pas l'ordre, le test relit la collection backoffice.
- Cartes « moi » : réordonner `technical` laisse `hobby` et `personal` intacts (positions relues).
- Anonyme → 401 ; palier de base → 403 ; `ROLE_SUPER` sans `X-XSRF-TOKEN` → 403.
- `ApiRouteExposureTest` et `AccessControlAnchoringTest` inchangés et verts ; `debug:router` ne
  montre aucune route synthétisée pour les ressources d'ordre (`#[ApiProperty(identifier: false)]`
  si nécessaire).

### B6 — Frontend (page Incidents)

- Le tableau affiche une ligne par groupe, la poignée, un badge par locale de `SUPPORTED_LOCALES`,
  et « Traduction manquante » + « Créer la version EN » quand une locale manque.
- Glisser la première ligne sur la troisième puis « Enregistrer l'ordre » envoie `PUT …/order` avec
  les clés `[g2, g3, g1]` ; la barre repasse à l'état enregistré ; la liste est rechargée.
- Au clavier : focus sur la poignée, `ArrowDown` déplace la ligne d'un rang, le focus reste sur la
  même poignée, la zone `role="status"` annonce « Déplacé en position 2 sur 3 ».
- État modifié : Modifier, Supprimer, Créer la version et le bouton Enregistrer du formulaire sont
  `disabled` avec l'aide « Enregistrez ou annulez l'ordre d'abord » ; Annuler restaure l'ordre du
  serveur et réactive tout.
- 422 `incomplete-order` à l'enregistrement → la liste est rechargée, un message `role="alert"`
  explique que la liste a changé entre-temps, le brouillon est abandonné.
- « Créer la version EN » ouvre le formulaire en création, `locale = en`, « Version de » sur le
  groupe, `version` et `occurredAt` recopiés, prose vide, en-tête « Créer ».
- Éditer l'entrée FR puis « Proposer la version EN » (assistant) donne un formulaire de création
  rattaché au même groupe ; l'enregistrer envoie `translationGroup` dans le `POST`.
- Le champ numérique `Position` n'existe plus ; aucun `position` n'est envoyé par le formulaire.
- `onBeforeRouteLeave` en état modifié appelle `window.confirm` ; refuser reste sur la page.
- `expectNoAccessibilityViolation` vert avec le tableau groupé, la poignée et la barre rendus ;
  `npm run lint` : toutes les chaînes sous `admin.order.*` (fr et en).

## 5. Structure du projet

### Backend

```
src/Portfolio/Shared/Domain/Service/OrderAssigner.php                 # D5, pur
src/Portfolio/Shared/Domain/Orderable.php                              # orderingKey(), moveToPosition()
src/Portfolio/Shared/Domain/Exception/UnknownOrderEntryException.php   # 422, HasProblemType
src/Portfolio/Shared/Domain/Exception/IncompleteOrderException.php     # 422, HasProblemType
src/Portfolio/Shared/Domain/Exception/TranslationAlreadyExistsException.php   # 409, HasProblemType
src/Portfolio/<Contexte>/Domain/Entity/*.php                            # translationGroup, Orderable
src/Portfolio/<Contexte>/Domain/Repository/*RepositoryInterface.php     # findByTranslationGroup(), findAll() déjà présent ; findByCategory() pour AboutMeCard
src/Portfolio/<Contexte>/Application/*Administrator.php                 # create/update sans position, avec ?Uuid $translationGroup ; reorder(list<string>)
src/Portfolio/<Contexte>/Presentation/ApiResource/Backoffice<X>Resource.php        # - position (écriture), + translationGroup
src/Portfolio/<Contexte>/Presentation/ApiResource/Backoffice<X>OrderResource.php   # Put /backoffice/<x>/order, read: false, 204
src/Portfolio/<Contexte>/Infrastructure/ApiPlatform/Backoffice<X>OrderProcessor.php
src/Portfolio/<Contexte>/Presentation/Command/Seed*Command.php          # groupe FR → EN par index
migrations/Version2026MMDDHHMMSS.php                                   # 8 tables, appariement, réversible
config/packages/api_platform.yaml                                      # + 3 entrées exception_to_status
```

Contextes : `About` (SiteCard, MeCard), `AnonymousCv`, `CaseStudy`, `Contribution`, `Incident`,
`Quality` (Principle, Trait) ; `Watch` (`WatchedProduct`) reçoit `Orderable` (clé = id), `reorder()`
et sa ressource d'ordre sur `{ "ids": [...] }`, sans groupe.

Corps des ressources d'ordre : `{ "groups": [uuid…] }` (`NotBlank`, `All(Uuid)`, `Unique`) ;
`BackofficeAboutMeCardOrderResource` ajoute `category` (`Choice` sur `AboutMeCardCategory::cases()`) ;
`BackofficeWatchedProductOrderResource` prend `ids`.

### Frontend — `frontend/src/`

```
domain/admin/shared/ordering/groupByTranslationGroup.ts   # entries × SUPPORTED_LOCALES → lignes {key, position, byLocale, missing}
domain/admin/shared/ordering/moveKey.ts                   # (keys, from, to) → keys
domain/admin/*/entities/*.ts                              # + translationGroup: string ; - position dans les types d'entrée
domain/admin/*/repositories/*.ts                          # + reorder(keys) ; list() sans locale sur Quality/About ; + category sur MeCard
infrastructure/admin/*/Http*Repository.ts                 # PUT …/order ; mapping 422 → 'stale-order'
application/admin/shared/useOrderDraft.ts                 # serverOrder, draft, isDirty, move, reset, save
application/admin/shared/useRowDragAndDrop.ts             # handlers dragstart/dragover/drop, clé en état interne
presentation/ui/admin/OrderHandle.vue                     # bouton poignée, ↑/↓, focus, annonce
presentation/ui/admin/OrderToolbar.vue                    # statut + Annuler + Enregistrer l'ordre
presentation/pages/admin/*.vue                            # tableau groupé, « Version de », « Créer la version », garde de route
infrastructure/i18n/locales/{fr,en}.json                  # admin.order.*, admin.<page>.translationOfLabel, createVersion
```

Tests miroir sous `tests/` (`tests/domain/admin/shared/ordering/…`, `tests/application/admin/shared/…`,
`tests/presentation/ui/admin/Order{Handle,Toolbar}.spec.ts`, extension des specs de pages).

### Ce qui est touché en dehors du contexte

- **`.claude/specs/0002-ai-translation-assistant.md`** : D2 et M5, « `position` est recopiée » →
  « le groupe est recopié » ; la ligne « Lien persistant `translationGroup` : hors périmètre » du §1
  renvoie vers cette spec.
- **`CLAUDE.md`** : sous « Backend architecture », le groupe de traduction (D1, D2), « la position
  n'est jamais saisie » (D3), `OrderAssigner` et la règle d'ensemble exact (D4) ; sous « Frontend
  architecture / Backoffice », les briques `ordering/` partagées et la règle « le tableau rend ses
  langues depuis `SUPPORTED_LOCALES` ».
- **`.gitignore`** : `.superpowers/` (maquettes de session du compagnon visuel) — fait avec le commit des specs.
- **`k8s/base/seed-job.yaml`** : inchangé (les sept commandes existent déjà).

## 6. Commandes

```bash
# Backend (dans make sh)
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/console app:incidents:seed --force        # sur une base de dev, vérifier les groupes FR/EN
php bin/console debug:router | grep '/order'      # neuf routes PUT, aucune route synthétisée
php bin/phpunit tests/Portfolio/Shared tests/Portfolio/Incident
composer phpstan && composer rector && composer psalm

# Frontend
make front-test && make front-lint && make front-build

# Non-régression grep
grep -rn 'positionLabel\|BaseNumberInput' frontend/src/presentation/pages/admin   # vide en fin de B7, sauf Watch si un champ y reste
grep -rn "'fr', 'en'" backend/src/*/*/Presentation/ApiResource                   # vide (D2)
```

## 7. Style de code

- Conventions du projet sans exception (`strict_types`, `readonly`, VO, exceptions métier, PHPStan
  `max`, Rector, Psalm taint seulement).
- `OrderAssigner` ne connaît ni Doctrine ni API Platform : il reçoit des `Orderable` et des chaînes.
  La transaction est la responsabilité de l'`Administrator`.
- Le groupe est un `Uuid` dans le domaine, une chaîne RFC 4122 aux frontières (DTO, frontend),
  comme l'id (spec 0003, D7).
- Frontend : aucune logique d'ordre dans les `.vue` — les pages composent `useOrderDraft`,
  `useRowDragAndDrop`, `OrderHandle`, `OrderToolbar` et leurs colonnes. Jamais de `v-html`. Les
  badges de langue et le champ « Version de » itèrent `SUPPORTED_LOCALES`, jamais `'fr'`/`'en'` en
  dur.
- Le bouton « Créer la version XX » recopie les champs **non-prose** de l'entrée existante ; la
  liste de ces champs est celle que la page déclare déjà pour l'assistant (`PROSE_FIELDS`), par
  complément — une seule source de vérité par page.

## 8. Stratégie de test

- **Unitaires backend** : `OrderAssigner` (§4, B3) ; entités (constructeur avec/sans groupe,
  `attach`/`detach`, `moveToPosition`) ; `Administrator::create` (héritage de position, `max + 1`,
  409).
- **Fonctionnels backend** : par ressource localisée, le CRUD adapté (B2) et l'ordre (B4) relu sur
  la collection backoffice **et** les endpoints publics FR et EN ; cartes « moi » par catégorie ;
  Watch sur ids ; 401/403/403-CSRF ; les deux 422 ; le 409.
- **Migration** : sur la base de test peuplée, un test fonctionnel de l'appariement n'est pas
  écrit (règle du projet, spec 0003 §1) ; l'appariement est vérifié en dev sur des données de seed
  puis en préprod sur des données éditées.
- **Frontend** : fonctions pures (regroupement par groupe avec locale manquante, `moveKey` bornes et
  no-op) ; `useOrderDraft` (états, `save` nominal, 422 obsolète) ; `useRowDragAndDrop` (dragstart →
  drop déplace, drop sans dragstart no-op) ; `OrderHandle` (clavier, focus, annonce) ;
  `OrderToolbar` ; chaque page selon B6 ; axe.
- **Sécurité** : `ApiRouteExposureTest` et `AccessControlAnchoringTest` inchangés et verts.

## 9. Limites

### Toujours

- `debug:router` après chaque ressource d'ordre : une route, pas d'item synthétisé.
- L'ensemble exact (D4) : ne jamais accepter une liste partielle « pour simplifier le frontend ».
- Recharger la liste après un 422 d'ordre, jamais réessayer l'envoi automatiquement.
- Poser le groupe à la création via le DTO ou l'assistant ; ne jamais le deviner par position ou
  par titre côté serveur.

### Demander avant

- Ajouter un endpoint d'ordre acceptant un sous-ensemble ou une position absolue.
- Réintroduire un champ numérique de position, même en lecture seule.
- Persister quoi que ce soit au dépôt (D6).
- Ajouter une dépendance de glisser-déposer (D7).
- Promouvoir le groupe en entité (D1, voie prévue) : c'est une spec à part.

### Jamais

- Un ordre différent entre les locales d'un même groupe (D5 l'exclut par construction ; un test
  fonctionnel le pin sur chaque ressource).
- Un `v-html` sur un titre rendu dans le tableau groupé.
- Assouplir `ApiRouteExposureTest` pour faire passer une route d'ordre.
- Une poignée sans équivalent clavier.

## 10. Journal des validations

**2026-09-14** — Design validé en session, par sections : glisser-déposer plutôt que flèches ou
champ amélioré ; enregistrement explicite plutôt qu'au dépôt ; champ `Position` supprimé ; lien
FR/EN **explicite en base** (exigence formulée en séance : « chaque entrée FR doit avoir à terme
son entrée EN liée, l'ordre doit se suivre quelle que soit la langue »), avec la question de
l'extension à d'autres langues traitée par D2 et la voie A → B de D1 ; DnD natif ; endpoint
d'ordre à ensemble exact ; tableau « une ligne par groupe, langues empilées » choisi sur maquette
(option A, dates sans retour à la ligne). Décision connexe prise pendant ce design et sortie en
spec 0003, livrée avant : clés primaires UUID sur toutes les entités.

**Audit de sensibilité avant publication** : aucun e-mail, aucune adresse, aucun nom de compte, aucun
secret. Les titres d'incidents cités en exemple dans les maquettes sont ceux du contenu public déjà
servi par `/api/incidents/{locale}`. Le document décrit le cloisonnement `ROLE_SUPER` au même niveau
que `CLAUDE.md` et les specs précédentes.
