<script setup lang="ts">
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import AppHeader from './AppHeader.vue'

/**
 * Chrome commun à toutes les pages (en-tête + navigation). Rendu une seule
 * fois par App.vue, autour du <router-view> : chaque page ne s'occupe que de
 * son propre contenu.
 */
const { siteIdentity, navigationLinks } = usePortfolioContent()
</script>

<template>
  <div class="min-vh-100">
    <AppHeader :site-identity="siteIdentity" :navigation-links="navigationLinks" />
    <main>
      <RouterView />
    </main>
  </div>
</template>
