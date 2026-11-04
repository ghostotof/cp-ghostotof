import { onBeforeUnmount, onMounted } from 'vue'
import { useAuth } from '../../application/auth/useAuth'

/**
 * La revérification de la session finie au retour sur l'onglet (#748, R8, CR7) : **un seul mécanisme, installé
 * une fois par `AppLayout`**, donc valable sur toutes les pages — le backoffice, « Mes connexions MCP »,
 * l'assistant, le consentement. Elle vivait dans `SessionEndedBanner`, qui n'existe que dans le backoffice : sur
 * les trois autres pages, une session retrouvée dans un autre onglet n'était jamais constatée.
 *
 * Elle ne fait que relire la session (`recheckEndedSession`, silencieuse, sans effet hors session finie) ; ce qui
 * dépend du retour (relire une liste, effacer une erreur, annoncer « Session rétablie ») suit l'état
 * `authState.sessionEnded` qui retombe, chaque page pour ce qui la concerne. Aucune relecture de contenu ici.
 */
export function useSessionRecheckOnFocus(): void {
  const { recheckEndedSession } = useAuth()

  const recheck = (): void => {
    // `recheckEndedSession` avale ses propres pannes : la promesse ne rejette jamais.
    void recheckEndedSession()
  }

  onMounted(() => {
    window.addEventListener('focus', recheck)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('focus', recheck)
  })
}
