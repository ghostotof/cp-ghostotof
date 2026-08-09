<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import IconDisc3 from '~icons/lucide/disc-3'

const { t, locale } = useI18n()
const homeLink = computed(() => `/${locale.value as Locale}`)
</script>

<template>
  <section class="container-xl py-5 text-center">
    <div
      class="surface-panel p-4 p-sm-5 mx-auto"
      style="max-width: 32rem"
    >
      <h1 class="h3 fw-bold text-white d-inline-flex align-items-center justify-content-center gap-2">
        {{ t('notFound.heading') }}
        <!-- Easter egg discret, clin d'œil à "Whole Lotta Love" (Led Zeppelin) -->
        <span
          class="opacity-50"
          aria-hidden="true"
          title="Whole Lotta 404"
        >
          <IconDisc3
            width="18"
            height="18"
          />
        </span>
      </h1>
      <p class="text-body-secondary mt-3 mb-4">
        {{ t('notFound.message') }}
      </p>
      <RouterLink
        :to="homeLink"
        class="btn btn-outline-light"
      >
        {{ t('notFound.backHome') }}
      </RouterLink>
    </div>
  </section>
</template>
