# v0.30.1 — Correctifs de sécurité : Symfony 8.1.11, nginx 1.30.6, API Platform 4.4.6

Cette release est un correctif de sécurité. Elle monte les composants Symfony en 8.1.11 pour les 18 avis
publiés le 2026-11-04, et nginx en 1.30.6 pour trois failles hautes. Elle livre aussi des corrections en
attente dans `develop` : API Platform aligné sur la 4.4.6, un `Retry-After` exact sur les quotas, et un audit
qui n'envoie plus d'identifiants sur `http://`.

## Ce qui change

- **Symfony 8.1.11** (#757) : les 18 avis de sécurité du 2026-11-04 visent 11 composants, dont
  `http-foundation`, `routing`, `security-http`, `security-core`, `serializer`, `http-client`, `http-kernel`
  et `validator`. Tous les composants `symfony/*` en 8.1.x passent à leur dernière version, 8.1.11, ou 8.1.10
  pour ceux qui n'ont pas de 8.1.11. L'exposition, estimée à la lecture de la configuration, est faible :
  - `trusted_proxies: private_ranges`, alors que le site est servi en IPv4 seul ;
  - deux clients HTTP scopés ;
  - règles de chemin devant PHP.

  Elle est nulle pour les autres avis : aucune règle d'accès sur `methods`, aucune route à hôte, aucune
  connexion programmatique, aucun `UploadedFile`, aucune contrainte `Ip`, pas d'X509.
- **nginx 1.30.6** : trois failles hautes sont corrigées (CVE-2026-15176, CVE-2026-93479, CVE-2026-97205),
  dont un dépassement de tas dans `ngx_http_core_module`. La version monte dans l'image frontend et dans le
  sidecar nginx du backend.
- **API Platform 4.4.6** (#701) : le bundle était resté épinglé en 4.3.19 alors que des composants étaient déjà
  en 4.4. La famille est désormais alignée sur une seule version, avec des contraintes en caret. Une garde de
  tests fait échouer la suite sur toute dépréciation d'API Platform. `dependabot.yml` écarte la majeure 5.0
  tant qu'elle n'est pas migrée.
  - **Comportement visible** : une requête `HEAD` ne porte plus les en-têtes de cache du `GET` (option
    `enable_head_request_optimization`, active par défaut en 4.4 et gardée). Par exemple, `HEAD /api/watch`
    répond `Cache-Control: no-cache, private`, et `HEAD /api/experience/technologies` n'a ni `ETag` ni `Vary`.
    Les `GET` sont inchangés.
- **`symfony/mime` 8.1.10** (CVE-2026-45070) : aucune exposition, aucun nom de paramètre MIME ne vient d'un
  client.
- **`Retry-After` exact** (#743) :
  - Quotas `sliding_window` (formulaire de contact, définition du mot de passe, accès de base, assistants
    IA) : le `Retry-After` annonce désormais le vrai délai. Avant, il pouvait annoncer environ 120 s pour une
    attente réelle de 3 600 s.
  - Blocage de connexion : le `Retry-After` est plafonné à la fenêtre de `login_throttling` (900 s). Il
    dépasse le vrai délai de moins de 62 s et n'invite jamais à réessayer trop tôt.
  - `RetryAfterListener` arrondit désormais par excès.
- **Audit sans identifiants sur `http://`** (#750) : les sondes de redirection d'`audit-prod.sh`, qui sert
  aussi à `audit-preprod`, partent désormais sans Basic Auth. Avant, l'en-tête partait en clair sur
  `http://` avant la redirection vers `https://`. Un test, `tools/tests/audit-prod.test.sh`, le garde. Les
  identifiants n'ont pas été changés : le risque est jugé faible et accepté.
- **Dépendances** :
  - backend : PHPUnit 13.4.5, Rector 2.8.3 (règle `ExplicitNullableObjectInstanceRector` écartée), PHPStan
    2.3.4, Monolog 3.12.1, `symfony/polyfill-*` 1.43.3 ;
  - frontend : Vite 8.3.5, `eslint-plugin-vuejs-accessibility` 2.6.3, jeu d'icônes Simple Icons ;
  - CI : `github/codeql-action` 4.39.1, `docker/login-action` 4.7.0.

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé ni de secret : pas de fenêtre de maintenance, et pas besoin de
  rejouer la procédure des rôles.
- **Un seul manifeste Kubernetes change** : l'image du sidecar nginx dans `k8s/base/backend-deployment.yaml`.
  Les pods backend redémarrent, comme à chaque release. PostgreSQL et RabbitMQ ne redémarrent pas.
- **Clients qui se fient à `HEAD`** : un outil de supervision qui lisait `ETag` ou `Cache-Control` sur un
  `HEAD` doit passer à `GET`.

## Vérifications

- **Préprod et prod** : `/` et `/api/watch` répondent `200`, et le formulaire de contact passe comme avant.
- **API Platform** : après un `POST /api/contact` en préprod, les journaux du backend ne contiennent aucune
  dépréciation `api-platform/core`.
- **Quotas** : un `429` du formulaire de contact porte un `Retry-After` cohérent avec la fenêtre configurée,
  et non plus d'environ 120 s.
- **nginx** : `nginx -v` dans le sidecar du backend et dans le pod frontend affiche `1.30.6`.
