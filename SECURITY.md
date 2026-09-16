# Politique de sécurité

La sécurité est l'objectif n°8 de ce projet, et le dépôt documente ses propres audits (voir
`docs/adr/` et l'historique des releases). Un signalement responsable est bienvenu.

## Signaler une vulnérabilité

**Ne pas ouvrir d'issue publique** pour une faille exploitable : une issue est visible de tous
avant qu'un correctif existe.

Utilisez le **signalement privé de GitHub** (*Private Vulnerability Reporting*), qui ouvre un fil
entre vous et le mainteneur, invisible tant qu'un correctif n'existe pas :
[ouvrir un signalement](https://github.com/ghostotof/cp-ghostotof/security/advisories/new).
Décrivez le comportement observé, les étapes pour le reproduire et, si possible, l'impact que vous
estimez. Aucune preuve d'exploitation sur les données d'autrui n'est nécessaire ni souhaitée.

À défaut de compte GitHub, le **formulaire de contact du site** reste ouvert et arrive directement
au mainteneur : [cp-ghostotof.com/fr/contact](https://cp-ghostotof.com/fr/contact) (ou
[/en/contact](https://cp-ghostotof.com/en/contact)), en indiquant « sécurité » dans le sujet.

Une réponse est apportée sous une semaine ; le correctif est publié dans une release dont les
notes citent le signalement, avec votre accord et sous le nom que vous choisissez.

## Périmètre

- Le code de ce dépôt : backend Symfony (`backend/`), frontend Vue (`frontend/`), images Docker
  (`docker/`), manifests Kubernetes (`k8s/`), pipeline GitHub Actions (`.github/`).
- Le site en production, [cp-ghostotof.com](https://cp-ghostotof.com), **sans** test destructif ni
  déni de service : les limiteurs de débit sont documentés dans `CLAUDE.md` et le formulaire de
  contact envoie un vrai e-mail à chaque soumission.

Hors périmètre : les services tiers (hébergeur, registre d'images, fournisseurs d'API) et les
dépendances, à signaler à leurs mainteneurs — Dependabot suit les quatre écosystèmes du dépôt.

## Versions prises en charge

Seule la **dernière release** (`main`, tag le plus récent) est déployée et corrigée. Les tags
précédents ne reçoivent pas de correctif.

## Ce que le dépôt fait déjà

Analyse statique (PHPStan niveau maximal, Psalm en analyse de flux de données, CodeQL, ESLint
avec règles d'accessibilité), tests d'exposition des routes qui refusent par défaut toute route
non justifiée, secrets hors dépôt (Secret Manager), actions GitHub figées sur un SHA — l'épinglage
est imposé par le dépôt, une action référencée par un tag ou une branche est refusée —, alertes
Dependabot et correctifs de sécurité automatiques actifs, images `read-only` sous un utilisateur non
privilégié. Les décisions et leurs raisons sont dans `docs/adr/`.
