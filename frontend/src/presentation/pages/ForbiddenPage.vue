<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Locale } from '../../domain/portfolio/entities/Locale'

const { t, locale } = useI18n()
const homeLink = computed(() => `/${locale.value as Locale}`)
</script>

<template>
  <section class="container-xl py-5 text-center">
    <div
      class="surface-panel p-4 p-sm-5 mx-auto"
      style="max-width: 32rem"
    >
      <h1 class="h3 fw-bold text-white">
        {{ t('forbidden.heading') }}
      </h1>
      <p class="text-body-secondary mt-3 mb-4">
        {{ t('forbidden.message') }}
      </p>
      <RouterLink
        :to="homeLink"
        class="btn btn-outline-light"
      >
        {{ t('forbidden.backHome') }}
      </RouterLink>
    </div>
  </section>
</template>
