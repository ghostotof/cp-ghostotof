<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { onAccountSwitched } from '../../application/auth/useAuth'
import { usePortfolioContent } from '../../application/portfolio/usePortfolioContent'
import AppHeader from './AppHeader.vue'
import AppFooter from './AppFooter.vue'
import CookieNotice from './CookieNotice.vue'
import { useSessionRecheckOnFocus } from './useSessionRecheckOnFocus'

/**
 * Chrome commun à toutes les pages (en-tête + navigation + pied de page). Rendu
 * une seule fois par App.vue, autour du <router-view> : chaque page ne s'occupe
 * que de son propre contenu.
 */
const { siteIdentity, navigationLinks } = usePortfolioContent()
const { t } = useI18n()

// Au retour sur l'onglet, une session finie est relue — pour toutes les pages protégées (#748, R8).
useSessionRecheckOnFocus()

const router = useRouter()

/**
 * La `key` du composant routé : elle avance quand un **autre compte** est revenu (#748, R18). Sans elle, rejouer la
 * navigation sur le même chemin garderait la même instance de page — l'assistant montrerait encore la conversation du
 * premier compte, un formulaire d'administration sa saisie.
 */
const pageKey = ref(0)

/**
 * Un autre compte que celui dont la session a fini est revenu (`useAuth().onAccountSwitched`) : la session est déjà
 * appliquée, mais la page ouverte n'est pas la sienne. La navigation est **rejouée** vers la route courante — `force`,
 * sans quoi le routeur ignore une navigation vers l'adresse où l'on est, et la garde ne jugerait jamais ce compte
 * (compte sans `ROLE_SUPER` sur `/admin` : redirection vers « interdit », jamais la page avec les données du premier).
 * Puis la page est remontée. Dans cet ordre : remonter d'abord monterait, un instant, une page protégée pour un compte
 * que la garde va refuser.
 */
onAccountSwitched(() => {
  void replayNavigation()
})

async function replayNavigation(): Promise<void> {
  const { path, query, hash } = router.currentRoute.value
  try {
    await router.replace({ path, query, hash, force: true })
  } catch (caught) {
    // Une garde ou un composant routé qui lève : la page est remontée quand même, elle ne reste pas celle du premier compte.
    console.error('Le rejeu de la navigation après un changement de compte a échoué.', caught)
  } finally {
    pageKey.value += 1
  }
}
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
      <RouterView v-slot="{ Component }">
        <component
          :is="Component"
          :key="pageKey"
        />
      </RouterView>
    </main>
    <AppFooter :site-identity="siteIdentity" />
    <CookieNotice />
  </div>
</template>
