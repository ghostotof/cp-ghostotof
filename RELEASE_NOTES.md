# v0.18.8 — Mises à jour de dépendances (Messenger, Vite, CodeQL)

Release de maintenance : uniquement des mises à jour de version corrective proposées par
Dependabot. Aucune migration, aucun secret nouveau, aucun changement de configuration
Kubernetes. Les images backend et frontend sont reconstruites avec les nouvelles versions.

## Backend (#302)

- `symfony/messenger`, `symfony/amqp-messenger` et `symfony/doctrine-messenger` passent de
  8.1.7 à 8.1.8.
- Ces composants font tourner le worker et le transport d'échec : l'envoi des messages du
  formulaire de contact et des invitations passe par eux.

## Frontend (#301)

- `vite` passe de 8.3.0 à 8.3.1, et le bundle est reconstruit avec cette version.
- Les outils de développement passent aussi en version corrective : `jsdom` 30.1.1,
  `eslint-plugin-vue` 10.11.1, `@types/node` 26.6.3. Ils n'entrent pas dans l'image servie.
- Les icônes `@iconify-json/lucide` passent en 1.2.137.

## CI (#303)

- `github/codeql-action` (`init` et `analyze`) passe de 4.38.1 à 4.38.2.

## À vérifier en préprod

- Smoke tests et audit verts.
- Le formulaire de contact aboutit de bout en bout : le message est consommé par le worker, qui
  tourne sur la nouvelle version de Messenger.
