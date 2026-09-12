/**
 * Abstraction (DIP) de l'action « obtenir le palier de base » (ADR 0003 D6,
 * POST /api/account/base-access). Pas d'entité de retour : le succès pose un
 * cookie BEARER httpOnly, invisible en JS — seul l'appelant sait qu'il doit
 * relire le contenu protégé après coup.
 */
export interface BaseAccessRepository {
  grant(): Promise<void>
}
