<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const { t } = useI18n()

/**
 * Sections d'édition de contenu, regroupées sous un seul onglet « Contenu »
 * dans la navigation de 1er niveau. Elles gardent leurs routes (`/admin/<section>`,
 * URL inchangées) : le regroupement est purement présentationnel, la sous-nav
 * ci-dessous n'apparaît que lorsqu'on est déjà sur l'une de ces routes.
 */
const contentSections = [
  { name: 'admin-technologies', labelKey: 'admin.nav.technologies' },
  { name: 'admin-about', labelKey: 'admin.nav.about' },
  { name: 'admin-quality', labelKey: 'admin.nav.quality' },
  { name: 'admin-stats', labelKey: 'admin.nav.stats' },
] as const

const isContentRoute = computed(() =>
  contentSections.some((section) => section.name === route.name),
)
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
        :to="{ name: 'admin-technologies' }"
        class="btn btn-sm"
        :class="isContentRoute ? 'btn-gradient' : 'btn-outline-light'"
        :aria-current="isContentRoute ? 'page' : undefined"
      >
        {{ t('admin.nav.content') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-users' }"
        class="btn btn-sm"
        :class="'admin-users' === route.name ? 'btn-gradient' : 'btn-outline-light'"
        :aria-current="'admin-users' === route.name ? 'page' : undefined"
      >
        {{ t('admin.nav.users') }}
      </RouterLink>
    </nav>

    <nav
      v-if="isContentRoute"
      class="d-flex flex-wrap gap-2"
      :aria-label="t('admin.contentNavigation')"
    >
      <RouterLink
        v-for="section in contentSections"
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
