/**
 * Accorder une connexion MCP demande une authentification récente (#647) : le
 * serveur a refusé l'accord parce que la connexion de la personne au site date
 * de plus de dix minutes (401 `/errors/reauthentication-required`). La session
 * vaut toujours pour le reste du site — elle n'est pas expirée — ; la page
 * propose de se reconnecter, puis ramène à la même demande.
 */
export class ReauthenticationRequiredError extends Error {
  constructor(message = 'A recent authentication is required to grant this connection') {
    super(message)
    this.name = 'ReauthenticationRequiredError'
  }
}
