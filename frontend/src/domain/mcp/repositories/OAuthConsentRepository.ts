import type { ConsentParams, ConsentRequest } from '../entities/ConsentRequest'

/**
 * Abstraction (DIP) du consentement à une connexion MCP (#647, spec 0007
 * phase 2). L'implémentation HTTP (HttpOAuthConsentRepository) est injectée au
 * composition root (main.ts). Réservé au palier de confiance : session par
 * cookie, CSRF sur la décision.
 *
 * Les deux méthodes reçoivent la demande telle que la page l'a reçue ; le
 * serveur la revalide à chaque appel.
 */
export interface OAuthConsentRepository {
  /**
   * Le résumé de la demande, à présenter avant la décision.
   *
   * @throws InvalidConsentRequestError demande invalide, expirée ou altérée (400 `authorization-request-rejected`)
   * @throws SessionExpiredError session absente, expirée ou sans le palier de confiance (401/403)
   * @throws ConsentFailedError tout autre échec
   */
  describe(params: ConsentParams): Promise<ConsentRequest>

  /**
   * Transmet la décision de la personne, pour le compte que le résumé a nommé.
   *
   * `accountId` est l'UUID reçu de `describe()` (`account.id`), renvoyé tel quel :
   * le serveur le compare au compte du cookie de CETTE requête (#650, F5).
   *
   * @returns l'URL de retour vers le client (avec `code`, ou `error=access_denied`),
   *   que la page suit — après en avoir vérifié le schéma et l'hôte.
   * @throws InvalidConsentRequestError demande invalide, expirée ou altérée (400 `authorization-request-rejected`)
   * @throws SessionExpiredError session absente, expirée, sans le palier de confiance, ou CSRF refusé (401/403)
   * @throws ConsentAccountChangedError le compte connecté n'est plus celui que le résumé a nommé (409 `consent-account-changed`) : rien n'a été fait
   * @throws MalformedConsentDecisionError corps de la décision refusé (422), un défaut de la page
   * @throws ConsentFailedError tout autre échec
   */
  decide(params: ConsentParams, approved: boolean, accountId: string): Promise<string>
}
