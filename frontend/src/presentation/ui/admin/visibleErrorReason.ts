import { SESSION_ENDED, type SessionEndedReason } from '../../../domain/admin/shared/errors/sessionEnded'

/**
 * La raison d'une erreur telle qu'une page doit l'afficher (#748, constat 7) : `null` quand il n'y a
 * rien à dire, et aussi quand la raison est « session finie », que le bandeau de session finie
 * (`SessionEndedBanner`, rendu une fois par `AdminLayout`) annonce déjà. Sans ce filtre, la page y
 * ajouterait une alerte sous une clé de traduction qui n'existe pas.
 *
 * Toute page qui compose une clé `admin.<contexte>.errors.${raison}` passe d'abord par ici ; la garde
 * de source `errorReasonsHideSessionEnded.spec.ts` le vérifie.
 */
export function visibleErrorReason<R extends string>(
  reason: R | null | undefined,
): Exclude<R, SessionEndedReason> | null {
  if (null === reason || undefined === reason || SESSION_ENDED === reason) {
    return null
  }

  return reason as Exclude<R, SessionEndedReason>
}
