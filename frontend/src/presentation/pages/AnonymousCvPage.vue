<script setup lang="ts">
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAnonymousCv } from '../../application/anonymousCv/useAnonymousCv'
import { useBaseAccess } from '../../application/baseAccess/useBaseAccess'
import { authState } from '../../application/auth/useAuth'
import RichText from '../ui/RichText.vue'

const { t } = useI18n()
const { sections, isLoading, hasError, needsAccess, reload } = useAnonymousCv()
const { isGranting, errorReason, grant } = useBaseAccess()

/**
 * Un seul chemin de rechargement : le changement de palier — il couvre le
 * bouton de cette page comme le CTA de l'en-tête, qui ne remonte pas la page
 * puisque la route ne change pas (même mécanique que CaseStudiesPage).
 */
watch(
  () => authState.tier,
  () => {
    if (needsAccess.value) {
      void reload()
    }
  },
)

async function handleGrantAccess(): Promise<void> {
  await grant()
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
        {{ t('anonymousCv.eyebrow') }}
      </h1>

      <p class="text-body-secondary mb-0">
        {{ t('anonymousCv.intro') }}
      </p>
    </div>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('anonymousCv.loading') }}
    </p>

    <div
      v-else-if="needsAccess"
      class="surface-panel p-3 p-sm-4 d-flex flex-column gap-3"
    >
      <p class="text-body-secondary mb-0">
        {{ t('anonymousCv.needsAccess.message') }}
      </p>

      <button
        type="button"
        class="btn btn-gradient align-self-start"
        :disabled="isGranting"
        @click="handleGrantAccess"
      >
        {{ isGranting ? t('anonymousCv.needsAccess.granting') : t('anonymousCv.needsAccess.action') }}
      </button>

      <p
        v-if="errorReason"
        class="text-danger mb-0"
        role="alert"
      >
        {{ 'rate-limited' === errorReason ? t('anonymousCv.needsAccess.rateLimited') : t('anonymousCv.needsAccess.error') }}
      </p>
    </div>

    <p
      v-else-if="hasError"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('anonymousCv.error') }}
    </p>

    <p
      v-else-if="sections.length === 0"
      class="text-body-secondary mb-0"
    >
      {{ t('anonymousCv.empty') }}
    </p>

    <article
      v-for="section in sections"
      v-else
      :key="section.title"
      class="surface-panel p-3 p-sm-4"
    >
      <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-3">
        <h2 class="h4 text-white mb-0">
          {{ section.title }}
        </h2>
        <span class="badge rounded-pill text-bg-secondary fw-normal">
          {{ t('anonymousCv.years', { count: section.yearsOfExperience }, section.yearsOfExperience) }}
        </span>
      </div>

      <div class="d-flex flex-column gap-3">
        <div>
          <h3 class="text-eyebrow text-uppercase small fw-semibold mb-2">
            {{ t('anonymousCv.fields.skills') }}
          </h3>
          <RichText
            :text="section.skills"
            paragraph-class="text-body-secondary"
          />
        </div>

        <div>
          <h3 class="text-eyebrow text-uppercase small fw-semibold mb-2">
            {{ t('anonymousCv.fields.achievements') }}
          </h3>
          <RichText
            :text="section.achievements"
            paragraph-class="text-body-secondary"
          />
        </div>
      </div>
    </article>
  </section>
</template>
