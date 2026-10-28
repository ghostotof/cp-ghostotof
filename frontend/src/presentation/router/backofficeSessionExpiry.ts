import { markSessionExpired } from '../../application/auth/useAuth'

/**
 * La réaction du backoffice à un 401, branchée dans `main.ts` sur
 * `setBackofficeUnauthorizedHandler` : la session est finie (JWT expiré, ou
 * ouverte avant un changement de mot de passe, #650 R1b). L'en-tête repasse à
 * « anonyme » et l'état « session finie » est levé — le bandeau d'`AdminLayout`
 * (`SessionEndedBanner`) l'annonce et offre le lien de reconnexion.
 *
 * **Aucune navigation, délibérément** (#650, S2 ; R1c menait à la connexion) : un
 * `router.replace` ici démontait la page au moment où la personne cliquait
 * « Enregistrer », et une étude de cas rédigée pendant plus d'une heure (la durée
 * du JWT) était perdue avec elle. Le garde de route (`requiresAuth`) continue de
 * mener à la connexion à la navigation suivante : la saisie est alors enregistrée
 * ou abandonnée.
 *
 * Une page du backoffice charge plusieurs listes en parallèle, et chacune en 401
 * appelle cette réaction : elle est idempotente (un état, pas un événement), il
 * n'y a rien à dédoublonner.
 */
export function onBackofficeUnauthorized(): void {
  markSessionExpired()
}
