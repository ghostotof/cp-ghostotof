/**
 * Le serveur a refusé l'accès par un 403 qui n'est pas une fin de session (#748, #732 point 4) : une
 * lecture, que le CSRF ne contrôle jamais, ou une écriture qui portait son jeton. C'est un refus réel
 * (compte sans l'accès de confiance, ou qui l'a perdu pendant que la page était ouverte ; signature
 * CSRF périmée) : se reconnecter au même compte ne le lèverait pas. Voir
 * `infrastructure/http/sessionAwareFetch.ts` pour la frontière avec `SessionEndedError`.
 */
export class AccessForbiddenError extends Error {
  constructor() {
    super('The signed-in account is not allowed to do this')
    this.name = 'AccessForbiddenError'
  }
}
