import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'

/**
 * Quitter une page admin avec un ordre modifié l'abandonnerait sans rien dire
 * (spec 0004, D6) : la navigation interne demande confirmation, la fermeture
 * de l'onglet passe par `beforeunload`, que le navigateur traduit en sa propre
 * boîte de dialogue.
 *
 * Un seul composable pour les sept pages ordonnées (#170 F5) — même argument
 * que pour `RichText.vue` : recopié sept fois, un correctif appliqué à une
 * copie laisserait silencieusement les six autres en arrière. L'écouteur est
 * retiré au démontage, sans quoi la page suivante hériterait d'un
 * avertissement fantôme.
 *
 * À appeler dans `setup()` d'un composant rendu par le routeur (il pose
 * `onBeforeRouteLeave`).
 */
export function useUnsavedOrderGuard(isDirty: Ref<boolean>): void {
  const { t } = useI18n()

  onBeforeRouteLeave(() => !isDirty.value || window.confirm(t('admin.order.leaveConfirm')))

  function warnBeforeUnload(event: BeforeUnloadEvent): void {
    if (!isDirty.value) {
      return
    }

    event.preventDefault()
    event.returnValue = ''
  }

  onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))
}
