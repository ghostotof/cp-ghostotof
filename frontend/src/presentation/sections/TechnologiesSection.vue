<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Technology } from '../../domain/portfolio/entities/Technology'
import BaseCard from '../ui/BaseCard.vue'

defineProps<{
  featuredTechnologies: readonly Technology[]
  additionalTechnologies: readonly Technology[]
}>()

const { t } = useI18n()
</script>

<template>
  <section
    id="technologies"
    class="container-xl py-4"
  >
    <div class="surface-panel p-3 p-sm-4">
      <p class="d-flex align-items-center gap-2 text-primary text-uppercase small fw-semibold mb-4">
        <span
          class="rounded-circle bg-primary"
          style="width: 0.4rem; height: 0.4rem"
        />
        {{ t('technologies.sectionTitle') }}
      </p>

      <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-6 g-3">
        <div
          v-for="technology in featuredTechnologies"
          :key="technology.name"
          class="col"
        >
          <BaseCard
            :title="technology.name"
            :description="technology.description"
            :icon-key="technology.iconKey"
            :highlighted="technology.name === 'Symfony'"
          />
        </div>
      </div>

      <div
        class="d-flex flex-wrap align-items-center gap-2 border-top mt-4 pt-4 small text-body-secondary"
      >
        <span class="fw-semibold text-white">{{ t('technologies.andAlso') }}</span>
        <template
          v-for="(technology, index) in additionalTechnologies"
          :key="technology.name"
        >
          <!-- Séparateur visuel, pas du texte à traduire -->
          <span
            v-if="index > 0"
            class="text-body-secondary"
            aria-hidden="true"
          >&middot;</span>
          <span>{{ technology.name }}</span>
        </template>
      </div>
    </div>
  </section>
</template>
