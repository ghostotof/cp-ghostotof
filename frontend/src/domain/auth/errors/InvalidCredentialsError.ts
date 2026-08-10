/**
 * Levée par AuthRepository.login() en cas de nom d'utilisateur/mot de passe incorrect,
 * pour que la présentation puisse afficher un message dédié sans avoir à
 * connaître le détail du transport HTTP (code 401, etc.).
 */
export class InvalidCredentialsError extends Error {
  constructor() {
    super('Invalid credentials')
    this.name = 'InvalidCredentialsError'
  }
}
