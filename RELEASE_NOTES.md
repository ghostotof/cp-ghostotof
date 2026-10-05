# v0.20.0 — Erreurs problem+json partagées et échecs IA lisibles

Release de consolidation, sans nouvelle fonctionnalité visible. Elle réunit en un seul endroit le
rendu des erreurs typées hors API Platform, rend lisibles dans les journaux les échecs des
fournisseurs de modèles, et épingle l'outil d'analyse `lsp:check` de la CI. Aucune migration,
aucun manifeste Kubernetes modifié, aucun secret nouveau.

## Erreurs typées sous `/api` (#322)

- **Un seul écouteur rend en problem+json** toute exception typée levée par un contrôleur sous
  `/api` hors API Platform (`ApiProblemResponseListener`). Il remplace les deux copies propres à
  l'assistant de parcours et à l'accès instantané. Sa priorité est figée par un test qui lit le
  vrai dispatcher.
- **Le 429 de l'accès instantané** (`POST /api/account/base-access`) porte désormais le type
  `/errors/rate-limited`, comme les autres quotas. Le frontend ne lit que le statut, donc rien ne
  change à l'écran. Ce 429 passe aussi par la journalisation du noyau, en `info`. Avant, il n'était
  pas journalisé du tout.
- **Une seule règle d'ancrage de sous-arbre** (`CanonicalPath::isUnder`) : « le préfixe exactement,
  ou le préfixe suivi de `/` », sur le chemin décodé. Les gardes qui recopiaient ce test s'appuient
  dessus.

## Échecs des fournisseurs de modèles (#308)

- **Le traducteur et l'assistant décrivent l'échec du fournisseur de la même façon**, par un seul
  lecteur partagé (`ProviderFailure`). Ce lecteur ne cite jamais le message, qui peut contenir le
  corps de la réponse du fournisseur.
- **Un motif d'échec lisible**, même en flux, où aucun type d'erreur ne survit : la clé
  `providerFailure` du journal vaut par exemple `authentication`, `permission-denied`,
  `rate-limited` ou `server-error`.
- **Changement de clé dans les journaux** : `serverErrorStatus` (traducteur) devient
  `providerStatus`, comme pour l'assistant. Un filtre `jq` écrit sur l'ancienne clé est à adapter.

## Outillage

- **`lsp:check` exécute une version épinglée des Symfony Language Tools** (#343), installée et
  vérifiée par somme SHA-256 sans appel à l'API GitHub. Cela met fin aux 403 intermittents du job
  `lsp-check-backend`, tombés deux fois sur `main`. Un échec de téléchargement est nommé comme tel
  dans le résumé du job, pour ne plus passer pour un défaut du code.

## Documentation

- L'ADR 0004 et `CLAUDE.md` disent la phase 2 de l'assistance IA livrée en v0.19.0 (#342).

## À vérifier en préprod

- Smoke tests et audit verts.
- `lsp-check-backend` vert sur la branche, avec la version épinglée.
- `POST /api/account/base-access` rejoué au-delà du quota : 429 en `application/problem+json`, type
  `/errors/rate-limited`, avec `Retry-After`.
