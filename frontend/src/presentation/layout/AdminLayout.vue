<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const { t } = useI18n()

/**
 * Sections d'édition de contenu, regroupées derrière l'onglet « Contenu » de
 * la navigation de 1er niveau. Elles gardent leurs routes (`/admin/<section>`,
 * URL inchangées) : le regroupement est purement présentationnel. L'onglet
 * ouvre un menu déroulant (pas de barre de sous-navigation séparée).
 */
const contentSections = [
  { name: 'admin-technologies', labelKey: 'admin.nav.technologies' },
  { name: 'admin-about', labelKey: 'admin.nav.about' },
  { name: 'admin-quality', labelKey: 'admin.nav.quality' },
  { name: 'admin-stats', labelKey: 'admin.nav.stats' },
] as const

const isContentRoute = computed(() => contentSections.some((section) => section.name === route.name))

const isMenuOpen = ref(false)
const dropdownRef = ref<HTMLElement | null>(null)

function closeMenu(): void {
  isMenuOpen.value = false
}

/**
 * Bootstrap 5 n'est chargé qu'en CSS (pas de bundle JS) : l'ouverture/fermeture
 * et la fermeture au clic extérieur / touche Échap sont gérées ici, comme le
 * menu mobile de AppHeader.
 */
function onDocumentClick(event: MouseEvent): void {
  if (!isMenuOpen.value) {
    return
  }
  if (null !== dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
    closeMenu()
  }
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if ('Escape' === event.key) {
    closeMenu()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})
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
      <div
        ref="dropdownRef"
        class="admin-content-menu"
      >
        <button
          type="button"
          class="btn btn-sm dropdown-toggle"
          :class="isContentRoute ? 'btn-gradient' : 'btn-outline-light'"
          aria-haspopup="true"
          :aria-expanded="isMenuOpen"
          @click="isMenuOpen = !isMenuOpen"
        >
          {{ t('admin.nav.content') }}
        </button>

        <ul
          v-if="isMenuOpen"
          class="dropdown-menu show mt-1"
          data-bs-theme="dark"
          :aria-label="t('admin.contentNavigation')"
        >
          <li
            v-for="section in contentSections"
            :key="section.name"
          >
            <RouterLink
              :to="{ name: section.name }"
              class="dropdown-item"
              :class="{ active: route.name === section.name }"
              :aria-current="route.name === section.name ? 'page' : undefined"
              @click="closeMenu"
            >
              {{ t(section.labelKey) }}
            </RouterLink>
          </li>
        </ul>
      </div>

      <RouterLink
        :to="{ name: 'admin-users' }"
        class="btn btn-sm"
        :class="'admin-users' === route.name ? 'btn-gradient' : 'btn-outline-light'"
        :aria-current="'admin-users' === route.name ? 'page' : undefined"
      >
        {{ t('admin.nav.users') }}
      </RouterLink>
    </nav>

    <RouterView />
  </section>
</template>

<style scoped>
.admin-content-menu {
  position: relative;
}

/* Sans Popper (pas de bundle JS Bootstrap), on ancre le menu sous le bouton. */
.admin-content-menu .dropdown-menu {
  display: block;
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 1030;
  min-width: 12rem;
}
</style>
