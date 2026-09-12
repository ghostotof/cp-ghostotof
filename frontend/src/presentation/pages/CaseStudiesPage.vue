<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useCaseStudies } from '../../application/caseStudies/useCaseStudies'
import { useBaseAccess } from '../../application/baseAccess/useBaseAccess'
import RichText from '../ui/RichText.vue'

const { t } = useI18n()
const { caseStudies, isLoading, hasError, needsAccess, reload } = useCaseStudies()
const { isGranting, errorReason, grant } = useBaseAccess()

async function handleGrantAccess(): Promise<void> {
  const granted = await grant()
  if (granted) {
    await reload()
  }
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
        {{ t('caseStudies.eyebrow') }}
      </h1>

      <p class="text-body-secondary mb-0">
        {{ t('caseStudies.intro') }}
      </p>
    </div>

    <p
      v-if="isLoading"
      class="text-body-secondary mb-0"
    >
      {{ t('caseStudies.loading') }}
    </p>

    <div
      v-else-if="needsAccess"
      class="surface-panel p-3 p-sm-4 d-flex flex-column gap-3"
    >
      <p class="text-body-secondary mb-0">
        {{ t('caseStudies.needsAccess.message') }}
      </p>

      <button
        type="button"
        class="btn btn-gradient align-self-start"
        :disabled="isGranting"
        @click="handleGrantAccess"
      >
        {{ isGranting ? t('caseStudies.needsAccess.granting') : t('caseStudies.needsAccess.action') }}
      </button>

      <p
        v-if="errorReason"
        class="text-danger mb-0"
        role="alert"
      >
        {{ 'rate-limited' === errorReason ? t('caseStudies.needsAccess.rateLimited') : t('caseStudies.needsAccess.error') }}
      </p>
    </div>

    <p
      v-else-if="hasError"
      class="text-danger mb-0"
      role="alert"
    >
      {{ t('caseStudies.error') }}
    </p>

    <p
      v-else-if="caseStudies.length === 0"
      class="text-body-secondary mb-0"
    >
      {{ t('caseStudies.empty') }}
    </p>

    <article
      v-for="caseStudy in caseStudies"
      v-else
      :key="caseStudy.title"
      class="surface-panel p-3 p-sm-4"
    >
      <h2 class="h4 text-white mb-3">
        {{ caseStudy.title }}
      </h2>

      <div class="d-flex flex-column gap-3">
        <div>
          <h3 class="text-eyebrow text-uppercase small fw-semibold mb-2">
            {{ t('caseStudies.fields.problem') }}
          </h3>
          <RichText
            :text="caseStudy.problem"
            paragraph-class="text-body-secondary"
          />
        </div>

        <div>
          <h3 class="text-eyebrow text-uppercase small fw-semibold mb-2">
            {{ t('caseStudies.fields.solution') }}
          </h3>
          <RichText
            :text="caseStudy.solution"
            paragraph-class="text-body-secondary"
          />
        </div>

        <div>
          <h3 class="text-eyebrow text-uppercase small fw-semibold mb-2">
            {{ t('caseStudies.fields.tradeoffs') }}
          </h3>
          <RichText
            :text="caseStudy.tradeoffs"
            paragraph-class="text-body-secondary"
          />
        </div>

        <div>
          <h3 class="text-eyebrow text-uppercase small fw-semibold mb-2">
            {{ t('caseStudies.fields.measuredResult') }}
          </h3>
          <RichText
            :text="caseStudy.measuredResult"
            paragraph-class="text-body-secondary"
          />
        </div>
      </div>
    </article>
  </section>
</template>
