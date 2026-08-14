<script setup lang="ts">
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const { t } = useI18n()

const sections = [
  { name: 'admin-technologies', labelKey: 'admin.nav.technologies' },
  { name: 'admin-about', labelKey: 'admin.nav.about' },
  { name: 'admin-quality', labelKey: 'admin.nav.quality' },
  { name: 'admin-stats', labelKey: 'admin.nav.stats' },
  { name: 'admin-users', labelKey: 'admin.nav.users' },
] as const
</script>

<template>
  <section class="container-xl py-5 d-flex flex-column gap-4">
    <h1 class="h4 fw-bold text-white mb-0">
      {{ t('admin.heading') }}
    </h1>

    <nav
      class="d-flex flex-wrap gap-2"
      :aria-label="t('admin.navigation')"
    >
      <RouterLink
        v-for="section in sections"
        :key="section.name"
        :to="{ name: section.name }"
        class="btn btn-sm"
        :class="route.name === section.name ? 'btn-gradient' : 'btn-outline-light'"
        :aria-current="route.name === section.name ? 'page' : undefined"
      >
        {{ t(section.labelKey) }}
      </RouterLink>
    </nav>

    <RouterView />
  </section>
</template>
