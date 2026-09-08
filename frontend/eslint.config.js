import { globalIgnores } from 'eslint/config'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'
import pluginVueI18n from '@intlify/eslint-plugin-vue-i18n'
import pluginVueA11y from 'eslint-plugin-vuejs-accessibility'
import globals from 'globals'

export default defineConfigWithVueTs(
  globalIgnores(['dist/**', 'coverage/**', 'node_modules/**']),
  {
    name: 'app/files-to-lint',
    files: ['**/*.{ts,vue}'],
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.node,
      },
    },
  },
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.recommended,
  ...pluginVueI18n.configs['flat/recommended'],
  // Accessibilité : analyse statique des templates (alt manquant, champ sans
  // label, @click sans équivalent clavier, ARIA invalide…). `npm run lint`
  // étant bloquant en CI, ces règles le sont aussi.
  //
  // Ce niveau ne voit que ce qui se lit dans le template. Il ne dira rien du
  // contraste ni de l'ordre de tabulation, qui demandent un rendu réel : le
  // Tab-through manuel reste nécessaire, l'outillage le complète sans le
  // remplacer.
  ...pluginVueA11y.configs['flat/recommended'],
  {
    name: 'app/vue-a11y-settings',
    rules: {
      // Par défaut la règle exige qu'un label soit À LA FOIS imbriqué autour de
      // son champ ET porteur d'un `for`. C'est plus strict que WCAG, qui tient
      // l'une ou l'autre méthode pour valide — et les composants Base*.vue
      // utilisent `for`/`id`, l'association la plus explicite, notamment parce
      // qu'elle survit à un champ déplacé dans le template.
      'vuejs-accessibility/label-has-for': ['error', { required: { some: ['nesting', 'id'] } }],
    },
  },
  {
    name: 'app/tests',
    files: ['tests/**/*.{ts,vue}'],
    rules: {
      // Les specs montent des composants « sonde » jetables (@vue/test-utils,
      // `defineComponent` inline) pour exécuter un composable et observer son
      // retour — ce n'est pas de l'organisation de composants SFC.
      'vue/one-component-per-file': 'off',
    },
  },
  {
    name: 'app/vue-i18n-settings',
    settings: {
      'vue-i18n': {
        localeDir: './src/infrastructure/i18n/locales/*.json',
        messageSyntaxVersion: '^11.0.0',
      },
    },
    rules: {
      // Ignore les textes sans aucune lettre (glyphes/séparateurs décoratifs comme
      // "</>" ou "·") : jamais du contenu à traduire, donc pas concernés par cette règle.
      '@intlify/vue-i18n/no-raw-text': ['warn', { ignorePattern: '^[^\\p{L}]*$' }],
    },
  },
)
