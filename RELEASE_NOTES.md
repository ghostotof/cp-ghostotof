# v0.14.1 — Correctif de sécurité : les limiteurs de débit retrouvent leur état

Hotfix issu de l'audit de sécurité du 2026-09-16. En production, aucun limiteur de débit
Symfony ne fonctionnait : l'anti-brute-force du login, les quotas du formulaire de contact,
du parcours de définition de mot de passe, de l'accès au palier de base et de l'assistant de
traduction laissaient tout passer. Seules les zones nginx freinaient encore, et il n'y en
avait pas sur le login.

## Cause et correctif (ADR 0005)

- Le pool `cache.app`, dont hérite le stockage de tous les limiteurs, écrivait sur le système de
  fichiers du pod — en lecture seule en production. L'écriture échouait en silence et chaque
  requête repartait d'un compteur vide.
- `cache.app` est désormais adossé à Doctrine DBAL (table `cache_items`, créée par migration,
  purgée chaque nuit) : partagé entre les réplicas, durable, sans service supplémentaire.
- Un test de conteneur refuse toute configuration qui ramènerait un limiteur sur le disque.

## Deux filets, indépendants du stockage

- Une zone nginx dédiée à `POST /api/login_check` (10 requêtes par minute, rafale de 10), en
  amont de PHP.
- Le smoke test de la préprod exerce désormais réellement le throttling : six connexions
  erronées, la sixième doit être freinée, sinon la release ne va pas en production.

## Documentation

- `docs/adr/0005-etat-hors-du-pod.md` : aucun état applicatif sur le système de fichiers du
  pod, et pourquoi ni un volume par pod ni un test PHPUnit n'auraient suffi.
- `CLAUDE.md` : nouvel invariant de déploiement.
