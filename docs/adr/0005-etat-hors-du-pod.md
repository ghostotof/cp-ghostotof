# ADR 0005 — Aucun état applicatif sur le système de fichiers du pod

- Statut : **accepté** (2026-09-16), livré par le hotfix `v0.14.1`
- Date : 2026-09-16
- Portée : `backend/config/packages/cache.yaml` (`framework.cache.app`), migration
  `Version20260916180000` (table `cache_items`), `k8s/base/messenger-purge-cronjob.yaml`
  (`cache:pool:prune`), les deux configurations nginx (zone `login`),
  `tools/smoke-login-throttling.sh` et le job `smoke-test-preprod`,
  `tests/Security/RateLimiterStorageTest.php` ; objectif n°8 (sécurité)

## Contexte

L'audit de sécurité du 2026-09-16 a constaté qu'en production **aucun limiteur de débit
Symfony ne fonctionnait** : quatorze tentatives de connexion erronées consécutives contre
`cp-ghostotof.com` ont toutes répondu « Invalid credentials. », alors que
`login_throttling` (5 essais par quart d'heure et par couple IP-identifiant) aurait dû répondre
« Too many failed login attempts » dès la sixième — c'est ce que
`tests/Security/Authentication/LoginThrottlingTest` vérifie, et il est vert depuis des mois.

La cause n'était ni le code ni la configuration de Symfony, mais la rencontre de deux choix
pris chacun pour de bonnes raisons :

1. Les pods tournent avec `readOnlyRootFilesystem: true` (audit de 2026-09, durcissement des
   images) et seul `var/log` est monté en `emptyDir`.
2. Le pool `cache.app` était le `FilesystemAdapter` par défaut de Symfony, sous
   `var/cache/prod/pools`. Or `cache.rate_limiter`, le stockage de **tous** les limiteurs
   (`login_throttling`, contact, définition de mot de passe, palier de base, assistant de
   traduction), en hérite ; le cache de résultats Doctrine activé en `when@prod` aussi.

Sur un disque en lecture seule, `FilesystemAdapter::save()` rend `false` **sans exception**
et le composant RateLimiter ignore cette valeur de retour : chaque requête relisait un
compteur vide et repartait d'une fenêtre neuve. Le défaut a été reproduit sur l'image de
production elle-même (`docker run --read-only`), puis confirmé sur le site.

Ce qui rend le cas instructif est que **rien ne pouvait le voir** :

- la suite PHPUnit tourne sur un disque inscriptible, en local comme en CI ;
- le smoke test de la préprod vérifiait que l'API répond, pas qu'elle freine ;
- aucun journal n'alertait : sans Monolog, l'avertissement « Failed to save key » du composant
  Cache n'est émis qu'au niveau `warning` du logger par défaut, dans le flot de stderr du
  pod, que personne ne lit.

Les seules protections restantes étaient les zones `limit_req` de nginx — et il n'y en avait
aucune sur `/api/login_check`, couvert par le seul filet général `publicapi` (600 requêtes par
minute), soit de l'ordre de 860 000 essais de mot de passe par jour et par adresse IP.

## Décision

**D1 — L'état applicatif ne vit jamais sur le système de fichiers du pod.** Un pod est
jetable, en lecture seule, et il y en a plusieurs : ce qui doit survivre à une requête, être
partagé entre réplicas ou survivre à un redémarrage va en base ou dans un service dédié.
`framework.cache.app` passe sur `cache.adapter.doctrine_dbal`, connexion `database_connection`
(celle de `DATABASE_URL`), dans **tous** les environnements — dev et CI exercent ainsi la
migration et le même chemin d'écriture que la production. `cache.system` (métadonnées de
validation, sérialisation, annotations) reste sur le disque : il est réchauffé au
`docker build` et n'est que lu ensuite.

**D2 — La table `cache_items` est créée par migration**, pas par le `createTable()` paresseux
de l'adaptateur : le schéma reste sous Doctrine Migrations comme tout le reste, et la
première requête d'une release n'a pas un DDL à sa charge. La migration est purement additive
(#175, déploiement standard, sans fenêtre de maintenance).

**D3 — Une purge quotidienne.** L'adaptateur n'efface jamais une ligne expirée de lui-même ;
`cache:pool:prune` rejoint le CronJob de ménage existant
(`contact-failed-messages-purge`, dont le nom n'est pas changé : `kubectl apply -k` ne supprime
pas un objet renommé et le Role du déployeur n'a pas `delete` sur les CronJobs).

**D4 — Deux filets indépendants du stockage, parce que le stockage a déjà failli une fois :**

- une zone nginx `login` sur `/api/login_check` (10 requêtes par minute, rafale de 5), dans les
  deux configurations miroirs. Ce n'est pas le plafond, c'est ce qui sépare un brute-force
  des 600 r/min si le vrai plafond disparaît à nouveau ;
- un smoke test de la préprod qui **exerce** le throttling — six connexions erronées, la
  sixième doit être freinée (`tools/smoke-login-throttling.sh`, testé hors ligne dans
  `tools/tests/`). C'est le seul endroit où un pod réel est interrogé, donc le seul qui aurait
  vu ce défaut. Il est requis par le ruleset de `main` : une préprod qui ne freine plus ne va
  pas en production.

**D5 — L'invariant est pincé par un test de conteneur**, `RateLimiterStorageTest` : chaque
limiteur déclaré et `cache.app` sont adossés à Doctrine DBAL, jamais à un `FilesystemAdapter`.
Il ne reproduit pas l'incident (il ne le peut pas) ; il refuse la configuration qui l'a
permis.

## Alternatives écartées

- **Un `emptyDir` sur `var/cache`.** Réparerait l'écriture, mais chaque réplica aurait ses
  compteurs : avec deux pods, 10 essais au lieu de 5, et un troisième réplica en donnerait 15.
  Le stockage doit être partagé, pas seulement inscriptible.
- **Redis.** Le bon outil pour ce type d'état, mais un service de plus à déployer, sécuriser,
  sauvegarder et surveiller, pour quelques dizaines d'écritures par heure. Postgres est déjà
  là, sauvegardé avec le reste, et Doctrine DBAL est un adaptateur de première classe du
  composant Cache. Le jour où le volume le justifie, changer d'adaptateur est une ligne.
- **Un test PHPUnit qui simule le disque en lecture seule.** Fragile (droits POSIX, conteneur
  de CI en root) et, surtout, à côté du sujet : le défaut n'est pas dans le code mais dans la
  rencontre du code et du manifeste. Seul un test contre le déploiement réel la voit.

## Conséquences

- Une écriture en base par requête freinée et par lecture de quota. Négligeable au regard du
  trafic ; `cache_items` reste petite grâce à la purge quotidienne.
- Les tests fonctionnels qui exercent un quota vident `cache.rate_limiter` avant de commencer
  (ils le faisaient déjà) : la table est partagée entre les tests d'une même base.
- Un nouveau limiteur dans `rate_limiter.yaml` s'ajoute à la liste de `RateLimiterStorageTest` ;
  un `cache_pool` explicite sur un limiteur est précisément ce qu'un oubli laisserait passer.
- Tout futur état « pratique » à poser sur le disque du pod (session, verrou `flock`, cache de
  rendu, profil Xdebug) tombe sous D1 : en base, dans un service, ou nulle part.
- La leçon de méthode vaut au-delà du cache : **un durcissement qui rend une écriture
  impossible doit s'accompagner d'un test qui échoue quand cette écriture était nécessaire.**
  Le `readOnlyRootFilesystem` était juste ; il n'a pas été accompagné.
