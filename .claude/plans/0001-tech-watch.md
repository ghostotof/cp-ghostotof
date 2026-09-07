# PLAN — Radar de veille technique

**Spec** : [`.claude/specs/0001-tech-watch.md`](../specs/0001-tech-watch.md) · **Branche** : `feature/tech-watch`
· **Établi le** : 2026-09-07

---

## Principe de découpage : vertical, pas par couches

La §3 de la spec (M1 → M6) est une **carte de dépendances**, pas un ordre de livraison. La suivre
telle quelle produirait cinq tâches invérifiables (« le socle HTTP est fini » ne se démontre pas) et
un unique moment de vérité à la toute fin, quand corriger coûte le plus cher.

Ce plan la retourne en **six tranches verticales** : chacune traverse tout l'empilement — source
externe → domaine → persistance → API → écran — sur un périmètre fonctionnel réduit. La tranche 1
affiche *une seule ligne* dans le navigateur, mais elle l'affiche pour de vrai, et toutes les
suivantes n'en sont que l'élargissement. Les modules M1–M6 de la spec restent la référence pour
*où va le code* ; les tranches disent *dans quel ordre il arrive*.

| Tranche | Périmètre | Modules spec couverts |
|---|---|---|
| 1 | Squelette bout en bout : PHP seul, statut de support, affiché | M1, M2 (partiel), M4 (partiel), M5, M6 (partiel) |
| 2 | Administration des produits suivis | M2 (fin), M5, M6 |
| 3 | Volet vulnérabilités, agrégat public | M3, M5 |
| 4 | Détail des CVE réservé `ROLE_SUPER` | M3 (fin), M5 |
| 5 | Fraîcheur, dégradation, exploitation | M4 (fin) |
| 6 | i18n, SEO, navigation, a11y, documentation | M6 (fin) |

### Graphe de dépendances

```
T1.1 entités+migration ─┬─> T1.4 refresher+commande ──> T1.5 API publique ──> T1.6 page
T1.2 client EOL ────────┤
T1.3 statut+versions ───┘

T1.5b seed du catalogue  (remontée depuis la tranche 2 : sans lui, la page du Checkpoint A est vide)
T2.1 CRUD backoffice ──> T2.2 validation slug ──> T2.3 page admin
T3.1 manifeste ──> T3.2 client OSV ──> T3.3 agrégat public ──> T3.4 «rien cherché» ──> T3.5 rendu ──> T3.6 CI
T4.1 API détail ──> T4.2 rendu admin
T5.1 fraîcheur ──> T5.2 rendu ──> T5.3 CronJob
T6.1 i18n+SEO · T6.2 navigation · T6.3 a11y · T6.4 doc+ADR   (parallélisables)
```

T1.2 et T1.3 sont indépendantes de T1.1 et peuvent être menées en parallèle. Tout le reste est
séquentiel à l'intérieur de sa tranche.

---

## Tranche 1 — Squelette bout en bout

> **Objectif de la tranche** : `https://…/fr/stack` affiche « PHP 8.5.9 — support actif jusqu'au
> 2027-12-31 », donnée réellement issue d'endoflife.date via un snapshot en base. Une seule ligne,
> mais tout le chemin est là et déployable.

