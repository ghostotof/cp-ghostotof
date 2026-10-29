import { inject, readonly, ref, shallowRef, type DeepReadonly, type InjectionKey, type Ref } from 'vue'
import type { McpConnectionSummary } from '../../domain/mcp/entities/McpConnection'
import type { McpConnectionsRepository } from '../../domain/mcp/repositories/McpConnectionsRepository'
import { McpConnectionNotFoundError } from '../../domain/mcp/errors/McpConnectionNotFoundError'
import { AccessForbiddenError } from '../../domain/auth/errors/AccessForbiddenError'
import { SessionEndedError } from '../../domain/auth/errors/SessionEndedError'
import { markSessionExpired, useAuth } from '../auth/useAuth'

export const MCP_CONNECTIONS_REPOSITORY: InjectionKey<McpConnectionsRepository> = Symbol('McpConnectionsRepository')

/**
 * - `loading` : lecture de la liste (au montage de la page, et tant qu'elle n'a pas abouti) ;
 * - `ready`   : liste affichée, éventuellement vide ;
 * - `error`   : la liste n'a pu être lue (réseau, 5xx, réponse mal formée, refus du serveur, session finie).
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

/**
 * Pourquoi le serveur a refusé la dernière lecture ou la dernière révocation, quand il l'a fait :
 * - `session-ended` : la session a pris fin (`SessionEndedError`) ; la page propose de se reconnecter ;
 * - `forbidden`     : un refus réel (`AccessForbiddenError`) : le compte n'a pas, ou plus, l'accès de
 *                     confiance, ou la signature CSRF est périmée ; la page propose de changer de compte.
 * Les deux : réessayer enverrait la même requête, avec le même verdict.
 */
export type McpRefusal = 'session-ended' | 'forbidden'

export interface UseMcpConnectionsResult {
  status: DeepReadonly<Ref<McpConnectionsStatus>>
  connections: DeepReadonly<Ref<McpConnectionSummary[]>>
  /** Vrai quand le serveur a coupé l'historique des connexions terminées : la page le dit. */
  terminatedTruncated: DeepReadonly<Ref<boolean>>
  /** L'identifiant en cours de révocation, `null` sinon : la page y désactive ses boutons. */
  revokingId: DeepReadonly<Ref<string | null>>
  lastOutcome: DeepReadonly<Ref<McpRevocationOutcome | null>>
  /**
   * Le refus du serveur à la dernière lecture ou à la dernière révocation, `null` sinon ; remis à `null` au
   * début de chaque `load()` et `revoke()`. La page propose alors de se reconnecter (`session-ended`, voir
   * `infrastructure/http/sessionAwareFetch.ts`) ou de changer de compte (`forbidden`) plutôt que de
   * réessayer, ce qui renverrait la même requête avec le même verdict (#748, R7).
   */
  refusal: DeepReadonly<Ref<McpRefusal | null>>
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
 * finie (`SessionEndedError` du dépôt, voir `infrastructure/http/sessionAwareFetch.ts`) marque la session expirée, comme `useAssistant` : la page
 * ne sert que le palier de confiance, l'en-tête ne doit pas rester « connecté » ;
 * elle est aussi dite à la page (`refusal`), qui propose de se reconnecter.
 *
 * Un refus réel (`AccessForbiddenError`) ne marque **jamais** la session expirée : elle vaut pour le reste du
 * site. Il relit `/api/me` (`checkAuth`, une fois, sans l'attendre, un rejet avalé) pour remettre l'en-tête au
 * palier que le serveur reconnaît (les rôles sont relus en base à chaque requête) ; un compte encore de
 * confiance (signature CSRF périmée) n'y change rien. `/api/me` ne rejette pas sur un 401/403 (il rend un
 * palier) : rien ne reboucle sur lui.
 */
export function useMcpConnections(): UseMcpConnectionsResult {
  const repository = inject(MCP_CONNECTIONS_REPOSITORY)

  if (!repository) {
    throw new Error(
      "McpConnectionsRepository n'a pas été fourni. Vérifiez que app.provide(MCP_CONNECTIONS_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  // Après le contrôle ci-dessus : l'absence d'un dépôt se dit par son propre nom.
  const { checkAuth } = useAuth()

  const status = ref<McpConnectionsStatus>('loading')
  const connections = shallowRef<McpConnectionSummary[]>([])
  const terminatedTruncated = ref(false)
  const revokingId = ref<string | null>(null)
  const lastOutcome = ref<McpRevocationOutcome | null>(null)
  const refusal = ref<McpRefusal | null>(null)
  /**
   * Le numéro de la dernière lecture lancée. Deux `load()` peuvent se croiser (le
   * bouton « Réessayer » pendant une relecture après une révocation, deux
   * révocations de suite) et le réseau ne rend pas les réponses dans l'ordre :
   * seule la réponse de la **dernière** lecture lancée écrit l'état, une plus
   * ancienne arrivée après elle est jetée — sa liste est périmée, et sa
   * session « expirée » n'est plus ce que le serveur vient de répondre (#650, R5a).
   */
  let latestLoad = 0

  /** Range un refus du serveur dans `refusal` ; un refus réel relit en plus la session. Toute autre erreur : rien. */
  const refuse = (caught: unknown): void => {
    if (caught instanceof SessionEndedError) {
      markSessionExpired()
      refusal.value = 'session-ended'
    } else if (caught instanceof AccessForbiddenError) {
      refusal.value = 'forbidden'
      // Sans l'attendre : l'état de la page ne dépend pas de la réponse ; un rejet (panne) est avalé.
      void checkAuth().catch(() => undefined)
    }
  }

  const load = async (): Promise<void> => {
    const mine = ++latestLoad
    status.value = 'loading'
    refusal.value = null
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
      refuse(caught)
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
    refusal.value = null

    try {
      await repository.revoke(id)
      lastOutcome.value = 'revoked'
    } catch (caught) {
      if (caught instanceof McpConnectionNotFoundError) {
        lastOutcome.value = 'already-gone'
      } else {
        refuse(caught)
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
    refusal: readonly(refusal),
    load,
    revoke,
  }
}
