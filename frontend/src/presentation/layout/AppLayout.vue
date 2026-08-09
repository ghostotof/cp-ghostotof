<script setup lang="ts">
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import AppHeader from './AppHeader.vue'
import AppFooter from './AppFooter.vue'

/**
 * Chrome commun à toutes les pages (en-tête + navigation + pied de page). Rendu
 * une seule fois par App.vue, autour du <router-view> : chaque page ne s'occupe
 * que de son propre contenu.
 */
const { siteIdentity, navigationLinks } = usePortfolioContent()
</script>

<template>
  <div class="min-vh-100 d-flex flex-column">
    <AppHeader
      :site-identity="siteIdentity"
      :navigation-links="navigationLinks"
    />
    <main class="flex-grow-1">
      <RouterView />
    </main>
    <AppFooter :site-identity="siteIdentity" />
  </div>
</template>
