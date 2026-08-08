import { globalIgnores } from 'eslint/config'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'
import pluginVueI18n from '@intlify/eslint-plugin-vue-i18n'
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
