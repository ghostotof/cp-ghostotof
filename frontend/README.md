# frontend/ — Vue 3 + TypeScript

Application monopage découplée du backend : elle ne parle à l'API que par HTTP
(`VITE_API_URL` en développement, `config.js` généré au démarrage du conteneur en déployé).

- **Stack** : Vue 3, TypeScript, Vite, Vue Router 4, vue-i18n (français et anglais, la locale est
  dans l'URL), Bootstrap 5.
- **Architecture** : couches `domain/` (entités et interfaces de repositories, sans import Vue),
  `infrastructure/` (repositories HTTP, contenu statique, fichiers i18n), `application/`
  (composables, injection par `InjectionKey`), `presentation/` (pages, sections, composants,
  routeur). Les tests miroitent cet arbre sous `tests/`.
- **Qualité** : Vitest avec axe-core sur les pages, ESLint (`eslint-plugin-vue`,
  `vuejs-accessibility`, `@intlify/vue-i18n`), `vue-tsc` au build.

Tout se lance depuis la racine du dépôt, dans le conteneur, pour que la version de Node de la
machine hôte n'entre jamais en jeu :

```bash
make front-test      # Vitest
make front-lint      # ESLint
make front-build     # vue-tsc -b + vite build
make sh-front        # shell dans le conteneur (npm run test:watch, etc.)
```

Les conventions détaillées (ajout d'une page, d'un contenu, accessibilité, i18n) sont dans
[`../.claude/CLAUDE.md`](../.claude/CLAUDE.md), section « Frontend architecture ».
