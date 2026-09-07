# SPEC — Radar de veille technique (`Portfolio/Watch`)

> Statut : **validée le 2026-09-07**, prête pour le découpage en tâches. Une fois la fonctionnalité livrée, ce fichier
> a vocation à céder la place à un ADR (`docs/adr/0002-veille-technique.md`) : l'ADR documente la
> décision qui tient, la spec documente l'intention du moment.
>
> ⚠️ **Ce fichier vit dans un dépôt public** (`.claude/` est versionné, hors `CLAUDE.local.md`).
> Toute spec déposée ici est publiée au prochain push : aucun secret, aucune adresse réelle, aucun
> nom de domaine de production. Voir le journal d'audit en §10.

---

## 1. Objectif

Ajouter au site une page publique qui **prouve, en données vivantes, deux affirmations que le reste du
site ne fait qu'énoncer** : « je maintiens ma stack » et « la sécurité est une priorité ».

La page affiche :

1. **Le cycle de vie des versions** de la stack réellement utilisée par ce site (PHP, Symfony,
   PostgreSQL, Node, Vue, nginx, RabbitMQ…), face aux dates officielles de fin de support actif et
   de fin de vie publiées par [endoflife.date](https://endoflife.date).
2. **Le statut agrégé des vulnérabilités connues** affectant les dépendances du projet, d'après
   [OSV.dev](https://osv.dev) — « 0 vulnérabilité connue sur 84 paquets, vérifié il y a 3 h ».

### Le vrai sujet de démonstration

Le thème est un prétexte assumé. Ce qui est mis en scène, c'est **l'intégration d'un tiers sans en
devenir l'otage** : couche anti-corruption, appel sortant hors du chemin de rendu, snapshot persisté,
dégradation gracieuse, aucun secret dans le navigateur, testabilité par `MockHttpClient`. La page
elle-même **rend cette ingénierie visible** (source, âge de la donnée, état du dernier
rafraîchissement) — c'est ce qui la distingue d'un gadget.

### Public visé

Recruteur ou lead technique arrivé depuis LinkedIn, non authentifié, qui accorde deux minutes au site.
Objectif secondaire : servir de support de discussion en entretien (les compromis documentés en §4
sont le vrai contenu).

### Hors périmètre (v1)

- Historisation des snapshots et graphiques d'évolution dans le temps.
- Notification (mail, webhook) sur détection d'une nouvelle vulnérabilité.
- Surveillance de dépendances autres que celles de ce dépôt.
- Toute rubrique « tech radar » au sens ThoughtWorks (adopt/trial/assess/hold) — nom volontairement
  évité pour ne pas créer l'ambiguïté.

---

## 2. Décisions structurantes

| # | Décision | Justification | Alternative écartée |
|---|---|---|---|
| **D1** | **Tout appel sortant part du backend**, jamais du navigateur. | Pas de CORS subi, pas de clé exposée, et surtout **l'IP du visiteur n'est jamais transmise à un tiers** → rien à ajouter à la politique de confidentialité. | `fetch` direct depuis Vue. |
| **D2** | **Versions surveillées : source hybride.** Les produits suivis sont saisis au backoffice (slug endoflife.date + version), **sauf PHP et Symfony dont la version est lue au runtime** (`PHP_VERSION`, `Symfony\Component\HttpKernel\Kernel::VERSION`). | Un radar de veille qui afficherait une version périmée par oubli de saisie serait un contre-argument en entretien. Les deux versions les plus regardées ne peuvent pas mentir. | Tout au backoffice (fragile) ; tout déduit de `composer.lock` (aveugle à Postgres/Node/Vue). |
| **D3** | **La CI produit un manifeste de paquets, pas un snapshot de vulnérabilités.** Un job CI génère `backend/config/watch/package-manifest.json` (liste normalisée `{ecosystem, name, version}` issue de `composer.lock` **et** `frontend/package-lock.json`) **avant** le `docker build` ; le fichier voyage dans l'image. Le CronJob de production interroge ensuite OSV avec ce manifeste. | *(Raffinement de « snapshot produit en CI », **validé le 2026-09-07**.)* Garde l'esprit (c'est le build qui connaît le périmètre exact, frontend compris) tout en évitant **deux** défauts d'un push CI → prod : (a) il faudrait un credential machine et un endpoint d'écriture supplémentaires en production, (b) le snapshot serait figé au build alors qu'une CVE publiée trois semaines après le déploiement doit apparaître. | CI qui interroge OSV et pousse le résultat via un endpoint authentifié. |
| **D4** | **Détail des vulnérabilités réservé à `ROLE_SUPER`.** Public = compteur agrégé + date de vérification. | Le compte invité `ROLE_USER` est partagé et ses identifiants circulent (cf. `CLAUDE.md` / ADR 0001 pour le CV) : `ROLE_USER` ≈ public dès qu'il s'agit d'une **surface d'attaque**, ce qui n'est pas le cas d'un CV. | `ROLE_USER`, ou publication intégrale. |
| **D5** | **Rafraîchissement par CronJob → snapshot en base.** L'API ne sert **que** du local. | Temps de réponse constant, aucune latence tierce dans le rendu, aucun visiteur ne paie l'appel sortant, et le site survit à une panne d'endoflife.date. Le pattern « CronJob k8s → commande console » existe déjà (`k8s/base/messenger-purge-cronjob.yaml`). | Cache paresseux (le premier visiteur après expiration paie et voit un 500 si le tiers est down). |
| **D6** | **Les produits suivis ne sont pas localisés.** Versions et dates sont neutres ; seuls les libellés d'interface le sont, et ils vivent dans `frontend/src/infrastructure/i18n/locales/{fr,en}.json`. | Écart délibéré avec les autres contextes `Portfolio/*` (qui ont tous une colonne `locale`) : dupliquer « PostgreSQL 18.4 » en deux langues créerait deux vérités possibles pour un fait unique. | Colonne `locale` par cohérence de façade. |
| **D7** | **Route `/{locale}/stack`** (`/fr/stack`, `/en/stack`), intitulée « Ma stack, en vie » / « My stack, alive ». | « Radar » et « tech radar » entrent en collision sémantique avec le Tech Radar de ThoughtWorks (adopt/trial/assess/hold), qui n'a rien à voir avec le sujet. | `/tech-radar`, `/veille`. |
| **D8** | **La page remplace l'ancre `#technologies` dans le menu**, et `TechnologiesSection.vue` gagne un lien « voir l'état de ma stack → ». | La navigation compte déjà 7 entrées ; une 8e la déséquilibre. La section d'accueil garde son rôle de vitrine et gagne une suite naturelle. | Ajouter une 8e entrée ; masquer la page hors du menu. |
| **D9** | **CronJob quotidien.** Seuil d'obsolescence à **36 h**, soit 1,5 × la période. | Les dates d'EOL évoluent au mois. Un seuil égal à la période afficherait « obsolète » chaque jour avant l'exécution : le seuil doit toujours laisser passer un cycle manqué. | Toutes les 6 h (inutile pour l'EOL, et 4× plus de sollicitation des tiers). |
| **D10** | **Le slug produit est validé auprès d'endoflife.date à l'enregistrement backoffice**, avec un timeout court (2 s) et un **échec non bloquant** (avertissement, enregistrement accepté). | Évite qu'une faute de frappe ne produise une entrée `unknown` silencieuse. Tension assumée avec D5 : l'appel est dans une requête utilisateur, mais authentifiée `ROLE_SUPER`, non publique, et son échec ne bloque rien. | Aucune validation (saisie fragile) ; validation bloquante (une panne du tiers empêcherait d'administrer son propre site). |
### Contrats externes (vérifiés le 2026-09-07, pas de mémoire)

**endoflife.date** — `GET https://endoflife.date/api/v1/products/{slug}/`

```jsonc
{
  "schema_version": "1.2.1",
  "generated_at": "2026-09-07T08:04:15+00:00",
  "result": {
    "name": "php", "label": "PHP",
    "links": { "html": "https://endoflife.date/php" },
    "releases": [{
      "name": "8.5", "label": "8.5", "releaseDate": "2025-11-20",
      "isLts": false, "isMaintained": true,
      "isEoas": false, "eoasFrom": "2027-12-31",   // fin du support actif
      "isEol": false,  "eolFrom": "2029-12-31",    // fin de vie
      "latest": { "name": "8.5.10", "date": "2026-08-27" }
    }]
  }
}
```

Sans clé d'API. Pas d'en-tête de quota observé, mais **`User-Agent` identifiant obligatoire**
(`cp-ghostotof/1.0 (+https://<domaine>)`).

**OSV.dev** — `POST https://api.osv.dev/v1/querybatch`, corps
`{"queries":[{"package":{"name":"symfony/http-client","ecosystem":"Packagist"},"version":"8.1.0"}]}`.
Écosystèmes : `Packagist` (Composer) et `npm`. **Attention : la réponse ne contient que
`{id, modified}`** — obtenir la sévérité et le résumé impose un second appel
`GET /v1/vulns/{id}` par vulnérabilité. Pagination via `next_page_token` au-delà de 1 000 résultats
pour une requête (cas improbable ici, mais à gérer explicitement plutôt qu'à ignorer).

---

## 3. Carte des capacités (ordre de construction)

Six modules indépendamment testables. Chaque module doit être vert (tests + PHPStan/ESLint) avant
d'attaquer le suivant.

```
M1 Socle d'intégration sortante
        │
        ├──────────────┐
        ▼              ▼
M2 Radar EOL      M3 Veille vulnérabilités
        └──────┬───────┘
               ▼
        M4 Snapshot & fraîcheur
               ▼
        M5 API (publique + backoffice)
               ▼
        M6 Frontend (page + backoffice)
```

| Module | Contenu | Livrable vérifiable |
|---|---|---|
| **M1** | `Portfolio/Watch/Domain` (VO, exceptions) + socle HTTP : timeouts explicites, `User-Agent`, mapping des échecs vers des exceptions métier. | Tests unitaires avec `MockHttpClient` couvrant succès, 404, 500, timeout, JSON malformé. |
| **M2** | `EndOfLifeDateClient`, entité `WatchedProduct`, résolution des versions runtime (D2), calcul de `SupportStatus`. | `SupportStatus` correct sur les cas limites (date d'EOL = aujourd'hui, dates nulles, cycle introuvable). |
| **M3** | `PackageManifest` (lecture + commande de génération), `OsvClient` (querybatch + enrichissement `/v1/vulns/{id}` + pagination). | Un manifeste de 84 paquets produit un agrégat correct ; batch vide et batch avec CVE testés. |
| **M4** | Entité `WatchSnapshot`, commande `app:watch:refresh`, sémantique `fresh`/`stale`/`never_refreshed`, CronJob k8s. | Source injoignable → **le snapshot précédent est conservé** et marqué `stale` ; jamais d'écrasement par du vide. |
| **M5** | `WatchResource` (public), ressources backoffice, entrée `PUBLIC_PATHS` justifiée, `exception_to_status`. | `ApiRouteExposureTest` vert ; le JSON public **ne contient aucun identifiant de CVE**. |
| **M6** | Slice frontend `domain/watch` → `HttpWatchRepository` → `useWatch` → `StackPage.vue`, i18n fr/en, SEO, navigation, page backoffice. | Vitest : états chargement / erreur / contenu / donnée obsolète. |

---

## 4. Critères d'acceptation

### M2 — Radar des cycles de vie

- **Étant donné** un produit suivi `php` en version `8.5.9`, **quand** le radar est rafraîchi,
  **alors** le statut est `supported`, la fin de support actif `2027-12-31`, la fin de vie
  `2029-12-31`, et la dernière version connue du cycle `8.5.10`.
- **Étant donné** une version installée inférieure à `latest.name` du même cycle, **alors** la page
  signale « patch disponible » sans pour autant dégrader le statut de support.
- **Étant donné** un produit dont le cycle courant a `isEol: true`, **alors** le statut est `eol` et
  l'entrée est mise en avant visuellement (c'est l'information la plus utile de la page).
- **Étant donné** un slug inconnu d'endoflife.date (404), **alors** l'entrée est marquée `unknown`,
  **les autres produits restent affichés**, et l'échec est journalisé — un produit mal saisi ne
  casse pas la page.
- **Étant donné** PHP et Symfony, **alors** la version affichée provient du runtime et **aucune
  saisie backoffice ne peut la contredire** (le champ version est en lecture seule pour ces deux
  entrées).

### M3 / M5 — Vulnérabilités et cloisonnement

- **Étant donné** un appelant **anonyme** sur `GET /api/watch`, **alors** la réponse contient
  `{ vulnerabilities: { packagesScanned, affectedCount, checkedAt } }` et **aucun** identifiant de
  CVE, nom de paquet affecté ou version vulnérable. *(Test dédié assertant l'absence de ces clés —
  c'est le garde-fou de D4, il ne doit jamais être assoupli.)*
- **Étant donné** un compte authentifié **sans** `ROLE_SUPER` sur
  `GET /api/backoffice/watch/vulnerabilities`, **alors** 403.
- **Étant donné** un `ROLE_SUPER`, **alors** 200 avec `{id, aliases, severity, package, version, fixedIn}`.
- **Étant donné** un manifeste vide ou absent (dev local sans génération), **alors** l'agrégat vaut
  `packagesScanned: 0` et la page l'indique explicitement plutôt que d'afficher un « 0 vulnérabilité »
  mensonger. *(Distinguer « rien trouvé » de « rien cherché » est le point le plus important de ce module.)*

### M4 — Fraîcheur et dégradation

- **Étant donné** un snapshot de moins de **36 h** (1,5 × la période du CronJob quotidien, cf. D9),
  **alors** il est `fresh` et la page affiche l'âge de la donnée sans avertissement. Au-delà, il passe
  `stale` et la page le signale. *(Le seuil doit rester strictement supérieur à la période, sinon un
  unique cycle manqué — ou simplement l'heure qui précède l'exécution — afficherait « obsolète ».)*
- **Étant donné** une source injoignable pendant le rafraîchissement, **alors** la commande sort en
  échec **et** le snapshot précédent reste servi, marqué `stale` avec sa date d'origine visible.
- **Étant donné** qu'aucun rafraîchissement n'a jamais eu lieu, **alors** l'API répond 200 avec un
  état `never_refreshed` (jamais 404, jamais 500, jamais d'appel sortant synchrone de secours).
- **Étant donné** deux exécutions successives de `app:watch:refresh`, **alors** l'état final est
  identique (idempotence).

### M6 — Frontend

- Trois états rendus : chargement, erreur (`role="alert"`), contenu.
- Aucun `v-html` — toute prose éventuelle passe par `presentation/ui/RichText.vue`.
- Titres en `<h2>`/`<h3>` réels, pas de saut de niveau ; contraste via `.text-eyebrow`, jamais
  `text-primary` sur du texte.
- Tableau des versions encapsulé dans un conteneur `overflow-x: auto` (le `<body>` ne défile jamais
  horizontalement).
- Page rendue en français et en anglais, `meta.titleKey`/`descriptionKey` renseignés.
- **Étant donné** le menu principal, **alors** l'entrée « Compétences » pointe vers `/{locale}/stack`
  et non plus vers l'ancre `#technologies` (D8), avec `aria-current="page"` sur la page active.
- **Étant donné** la section Technologies de la page d'accueil, **alors** elle propose un lien
  « voir l'état de ma stack → » vers la nouvelle page.
- **Étant donné** un slug produit invalide saisi au backoffice alors qu'endoflife.date est
  injoignable, **alors** l'enregistrement **aboutit** et un avertissement est affiché (D10).

---

## 5. Structure du projet

### Backend — `backend/src/Portfolio/Watch/`

```
Domain/
  Entity/WatchedProduct.php          # slug endoflife, label, versionSource, version, position
  Entity/WatchSnapshot.php           # type, payload JSON, refreshedAt, sourceStatus
  ValueObject/SupportStatus.php      # enum: Supported|SecurityOnly|Eol|Unknown
  ValueObject/VersionSource.php      # enum: Manual|RuntimePhp|RuntimeSymfony
  ValueObject/PackageCoordinates.php # ecosystem + name + version
  ValueObject/KnownVulnerability.php
  Exception/{WatchedProductNotFoundException,ReleaseCycleSourceUnavailableException,
             VulnerabilitySourceUnavailableException,PackageManifestUnavailableException}.php
  Repository/{WatchedProductRepositoryInterface,WatchSnapshotRepositoryInterface}.php
  Service/{ReleaseCycleSourceInterface,VulnerabilitySourceInterface,
           InstalledVersionResolverInterface}.php
Application/
  WatchRefresher.php + Interface           # orchestre M2 + M3, écrit le snapshot
  WatchPresenter.php + Interface           # vue publique (agrégée)
  WatchAdminPresenter.php + Interface      # vue ROLE_SUPER (détaillée)
  WatchedProductAdministrator.php + Interface
Infrastructure/
  Http/EndOfLifeDateClient.php             # implements ReleaseCycleSourceInterface
  Http/OsvClient.php                       # implements VulnerabilitySourceInterface
  Manifest/FilePackageManifestReader.php
  Runtime/PhpAndSymfonyVersionResolver.php # implements InstalledVersionResolverInterface
  Doctrine/{WatchedProduct,WatchSnapshot}Repository.php
  ApiPlatform/{WatchProvider,BackofficeWatchedProduct{Provider,Processor},
               BackofficeVulnerabilityProvider}.php
Presentation/
  ApiResource/{WatchResource,BackofficeWatchedProductResource,
               BackofficeVulnerabilityResource}.php
  Command/{RefreshWatchCommand,BuildPackageManifestCommand}.php
```

### Frontend — `frontend/src/`

```
domain/watch/{entities,repositories}/       # WatchContent, WatchRepository (interface)
infrastructure/watch/HttpWatchRepository.ts
application/watch/useWatch.ts               # injecté par InjectionKey depuis main.ts
presentation/pages/StackPage.vue
presentation/pages/admin/AdminWatchPage.vue
```

Tests miroirs sous `backend/tests/Portfolio/Watch/` et `frontend/tests/…`.

### Ce qui est touché en dehors du contexte

- `backend/config/packages/api_platform.yaml` — `exception_to_status` pour les nouvelles exceptions.
- `backend/tests/Security/ApiRouteExposureTest.php` — entrée `PUBLIC_PATHS` **avec justification écrite**.
- `.github/workflows/pipeline.yml` — génération du manifeste en **étape du job `build-images`**, juste
  avant `Build & push` : ce job régénère déjà la carte Open Graph au même endroit, et un job séparé
  imposerait de transmettre un artefact entre jobs pour rien.
- `k8s/base/watch-refresh-cronjob.yaml` + `kustomization.yaml` — calqués sur `messenger-purge-cronjob.yaml`.
- `frontend/src/presentation/router/index.ts`, `…/i18n/locales/{fr,en}.json`,
  `…/infrastructure/portfolio/StaticPortfolioContentRepository.ts` (lien de navigation, D8),
  `…/presentation/sections/TechnologiesSection.vue` (lien « voir l'état de ma stack → »).

---

## 6. Commandes

```bash
# Backend (dans make sh)
php bin/console app:watch:build-manifest       # composer.lock [+ package-lock.json] -> manifeste
php bin/console app:watch:refresh              # interroge les sources, écrit le snapshot
php bin/console app:watch:refresh --dry-run    # affiche sans persister
php bin/console doctrine:migrations:diff       # migration des deux nouvelles tables

php bin/phpunit tests/Portfolio/Watch
composer phpstan && composer rector

# Frontend
npm test && npm run lint && npm run build
```

---

## 7. Style de code

Rien de spécifique : les règles du projet s'appliquent telles quelles.

- `declare(strict_types=1)`, tout typé, `readonly` partout où c'est pertinent, PHPStan `max` **sans
  baseline** sur `src/` **et** `tests/`.
- Controller → **Interface** → Service ; aucun service métier instancié directement.
- Providers/Processors API Platform : réutiliser le trait `ResolvesUriVariables` plutôt que de caster
  du `mixed`.
- Exceptions métier explicites, jamais de `RuntimeException` générique. Deux exceptions partageant un
  code HTTP que le frontend doit distinguer implémentent `ProblemExceptionInterface` + `HasProblemType`.
- `Locale::from()` sur une valeur déjà validée, `Locale::fromString()` sur toute entrée externe.
- Frontend : composants de formulaire `Base*.vue` existants, jamais de `<input>` brut dans `admin/*`.

**Spécificité de ce contexte** : la réponse d'un tiers ne franchit jamais la frontière de
`Infrastructure/`. Les clients HTTP retournent des Value Objects du domaine, pas des `array` décodés —
c'est la couche anti-corruption, et c'est ce qui rend le domaine testable sans réseau.

---

## 8. Stratégie de test

| Niveau | Portée | Outil |
|---|---|---|
| Unitaire | Clients HTTP (succès, 404, 500, timeout, JSON malformé, `schema_version` inattendu), calcul de `SupportStatus`, lecture du manifeste, pagination OSV. | PHPUnit + `MockHttpClient` |
| Unitaire | `WatchRefresher` : une source en panne n'empêche pas l'autre d'écrire son résultat. | PHPUnit + doublures |
| Fonctionnel | `/api/watch` anonyme = agrégat sans CVE ; backoffice 403 anonyme / 403 `ROLE_USER` / 200 `ROLE_SUPER`. | `WebTestCase` |
| Sécurité | `ApiRouteExposureTest` (déjà en place, doit rester vert) **+ un test dédié assertant l'absence des clés sensibles dans le payload public**. | `WebTestCase` |
| Commande | Idempotence, `--dry-run`, conservation du snapshot en cas d'échec de source. | `CommandTester` |
| Frontend | États chargement/erreur/contenu, affichage de l'âge de la donnée, bandeau « donnée obsolète ». | Vitest + `@vue/test-utils` |

**Aucun test ne sort sur le réseau.** Un test qui appellerait réellement endoflife.date serait
intermittent par construction et ferait échouer la CI le jour où le tiers a un incident — exactement
le couplage que cette fonctionnalité prétend démontrer qu'on sait éviter.

**Definition of done** : PHPUnit vert, PHPStan `max` vert, Rector `--dry-run` sans diff, ESLint vert,
`vue-tsc -b` vert, page vérifiée à l'exécution en fr **et** en en, `CLAUDE.md` mis à jour.

---

## 9. Limites

### Toujours

- Appels sortants **depuis le backend uniquement**, avec `timeout` **et** `max_duration` explicites
  et un `User-Agent` identifiant le site.
- Persister le snapshot avant de le servir ; toute lecture d'API sert du local.
- Dégrader proprement : une source en panne laisse la page utile et honnête sur l'âge de sa donnée.
- Toute nouvelle route est protégée par défaut ; la rendre publique est un acte délibéré, inscrit
  dans `PUBLIC_PATHS` avec sa justification.
- Branche `feature/*` conformément au git flow du projet.

### Demander avant

- Ajouter une source externe supplémentaire (chaque tiers est une dépendance de disponibilité).
- Exposer publiquement un champ qui ne figure pas dans l'agrégat défini en §4.
- Introduire un secret ou un credential (aucun n'est nécessaire en l'état — c'est un acquis à défendre).
- Modifier `k8s/base/` au-delà de l'ajout du CronJob (cf. les invariants de déploiement du `CLAUDE.md`).

### Jamais

- Appeler endoflife.date ou OSV depuis le navigateur (D1).
- Publier un identifiant de CVE, un nom de paquet affecté ou une version vulnérable sur une réponse
  non authentifiée (D4).
- Placer un appel sortant dans le chemin de rendu d'une requête **publique** (D5). Seule exception,
  documentée : la validation de slug au backoffice (D10) — `ROLE_SUPER`, timeout 2 s, échec non bloquant.
- Affaiblir, contourner ou supprimer `ApiRouteExposureTest`.
- Utiliser `v-html`, ni écraser un snapshot valide par un résultat vide.

---

## 10. Journal des validations

**2026-09-07** — Spec validée. D3 (manifeste produit en CI plutôt que snapshot poussé en production),
D7 (route `/stack`), D8 (remplacement de l'ancre `#technologies` au menu), D9 (CronJob quotidien) et
D10 (validation de slug non bloquante) sont arrêtées ; il ne reste aucune question ouverte.

Correction apportée à cette occasion : le seuil d'obsolescence, initialement fixé à 24 h, entrait en
collision avec la période quotidienne du CronJob — la page aurait affiché « donnée obsolète » chaque
jour avant l'exécution. Porté à 36 h (cf. D9 et les critères de M4).

**2026-09-07** — Spec déplacée à `.claude/specs/0001-tech-watch.md`. **Audit de sensibilité avant
publication** : aucun e-mail, aucune adresse IP, aucun chemin utilisateur, aucun secret ni credential ;
`User-Agent` laissé en placeholder `https://<domaine>`. Seul point relevé, non bloquant et assumé :
le document décrit le modèle de menace et le cloisonnement `ROLE_SUPER` (D4), au même niveau de détail
que `.claude/CLAUDE.md` et les ADR, déjà publics.

Prochaine étape : découpage en tâches (`/plan` sur ce fichier), sur une branche `feature/tech-watch`.
