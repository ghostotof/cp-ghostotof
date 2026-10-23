import { inject, readonly, ref, shallowRef, type DeepReadonly, type InjectionKey, type Ref } from 'vue'
import type { ConsentParams, ConsentRequest } from '../../domain/mcp/entities/ConsentRequest'
import type { OAuthConsentRepository } from '../../domain/mcp/repositories/OAuthConsentRepository'
import { InvalidConsentRequestError } from '../../domain/mcp/errors/InvalidConsentRequestError'
import { SessionExpiredError } from '../../domain/mcp/errors/SessionExpiredError'
import { isNavigableRedirect } from '../../domain/mcp/services/consentParams'
import { markSessionExpired } from '../auth/useAuth'

export const OAUTH_CONSENT_REPOSITORY: InjectionKey<OAuthConsentRepository> = Symbol('OAuthConsentRepository')

/**
 * Comment quitter le site vers le client après la décision. Par défaut
 * `window.location.assign` : aucun `provide` n'est nécessaire en production.
 * La clé existe pour les tests, jsdom ne sachant ni naviguer ni laisser
 * espionner `window.location`.
 */
export const CLIENT_REDIRECT: InjectionKey<(url: string) => void> = Symbol('ClientRedirect')

/**
 * - `loading`    : lecture du résumé de la demande (au montage de la page) ;
 * - `ready`      : demande affichée, les deux boutons actifs ;
 * - `submitting` : décision en cours de transmission, puis départ vers le client
 *                  — l'état reste `submitting` pendant que le navigateur quitte
 *                  la page, pour qu'aucun second clic ne parte ;
 * - `invalid`    : demande invalide, expirée ou altérée (400, ou requête
 *                  illisible), état terminal : recommencer depuis l'assistant ;
 * - `error`      : échec terminal, que `failure` précise.
 *
 * Un échec générique de la *décision* ramène à `ready` avec `failure` posé :
 * la demande est toujours valable, la personne peut réessayer.
 */
export type OAuthConsentState = 'loading' | 'ready' | 'submitting' | 'invalid' | 'error'

/**
 * - `session-expired` : 401/403, la session est marquée expirée ;
 * - `unsafe-redirect` : l'URL de retour n'est ni `http:` ni `https:`, la page
 *                       ne l'a pas suivie ;
 * - `unknown`         : réseau, 5xx, réponse inattendue.
 */
export type OAuthConsentFailure = 'session-expired' | 'unsafe-redirect' | 'unknown'

export interface UseOAuthConsentResult {
  state: DeepReadonly<Ref<OAuthConsentState>>
  request: DeepReadonly<Ref<ConsentRequest | null>>
  failure: DeepReadonly<Ref<OAuthConsentFailure | null>>
  /** Lit le résumé de la demande ; `null` pour une requête illisible (état `invalid`, sans appel). */
  load: (params: ConsentParams | null) => Promise<void>
  approve: () => Promise<void>
  deny: () => Promise<void>
}

/**
 * Machine d'états de la page de consentement à une connexion MCP (#647).
 *
 * La demande reçue est gardée telle quelle et renvoyée telle quelle à la
 * décision : la page ne calcule aucune des valeurs qu'elle transmet. Le serveur
 * la revalide à chaque appel.
 */
export function useOAuthConsent(): UseOAuthConsentResult {
  const repository = inject(OAUTH_CONSENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "OAuthConsentRepository n'a pas été fourni. Vérifiez que app.provide(OAUTH_CONSENT_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const redirect = inject(CLIENT_REDIRECT, (url: string) => window.location.assign(url))

  const state = ref<OAuthConsentState>('loading')
  const request = shallowRef<ConsentRequest | null>(null)
  const failure = ref<OAuthConsentFailure | null>(null)
  let params: ConsentParams | null = null

  /** Range un échec dans l'état qui lui revient ; `onUnknown` dit où mène un échec générique. */
  const fail = (caught: unknown, onUnknown: 'ready' | 'error'): void => {
    if (caught instanceof InvalidConsentRequestError) {
      state.value = 'invalid'
      failure.value = null
      return
    }
    if (caught instanceof SessionExpiredError) {
      markSessionExpired()
      state.value = 'error'
      failure.value = 'session-expired'
      return
    }
    state.value = onUnknown
    failure.value = 'unknown'
  }

  const load = async (received: ConsentParams | null): Promise<void> => {
    params = received
    request.value = null
    failure.value = null

    if (null === received) {
      state.value = 'invalid'
      return
    }

    state.value = 'loading'
    try {
      request.value = await repository.describe(received)
      state.value = 'ready'
    } catch (caught) {
      fail(caught, 'error')
    }
  }

  const decide = async (approved: boolean): Promise<void> => {
    // Un seul envoi : un double clic, ou un clic pendant le départ vers le
    // client, ne repart pas — sans quoi un second code serait émis.
    if ('ready' !== state.value || null === params) {
      return
    }

    state.value = 'submitting'
    failure.value = null

    let url: string
    try {
      url = await repository.decide(params, approved)
    } catch (caught) {
      fail(caught, 'ready')
      return
    }

    if (!isNavigableRedirect(url)) {
      state.value = 'error'
      failure.value = 'unsafe-redirect'
      return
    }

    redirect(url)
  }

  return {
    state: readonly(state),
    request: readonly(request),
    failure: readonly(failure),
    load,
    approve: () => decide(true),
    deny: () => decide(false),
  }
}
