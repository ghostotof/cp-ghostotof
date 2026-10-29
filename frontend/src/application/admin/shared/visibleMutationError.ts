import { SessionEndedError } from '../../../domain/auth/errors/SessionEndedError'

/**
 * L'erreur d'une mutation d'administration telle que la page doit l'afficher (#748, R3), écrite **une fois**
 * pour les `catch` des douze composables, d'`useOrderDraft` et d'`useAdminTranslation` :
 *
 * - `null` pour une session finie (`SessionEndedError`, levée par `BackofficeHttpClient`) : le bandeau de
 *   session finie est alors la seule annonce, la page n'y ajoute rien ;
 * - l'erreur elle-même quand c'est celle du contexte (`ownError`), avec sa raison ;
 * - `fallback()` sinon, l'échec générique du contexte (`new AdminXError('unknown', …)`), que la page traduit.
 *
 * Un `catch` qui convertirait tout ce qui n'est pas « son » erreur en `unknown` (`error instanceof AdminXError ?
 * error : …`) afficherait « Une erreur est survenue » sous le bandeau : c'est l'oubli que cette fonction retire.
 */
export function visibleMutationError<E extends Error>(
  error: unknown,
  ownError: abstract new (...args: never[]) => E,
  fallback: () => E,
): E | null {
  if (error instanceof SessionEndedError) {
    return null
  }

  return error instanceof ownError ? error : fallback()
}