### T1.1 — Domaine et persistance (S)
Entités `WatchedProduct` (slug, label, versionSource, version, position) et `WatchSnapshot` (type,
payload JSON, refreshedAt, sourceStatus), enums `SupportStatus`/`VersionSource`, interfaces de
repository dans `Domain/`, implémentations Doctrine dans `Infrastructure/`, migration.
**Vérification** : `php bin/console doctrine:migrations:migrate` puis `doctrine:schema:validate` ;
test de repository (écriture + relecture d'un snapshot).

### T1.2 — Client endoflife.date, couche anti-corruption (M)
`ReleaseCycleSourceInterface` dans `Domain/Service/`, `EndOfLifeDateClient` dans `Infrastructure/Http/`.
Timeout **et** `max_duration` explicites, `User-Agent` identifiant, mapping des échecs vers
`ReleaseCycleSourceUnavailableException`. **Retourne des Value Objects, jamais un `array` décodé.**
**Vérification** : tests `MockHttpClient` — 200 nominal, 404 produit inconnu, 500, timeout, JSON
malformé, `schema_version` inattendu. Aucun accès réseau réel.

### T1.3 — Statut de support et versions runtime (S)
Calcul de `SupportStatus` à partir de `isEol`/`eolFrom`/`isEoas`/`eoasFrom`/`isMaintained`, détection
« patch disponible » via `latest.name`. `PhpAndSymfonyVersionResolver` (`PHP_VERSION`,
`Kernel::VERSION`) implémentant `InstalledVersionResolverInterface` (D2).
**Vérification** : tests unitaires sur les cas limites — `eolFrom` = aujourd'hui, dates nulles, cycle
absent de la réponse, version installée hors de tout cycle connu.

### T1.4 — Rafraîchisseur et commande (M) — *dépend de T1.1, T1.2, T1.3*
`WatchRefresher` (Application) + `app:watch:refresh` avec `--dry-run`. Écrit un snapshot de type
`release_cycles`.
**Vérification** : `CommandTester` — snapshot créé, **idempotence** (deux exécutions ⇒ même état),
`--dry-run` ne persiste rien, un produit en erreur n'empêche pas les autres d'être écrits.

### T1.5 — API publique (M) — *dépend de T1.4*
`WatchResource` + `WatchProvider` (`GET /api/watch`), lecture du seul snapshot local — **aucun appel
sortant dans la requête**. Entrée dans `PUBLIC_PATHS` d'`ApiRouteExposureTest` **avec justification
écrite**. Mapping `exception_to_status` des nouvelles exceptions.
**Vérification** : test fonctionnel anonyme → 200 ; `ApiRouteExposureTest` vert ; assertion qu'aucune
requête HTTP sortante n'est émise pendant l'appel (client mocké et jamais sollicité).

### T1.5b — Catalogue des produits suivis (S) — *remontée depuis T2.4 le 2026-09-07*
`app:watch:seed` idempotente (purge puis recréation) : `php`, `symfony`, `postgresql`, `nodejs`,
`vue`, `nginx`, `rabbitmq`, versions issues de `versions.lock`.

*Pourquoi ici et non en tranche 2* : le Checkpoint A demande de voir la page fonctionner, or sans
catalogue elle s'affiche vide. Un « seed minimal » provisoire aurait fait repasser deux fois sur le
même fichier et son test pour cinq lignes de données — dans une commande de seed, le squelette
domine largement le contenu. La tranche 2 se réduit d'autant, ce qui correspond mieux à sa nature :
l'administration au backoffice.

**Vérification** : double exécution ⇒ même état ; les slugs répondent tous 200 chez endoflife.date.

### T1.6 — Tranche frontend minimale (M) — *dépend de T1.5*
`domain/watch/` (entités + `WatchRepository`), `infrastructure/watch/HttpWatchRepository.ts`,
`application/watch/useWatch.ts` (injecté par `InjectionKey` depuis `main.ts`),
`presentation/pages/StackPage.vue`, route `/{locale}/stack` (D7).
**Vérification** : Vitest — chargement, erreur (`role="alert"`), contenu ; tableau dans un conteneur
`overflow-x: auto` ; page ouverte pour de vrai dans le navigateur.

> ### ✅ Checkpoint A — revue humaine
> `make up`, `app:watch:refresh`, puis `/fr/stack` affiche la ligne PHP avec sa vraie date d'EOL.
> **Rien ne continue tant que ce bout-en-bout n'est pas vu à l'écran.** C'est ici qu'on valide la
> forme de l'API et l'allure de la page, pendant que les changer coûte encore peu.

---

## Tranche 2 — Administration des produits suivis

### T2.1 — CRUD backoffice (M)
`BackofficeWatchedProductResource` + Provider/Processor, en suivant à la lettre le pattern des
ressources existantes. **`Put`/`Delete` exigent un `provider:` explicite**, sinon API Platform 404
avant d'atteindre le processor.
**Vérification** : fonctionnel 403 anonyme / 403 `ROLE_USER` / 200 `ROLE_SUPER` ; `ApiRouteExposureTest`.

### T2.2 — Validation du slug (S) — *dépend de T2.1*
Appel de vérification à endoflife.date à l'enregistrement, timeout 2 s, **échec non bloquant** (D10).
**Vérification** : slug inexistant → avertissement, enregistrement refusé ; source injoignable →
**enregistrement accepté** avec avertissement. Ce second cas est le vrai test : une panne du tiers ne
doit jamais empêcher d'administrer son propre site.

### T2.3 — Page d'administration (M) — *dépend de T2.1*
`AdminWatchPage.vue` (formulaire + tableau Bootstrap, `window.confirm()` pour la suppression),
composants `Base*.vue` existants, entrée dans `AdminLayout.vue`, route `{ requiresAuth: true, roles: [ROLE_SUPER] }`.
**Vérification** : Vitest ; guard de route testé.

*(L'ancienne T2.4 — seed du catalogue — a été remontée en T1.5b, voir tranche 1.)*

> ### ✅ Checkpoint B — la page vit de son propre contenu

---

## Tranche 3 — Volet vulnérabilités (agrégat public)

### T3.1 — Manifeste de paquets (M)
`app:watch:build-manifest` : `composer.lock` (+ `frontend/package-lock.json` s'il est présent) →
`backend/config/watch/package-manifest.json`, normalisé en `{ecosystem, name, version}` avec
`Packagist` / `npm`. Fichier **gitignoré** (regénéré à chaque build).
**Vérification** : le manifeste produit contient le bon nombre de paquets et les bons écosystèmes ;
absence de `package-lock.json` ⇒ manifeste backend seul, sans erreur.

### T3.2 — Client OSV (M) — *dépend de T3.1*
`OsvClient` : `POST /v1/querybatch`, puis **enrichissement `GET /v1/vulns/{id}`** (le batch ne renvoie
que `{id, modified}`), pagination `next_page_token` gérée explicitement.
**Vérification** : `MockHttpClient` — batch vide, batch avec vulnérabilités, réponse paginée, 500 sur
l'enrichissement (l'identifiant reste compté, le détail manque proprement).

### T3.3 — Agrégat public (M) — *dépend de T3.2*
Intégration au `WatchRefresher` (snapshot `vulnerabilities`), exposition de
`{packagesScanned, affectedCount, checkedAt}` sur `GET /api/watch`.
**Vérification** : **test dédié assertant l'absence** des clés `id`/`cve`/`package`/`version`/`fixedIn`
dans la réponse anonyme. C'est le garde-fou de D4 ; il ne doit jamais être assoupli.

### T3.4 — « Rien trouvé » ≠ « rien cherché » (S) — *dépend de T3.3*
Manifeste absent ou vide ⇒ état explicite, jamais un « 0 vulnérabilité » mensonger.
**Vérification** : test fonctionnel sans manifeste ⇒ `packagesScanned: 0` et état distinct de « sain ».

### T3.5 — Rendu de l'agrégat (S) — *dépend de T3.3*
**Vérification** : Vitest sur les trois cas — sain, vulnérabilités présentes, analyse non effectuée.

### T3.6 — Génération à la construction de l'image (S) — *révisé le 2026-09-07*
Le manifeste est produit **pendant le `docker build`**, dans le stage `production`, par un script PHP
autonome (`bin/build-package-manifest.php`) sans kernel Symfony — au build, ni `APP_SECRET` ni
`DATABASE_URL` ne sont disponibles, et démarrer l'application pour lire deux fichiers JSON exigerait
une configuration complète sans aucun besoin.

*Révision de D3 et du plan initial, qui prévoyaient tous deux une étape CI.* Le contexte de build
étant la racine du dépôt, `frontend/package-lock.json` y est déjà accessible : **aucune modification
du pipeline n'est nécessaire**, et le manifeste ne peut pas se désynchroniser de l'image puisqu'il
naît avec elle.

**Vérification** : `docker run <image>` montre le manifeste attendu.

> ### ✅ Checkpoint C — revue de sécurité
> Le cloisonnement D4 est la promesse centrale de cette fonctionnalité. Relire à froid le payload
> public réel (pas seulement les tests) avant d'aller plus loin.

---

## Tranche 4 — Détail des CVE (`ROLE_SUPER`)

### T4.1 — API de détail (S)
`BackofficeVulnerabilityResource` + provider, exposant `{id, aliases, severity, package, version, fixedIn}`.
**Vérification** : 403 anonyme, 403 `ROLE_USER`, 200 `ROLE_SUPER`.

### T4.2 — Affichage dans le backoffice (S) — *dépend de T4.1*
**Vérification** : Vitest.

---

## Tranche 5 — Fraîcheur, dégradation, exploitation

### T5.1 — Sémantique de fraîcheur (M)
`fresh` < **36 h**, `stale` au-delà, `never_refreshed` si aucun snapshot (D9). Une source injoignable
⇒ la commande sort en échec **mais le snapshot précédent est conservé**, jamais écrasé par du vide.
**Vérification** : tests des trois états ; test explicite « source KO ⇒ ancien snapshot toujours servi,
marqué `stale` » ; jamais de 404 ni de 500 sur l'endpoint public.

### T5.2 — Rendu de la fraîcheur (S) — *dépend de T5.1*
Âge de la donnée, source, bandeau « donnée obsolète » — l'ingénierie rendue visible, qui est le vrai
sujet de démonstration.
**Vérification** : Vitest sur les trois états.

### T5.3 — CronJob Kubernetes (S)
`k8s/base/watch-refresh-cronjob.yaml` calqué sur `messenger-purge-cronjob.yaml` (`image: backend`,
`concurrencyPolicy: Forbid`, `seccompProfile: RuntimeDefault`, `readOnlyRootFilesystem`), **ajouté aux
`resources:` de `kustomization.yaml`** — contrairement à `migrate-job.yaml`, qui en est délibérément
exclu. Horaire décalé de la purge existante (`17 3 * * *`) : `41 4 * * *`.
**Vérification** : `kubectl apply --dry-run=server`, puis exécution réelle en préprod avec lecture des
logs du Job. Le CronJob ne touche ni Postgres ni RabbitMQ : l'invariant « un dry-run ne prouve rien »
vise ces deux workloads à état, pas celui-ci.

> ### ✅ Checkpoint D — rollout préprod complet avant toute promotion

---

## Tranche 6 — Finitions

### T6.1 — i18n et SEO (S)
Clés fr/en dans `infrastructure/i18n/locales/`, `meta.titleKey`/`descriptionKey`, hreflang.
**Vérification** : page rendue dans les deux langues ; `@intlify` sans avertissement.

### T6.2 — Navigation (S)
L'entrée de menu pointe vers `/{locale}/stack` au lieu de l'ancre `#technologies`, et
`TechnologiesSection.vue` gagne un lien « voir l'état de ma stack → » (D8).
**Vérification** : Vitest sur `AppHeader` (`aria-current="page"` sur la page active).

### T6.3 — Accessibilité (S)
Niveaux de titres réels sans saut, `.text-eyebrow` et jamais `text-primary` sur du texte, tableau en
`overflow-x: auto`, parcours clavier complet.
**Vérification** : Tab-through manuel, plan des titres, contrastes.

### T6.4 — Documentation (S)
`.claude/CLAUDE.md` (nouveau contexte `Portfolio/Watch` et ses invariants), ADR
`docs/adr/0002-veille-technique.md`, retrait de la spec devenue caduque.
**Vérification** : relecture ; un nouvel arrivant comprend pourquoi l'appel sortant est hors du
chemin de rendu.

> ### ✅ Checkpoint E — go/no-go release
> Suite complète verte, `/simplify` passé sur la branche, puis PR `feature/tech-watch` → `develop`.

---

## Definition of done (chaque tâche)

`php bin/phpunit` · `composer phpstan` (niveau max, **sans baseline**) · `composer rector` sans diff ·
`npm test` · `npm run lint` · `npm run build` (`vue-tsc`) · comportement vu à l'exécution, pas
seulement en test.

## Risques identifiés

| Risque | Parade |
|---|---|
| Les slugs endoflife.date supposés (`nodejs`, `vue`, `rabbitmq`…) peuvent différer. | Les vérifier un par un en T2.4 ; un slug inconnu dégrade en `unknown` sans casser la page. |
| Le volume de `GET /v1/vulns/{id}` explose si beaucoup de vulnérabilités. | Enrichissement borné et exécuté hors requête utilisateur ; l'agrégat public n'en dépend pas. |
| `frontend/package-lock.json` absent du contexte de build backend. | Le manifeste est généré côté CI **avant** le build (T3.6), là où les deux locks coexistent. |
| Un test qui appellerait réellement le réseau rendrait la CI intermittente. | Interdit par la spec §8 ; `MockHttpClient` partout. |
