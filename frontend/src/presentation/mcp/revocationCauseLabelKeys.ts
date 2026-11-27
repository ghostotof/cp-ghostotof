/**
 * Les clés i18n des causes de révocation d'une connexion MCP, partagées par
 * « Mes connexions MCP » (la personne) et les connexions du backoffice (#652).
 * Une seule table : une cause ajoutée par le backend reçoit son libellé une
 * fois, pour les deux pages.
 *
 * Correspondances explicites plutôt qu'une clé construite à la volée : une
 * `t('mcpConnections.causes.' + cause)` échapperait à la vérification d'usage
 * des clés, et une cause ajoutée par un backend plus récent afficherait une clé
 * brute. Une cause absente de cette table tombe sur le libellé générique
 * (`mcpConnections.causes.unknown`), que chaque page choisit elle-même.
 *
 * `client-removed` n'apparaît qu'au journal d'audit (décision D-B de #652 : le
 * retrait d'un client supprime ses connexions, aucune ligne ne garde cette
 * cause) ; elle reste lisible si une ligne la porte un jour.
 *
 * Indexée par `string` et non par une union fermée : la cause vient du serveur,
 * et la page la lit avec `Object.hasOwn` avant de s'en servir, sans `as`.
 */
export const REVOCATION_CAUSE_LABEL_KEYS: Readonly<Record<string, string>> = {
  person: 'mcpConnections.causes.person',
  client: 'mcpConnections.causes.client',
  'refresh-token-reuse': 'mcpConnections.causes.refreshTokenReuse',
  'code-replay': 'mcpConnections.causes.codeReplay',
  superseded: 'mcpConnections.causes.superseded',
  expired: 'mcpConnections.causes.expired',
  'password-changed': 'mcpConnections.causes.passwordChanged',
  'trusted-tier-withdrawn': 'mcpConnections.causes.trustedTierWithdrawn',
  'client-removed': 'mcpConnections.causes.clientRemoved',
}
