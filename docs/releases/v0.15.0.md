# v0.15.0 — Journal de sécurité, secrets par environnement, jeton de déploiement rotatif

Lot 1 de la remédiation du 3e audit de sécurité (2026-09-16), phases 2 et 3. La phase 1 (limiteurs
de débit et adresse du visiteur) est en production depuis v0.14.1.

## Journal de sécurité (phase 3)

- Monolog est installé : en production, les canaux `security` et `security_audit` sortent en JSON
  sur stderr dès le niveau `info`, le reste suit `LOG_LEVEL` (`warning` par défaut).
- `SecurityAuditLogger` trace treize événements : connexion réussie, ratée ou freinée, déconnexion,
  jeton du palier de base, rejet CSRF, refus d'accès au backoffice, invitation, changement de rôle,
  changement de mot de passe, suppression de compte, activation. Chaque ligne porte l'événement,
  l'identifiant visé, l'auteur, l'adresse IP et le chemin, et jamais un mot de passe, un jeton ni un
  e-mail : un test le garantit.
- Lecture sur un pod documentée dans `k8s/README.md` (`kubectl logs … | jq`).

## Dépôt GitHub et accès au cluster (phase 2)

- Alertes de vulnérabilité Dependabot et signalement privé (Private Vulnerability Reporting)
  activés ; `SECURITY.md` en fait le canal principal. Épinglage SHA des actions imposé par le dépôt.
  Les correctifs automatiques Dependabot restent désactivés : leurs PR viseraient `main`, hors du
  flux de release.
- Les secrets de déploiement deviennent des secrets d'environnement : `preprod` n'est lisible que
  depuis `release/*`, `production` que depuis `main`. Tous les jobs qui les lisent déclarent leur
  environnement.
- Le jeton du déployeur GitHub Actions n'est plus un secret Kubernetes sans expiration mais un jeton
  lié, renouvelé tous les 90 jours par `tools/rotate-deployer-token.sh`, qui vérifie ses droits
  avant de le publier. Le modèle de privilège réel du déployeur est documenté tel quel.
- Tout est appliqué par `tools/github-settings.sh`, idempotent.

## Sans changement fonctionnel

Le site est celui de v0.14.1 ; cette version ne modifie ni le contenu ni le frontend.
