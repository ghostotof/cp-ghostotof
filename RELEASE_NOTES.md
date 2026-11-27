# v0.30.2 — Correctifs de sécurité : Symfony 8.1.12, Twig 3.31.1

Cette release est un correctif de sécurité. Elle monte les composants Symfony en 8.1.12 pour les 17 avis
publiés le 2026-11-25, et Twig en 3.31.1 pour neuf avis publiés le 2026-11-23. `composer audit` bloquait la
CI sur toutes les branches. Elle livre aussi des montées de dépendances en attente dans `develop`.

## Ce qui change

- **Symfony 8.1.12** : les 17 avis visent `doctrine-bridge`, `framework-bundle`, `http-client`,
  `http-kernel`, `mailer`, `routing`, `security-core`, `security-http` et `serializer`. Tous les composants
  `symfony/*` en 8.1.x passent à leur dernière version. `composer.json` ne change pas, les contraintes
  restent en `8.1.*`. L'exposition, estimée à la lecture de la configuration, est faible :
  - `trusted_proxies: private_ranges` et des clients HTTP scopés, sans cache HTTP ni
    `NoPrivateNetworkHttpClient` ;
  - aucun `remember_me`, aucune stratégie `unanimous`, aucun fragment, aucun `CsvEncoder` exposé ;
  - le mailer joint Scaleway TEM par un DSN tenu dans Secret Manager ; les deux avis du mailer visent un
    homme du milieu sur SMTP, et le correctif s'applique sans configuration.
- **Twig 3.31.1** : huit des neuf avis visent le bac à sable (`sandbox`), que le projet n'utilise pas. Twig
  ne sert qu'à composer les e-mails, à partir de gabarits versionnés. Le neuvième, un échappement manquant
  pour une variable nommée comme l'alias d'un import de macro, ne touche aucun gabarit du dépôt.
- **`messenger:stop-workers`** : depuis 8.1.12, la commande de Symfony échoue quand la base refuse
  l'écriture du signal. Elle répondait `[OK]` jusqu'en 8.1.11, d'où la commande `app:messenger:stop-workers`
  (#528). Le test qui épinglait l'ancien comportement est retourné. Le Job
  `messenger-stop-workers` garde la commande du projet.
- **`mcp/sdk`** (#731) : l'avis GHSA-f4rf-6ghj-mhr6 est ignoré par `composer audit`. Le serveur MCP n'est pas
  encore en production, l'exposition est nulle.
- **Dépendances** :
  - backend : Doctrine ORM 3.7.6, PHPStan 2.3.5, `phpstan-doctrine` 2.1.0, `phpstan-strict-rules` 2.1.1,
    PHPUnit 13.4.6, Psalm 6.20.9, Rector 2.8.4, `symfony/flex` 2.12.0 ;
  - frontend : `vue-i18n` 11.4.17, ESLint 10.14.0, `eslint-plugin-vue` 10.12.0, `typescript-eslint` 8.72.1,
    `vue-tsc` 3.3.14, et le jeu d'icônes Lucide.
- **Documentation** : le détail de `CLAUDE.md` est déplacé dans des règles chargées par chemin.

## À savoir pour le déploiement

- **Aucune migration**, aucun changement de clé, de secret ni de manifeste Kubernetes : pas de fenêtre de
  maintenance, et pas besoin de rejouer la procédure des rôles.
- Les pods backend et frontend redémarrent, comme à chaque release. PostgreSQL et RabbitMQ ne redémarrent pas.

## Vérifications

- **Préprod et prod** : `/` et `/api/watch` répondent `200`, et le formulaire de contact passe comme avant.
- **E-mails** : le message du formulaire de contact arrive, ce qui prouve que le mailer joint toujours
  Scaleway TEM après le correctif `require_tls`.
- **Workers** : s'il est lancé, le Job `messenger-stop-workers` finit au vert.
