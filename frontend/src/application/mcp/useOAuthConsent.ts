import { inject, readonly, ref, shallowRef, type DeepReadonly, type InjectionKey, type Ref } from 'vue'
import type { ConsentParams, ConsentRequest } from '../../domain/mcp/entities/ConsentRequest'
import type { OAuthConsentRepository } from '../../domain/mcp/repositories/OAuthConsentRepository'
import { ConsentAccountChangedError } from '../../domain/mcp/errors/ConsentAccountChangedError'
import { AccessForbiddenError } from '../../domain/auth/errors/AccessForbiddenError'
import { InvalidConsentRequestError } from '../../domain/mcp/errors/InvalidConsentRequestError'
import { MalformedConsentDecisionError } from '../../domain/mcp/errors/MalformedConsentDecisionError'
import { ReauthenticationRequiredError } from '../../domain/mcp/errors/ReauthenticationRequiredError'
import { SessionEndedError } from '../../domain/auth/errors/SessionEndedError'
import { isNavigableRedirect } from '../../domain/mcp/services/consentParams'
import { markSessionExpired, sessionStamp, useAuth } from '../auth/useAuth'

export const OAUTH_CONSENT_REPOSITORY: InjectionKey<OAuthConsentRepository> = Symbol('OAuthConsentRepository')

/**
 * Comment quitter le site vers le client après la décision. Par défaut
 * `window.location.replace` : aucun `provide` n'est nécessaire en production.
 * `replace`, jamais `assign` : la page de consentement ne doit pas rester dans
 * l'historique — « Précédent » depuis le client la rechargerait, demande
 * comprise, et offrirait de consentir une seconde fois.
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
 * - `session-ended`      : la session a pris fin (`SessionEndedError`, voir
 *                          `infrastructure/http/sessionAwareFetch.ts`), elle est marquée expirée ;
 * - `forbidden`          : `AccessForbiddenError`, tout autre 403 (le résumé, ou la décision partie avec son
 *                          jeton) — ce compte n'a pas, ou plus, l'accès de confiance, ou sa signature CSRF est
 *                          périmée. Terminal ici, la demande n'est plus montrée ; la session n'est pas marquée
 *                          expirée, elle vaut pour le reste du site, `/api/me` est relu pour l'en-tête, et la
 *                          page propose de changer de compte (#748, #732, R7) ;
 * - `reauthentication-required` : 401 typé à l'accord, l'authentification
 *                          date de plus de dix minutes — terminal ici, la page
 *                          propose de se reconnecter puis ramène à la même
 *                          demande ; la session n'est pas marquée expirée,
 *                          elle vaut toujours pour le reste du site ;
 * - `account-changed`   : 409 typé, le compte connecté n'est plus celui que la
 *                          page a montré (reconnexion dans un autre onglet,
 *                          #650 F5) — terminal ici, la demande n'est plus
 *                          montrée ; `reload()` la relit, sous le compte courant ;
 * - `unsafe-redirect`    : l'URL de retour n'est ni `http:` ni `https:`, ou ne
 *                          mène pas à l'hôte annoncé : la page ne l'a pas suivie ;
 * - `malformed-decision` : 422, le serveur a refusé le corps de la décision —
 *                          un défaut de la page, terminal (réessayer renverrait
 *                          le même corps) ;
 * - `unknown`            : réseau, 5xx, réponse inattendue.
 */
export type OAuthConsentFailure = 'session-ended' | 'forbidden' | 'reauthentication-required' | 'account-changed' | 'unsafe-redirect' | 'malformed-decision' | 'unknown'

export interface UseOAuthConsentResult {
  state: DeepReadonly<Ref<OAuthConsentState>>
  request: DeepReadonly<Ref<ConsentRequest | null>>
  failure: DeepReadonly<Ref<OAuthConsentFailure | null>>
  /** Lit le résumé de la demande ; `null` pour une requête illisible (état `invalid`, sans appel). */
  load: (params: ConsentParams | null) => Promise<void>
  /** Relit la demande reçue (même requête) : le résumé nomme alors le compte connecté d'aujourd'hui. */
  reload: () => Promise<void>
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

