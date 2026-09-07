<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useContributions } from '../../application/contributions/useContributions'
import BaseButton from '../ui/BaseButton.vue'
import RichText from '../ui/RichText.vue'

const { t } = useI18n()
const { contributions, isLoading, hasError } = useContributions()

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

      <RichText
        :text="contribution.body"
        paragraph-class="contribution__paragraph text-body-secondary"
      />

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

</style>
