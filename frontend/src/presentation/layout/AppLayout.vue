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
 * Vrai de l'instant où un autre compte est revenu jusqu'à la fin du rejeu de la navigation (#748, R27, A3-13). Dans
 * l'intervalle la session du nouveau compte est déjà appliquée (l'en-tête change) mais la garde n'a pas encore rejugé la
 * route : sans ce masque, l'ancienne page — avec les données du premier compte — resterait affichée sous l'en-tête du
 * second. Posé **de façon synchrone** par l'abonné, avant tout `await`, pour qu'aucun rendu n'intervienne entre les deux.
 * Le cas « même compte » ne passe jamais par ici : aucun rejeu, aucun clignotement.
 *
 * Deux rejeux peuvent se chevaucher (deux changements de compte rapprochés) : le routeur annule le premier dès que le
 * second démarre, et la promesse du premier se règle alors que le second court encore. Un booléen seul serait donc
 * rabattu trop tôt, page du premier compte visible. Chaque rejeu prend un **jeton** (`replayId`) : seul le dernier
 * lève le masque (#748, CR4-2).
 */
const isReplaying = ref(false)

/** Le numéro du dernier rejeu lancé : un rejeu ne démasque la page que s'il est encore celui-là. */
let replayId = 0

/**
 * Un autre compte que celui dont la session a fini est revenu (`useAuth().onAccountSwitched`) : la session est déjà
 * appliquée, mais la page ouverte n'est pas la sienne. La navigation est **rejouée** vers la route courante — `force`,
 * sans quoi le routeur ignore une navigation vers l'adresse où l'on est, et la garde ne jugerait jamais ce compte
 * (compte sans `ROLE_SUPER` sur `/admin` : redirection vers « interdit », jamais la page avec les données du premier).
 * Puis la page est remontée. Dans cet ordre : remonter d'abord monterait, un instant, une page protégée pour un compte
 * que la garde va refuser.
 */
onAccountSwitched(() => {
  const mine = ++replayId
  isReplaying.value = true
  void replayNavigation(mine)
})

async function replayNavigation(mine: number): Promise<void> {
  const { path, query, hash } = router.currentRoute.value
  try {
    await router.replace({ path, query, hash, force: true })
  } catch (caught) {
    // Une garde ou un composant routé qui lève : la page est remontée quand même, elle ne reste pas celle du premier compte.
    console.error('Le rejeu de la navigation après un changement de compte a échoué.', caught)
  } finally {
    // Dans cet ordre : la clé avance avant que la page revienne, qui est alors une instance neuve (ou celle que la
    // garde a choisie), jamais l'ancienne. Aussi en cas d'échec : la page ne reste pas masquée pour toujours.
    pageKey.value += 1
    if (mine === replayId) {
      isReplaying.value = false
    }
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
          v-if="!isReplaying"
          :key="pageKey"
        />
      </RouterView>
    </main>
    <AppFooter :site-identity="siteIdentity" />
    <CookieNotice />
  </div>
</template>
