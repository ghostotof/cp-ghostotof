import { defineConfig, globalIgnores } from 'eslint/config'
import tseslint from 'typescript-eslint'
import vueParser from 'vue-eslint-parser'
import pluginVue from 'eslint-plugin-vue'
import pluginVueI18n from '@intlify/eslint-plugin-vue-i18n'
import pluginVueA11y from 'eslint-plugin-vuejs-accessibility'
import globals from 'globals'

/**
 * TypeScript dans les SFC, écrit à la main (issue #328).
 *
 * C'est ce que produisait `@vue/eslint-config-typescript` pour ce projet, qui
 * n'active aucune règle typée : retiré parce qu'il tirait `fast-glob` →
 * `micromatch` → `braces`, porteur d'une faille sans correctif publié
 * (GHSA-vfj7-8cjw-p6xm), et il ne s'en servait que pour lister les `.vue`
 * à soumettre au contrôle typé. Trois pièces, dans l'ordre où le paquet les
 * plaçait :
 * - les règles `recommended` de typescript-eslint, étendues aux `.vue` ;
 * - la config de base d'eslint-plugin-vue, reposée après elles : celle de
 *   typescript-eslint remplace le parseur de tous les fichiers ;
 * - `vue-eslint-parser` pour les `.vue`, qui délègue `<script lang="ts">` au
 *   parseur TypeScript, et `vue/block-lang` qui impose ce `lang="ts"`.
 *
 * Passer un jour aux règles typées (`recommendedTypeChecked`) demanderait le
 * `projectService` et un traitement des `.vue` sans TypeScript : c'est à ce
 * moment-là, pas avant, qu'il faudra réévaluer le retour au paquet (voir la
 * section Lint de `.claude/CLAUDE.md`).
 */
const typescriptForTsAndVue = tseslint.configs.recommended.map((config) =>
  config.files?.includes('**/*.ts') ? { ...config, files: [...config.files, '**/*.vue'] } : config,
)

const vueSfcWithTypescript = [
  ...pluginVue.configs['flat/base'],
  {
    name: 'app/vue-typescript-setup',
    files: ['*.vue', '**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        // espree pour un éventuel <script> JS, le parseur TypeScript sinon ;
        // le template suit le lang du <script> (TypeScript autorisé dedans).
        parser: { js: 'espree', jsx: 'espree', ts: tseslint.parser, tsx: tseslint.parser },
        // vue-eslint-parser embarque espree 9, qui s'arrête à ES2024.
        ecmaVersion: 2024,
        ecmaFeatures: { jsx: false },
        extraFileExtensions: ['.vue'],
      },
    },
    rules: {
      'vue/block-lang': ['error', { script: { lang: ['ts'], allowNoLang: false } }],
    },
  },
]

export default defineConfig(
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
  typescriptForTsAndVue,
  vueSfcWithTypescript,
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
