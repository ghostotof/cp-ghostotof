/**
 * Le serveur a refusé la page de consentement ou la décision par un 403 qui n'est
 * pas une session finie (#748, #732 point 4) : le `GET` du résumé (jamais soumis
 * au CSRF, son 403 est le refus d'`access_control` — compte sans l'accès de
 * confiance, ou qui l'a perdu pendant que la page était ouverte), ou le `POST`
 * de la décision parti avec son jeton CSRF (même refus, ou signature CSRF
 * périmée). La session vaut toujours pour le reste du site : la page ne la marque
 * pas expirée, elle propose de changer de compte, ce qui repose aussi des
 * cookies neufs.
 *
 * Un 403 au `POST` parti *sans* jeton CSRF est autre chose — une session finie —
 * et reste une `SessionExpiredError` (voir `endsTheSession`).
 */
export class ConsentForbiddenError extends Error {
  constructor(message = 'The signed-in account is not allowed to grant this connection') {
    super(message)
    this.name = 'ConsentForbiddenError'
  }
}
