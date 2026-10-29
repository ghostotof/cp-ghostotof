import { ANONYMOUS_SESSION, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { advanceGeneration, isCurrentGeneration } from './sessionGeneration'
import { notifyAccountSwitched, notifySessionRestored } from './sessionEvents'
import { applySession, state } from './sessionState'

/**
 * La fin et le retour de la session (#748, R25 : sortis de `useAuth.ts`). Ils sont les seuls à lever et à baisser
 * `sessionEnded` : `markSessionExpired` la lève, `restoreSession` la baisse.
 */

/**
 * Le compte dont la session a fini (#748, R18) : l'identifiant du compte affiché à la **première** fin acceptée
 * (`markSessionExpired` repasse l'en-tête à anonyme, il faut le lire avant), `null` quand aucun compte n'était
 * affiché (palier de base, anonyme). Ce qui revient est « le même » si l'identifiant est celui-ci, ou s'il n'y en a
 * aucun de retenu ; sinon la page ouverte est celle d'un autre compte. Oublié au retour.
 */
let endedAccount: string | null = null

/**
 * Un compte est-il revenu pendant une session finie ? C'est la moitié de la condition de descente : l'autre est le
 * jeton d'écriture (`canRestore`).
 */
export function isReturnOfAccount(session: AuthSession): boolean {
  return state.sessionEnded && null !== session.user
}

/**
 * **La** condition de descente de `sessionEnded`, écrite une fois (#748, R25) : la session est finie, un compte est
 * revenu **et** un jeton d'écriture existe (`hasWriteToken`, `AuthRepository.hasWriteToken()`) — sans lui la première
 * écriture prendrait le 403 du CSRF, lu comme une session finie : « rétablir » relancerait la boucle. La génération
 * est jugée par l'appelant (`isCurrentGeneration`), sauf pour une connexion, geste de la personne.
 */
export function canRestore(session: AuthSession, hasWriteToken: () => boolean): boolean {
  return isReturnOfAccount(session) && hasWriteToken()
}

/**
 * **La** descente de `sessionEnded` : un compte est revenu avec son jeton d'écriture (l'appelant l'a vérifié par
 * `canRestore`, et la génération, sauf pour une connexion). Applique la session, avance la génération, puis — une
 * fois, de façon synchrone, l'état posé : les abonnés lisent la session revenue — appelle :
 * - `onSessionRestored` si c'est **le même compte** que celui dont la session a fini (ou si aucun n'était affiché) :
 *   la page ouverte est la sienne, elle reprend en place ;
 * - `onAccountSwitched` si c'est un autre : la session est appliquée, mais la page ouverte n'est pas la sienne (#748,
 *   R18) ; seul `AppLayout` y réagit, en rejouant la navigation.
 * Une **connexion** (`announce` faux) ne rappelle personne : la page de connexion navigue elle-même.
 */
export function restoreSession(session: AuthSession, announce: boolean): void {
  const endedFor = endedAccount
  endedAccount = null
  applySession(session)
  state.sessionEnded = false
  advanceGeneration()
  if (!announce) {
    return
  }

  const isSameAccount = null === endedFor || endedFor === session.user?.username
  if (isSameAccount) {
    notifySessionRestored()
  } else {
    notifyAccountSwitched()
  }
}

/**
 * Un appel du palier de confiance (l'assistant, ROLE_TRUSTED) ou du backoffice a reçu un verdict « session finie »
 * (un 401, ou un 403 à une écriture sans jeton CSRF) : le jeton ne vaut plus ce palier (expiré, révoqué, ou compte
 * rétrogradé). Contrairement à markBaseAccessExpired, repasse à anonyme sans condition : la page qui l'appelle ne
 * sert que ce palier, et garder un en-tête « connecté » au-dessus d'une page qui vient de refuser l'accès serait mentir.
 *
 * `sentAt` est la génération lue à l'envoi (`sessionStamp()`), et le rendu dit si la session **est finie** :
 * - sans `sentAt`, ou sous la génération courante : la fin est acceptée (l'en-tête devient anonyme, `sessionEnded`
 *   monte, `sessionEndedCount` augmente — un refus pendant une session déjà finie réannonce), la génération avance ;
 *   rend `true` ;
 * - sous une génération révolue : rien ne change. Rend `true` si la session est finie (le verdict tient, il a déjà
 *   été annoncé : les lectures parallèles du chargement d'une page ne le réannoncent pas), `false` si elle a été
 *   rétablie depuis l'envoi — la réponse est celle d'une session qui n'est plus la courante, l'appelant la traite
 *   comme un échec ordinaire.
 */
export function markSessionExpired(sentAt?: number): boolean {
  if (undefined !== sentAt && !isCurrentGeneration(sentAt)) {
    return state.sessionEnded
  }

  // Le compte dont la session finit est celui de la **première** fin : les suivantes trouvent l'en-tête déjà anonyme.
  if (!state.sessionEnded) {
    endedAccount = state.user?.username ?? null
  }
  applySession(ANONYMOUS_SESSION)
  state.sessionEnded = true
  state.sessionEndedCount += 1
  advanceGeneration()

  return true
}

/** Réservée aux tests (`resetAuthStateForTests`), jamais appelée par `src/` : aucun compte retenu. */
export function resetSessionLifecycleForTests(): void {
  endedAccount = null
}