  // Après le contrôle ci-dessus : l'absence d'un dépôt se dit par son propre nom.
  const { checkAuth, onSessionRestored } = useAuth()
  const redirect = inject(CLIENT_REDIRECT, (url: string) => window.location.replace(url))

  const state = ref<OAuthConsentState>('loading')
  const request = shallowRef<ConsentRequest | null>(null)
  const failure = ref<OAuthConsentFailure | null>(null)
  let params: ConsentParams | null = null

  /**
   * Range un échec dans l'état qui lui revient ; `onUnknown` dit où mène un échec générique. `sentAt` est la
   * génération de session lue **avant l'envoi** (#748, R14) : un verdict « session finie » d'une requête partie
   * avant que la session soit rétablie est un échec ordinaire (`markSessionExpired` rend `false`), pas une
   * nouvelle fin de session.
   */
  const fail = (caught: unknown, onUnknown: 'ready' | 'error', sentAt: number): void => {
    if (caught instanceof InvalidConsentRequestError) {
      state.value = 'invalid'
      failure.value = null
      return
    }
    if (caught instanceof SessionEndedError && markSessionExpired(sentAt)) {
      state.value = 'error'
      failure.value = 'session-ended'
      return
    }
    if (caught instanceof AccessForbiddenError) {
      // Le résumé d'une demande qu'on ne peut pas décider n'a plus à rester affiché.
      request.value = null
      // Le refus peut venir d'un compte rétrogradé : `/api/me` remet l'en-tête au palier que le serveur
      // reconnaît (une fois, sans l'attendre, un rejet avalé). La session n'est jamais marquée expirée (R7).
      void checkAuth().catch(() => undefined)
      state.value = 'error'
      failure.value = 'forbidden'
      return
    }
    if (caught instanceof ReauthenticationRequiredError) {
      state.value = 'error'
      failure.value = 'reauthentication-required'
      return
    }
    if (caught instanceof ConsentAccountChangedError) {
      state.value = 'error'
      failure.value = 'account-changed'
      return
    }
    if (caught instanceof MalformedConsentDecisionError) {
      state.value = 'error'
      failure.value = 'malformed-decision'
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
    const sentAt = sessionStamp()
    try {
      request.value = await repository.describe(received)
      state.value = 'ready'
    } catch (caught) {
      fail(caught, 'error', sentAt)
    }
  }

  const decide = async (approved: boolean): Promise<void> => {
    // Un seul envoi : un double clic, ou un clic pendant le départ vers le
    // client, ne repart pas — sans quoi un second code serait émis.
    const shown = request.value
    if ('ready' !== state.value || null === params || null === shown) {
      return
    }

    state.value = 'submitting'
    failure.value = null

    // L'hôte annoncé à la personne, lu avant l'appel : c'est sur lui qu'elle a consenti.
    const announcedHost = request.value?.redirectHost ?? ''

    const sentAt = sessionStamp()
    let url: string
    try {
      // Pour le compte que le résumé a nommé, pas pour celui du cookie à l'envoi (F5).
      url = await repository.decide(params, approved, shown.account.id)
    } catch (caught) {
      fail(caught, 'ready', sentAt)
      return
    }

    if (!isNavigableRedirect(url, announcedHost)) {
      state.value = 'error'
      failure.value = 'unsafe-redirect'
      return
    }

    redirect(url)
  }

  // Au retour de la session (`onSessionRestored`), la demande que la session finie avait refusée se relit,
  // sous le compte revenu (#748, R8) — `reload()` : la même requête. Les autres échecs terminaux ne se relisent
  // pas. Rétablir la session est l'affaire de `useAuth().recheckEndedSession()`, branchée par
  // `useSessionRecheckOnFocus` (AppLayout), pas d'ici.
  onSessionRestored(() => {
    if ('session-ended' === failure.value) {
      void load(params)
    }
  })

  return {
    state: readonly(state),
    request: readonly(request),
    failure: readonly(failure),
    load,
    reload: () => load(params),
    approve: () => decide(true),
    deny: () => decide(false),
  }
}
