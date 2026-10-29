import { markSessionExpired, sessionStamp, type UseAuthResult } from '../../application/auth/useAuth'
import { markBackofficeAccessRefused } from '../../application/admin/shared/backofficeAccessRefusal'
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
 *
 * **Un refus réel** (#748, R17, F4 : un 403 qui n'est pas une fin de session, `refused`) ne ferme rien : la réaction
 * le compte (`markBackofficeAccessRefused`, que l'alerte d'`AdminLayout` affiche) et **relit le palier**
 * (`rereadAfterRefusal`, une relecture en vol partagée : une lecture et une écriture refusées ensemble n'envoient
 * qu'un `/api/me`) — l'en-tête reprend le palier que le serveur reconnaît, ou la session est dite finie si `/api/me`
 * répond « anonyme ». La relecture est branchée ici, côté composition, et non dans chaque composable : ils
 * n'appellent pas `useAuth()` (treize composables d'administration, dont aucun test ne fournit `AUTH_REPOSITORY`).
 *
 * Construite par `main.ts` avec le `useAuth()` de l'application (`app.runWithContext`) : `rereadAfterRefusal` a besoin
 * du dépôt d'authentification, que `inject` ne donne qu'avec un contexte.
 */
export function createBackofficeSessionEndedHandler(auth: Pick<UseAuthResult, 'rereadAfterRefusal'>): BackofficeSessionEndedHandler {
  return {
    stampAtSend: sessionStamp,
    ended: (sentAt: number): boolean => markSessionExpired(sentAt),
    refused: (): void => {
      markBackofficeAccessRefused()
      // Jamais rejetée (un rejet de `/api/me` est avalé) ; son rendu est sans objet ici : une session finie
      // est annoncée par le bandeau, qui masque l'alerte.
      void auth.rereadAfterRefusal()
    },
  }
}
