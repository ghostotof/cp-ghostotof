import { markSessionExpired, sessionStamp } from '../../application/auth/useAuth'
import type { BackofficeSessionEndedHandler } from '../../infrastructure/admin/shared/BackofficeHttpClient'

/**
 * La réaction du backoffice à une session finie (un 401, ou un 403 à une écriture sans jeton CSRF), branchée
 * dans `main.ts` sur `setBackofficeSessionEndedHandler` : la session est finie (JWT expiré, ou ouverte avant un
 * changement de mot de passe, #650 R1b). L'en-tête repasse à « anonyme » et l'état « session finie » est levé —
 * le bandeau d'`AdminLayout` (`SessionEndedBanner`) l'annonce et offre le lien de reconnexion.
 *
 * **Aucune navigation, délibérément** (#650, S2 ; R1c menait à la connexion) : un
 * `router.replace` ici démontait la page au moment où la personne cliquait
 * « Enregistrer », et une étude de cas rédigée pendant plus d'une heure (la durée
 * du JWT) était perdue avec elle. Le garde de route (`requiresAuth`) continue de
 * mener à la connexion à la navigation suivante : la saisie est alors enregistrée
 * ou abandonnée.
 *
 * **Le verdict est jugé par génération de session** (#748, R14), pas « idempotent » : le client lit
 * `stampAtSend` avant l'envoi et rend la valeur à `ended` avec le verdict (`markSessionExpired(sentAt)`).
 * Une page du backoffice charge plusieurs listes en parallèle, parties sous la même génération : la première fin
 * compte, les suivantes rendent `true` sans rien réannoncer. Une réponse partie avant le retour de la session rend
 * `false` : elle ne la refinit pas, le client la rend au dépôt comme un échec ordinaire.
 */
export const backofficeSessionEndedHandler: BackofficeSessionEndedHandler = {
  stampAtSend: sessionStamp,
  ended: (sentAt: number): boolean => markSessionExpired(sentAt),
}
