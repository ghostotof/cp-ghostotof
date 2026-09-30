# v0.18.4 — /stack affiche ce qui tourne, cache système inscriptible, CI bornée

Trois correctifs. Nouvelle image backend, une migration de données (additive, exécutée avant
le rollout comme d'habitude) et une modification des manifestes Kubernetes : un volume et un
initContainer de plus dans les six pods qui exécutent l'image backend. Aucun secret nouveau.
Déploiement sans interruption : `postgres` et `rabbitmq` ne sont pas touchés.

## /stack publiait des versions fausses (#287)

Depuis le 9 septembre, la page annonçait PostgreSQL 18.4, RabbitMQ 4.3.4 et Node 26.7.0, alors
que le cluster exécute 18.6, 4.3.5 et 26.8.1. #19 avait fait relever ces versions au
`docker build`, mais seulement dans le seed : les produits déjà en base étaient restés en saisie
manuelle, et le seed ne réécrit jamais une base remplie.

- La migration `Version20260930180000` passe PostgreSQL, RabbitMQ, nginx, Node.js et Vue.js en
  version relevée au build, uniquement pour les lignes encore en saisie manuelle. Elle peut être
  rejouée sans effet.
- Le backoffice refusait cette source en 422 : un produit relevé au build ne pouvait plus être
  modifié. Le champ est désormais borné par les valeurs de l'enum, et le formulaire connaît la
  source.

`/stack` affichera les bonnes versions au rafraîchissement de la veille qui suit le déploiement
(04:41 UTC).

## Le cache système de Symfony n'était pas en lecture seule (#288)

Les pods tournent en `readOnlyRootFilesystem`, et `cache.system` passait pour n'être que lu en
production. Ce n'était pas le cas : property-info, serializer et API Platform y écrivent des clés
que le préchauffage ne produit pas. Chaque écriture échouait à chaque requête, avec un
avertissement dans les journaux, et le cache ne servait jamais pour ces clés.

- Les six pods qui exécutent l'image backend montent un `emptyDir` borné (64 Mo) sur ce
  répertoire. Un initContainer y copie d'abord le cache préchauffé de l'image (~9 Mo).
- ADR 0005 amendé (D11) : un cache dérivé, jetable et identique dans chaque pod n'est pas de
  l'état applicatif.
- `SystemCachePodVolumeTest` fait rougir la suite si un pod est ajouté sans ce volume.

## La CI ne peut plus rester bloquée six heures (#285)

Aucun job n'avait de `timeout-minutes` : GitHub les laissait tourner jusqu'à 360 minutes, et un
miroir apt figé a bloqué la release v0.18.3 plus de 30 minutes. Chaque job est désormais borné
(3 à 10 fois sa durée habituelle, 45 minutes pour les déploiements), et les étapes `apt-get` ont
une limite de 5 minutes avec des reprises. Un contrôle dans `tools-tests` empêche d'ajouter un
job sans limite.

## À vérifier en préprod

- `kubectl logs deploy/backend -c php-fpm | grep -c "Read-only file system"` doit rester à 0
  après les smoke tests.
- Après un rafraîchissement de la veille, `/api/watch` doit annoncer PostgreSQL 18.6,
  RabbitMQ 4.3.5 et Node 26.8.1.
