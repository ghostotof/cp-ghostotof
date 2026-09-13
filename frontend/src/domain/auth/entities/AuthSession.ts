import type { AuthenticatedUser } from './AuthenticatedUser'

/**
 * Les trois paliers de l'ADR 0003 D1, tels que le frontend peut les observer :
 * - `anonymous` : aucun jeton valide ;
 * - `base`      : un jeton porte ROLE_USER sans ROLE_TRUSTED — le jeton D6
 *                 (un clic, sans compte) ou, cas marginal, un compte réel
 *                 jamais promu ;
 * - `trusted`   : ROLE_TRUSTED (ou ROLE_SUPER, qui l'englobe) — le seul
 *                 palier qui ouvre le CV et /api/me.
 */
export type AccessTier = 'anonymous' | 'base' | 'trusted'

/**
 * Ce que le frontend sait de la session courante. Le JWT est httpOnly, donc
 * cette connaissance ne vient jamais d'une lecture locale : seulement des
 * réponses du backend (/api/me, /api/login_check, /api/account/base-access).
 * `user` n'est renseigné que lorsqu'un compte a été identifié — le palier de
 * base obtenu par le jeton D6 n'en a aucun (D6 : « jamais de compte
 * matérialisé »).
 */
export interface AuthSession {
  readonly tier: AccessTier
  readonly user: AuthenticatedUser | null
}

export const ANONYMOUS_SESSION: AuthSession = { tier: 'anonymous', user: null }

/** Palier de base obtenu sans compte (jeton D6) : aucune identité à porter. */
export const BASE_ACCESS_SESSION: AuthSession = { tier: 'base', user: null }
