# v0.18.1 — Le jeton d'invitation n'arrive plus que par le fragment

Correctif frontend uniquement : aucune migration, aucun changement d'image backend ni de
manifeste Kubernetes. Déploiement sans interruption.

## Retrait du repli `set-password/:token?` (3e audit, T4.4)

Depuis v0.16.0 (audit A7, T4.2), le lien d'invitation porte son jeton dans le **fragment** de
l'URL (`…/set-password#<jeton>`), qu'un navigateur n'envoie jamais au serveur : le jeton
n'atteint ni les journaux d'accès du frontend ni ceux de l'ingress. Le segment de chemin
`:token?` n'était conservé que pour les liens envoyés avant ce changement, dont la durée de
vie de 48 heures est écoulée.

- La route `set-password` n'accepte plus de segment : un lien `set-password/<jeton>` est un
  **404 du routeur**. Un secret ne peut plus arriver par le chemin, et c'est voulu.
- `meta.canonicalPath` disparaît avec lui : il n'existait que pour empêcher ce segment d'être
  recopié dans `<link rel="canonical">` et les `hreflang`. Ces liens sont construits depuis le
  chemin de la route, qui ne contient jamais le fragment — aucun mécanisme dédié n'est
  nécessaire, et une route qui porterait un secret dans son chemin serait le bug à corriger.
- Tests : le garde du routeur pin le 404 sur un ancien lien ; les cas « segment » de la page
  de définition de mot de passe et le test de `canonicalPath` sont remplacés par leurs
  équivalents sur le fragment.

Avec ce point, le 3e audit de sécurité (2026-09-16) n'a plus aucun constat de code ouvert.
