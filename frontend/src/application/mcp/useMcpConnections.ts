import { inject, readonly, ref, shallowRef, type DeepReadonly, type InjectionKey, type Ref } from 'vue'
import type { McpConnectionSummary } from '../../domain/mcp/entities/McpConnection'
import type { McpConnectionsRepository } from '../../domain/mcp/repositories/McpConnectionsRepository'
import { McpConnectionNotFoundError } from '../../domain/mcp/errors/McpConnectionNotFoundError'
import { SessionExpiredError } from '../../domain/mcp/errors/SessionExpiredError'
import { markSessionExpired } from '../auth/useAuth'

export const MCP_CONNECTIONS_REPOSITORY: InjectionKey<McpConnectionsRepository> = Symbol('McpConnectionsRepository')

/**
 * - `loading` : lecture de la liste (au montage de la page, et tant qu'elle n'a pas abouti) ;
 * - `ready`   : liste affichée, éventuellement vide ;
 * - `error`   : la liste n'a pu être lue (réseau, 5xx, réponse mal formée, session expirée).
 */
export type McpConnectionsStatus = 'loading' | 'ready' | 'error'

/**
 * Issue de la dernière révocation demandée, que la page annonce :
 * - `revoked`      : le serveur a révoqué, la liste est relue depuis lui ;
 * - `already-gone` : 404, la connexion n'existe plus pour ce compte — la liste
 *                    est relue aussi, la page dit « déjà terminée » ;
 * - `failed`       : échec, la liste affichée n'a pas bougé, la personne peut réessayer.
 */
export type McpRevocationOutcome = 'revoked' | 'already-gone' | 'failed'

export interface UseMcpConnectionsResult {
  status: DeepReadonly<Ref<McpConnectionsStatus>>
  connections: DeepReadonly<Ref<McpConnectionSummary[]>>
  /** Vrai quand le serveur a coupé l'historique des connexions terminées : la page le dit. */
  terminatedTruncated: DeepReadonly<Ref<boolean>>
  /** L'identifiant en cours de révocation, `null` sinon : la page y désactive ses boutons. */
  revokingId: DeepReadonly<Ref<string | null>>
  lastOutcome: DeepReadonly<Ref<McpRevocationOutcome | null>>
  /**
   * Vrai quand le serveur a refusé la session (401/403) à la dernière lecture ou
   * à la dernière révocation : la page propose alors de se reconnecter plutôt
   * que de réessayer, ce qui renverrait la même requête sans session.
   */
  sessionExpired: DeepReadonly<Ref<boolean>>
  load: () => Promise<void>
  revoke: (id: string) => Promise<void>
}

/**
 * « Mes connexions MCP » (#650) : la liste du compte et la révocation de l'une
 * d'elles.
 *
 * Après une révocation, la liste est relue depuis le serveur plutôt que
 * corrigée localement : l'état et la cause d'une connexion sont jugés par lui
 * (échéance, cause), le frontend n'en refait aucun calcul. Une session
 * expirée (401/403) marque la session expirée, comme `useAssistant` : la page
 * ne sert que le palier de confiance, l'en-tête ne doit pas rester « connecté » ;
 * elle est aussi dite à la page (`sessionExpired`), qui propose de se reconnecter.
 */
export function useMcpConnections(): UseMcpConnectionsResult {
  const repository = inject(MCP_CONNECTIONS_REPOSITORY)

  if (!repository) {
    throw new Error(
      "McpConnectionsRepository n'a pas été fourni. Vérifiez que app.provide(MCP_CONNECTIONS_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const status = ref<McpConnectionsStatus>('loading')
  const connections = shallowRef<McpConnectionSummary[]>([])
  const terminatedTruncated = ref(false)
  const revokingId = ref<string | null>(null)
  const lastOutcome = ref<McpRevocationOutcome | null>(null)
  const sessionExpired = ref(false)
  /**
   * Le numéro de la dernière lecture lancée. Deux `load()` peuvent se croiser (le
   * bouton « Réessayer » pendant une relecture après une révocation, deux
   * révocations de suite) et le réseau ne rend pas les réponses dans l'ordre :
   * seule la réponse de la **dernière** lecture lancée écrit l'état, une plus
   * ancienne arrivée après elle est jetée — sa liste est périmée, et sa
   * session « expirée » n'est plus ce que le serveur vient de répondre (#650, R5a).
   */
  let latestLoad = 0

  const load = async (): Promise<void> => {
    const mine = ++latestLoad
    status.value = 'loading'
    sessionExpired.value = false
    try {
      const list = await repository.list()
      if (mine !== latestLoad) {
        return
      }
      connections.value = list.connections
      terminatedTruncated.value = list.terminatedTruncated
      status.value = 'ready'
    } catch (caught) {
      if (mine !== latestLoad) {
        return
      }
      if (caught instanceof SessionExpiredError) {
        markSessionExpired()
        sessionExpired.value = true
      }
      connections.value = []
      terminatedTruncated.value = false
      status.value = 'error'
    }
  }

  const revoke = async (id: string): Promise<void> => {
    // Une seule révocation à la fois : un double clic, ou un clic sur une
    // autre ligne pendant l'appel, ne repart pas.
    if (null !== revokingId.value) {
      return
    }

    revokingId.value = id
    lastOutcome.value = null
    sessionExpired.value = false

    try {
      await repository.revoke(id)
      lastOutcome.value = 'revoked'
    } catch (caught) {
      if (caught instanceof McpConnectionNotFoundError) {
        lastOutcome.value = 'already-gone'
      } else {
        if (caught instanceof SessionExpiredError) {
          markSessionExpired()
          sessionExpired.value = true
        }
        lastOutcome.value = 'failed'
        revokingId.value = null
        // La liste affichée n'a pas bougé : rien à relire.
        return
      }
    }

    try {
      await load()
    } finally {
      revokingId.value = null
    }
  }

  return {
    status: readonly(status),
    connections: readonly(connections),
    terminatedTruncated: readonly(terminatedTruncated),
    revokingId: readonly(revokingId),
    lastOutcome: readonly(lastOutcome),
    sessionExpired: readonly(sessionExpired),
    load,
    revoke,
  }
}
