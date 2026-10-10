# ADR 0005 — Aucun état applicatif sur le système de fichiers du pod

- Statut : **accepté** (2026-09-16), livré par le hotfix `v0.14.1` ; **amendé deux fois le
  2026-09-30** (verrou des limiteurs, issue #272 ; `cache.system` inscriptible, issue #288 —
  voir les deux dernières sections)
- Date : 2026-09-16
- Portée : `backend/config/packages/cache.yaml` (`framework.cache.app`), migration
  `Version20260916180000` (table `cache_items`), `k8s/base/messenger-purge-cronjob.yaml`
  (`cache:pool:prune`), les deux configurations nginx (zone `login`),
  `tools/smoke-login-throttling.sh` et le job `smoke-test-preprod`,
  `tests/Security/RateLimiterStorageTest.php`, `backend/config/packages/lock.yaml` et
  `PostgresAdvisoryLockDsnEnvVarProcessor` (amendement #272), le volume `cache-system` des six
  manifestes qui exécutent l'image backend et `SystemCachePodVolumeTest` (amendement #288) ;
  objectif n°8 (sécurité)

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

## Second constat, révélé par le filet : l'adresse du visiteur ne traversait pas la chaîne

Le smoke test de D4 a refusé la première préprod corrigée : six connexions erronées, aucune
freinée — alors que la même image, en lecture seule, freinait bien contre une base locale. La
table `cache_items` de la préprod contenait pourtant les états. Décodés, ils montraient **deux
compteurs globaux distincts pour une seule machine cliente** : l'adresse IP vue par Symfony
changeait d'une requête à l'autre.

La cause est en amont de tout ce que ce dépôt déploie. Le Load Balancer Scaleway est un proxy
complet : sans proxy-protocol, ingress-nginx voit comme client l'une des deux adresses du LB
et la transmet dans `X-Forwarded-For`. Symfony (dont `private_ranges` inclut `100.64.0.0/10`,
la plage des pods du cluster) remontait donc jusqu'au LB et prenait son adresse pour celle du
visiteur. Le sidecar nginx, lui, ne faisait pas confiance à `100.64.0.0/10` : `real_ip` ne
s'appliquait jamais et toutes ses zones comptaient sous **une seule** clé, l'adresse du pod
ingress — le déni de service que l'audit C7 croyait avoir écarté, en place depuis le premier
déploiement, invisible parce que le trafic n'a jamais approché les plafonds.

Conséquence pratique, et raison de ne pas livrer D1 seule : avec un stockage qui fonctionne
enfin et des adresses qui ne distinguent personne, vingt-cinq essais de mot de passe auraient
verrouillé la connexion pour tout le monde pendant un quart d'heure, et le formulaire de
contact n'aurait accepté que cinq messages par heure pour tout le site.

**D6 — Le proxy-protocol v2 est un prérequis du cluster**, déclaré dans
`k8s/ingress-nginx-values.yaml` (annotation `scw-loadbalancer-proxy-protocol-v2` sur le Service
du contrôleur, `use-proxy-protocol` dans sa configuration), appliqué par `helm upgrade` en une
seule commande — les deux réglages vont ensemble ou le trafic casse.

**D7 — Les plages de confiance du sidecar incluent `100.64.0.0/10`** (RFC 6598, les pods
Kapsule), avec `real_ip_recursive on` pour remonter une chaîne à plusieurs sauts jusqu'à la
première adresse hors de confiance. Les deux confs nginx restent miroirs.

Le smoke test de D4 couvre aussi ce cas sans rien y ajouter : six essais d'une seule machine
doivent tomber sur une seule clé. C'est ce qu'il a prouvé en refusant la première livraison.

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

## Amendement du 2026-09-30 : le stockage partagé ne suffit pas, il faut un verrou partagé (issue #272)

La décision ci-dessus a rendu l'état des limiteurs **durable et partagé**, pas **atomique**.
`symfony/lock` n'était pas installé : `RateLimiterFactory` recevait un verrou `null`, et
`consume()` fait un lire-modifier-écrire sur `cache.app`. Deux requêtes qui lisent la même
fenêtre écrivent chacune « n + 1 ». Reproduit en dev : vingt `consume(1)` simultanés sur la même
clé ne décomptaient **qu'une** unité, trois passages sur trois. Tous les limiteurs étaient
concernés, `login_throttling` compris — et les deux quotas facturés (traduction, assistant) en
premier, puisqu'un compte peut y envoyer des salves synchronisées.

- **D8 — Chaque limiteur prend un verrou, et ce verrou est partagé entre pods.** `framework.lock`
  pointe sur un advisory lock PostgreSQL de la base de l'application
  (`DoctrineDbalPostgreSqlStore`). Dès que le composant est configuré, les limiteurs de
  `rate_limiter.yaml` (`lock_factory: 'auto'`) et ceux de `login_throttling` reçoivent
  `lock.factory`. `RateLimiterStorageTest` exige ce store pour chacun ;
  `RateLimiterConcurrencyTest` (issue #277) en vérifie l'effet : dix processus consomment au même
  instant la même clé d'un limiteur par politique (`sliding_window`, `fixed_window`), et chaque
  unité doit être décomptée — sans verrou, des unités sont perdues (une sur dix décomptée en dev).
- **D9 — Le DSN du verrou est dérivé de `DATABASE_URL`, jamais déclaré à part.** L'env processor
  `pg_advisory:` suffixe le schéma de `+advisory` — c'est ce suffixe qui fait choisir le store
  advisory à `StoreFactory` ; `postgresql://` seul donnerait un `DoctrineDbalStore` à table. Tout
  autre schéma est refusé à la première instanciation du verrou, sans reprendre l'URL.
- **D10 — Aucune requête web n'attend un verrou indéfiniment.** L'advisory lock est de session :
  `pg_advisory_lock()` attend sans fin, le TTL du composant Lock ne s'applique pas à ce store, et
  `max_execution_time` ne compte pas l'attente réseau. Un détenteur disparu sans fermer son socket
  (nœud perdu) garderait le verrou jusqu'au keepalive TCP, et huit requêtes sur la même clé
  figeraient un pod. `www.prod.conf` pose donc `env[PGOPTIONS] = "-c lock_timeout=5s"` — le DSN
  ne permet pas de viser la seule connexion du verrou, DBAL ne transmettant pas `options` à
  pdo_pgsql : la borne vaut pour toutes les connexions des workers FPM, ORM compris, jamais pour
  la console (migrations, worker Messenger, CronJobs) — et `request_terminate_timeout = 65s`,
  juste au-dessus du `fastcgi_read_timeout`. Démontré en dev : un `consume()` attend tant que le
  verrou est tenu, et échoue en 3,0 s sur `LockAcquiringException` avec un `lock_timeout` de 3 s.
  `FpmLockWaitBoundTest` fige les deux directives.

Alternatives écartées :

- **`flock` ou `semaphore`** — c'est ce que pose la recette Flex (`LOCK_DSN=flock`). Local au
  pod : il répare un poste de dev, la CI et un pod isolé, et laisse la production ouverte dès
  deux réplicas. C'est exactement l'erreur de l'`emptyDir` ci-dessus, sur le verrou au lieu du
  stockage. La recette a été défaite ; si une mise à jour la réintroduit, le test tombe.
- **Une variable `LOCK_DSN` routée par Secret Manager.** Aucun code, mais un second secret
  portant le mot de passe de la base, à router dans chaque `ExternalSecret` et à tenir
  synchronisé à chaque rotation.
- **La table `lock_keys` (`DoctrineDbalStore` sur la connexion Doctrine).** Aucun code non plus,
  mais une migration, un `INSERT`/`DELETE` par `consume()` et un verrou non bloquant, donc une
  attente par sondage (~100 ms) sous contention.
- **Un compiler pass qui branche le store advisory sur la connexion de l'ORM.** Économise une
  connexion, mais contourne le câblage du framework (l'identifiant du store n'est pas stable)
  et casserait silencieusement à une montée de version.

Conséquences :

- **~1,5 ms par `consume()`** mesuré en dev (1,0 → 2,5 ms : pose et levée du verrou), plus
  l'ouverture d'une seconde connexion PostgreSQL par requête qui atteint un limiteur — le store
  dérivé d'un DSN a la sienne. Négligeable sur des routes à faible trafic ; à surveiller si un
  limiteur venait à couvrir une route chaude.
- En test, Doctrine suffixe la base de `_test` mais pas le verrou, qui se pose sur la base sans
  suffixe : elle existe partout (`POSTGRES_DB` en CI) et un advisory lock y sérialise tout autant.
- Un nouveau limiteur s'ajoute toujours à `RateLimiterStorageTest` : la même liste vérifie
  désormais le stockage **et** le verrou, et un test la confronte aux services `limiter.*` du
  conteneur — un oubli fait rougir la suite au lieu d'échapper aux deux contrôles.
- **Un limiteur ne se consomme jamais dans une transaction Doctrine** (`wrapInTransaction`). Le
  verrou vit sur la connexion du store, la ligne de `cache_items` sur celle de l'ORM : PostgreSQL
  ne relie pas les deux, si bien qu'un détenteur qui attend une ligne verrouillée par une
  transaction dont l'auteur attend le verrou ne sortirait qu'au `lock_timeout` ; et l'écriture de
  la fenêtre, visible seulement au commit, serait publiée après la levée du verrou — la perte de
  mise à jour de #272 reviendrait. Aucun appel actuel n'est dans une transaction.
- Une panne du verrou (connexion refusée, `lock_timeout` atteint, connexion perdue avant la
  libération) refuse la requête, ne la laisse jamais passer, ce qui est le bon sens de
  défaillance. Elle répondait **500** ; depuis l'issue #276, `RateLimiterLockFailureListener` la
  rend en **503** problem+json (`/errors/rate-limiter-unavailable`, `Retry-After: 10`, au-dessus
  du `lock_timeout` de 5 s — `FpmLockWaitBoundTest` le fige) sur **toute** route `/api` limitée,
  servie par API Platform ou non : prise (`LockAcquiringException`), libération
  (`LockReleasingException`) et conflit relayé par le store interne (`LockConflictedException`).
  Le message de ces exceptions nomme la ressource verrouillée, donc la clé du limiteur (IP,
  identifiant tenté) : il ne sort ni dans la réponse ni sur le canal principal. Le listener est à
  la priorité 16, au-dessus du `logKernelException` de Symfony (0) qui l'aurait écrit en
  `critical`, et journalise lui-même une ligne `error` avec les classes des exceptions et le
  chemin — l'incident reste visible côté exploitation. Le canal `lock`, qui portait encore le nom
  de la ressource au niveau `notice`, est muet en production depuis l'issue #315 (point suivant).
  **Portée** : le listener prend toute panne du composant Lock sous `/api`, pas seulement celles
  des limiteurs — exact aujourd'hui, où ils en sont les seuls utilisateurs. Un futur verrou
  métier impose de revoir ce listener, sans quoi sa panne serait étiquetée « limiteur ».
  **Décision sur l'audit** : un événement dédié, `rate-limiter-unavailable`, émis au journal de
  sécurité sur **toutes** les routes limitées, pas seulement le login — et non `login-failed`,
  car aucun identifiant n'a été vérifié, et un filtre sur les échecs d'authentification
  mélangerait les deux. Sans sujet : le chemin dit quel limiteur a cédé. Une rafale de requêtes
  pendant une panne du verrou reste ainsi visible au `jq` sur le canal `security_audit`.
- Le canal Monolog `lock` a son propre handler, à `warning` (issue #315), et aucun autre handler
  de production ne le reçoit (`main` ni `console`). Le composant trace chaque pose et levée en
  `debug`, chaque échec en `notice`, toujours avec la ressource (IP ou identifiant tenté) : la
  préprod (`LOG_LEVEL=debug`) l'aurait écrite à chaque requête limitée, et même le plafond à
  `notice` d'abord retenu l'écrivait à chaque panne. Ce plafond se justifiait tant qu'aucune autre
  trace de la panne n'existait ; depuis #276, la ligne `error` de `RateLimiterLockFailureListener`
  la porte sans la ressource, et le composant n'émettant rien au-dessus de `notice`, le canal est
  muet en production. Le handler reste déclaré pour que le canal ne retombe dans aucun autre.
  Ce choix suppose que tout verrou est pris pendant une requête `/api` — vrai pour les sept
  limiteurs — : un futur verrou hors HTTP serait muet en cas de panne. `LockLogChannelTest` le
  fige ; le noyau de test ne charge pas `when@prod`, la preuve est donc un test de configuration
  et non de bout en bout.
- Les clés d'advisory lock sont un `crc32` de la ressource, sur 32 bits : deux ressources peuvent
  se sérialiser l'une l'autre, et une collision est calculable sur les clés IP. Accepté : l'effet
  est de quelques millisecondes, borné par les zones nginx et par D10.
- Le filet réel reste `tools/smoke-login-throttling.sh` en préprod : il traverse le limiteur de
  login, donc le verrou, sur un vrai pod et la vraie `DATABASE_URL`.

## Amendement du 2026-09-30 : `cache.system` n'est pas en lecture seule (issue #288)

D1 posait que `cache.system` « est réchauffé au `docker build` et n'est que lu ensuite ».
C'était faux. Le préchauffage couvre l'essentiel (1 179 fichiers, ~9 Mo), mais une partie des
clés ne naît qu'à l'exécution : property-info et serializer pour des contextes que le
préchauffage n'énumère pas, métadonnées de propriétés d'API Platform, et, dans les Jobs CLI,
le `ParserResult` des requêtes DQL (le *query cache* de Doctrine est sur ce pool en
`when@prod`). Sur un disque en lecture seule, chacune de ces écritures échouait **à chaque
requête qui la demandait** : 76 échecs pour 27 requêtes juste après un déploiement de préprod,
35 clés distinctes, chacune ratée à nouveau à chaque passage, avec un `WARNING` du canal
`cache` à chaque fois. Le cache ne servait jamais pour ces clés.

Ce n'est pas le défaut de D1 : rien n'est faux fonctionnellement, et aucun contrôle de sécurité
n'en dépend. Le coût est du calcul refait et des journaux bruyants, où un vrai avertissement du
canal `cache` (celui de `cache.app`, qui signalerait le retour du constat A1) se noierait.

- **D11 — `cache.system` est monté sur un `emptyDir` propre au pod, rempli au démarrage.**
  Chaque pod qui exécute l'image backend (les deux Deployments, les deux CronJobs, les Jobs
  `migrate` et `seed`) déclare un volume `cache-system` borné (`sizeLimit: 64Mi`), monté
  inscriptible sur `var/cache/prod/pools/system`. Un initContainer `seed-cache-system` y copie
  d'abord le cache préchauffé de l'image, que l'`emptyDir` masquerait sinon. Le reste de
  `var/cache` (conteneur compilé, proxies Doctrine) reste en lecture seule.

**Pourquoi ce n'est pas une exception à D1, mais sa limite.** D1 vise l'état **applicatif** :
ce qui doit être partagé entre réplicas ou survivre à un pod. `cache.system` n'est ni l'un ni
l'autre. Il est **dérivé** (recalculable à tout moment à partir du code), **jetable** (le
perdre coûte un recalcul, jamais une donnée), et **identique d'un pod à l'autre** (même image,
mêmes métadonnées). L'alternative écartée « un `emptyDir` sur `var/cache` » l'était pour les
compteurs, qu'un volume par pod aurait divisés ; un cache de métadonnées n'a pas cette
propriété à perdre. La règle de D1 devient donc : **l'état applicatif va en base, dans un
service ou nulle part ; un cache dérivé et propre au pod peut vivre dans un `emptyDir`
borné**. Une session, un verrou, un compteur ou un cache de rendu dépendant de l'utilisateur
restent de l'état applicatif.

Alternatives écartées :

- **APCu en mémoire.** Rien sur le disque, conforme à la lettre de D1, mais une extension de
  plus, un cache préchauffé qui ne sert plus (chaque pod repart de zéro), et aucune APCu en
  CLI : les Jobs auraient gardé le défaut.
- **Garder le comportement, filtrer les avertissements.** Le moins cher, mais le cache ne sert
  toujours pas, et tout filtre sur le canal `cache` risque de masquer ceux de `cache.app`.
- **Mettre le *query cache* de Doctrine sur `cache.app` (DBAL).** Ne traite qu'une des sources,
  et un aller-retour PostgreSQL pour éviter l'analyse d'une requête DQL n'est pas un gain
  évident.

Conséquences :

- Chaque démarrage de pod copie ~9 Mo (moins d'une seconde) avant le conteneur principal.
- `SystemCachePodVolumeTest` fige le dispositif sur chaque pod spec (volume borné, montage
  inscriptible dans chaque conteneur de l'image, initContainer de copie) et vérifie qu'aucun
  autre manifeste n'exécute l'image backend : un pod ajouté sans le volume fait rougir la suite.
- Le filet réel est le déploiement en préprod : le compte des `Read-only file system` dans les
  journaux de `php-fpm` doit y tomber à zéro.
- Leçon, dans le prolongement de celle de D1 : **un commentaire qui affirme qu'un chemin n'est
  que lu est une hypothèse à vérifier dans les journaux d'un vrai pod**, pas une propriété du
  code.
