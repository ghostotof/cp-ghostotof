# ADR 0002 — Veille technique en données vivantes (`Portfolio/Watch`)

- Statut : accepté
- Date : 2026-09-08
- Portée : `src/Portfolio/Watch`, `backend/bin/build-package-manifest.php`, `docker/php/Dockerfile`
  (stage `production`), `k8s/base/watch-refresh-cronjob.yaml`, frontend `watch` + `admin/watch` +
  page publique `/{locale}/stack`

## Contexte

Le site affirmait deux choses qu'il ne prouvait pas : « je maintiens ma stack » et « la sécurité est
une priorité ». Une section « Technologies » listant des logos ne démontre ni l'un ni l'autre — elle
énonce.

D'où une page publique alimentée par deux sources externes :

1. **[endoflife.date](https://endoflife.date)** — les dates officielles de fin de support actif et de
   fin de vie des versions réellement en service ici (PHP, Symfony, PostgreSQL, Node, Vue, nginx,
   RabbitMQ) ;
2. **[OSV.dev](https://osv.dev)** — les vulnérabilités connues affectant les dépendances du dépôt.

**Le thème est un prétexte assumé.** Le vrai sujet de démonstration est *l'intégration d'un tiers sans
en devenir l'otage* : couche anti-corruption, appel sortant hors du chemin de rendu, snapshot
persisté, dégradation gracieuse, aucun secret, testabilité sans réseau. La page **rend cette
ingénierie visible** (source, âge de la donnée, état du dernier rafraîchissement) — c'est ce qui la
sépare d'un gadget.

## Décisions

Les dix décisions structurantes, dans l'ordre où elles se rencontrent en lisant le code.

1. **Tout appel sortant part du backend** (D1). Jamais du navigateur : pas de CORS subi, aucune clé
   exposée, et surtout **l'IP du visiteur n'est jamais transmise à un tiers** — il n'y a donc rien à
   ajouter à la politique de confidentialité.

2. **Rafraîchissement par CronJob → snapshot en base ; l'API ne sert que du local** (D5).
   `WatchProvider` n'a **aucune dépendance vers une source externe** : il ne *peut pas*, même par
   accident, remettre un tiers dans le chemin de rendu d'une page publique. Temps de réponse
   constant, aucun visiteur ne paie l'appel sortant, et le site survit à une panne d'endoflife.date.
   Un cache paresseux aurait fait payer le premier visiteur après expiration — et lui aurait servi un
   500 le jour où le tiers est en panne.

3. **Versions surveillées : source hybride** (D2). Les produits sont saisis au backoffice (slug
   endoflife.date + version), **sauf PHP et Symfony dont la version est lue au runtime**
   (`PHP_VERSION`, `Kernel::VERSION`) et **non saisissable**. Un radar de veille affichant une version
   périmée par oubli de saisie serait un contre-argument en entretien : les deux versions les plus
   regardées ne peuvent pas mentir.

4. **Détail des vulnérabilités réservé à `ROLE_SUPER`** (D4). Le public voit un agrégat —
   `{packagesScanned, affectedCount, checkedAt}` — et rien d'autre : ni identifiant de CVE, ni nom de
   paquet affecté, ni version vulnérable. Motif : le compte invité `ROLE_USER` est partagé et ses
   identifiants circulent (cf. ADR 0001) ; `ROLE_USER` ≈ public **dès qu'il s'agit d'une surface
   d'attaque**, ce qui n'est pas le cas d'un CV. Le cloisonnement se joue **à la lecture** (dans le
   provider), jamais à l'écriture : le snapshot conserve le détail, sans quoi l'administrateur serait
   privé de ce qu'il est précisément le seul à avoir le droit de voir.

5. **Le périmètre analysé est relevé pendant le `docker build`** (D3, *révisée en cours de route*).
   `bin/build-package-manifest.php` — script autonome, **sans kernel Symfony** — fusionne
   `composer.lock` et `frontend/package-lock.json` en un manifeste normalisé
   `{ecosystem, name, version}`, écrit dans l'image en lecture seule.
   *La spec prévoyait une étape CI.* Le stage `production` s'est révélé meilleur : le contexte de
   build **étant** la racine du dépôt, les deux fichiers de verrouillage y coexistent déjà, **aucune
   modification du pipeline n'est nécessaire**, et le manifeste ne peut pas se désynchroniser de
   l'image puisqu'il naît avec elle. Sans kernel, parce qu'au build ni `APP_SECRET` ni
   `DATABASE_URL` n'existent : démarrer l'application pour lire deux fichiers JSON aurait exigé une
   configuration complète sans aucun besoin.

6. **Les produits suivis ne sont pas localisés** (D6). Écart délibéré avec les autres contextes
   `Portfolio/*`, qui portent tous une colonne `locale` : « PostgreSQL 18.4 » traduit deux fois, ce
   sont deux vérités possibles pour un fait unique. Seuls les libellés d'interface sont traduits, et
   ils vivent dans `frontend/src/infrastructure/i18n/locales/{fr,en}.json`.

7. **Route `/{locale}/stack`, intitulée « Ma stack »** (D7). « Radar » et *tech radar* entrent en
   collision sémantique avec celui de ThoughtWorks (adopt/trial/assess/hold), qui n'a rien à voir avec
   le sujet.

8. **La page remplace l'ancre `#technologies` au menu** (D8), et `TechnologiesSection.vue` gagne un
   lien « voir l'état de ma stack → ». La navigation comptait déjà 7 entrées ; une 8e la déséquilibre.
   Les deux blocs répondent d'ailleurs à deux questions successives : la section dit ce que je
   maîtrise, la page dit dans quelles versions cela tourne réellement.

9. **CronJob quotidien, seuil d'obsolescence à 36 h** (D9), soit 1,5 × la période. Un seuil égal à la
   période afficherait « donnée obsolète » chaque jour avant l'exécution : **le seuil doit toujours
   laisser passer un cycle manqué**. La fraîcheur est calculée **à la lecture**, ce qui fait qu'un
   snapshot se périme tout seul quand le rafraîchissement cesse d'aboutir, sans que personne n'ait à
   venir le marquer.

10. **Le slug est validé auprès d'endoflife.date à l'enregistrement backoffice, sans bloquer** (D10) :
    timeout 2 s, et **une panne du tiers laisse l'enregistrement aboutir**. Tension assumée avec la
    décision n°2 — c'est le seul appel sortant situé dans une requête HTTP — mais elle est
    `ROLE_SUPER`, non publique, et son échec ne coûte rien. Une validation bloquante aurait laissé une
    panne d'endoflife.date empêcher d'administrer son propre site.

### Ce qui n'est jamais négociable

- La réponse d'un tiers **ne franchit pas la frontière d'`Infrastructure/`**. Les clients HTTP
  retournent des Value Objects du domaine, jamais un `array` décodé — c'est la couche anti-corruption,
  et c'est ce qui rend le domaine testable sans réseau.
- **Aucun test ne sort sur le réseau.** Un test appelant réellement endoflife.date serait intermittent
  par construction et casserait la CI le jour d'un incident chez le tiers — exactement le couplage que
  cette fonctionnalité prétend démontrer qu'on sait éviter.
- **Si rien n'a pu être rafraîchi, rien n'est écrit.** Persister un payload « zéro produit » effacerait
  la page à la première panne du fournisseur, alors que la donnée de la veille reste parfaitement
  lisible.
- **« Rien trouvé » n'est pas « rien cherché ».** Manifeste absent ⇒ état explicite, jamais un
  « 0 vulnérabilité » mensonger.
- **Une chaîne venue d'un tiers ne devient jamais un `href` sans liste blanche de schémas**
  (`ExternalUrlFilter`, `https` seul). Voir ci-dessous : c'est une faille réelle, pas une précaution
  théorique.
- **Aucun appel sortant ne suit de redirection** (`max_redirects: 0`).

## Conséquences

- **Un contexte borné de plus** (`src/Portfolio/Watch/`), le premier du projet dont le domaine dépend
  de sources externes — et le seul à posséder un stage de build et un CronJob à lui.
- **Deux tables** : `watched_product` (catalogue) et `watch_snapshot` (une ligne par volet, écrasée à
  chaque rafraîchissement — l'historisation est hors périmètre v1).
- **Une entrée publique de plus** dans `PUBLIC_PATHS` d'`ApiRouteExposureTest`, `/api/watch`, avec sa
  justification écrite. Elle est doublée d'un **test dédié assertant l'absence** des clés
  `id`/`cve`/`package`/`version`/`fixedIn` de la réponse anonyme : c'est le garde-fou de la décision
  n°4, il ne doit jamais être assoupli.
- **Le contrat public est groupé par volet** — `{releaseCycles, vulnerabilities}` — et non plat. Il
  l'a été *avant* que le second volet n'existe : le regrouper plus tard aurait cassé le contrat, et
  surtout obligé à choisir lequel des deux instantanés une date unique décrirait.
  `skip_null_values: false`, sans quoi une installation jamais rafraîchie renverrait `{"products":[]}`
  et le client devrait déduire l'état « jamais rafraîchi » d'une clé absente.
- **Un seul objet du cluster provoque des appels sortants** : le CronJob `watch-refresh`
  (`41 4 * * *`, décalé de la purge Messenger). Les NetworkPolicy du namespace ne restreignent que
  l'entrée, ces appels passent donc sans règle supplémentaire.
- **`EndOfLifeDateClient` est déclaré `public: true` sous `when@test`.** C'est bien la **classe
  concrète** qu'il faut viser, pas l'alias : un service privé n'ayant qu'un consommateur est inliné à
  la compilation, si bien qu'un `TestContainer::set()` sur l'alias reste sans effet. Sans cela, trois
  tests fonctionnels croyaient simuler le fournisseur **tout en l'appelant réellement** — ils
  passaient, et la CI aurait cassé au premier incident chez le tiers.
- **`BackofficeVulnerabilityResource` déclare `#[ApiProperty(identifier: false)]`.** Même piège
  qu'au point d'audit C6 : sans cela, API Platform synthétise une opération d'item pour fabriquer ses
  IRI et publie `/api/backoffice_vulnerabilities/{id}`, une seconde route non documentée vers les
  mêmes données.
- **L'accessibilité est passée du manuel à l'outillé** : `eslint-plugin-vuejs-accessibility` est
  désormais actif dans `npm run lint`, donc bloquant en CI. Il ne voit que le template — ni le
  contraste ni l'ordre de tabulation, qui exigent un rendu réel. Le Tab-through manuel reste
  nécessaire, l'outil le complète sans le remplacer (suite prévue : axe-core, issue #12).
- **Une règle Rector de plus dans la liste de `skip`**,
  `ArrowFunctionDelegatingCallToFirstClassCallableRector` — la seule qui n'y soit pas pour raison
  cosmétique : elle met Rector et PHPStan en désaccord frontal, l'un exigeant la réécriture que
  l'autre refuse.

### Revue de sécurité du 2026-09-08 — deux correctifs

La relecture à froid du payload public (checkpoint C) a trouvé une faille réelle, reproduite de bout
en bout avant d'être corrigée.

**XSS stocké via le lien de documentation du tiers.** `links.html`, fourni par endoflife.date,
traversait la couche anti-corruption, la base et l'API publique **sans qu'aucune couche n'en vérifie
le schéma**, puis atterrissait dans un `:href` de `/stack` — or Vue ne filtre pas les `href`, et le
nginx frontend n'émet pas de CSP. Un `javascript:` publié chez le fournisseur devenait donc du script
exécutable sur une page anonyme. Compromettre un serveur n'était pas nécessaire : le catalogue
d'endoflife.date est un **jeu de données ouvert**, faire accepter une donnée suffisait.

Correctif : `Domain/Service/ExternalUrlFilter`, **liste blanche de schémas** (`https` seul) — une
liste noire de `javascript:` aurait laissé passer `data:`, `vbscript:` et le prochain schéma inventé.
Le filtre refuse aussi les caractères de contrôle, que les navigateurs ignorent en analysant un
`href` (`java\tscript:` s'exécute). Une URL refusée devient **absente**, jamais « nettoyée » :
réparer reviendrait à deviner l'intention de son auteur, et c'est ainsi qu'on reconstitue une charge
utile qu'on croyait neutralisée. L'entrée reste affichée, seul le lien disparaît.

Il est appliqué **deux fois**, à l'écriture (client) et à la lecture (provider). Ce n'est pas une
redondance : un snapshot écrit avant le correctif contient encore la valeur brute, et c'est la
lecture qui la republierait — filtrer seulement à l'écriture aurait laissé la faille ouverte sur tout
déploiement existant jusqu'au rafraîchissement suivant.

**Redirections non bornées.** Les deux clients suivaient le défaut Symfony de 20 redirections, alors
que le pod du CronJob n'a aucune restriction de sortie : un fournisseur détourné disposait d'un levier
vers le réseau interne. `max_redirects: 0` sur les quatre appels ; les URL sont canoniques, cela ne
coûte rien.

Ce que la revue a confirmé, en revanche : le cloisonnement D4 tient avec **27 CVE réellement en
base** — le payload anonyme ne contient aucun identifiant, nom de paquet, sévérité ni version
corrigée ; anonyme 401, `ROLE_USER` 403, `ROLE_SUPER` 200 ; sept routes, aucune fantôme.

Trois points mineurs relevés au passage ont été traités ensuite.

**`/api/watch` devient cachable publiquement.** La réponse est identique pour tout le monde et ne
bouge qu'une fois par jour ; la servir en `no-cache, private` (défaut Symfony) faisait traverser PHP
et Postgres à chaque visiteur. `public, max-age=300, s-maxage=300, stale-while-revalidate=600,
stale-if-error=3600`.

Les durées sont courtes, et **la raison n'est pas la fraîcheur des données mais celle du libellé** :
`freshness` est calculé au moment de la lecture, et le frontend s'y fie au lieu de le recalculer
depuis `refreshedAt`. Une réponse gardée T secondes affiche donc un libellé vieux de T secondes au
pire. Cinq minutes contre un seuil de 36 h, c'est du bruit ; une journée aurait pu afficher « donnée
fraîche » alors que le rafraîchissement avait cessé — soit mentir sur exactement ce que cette page
prétend rendre visible. `stale_if_error` s'autorise une heure parce que, pendant une panne du
backend, l'alternative n'est pas une page plus honnête : c'est une 502.

`public` n'est sûr **que parce que** `WatchProvider` ignore totalement l'appelant. Un test dédié
vérifie l'autre versant : le détail `ROLE_SUPER` reste `no-cache, private`, un `public` s'y glissant
autoriserait un cache partagé à le resservir à quelqu'un d'autre.

**Filet de débit sur les lectures publiques.** `/api/watch` n'avait aucun plafond — comme
`/api/about` et `/api/quality` : les zones existantes protègent un effet de bord (envoi d'e-mail,
jeton devinable), pas la ressource. Singulariser `/api/watch` aurait été incohérent, d'où une zone
`publicapi` générale (600 r/m, burst 200) posée sur `location /` dans **les deux** configurations
nginx, qui sont des miroirs. Le plafond est très au-dessus de tout usage réel, et cette marge est
délibérée : derrière un CGNAT d'opérateur mobile, des milliers de visiteurs partagent une adresse
(point d'audit C7). Vérifié en local — 30 requêtes séquentielles passent intégralement, 500 en
parallèle déclenchent 266 réponses 429.

**Justification de `PUBLIC_PATHS` reformulée.** Elle affirmait que la page ne révélait « rien qu'un
visiteur ne puisse déjà déduire du dépôt public ». C'est exact pour les numéros de version — `.env`
et `versions.lock` sont suivis en git — mais la page en dit un peu plus : que ces versions tournent
effectivement, et lesquelles attendent un correctif. Le dépôt dit ce qui est épinglé, la page dit ce
qui est en retard. Divulgation assumée, désormais écrite plutôt que sous-entendue.

### Ce qui reste ouvert

- **Pas de CSP sur le nginx frontend** (`docker/node/nginx.conf`), qui porte pourtant tous les autres
  en-têtes de sécurité — le nginx backend, lui, en a une stricte. C'est ce qui a transformé la faille
  ci-dessus de « bloquée » en « exploitable ». Préexiste à ce contexte, donc suivi à part :
  **issue #13**. Rien d'exploitable en l'état (aucun `v-html`, `href` liés à une donnée tous bornés),
  et la mise en œuvre n'est pas triviale — `connect-src` dépend de l'`API_URL` injectée au runtime,
  donc la conf nginx doit passer par le même `envsubst` que `config.template.js`.
- **La dérive de version des cinq produits saisis à la main.** PHP et Symfony lisent le runtime et ne
  peuvent pas mentir (décision n°3) ; PostgreSQL, Node, Vue, nginx et RabbitMQ sont en saisie. Monter
  `POSTGRES_TAG` dans `.env` et déployer laisse donc `/stack` annoncer l'ancienne version jusqu'à ce
  que quelqu'un modifie l'entrée au backoffice — la page affirme alors quelque chose de faux sur ce
  qui tourne, ce qu'elle existe précisément pour éviter. C'est le coût assumé de la source hybride ;
  la parade est de traiter l'édition du catalogue comme une étape du changement de version, au même
  titre que `versions.lock`. Une piste pour plus tard : lire ces versions depuis le cluster.
- **Le seed n'est joué par personne au déploiement.** `app:watch:seed` est un amorçage manuel, une
  fois par environnement, et doit le rester : comme tous les `app:*:seed` il purge puis recrée, donc
  l'automatiser effacerait à chaque release les modifications faites au backoffice. Un environnement
  neuf démarre par conséquent avec un catalogue vide, `app:watch:refresh` le dit sans échouer
  (« Aucun produit surveillé : rien à rafraîchir. »), et `/stack` affiche « jamais rafraîchi » jusqu'à
  l'amorçage. Au 2026-09-08 c'est l'état de la **préprod**, `Incident` et `Contribution` compris.
- L'historisation des snapshots et les courbes d'évolution (hors périmètre v1).
- La notification à la détection d'une nouvelle vulnérabilité : aujourd'hui il faut ouvrir la page.
- Le volume de `GET /v1/vulns/{id}` si les vulnérabilités se multipliaient — l'enrichissement est
  borné et hors requête utilisateur, mais rien ne le plafonne dans le temps.

## Alternatives écartées

- **`fetch` direct depuis Vue** vers endoflife.date/OSV : transmet l'IP du visiteur à un tiers, subit
  le CORS, et rend la page tributaire de la disponibilité d'autrui à chaque affichage.
- **Cache paresseux** au lieu du CronJob : le premier visiteur après expiration paie l'appel — et voit
  un 500 si le tiers est en panne.
- **Tout déduire de `composer.lock`** : aveugle à PostgreSQL, Node, nginx et RabbitMQ, qui ne sont
  dépendances Composer de rien.
- **Tout saisir au backoffice** : fragile là où c'est le plus visible (PHP, Symfony).
- **Publier le détail des CVE**, ou le réserver à `ROLE_USER` : la carte des vulnérabilités connues
  d'un site est une aide à l'attaque, et `ROLE_USER` est un compte partagé.
- **CI interrogeant OSV puis poussant le résultat en production** (formulation initiale de D3) : aurait
  exigé un credential machine et un endpoint d'écriture supplémentaires, et figé l'analyse au build —
  alors qu'une CVE publiée trois semaines après le déploiement doit apparaître.
- **Colonne `locale` sur `WatchedProduct`** par cohérence de façade avec les autres contextes
  `Portfolio/*` : deux vérités possibles pour un numéro de version.
- **`/tech-radar` ou `/veille`** comme route : collision sémantique avec le Tech Radar de
  ThoughtWorks pour le premier, intraduisible pour le second.
