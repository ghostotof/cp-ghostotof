/**
 * La décision a été prise sur la page d'un autre compte que celui dont le
 * cookie arrive avec elle (#650, F5) : la page a été ouverte sous le compte A,
 * la personne s'est reconnectée en B dans un autre onglet. Le serveur refuse
 * (409 `/errors/consent-account-changed`) et ne fait rien — ni accord, ni
 * refus. La session vaut toujours ; la page propose de relire la demande, qui
 * nommera alors le compte courant.
 */
export class ConsentAccountChangedError extends Error {
  constructor(message = 'The signed-in account is not the one the consent page was shown for') {
    super(message)
    this.name = 'ConsentAccountChangedError'
  }
}
