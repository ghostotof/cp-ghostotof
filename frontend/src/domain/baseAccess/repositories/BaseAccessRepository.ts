import type { BaseAccessGrant } from '../entities/BaseAccessGrant'

/**
 * Abstraction (DIP) de l'action « obtenir le palier de base » (ADR 0003 D6,
 * POST /api/account/base-access). Le succès pose un cookie BEARER httpOnly,
 * invisible en JS ; le seul retour exploitable est l'échéance du jeton.
 */
export interface BaseAccessRepository {
  grant(): Promise<BaseAccessGrant>
}
