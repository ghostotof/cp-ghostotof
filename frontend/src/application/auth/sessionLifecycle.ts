import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { ANONYMOUS_SESSION, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { advanceGeneration, isCurrentGeneration } from './sessionGeneration'
import { notifyAccountSwitched, notifySessionRestored } from './sessionEvents'
import { applySession, authState, getLastAccount, lowerSessionEnded, raiseSessionEnded, type LastAccount } from './sessionState'

/**
 * La fin et le retour de la session (#748, R25 : sortis de `useAuth.ts`). `markSessionExpired` est la seule à lever
 * `sessionEnded` ; deux descentes la baissent : `restoreSession` (un compte est revenu, avec signal) et
 * `markBaseAccessGranted` (`sessionState.ts`, R41 : l'accès de base obtenu clôt la fin, sans signal).
 */

/**
 * « Le même compte » à la descente de `sessionEnded` (#748, R26, CR3-5) : un compte était retenu (`LastAccount`), c'est
 * le même identifiant, **et** aucun des rôles qu'il avait n'a été perdu. Un rôle **gagné** ne change rien (le démonter
 * pour cela perdrait la saisie pour rien) ; un rôle **perdu** rejoue la navigation : la garde rejuge la route avec ses
 * nouveaux droits. Aucun compte retenu (la session a fini au palier de base, ou la personne s'était déconnectée) :
 * on ne sait pas à qui est la page, ce n'est pas « le même ».
 */
function isSameAccount(previous: LastAccount | null, user: AuthenticatedUser): boolean {
  return null !== previous && previous.username === user.username && previous.roles.every((role) => user.roles.includes(role))
}

/**
 * Un compte est-il revenu pendant une session finie ? C'est la moitié de la condition de descente : l'autre est le
 * jeton d'écriture (`canRestore`).
 */
export function isReturnOfAccount(session: AuthSession): boolean {
  return authState.sessionEnded && null !== session.user
}

/**
 * La condition de la descente par le retour d'un compte, écrite une fois (#748, R25) : la session est finie, un compte est
 * revenu **et** un jeton d'écriture existe (`hasWriteToken`, `AuthRepository.hasWriteToken()`) — sans lui la première
 * écriture prendrait le 403 du CSRF, lu comme une session finie : « rétablir » relancerait la boucle. La génération
 * est jugée par l'appelant (`isCurrentGeneration`), sauf pour une connexion, geste de la personne.
 */
export function canRestore(session: AuthSession, hasWriteToken: () => boolean): boolean {
  return isReturnOfAccount(session) && hasWriteToken()
}

/**
 * La descente de `sessionEnded` **par le retour d'un compte** (l'autre, sans signal, est `markBaseAccessGranted`,
 * `sessionState.ts`, R41) : un compte est revenu (l'appelant a vérifié par `canRestore` le jeton d'écriture et la
 * génération, sauf pour une connexion, geste de la personne). Applique la session, avance la génération, puis —
 * une fois, de façon synchrone, l'état posé : les abonnés lisent la session revenue — appelle :
 * - `onSessionRestored` si c'est **le même compte** que le dernier affiché (`isSameAccount`) : la page ouverte est la
 *   sienne, elle reprend en place ;
 * - `onAccountSwitched` sinon — un autre compte, le même avec un rôle perdu, ou aucun compte retenu : la session est
 *   appliquée, mais la page ouverte n'est pas (ou plus) la sienne (#748, R18, R26) ; seul `AppLayout` y réagit, en
 *   rejouant la navigation.
 * Une **connexion** (`announce` faux) ne rappelle personne : la page de connexion navigue elle-même.
 */
export function restoreSession(session: AuthSession, announce: boolean): void {
  // Lu avant `applySession`, qui le remplace par le compte qui revient.
  const previous = getLastAccount()
  applySession(session)
  lowerSessionEnded()
  advanceGeneration()
  if (!announce) {
    return
  }

  if (null !== session.user && isSameAccount(previous, session.user)) {
    notifySessionRestored()
  } else {
    notifyAccountSwitched()
  }
}

/**
 * Applique une session **lue** hors session finie (`checkAuth`, `rereadAfterRefusal`, #748, R26, CR3-6). Si un autre
 * compte que le dernier affiché est lu (autre identifiant), la page ouverte n'est pas la sienne : la session est
 * appliquée, puis `onAccountSwitched` rejoue la navigation, comme au retour d'une session finie. Le même identifiant
 * (rôles changés ou non) et un palier sans compte sont appliqués sans signal : un rôle perdu y est traité en place
 * (alerte, saisie gardée, R7/R17). Aucun compte retenu (premier chargement) : rien à comparer, aucun signal.
 *
 * **Un autre compte avance la génération, avant le signal** (#748, R48, CR5-2 ; amende R26, « une session *lue* n'avance
 * rien », pour ce seul cas) : la page ouverte n'est plus celle du compte qui l'a envoyée, tout ce qui est parti avant
 * est périmé — comme au retour d'une session finie, qui l'avance déjà (`restoreSession`). Un 401 parti sous le premier
 * compte ne finit alors pas la session du second (`markSessionExpired(sentAt)`). Le 403 tardif d'un enregistrement du
 * premier compte, lui, n'est plus jugé sur la génération mais sur le compte (R63, décision 2 de la sixième relecture :
 * le timbre d'envoi retient `lastAccountName()`, que ce changement de compte remplace). Avancée avant
 * `notifyAccountSwitched`, pour que l'abonné lise déjà la génération nouvelle.
 */
export function applyLiveSession(session: AuthSession): void {
  const previous = getLastAccount()
  applySession(session)
  if (null !== previous && null !== session.user && previous.username !== session.user.username) {
    advanceGeneration()
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
    return authState.sessionEnded
  }

  // Le dernier compte affiché survit : `applySession` ne l'oublie pas en passant à anonyme (voir `LastAccount`).
  applySession(ANONYMOUS_SESSION)
  raiseSessionEnded()
  advanceGeneration()

  return true
}
