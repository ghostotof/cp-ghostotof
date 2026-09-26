import type { ContactMessageInput } from '../entities/ContactMessageInput'

/**
 * Abstraction (DIP) : la présentation/application ne connaît que cette
 * interface, jamais HttpContactRepository directement (injecté au niveau du
 * composition root, main.ts).
 */
export interface ContactRepository {
  /**
   * Trois échecs distincts, parce que la conduite à tenir diffère : corriger,
   * attendre, réessayer plus tard. Les confondre est exactement le défaut
   * corrigé par l'issue #236.
   *
   * @throws ContactValidationError sur un 422 : la saisie est refusée et le
   * sera à l'identique à chaque tentative. Porte les `propertyPath` fautifs.
   * @throws ContactRateLimitedError sur un 429 du limiteur applicatif : rien à
   * corriger, seulement à attendre.
   * @throws ContactSubmissionFailedError pour tout le reste (panne réseau,
   * 5xx, réponse inattendue) : le seul cas où « réessayez plus tard » est vrai.
   */
  submit(input: ContactMessageInput): Promise<void>
}
