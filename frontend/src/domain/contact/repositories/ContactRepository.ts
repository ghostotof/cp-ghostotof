import type { ContactMessageInput } from '../entities/ContactMessageInput'

/**
 * Abstraction (DIP) : la présentation/application ne connaît que cette
 * interface, jamais HttpContactRepository directement (injecté au niveau du
 * composition root, main.ts).
 */
export interface ContactRepository {
  /**
   * @throws ContactSubmissionFailedError si le backend refuse ou échoue à
   * traiter la soumission (validation, indisponibilité...).
   */
  submit(input: ContactMessageInput): Promise<void>
}
