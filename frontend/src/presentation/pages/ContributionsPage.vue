<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useContributions } from '../../application/contributions/useContributions'
import BaseButton from '../ui/BaseButton.vue'

const { t } = useI18n()
const { contributions, isLoading, hasError } = useContributions()

/**
 * Le corps est du texte brut, paragraphes séparés par une ligne vide. Découpé
 * ici plutôt qu'injecté en `v-html` : le contenu provient d'un champ de saisie
 * du backoffice, et l'interpréter comme du HTML sur une page publique
 * échangerait une mise en forme contre une faille XSS. `filter(Boolean)` absorbe
 * les lignes vides surnuméraires d'une saisie manuelle.
 */
function paragraphsOf(body: string): string[] {
  return body
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph.length > 0)
}

/**
 * Découpe un paragraphe sur les `passages entre accents graves`, seule marque
 * de mise en forme reconnue — de quoi citer un nom de paramètre sans ouvrir la
 * porte au balisage.
 *
 * String.split avec un groupe capturant place les captures aux index impairs,
 * d'où l'alternance. Chaque segment reste interpolé comme du texte, jamais
 * injecté en HTML : c'est ce qui rend l'opération sûre sur du contenu saisi
 * depuis le backoffice.
 */
function segmentsOf(paragraph: string): { text: string, isCode: boolean }[] {
  return paragraph
    .split(/`([^`]+)`/)
    .map((text, index) => ({ text, isCode: 1 === index % 2 }))
    .filter((segment) => segment.text.length > 0)
}
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h1 class="d-flex align-items-center gap-2 text-eyebrow text-uppercase small fw-semibold mb-3">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
          aria-hidden="true"
        />
        {{ t('contributions.eyebrow') }}
      </h1>

      <p class="text-body-secondary mb-0">
        {{ t('contributions.intro') }}
      </p>
    </div>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('contributions.loading') }}
    </p>

    <p
      v-else-if="hasError"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('contributions.error') }}
    </p>

    <p
      v-else-if="contributions.length === 0"
      class="text-body-secondary mb-0"
    >
      {{ t('contributions.empty') }}
    </p>

    <article
      v-for="contribution in contributions"
      v-else
      :key="contribution.url + contribution.title"
      class="surface-panel p-3 p-sm-4"
    >
      <p class="text-eyebrow text-uppercase small fw-semibold mb-2">
        {{ contribution.project }} · {{ contribution.reference }}
      </p>

      <h2 class="h4 text-white mb-3">
        {{ contribution.title }}
      </h2>

      <p class="contribution__summary mb-4">
        {{ contribution.summary }}
      </p>

      <p
        v-for="(paragraph, index) in paragraphsOf(contribution.body)"
        :key="index"
        class="contribution__paragraph text-body-secondary"
      >
        <template
          v-for="(segment, segmentIndex) in segmentsOf(paragraph)"
          :key="segmentIndex"
        >
          <code
            v-if="segment.isCode"
            class="contribution__code"
          >{{ segment.text }}</code>
          <!--
            Contenu collé aux balises volontairement : ces segments sont des
            fragments d'une même phrase. Les aérer ferait apparaître une espace
            de part et d'autre de chaque passage en `code` dans le texte rendu.
          -->
          <!-- eslint-disable-next-line vue/singleline-html-element-content-newline -->
          <template v-else>{{ segment.text }}</template>
        </template>
      </p>

      <BaseButton
        :href="contribution.url"
        variant="secondary"
        icon-key="github"
        is-external
      >
        {{ t('contributions.readOnGitHub') }}
      </BaseButton>
    </article>
  </section>
</template>

<style scoped>
/*
  Le chapeau porte le propos : contraste plein plutôt que `text-body-secondary`,
  qui est réservé au développement de l'argument.
*/
.contribution__summary {
  color: var(--bs-body-color);
  font-size: 1.05rem;
  line-height: 1.6;
}

.contribution__code {
  padding: 0.1rem 0.35rem;
  border-radius: 0.3rem;
  background: rgba(124, 58, 237, 0.12);
  /* Pas `--bs-link-color` : ce jeton sert aux liens, et un code n'en est pas
     un — le lecteur ne doit pas croire qu'il peut cliquer dessus. */
  color: #c4b5fd;
  font-size: 0.9em;
}
</style>
