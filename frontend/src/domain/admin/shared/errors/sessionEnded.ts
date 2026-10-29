/**
 * La raison commune « la session est finie » (#748, constat 7).
 *
 * Elle est ajoutée à l'union de raisons de chaque contexte d'administration plutôt que portée par une
 * classe d'erreur partagée : chaque composable convertit déjà tout ce qui n'est pas *son* erreur en
 * `unknown` (`error instanceof AdminXError ? error : …`), une classe commune y redeviendrait
 * « Une erreur est survenue ». Avec une raison, les mutations n'ont rien à changer : l'erreur du dépôt
 * est gardée telle quelle.
 *
 * Produite par le dépôt sur un 401, ou un 403 à une écriture partie sans jeton CSRF
 * (`infrastructure/http/sessionAwareFetch.ts`) ; **jamais affichée** : le bandeau de session finie
 * (`SessionEndedBanner`) est alors la seule annonce, et les composables comme les pages la taisent
 * (`isSessionEndedError()` ici, `visibleErrorReason()` côté présentation).
 */
export const SESSION_ENDED = 'session-ended' as const

export type SessionEndedReason = typeof SESSION_ENDED

/**
 * Vrai pour toute erreur dont la raison est `session-ended`, quelle que soit sa classe : les onze
 * contextes ont chacun la leur, et un chargement doit pouvoir les reconnaître sans les connaître.
 */
export function isSessionEndedError(error: unknown): boolean {
  return 'object' === typeof error && null !== error && SESSION_ENDED === (error as { reason?: unknown }).reason
}
