/**
 * Ce que le backend rend quand le palier de base est accordé (ADR 0003 D6,
 * POST /api/account/base-access). Le cookie BEARER est httpOnly : `expiresAt`
 * est la seule façon pour le frontend de savoir quand le jeton s'éteint, et
 * donc quand cesser d'afficher « Accès de base ». `null` si le backend ne
 * l'a pas fourni (contrat plus ancien) : on retombe alors sur le comportement
 * d'avant, le badge reste jusqu'au prochain signal du serveur.
 */
export interface BaseAccessGrant {
  readonly expiresAt: Date | null
}
