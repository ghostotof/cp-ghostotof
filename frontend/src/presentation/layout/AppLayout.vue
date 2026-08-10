<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import AppHeader from './AppHeader.vue'
import AppFooter from './AppFooter.vue'

/**
 * Chrome commun à toutes les pages (en-tête + navigation + pied de page). Rendu
 * une seule fois par App.vue, autour du <router-view> : chaque page ne s'occupe
 * que de son propre contenu.
 */
const { siteIdentity, navigationLinks } = usePortfolioContent()
const { t } = useI18n()
</script>

<template>
  <div class="min-vh-100 d-flex flex-column">
    <a
      href="#main-content"
      class="visually-hidden-focusable skip-link"
    >
      {{ t('common.skipToContent') }}
    </a>
    <AppHeader
      :site-identity="siteIdentity"
      :navigation-links="navigationLinks"
    />
    <main
      id="main-content"
      class="flex-grow-1"
      tabindex="-1"
    >
      <RouterView />
    </main>
    <AppFooter :site-identity="siteIdentity" />
  </div>
</template>
