/**
 * Une violation telle que le backend la renvoie dans le corps problem+json
 * d'un 422 (cf. `violations[]` d'API Platform).
 *
 * `propertyPath` nomme le champ refusé (`name`, `email`, `message`) et c'est la
 * SEULE partie exploitable en présentation : `message` est le libellé du
 * backend, écrit en français en dur dans les contraintes de
 * App\Contact\Presentation\ApiResource\ContactMessageResource. L'afficher tel
 * quel servirait du français à un visiteur anglophone — la page porte donc ses
 * propres libellés i18n, choisis d'après `propertyPath`. On conserve tout de
 * même `message` : il documente la règle côté serveur et reste utile au
 * diagnostic.
 */
export interface ContactViolation {
  readonly propertyPath: string
  readonly message: string
}

/**
 * Levée par ContactRepository.submit() sur un 422 : la saisie est refusée et le
 * sera à chaque nouvelle tentative identique. C'est précisément ce qui
 * distingue ce cas de ContactSubmissionFailedError, dont le message invite à
 * « réessayer plus tard » (issue #236).
 */
export class ContactValidationError extends Error {
  readonly violations: ReadonlyArray<ContactViolation>

  constructor(violations: ReadonlyArray<ContactViolation> = []) {
    super('Contact submission rejected by validation')
    this.name = 'ContactValidationError'
    this.violations = violations
  }
}
