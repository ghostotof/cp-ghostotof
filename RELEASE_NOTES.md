# v0.19.0 — Assistant « interrogez mon parcours »

Release de fonctionnalité : la phase 2 de l'assistance IA (ADR 0004, spec 0005). Une personne du
palier nominatif (`ROLE_TRUSTED`) pose des questions en langage naturel sur le parcours, depuis
une page du site, et reçoit une réponse en flux, fondée uniquement sur les contenus qu'elle lit
déjà. Aucune migration. L'image backend gagne un paquet système (`poppler-utils`). Les deux
`ExternalSecret` du backend lisent deux clés de plus, publiées avant cette release.

## L'assistant de parcours (spec 0005)

- **Page `/fr/assistant` et `/en/assistant`**, réservée à `ROLE_TRUSTED` et `ROLE_SUPER`. La réponse
  s'affiche au fil de l'eau, la conversation glisse sur une fenêtre bornée, et une session expirée
  renvoie vers la connexion.
- **`POST /api/assistant/answers`** répond en `text/event-stream` : des fragments `delta`, puis un
  événement final `done` (jetons, durée) ou `error`. Un fournisseur injoignable avant le premier
  fragment donne un 503 problem+json (`/errors/assistant-unavailable`), jamais une page HTML.
- **Modèle Scaleway Generative APIs** (`mistral-small-3.2-24b-instruct-2506`, `fr-par`), l'hébergeur
  du site : c'est le seul opérateur auquel le CV nominatif peut être envoyé (ADR 0004 D3 amendée).
  Clé dédiée par environnement, limitée à l'inférence sur un seul projet.
- **Le corpus** est composé à chaque appel à partir des contenus du palier : CV sans identité,
  études de cas, et le texte du CV nominatif, extrait du PDF servi par `GET /api/cv` par
  `pdftotext`. Aucune base vectorielle, aucun outil, rien n'est persisté. Si le CV est absent ou
  illisible, l'assistant le dit au lieu d'inventer.

## Un coût borné par construction

- Une conversation compte au plus 11 messages et 16 000 caractères. Au-delà, la réponse est un 422
  typé (`/errors/invalid-conversation`). Un corps de plus de 128 Kio reçoit un 413, mais seulement
  après le pare-feu : un appelant non autorisé apprend seulement qu'il est refusé.
- Quota de 30 questions par heure et par compte (429 avec `Retry-After`). Une requête invalide ne
  consomme pas de quota. `max_tokens` est fixé à 1024, et le client HTTP a un délai total borné.
- Côté nginx, une zone dédiée limite à 10 requêtes par minute et à un seul flux simultané par
  adresse, avec une réponse 429 en problem+json.
- Le canal de journal `ai_usage` trace les jetons et la durée de chaque appel, jamais le contenu.

## Suivis de la revue de branche

- Le flux se termine toujours par un événement final, y compris sur une erreur en cours de route
  (#318).
- Une section du corpus n'est plus jamais vidée en silence : un contenu illisible arrête le rendu
  au lieu de faire répondre « ce n'est pas dans les documents » (#319).
- La configuration dit ce qu'elle fait vraiment : les 415 restent des erreurs client, et les
  entrées mortes de `exception_to_status` sont retirées (#320).
- Le préambule absent et l'appel sans compte lèvent chacun une exception dédiée, à la place d'une
  `LogicException` générique (#323).

## Limiteurs de débit et journaux

- Une panne du verrou partagé des limiteurs répond 503 avec `Retry-After`, et laisse une trace
  d'audit `rate-limiter-unavailable`, au lieu d'un 500 (#276).
- Le canal `lock` ne journalise plus en production la clé des limiteurs, c'est-à-dire une IP ou un
  identifiant tenté (#315).
- Un seul écouteur pose l'en-tête `Retry-After` de tous les 429 de quota, au lieu de quatre copies
  (#273).

## Outillage

- La configuration ESLint TypeScript est écrite à la main, sans `@vue/eslint-config-typescript`,
  ce qui retire `braces` de l'arbre et débloque `npm audit` (#328).
- La recette de publication des secrets de l'assistant (`k8s/README.md`) s'exécute sous zsh et
  devient rejouable.

## À vérifier en préprod

- Smoke tests et audit verts.
- `backend-secrets` synchronisé et porteur de `SCALEWAY_AI_API_KEY` et `SCALEWAY_AI_PROJECT_ID`
  (noms seulement).
- `nginx -T | grep assistant` sur le sidecar : les zones `assistant` et `assistantconn`, et la
  `location ^~ /api/assistant/`.
- La clé de préprod atteint le modèle depuis le pod, puis une question réelle depuis un compte
  `ROLE_TRUSTED` : la réponse arrive en flux, et le journal `ai_usage` porte `"outcome":"done"`.
