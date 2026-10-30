import { isCurrentGeneration, markSessionExpired, sessionStamp, type UseAuthResult } from '../../application/auth/useAuth'
import { markBackofficeAccessRefused, refusalScope } from '../../application/admin/shared/backofficeAccessRefusal'
import type { BackofficeSendKind, BackofficeSendStamp, BackofficeSessionReaction } from '../../domain/admin/shared/BackofficeSessionReaction'

/**
 * La réaction du backoffice à une session finie (un 401, ou un 403 à une écriture sans jeton CSRF), branchée
 * dans `main.ts` sur `setBackofficeSessionReaction` : la session est finie (JWT expiré, ou ouverte avant un
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
 * `stampAtSend` avant l'envoi et rend le timbre à `ended` avec le verdict (`markSessionExpired(sentAt.session)`).
 * Une page du backoffice charge plusieurs listes en parallèle, parties sous la même génération : la première fin
 * compte, les suivantes rendent `true` sans rien réannoncer. Une réponse partie avant le retour de la session rend
 * `false` : elle ne la refinit pas, le client la rend au dépôt comme un échec ordinaire.
 *
 * **Un refus réel** (#748, R17, F4 : un 403 qui n'est pas une fin de session, `refused`) ne ferme rien : la réaction
 * le compte (`markBackofficeAccessRefused`, que l'alerte d'`AdminLayout` affiche) et **relit le palier**
 * (`rereadAfterRefusal`, une relecture en vol partagée : une lecture et une écriture refusées ensemble n'envoient
 * qu'un `/api/me`) — l'en-tête reprend le palier que le serveur reconnaît, ou la session est dite finie si `/api/me`
 * répond « anonyme ». **Une lecture refusée après une navigation ne compte pas** (R28, CR3-8) : le timbre de l'envoi
 * porte le périmètre de refus, que chaque navigation avance ; le 403 d'une page quittée n'affiche aucune alerte sur la
 * suivante et ne relit rien. **Une écriture refusée compte toujours** (R38) : le timbre porte un périmètre nul, la
 * personne a fait le geste et doit apprendre son échec, au besoin sur la page où elle est allée — **sauf si la session a changé depuis l'envoi** (R48, CR5-2) : un refus
 * parti sous une autre génération (retour d'une session finie, autre compte lu) n'est ni compté ni relu, lecture ou
 * écriture. La relecture est branchée ici, côté composition, et non dans chaque composable : ils
 * n'appellent pas `useAuth()` (treize composables d'administration, dont aucun test ne fournit `AUTH_REPOSITORY`).
 *
 * Construite par `main.ts` avec le `useAuth()` de l'application (`app.runWithContext`) : `rereadAfterRefusal` a besoin
 * du dépôt d'authentification, que `inject` ne donne qu'avec un contexte.
 */
export function createBackofficeSessionReaction(auth: Pick<UseAuthResult, 'rereadAfterRefusal'>): BackofficeSessionReaction {
  return {
    // Une écriture ne relève aucun périmètre : son refus n'est jamais périmé (R38).
    stampAtSend: (kind: BackofficeSendKind): BackofficeSendStamp => ({
      session: sessionStamp(),
      refusalScope: 'write' === kind ? null : refusalScope(),
    }),
    ended: (sentAt: BackofficeSendStamp): boolean => markSessionExpired(sentAt.session),
    refused: (sentAt: BackofficeSendStamp): void => {
      // Partie sous une autre génération (un autre compte, un retour, une connexion) : la réponse est celle d'une
      // session qui n'est plus la courante, elle n'a rien à dire de celle-ci (CR5-2). Comme `ended`, mais ici le
      // refus n'est ni compté ni relu : le palier relu serait celui d'un compte que ce refus ne concerne pas.
      if (!isCurrentGeneration(sentAt.session)) {
        return
      }

      // Une lecture partie avant une navigation (CR3-8) : ni comptée ni relue, la page qui l'a émise n'existe plus.
      if (!markBackofficeAccessRefused(sentAt.refusalScope)) {
        return
      }

      // Jamais rejetée (un rejet de `/api/me` est avalé) ; son rendu est sans objet ici : une session finie
      // est annoncée par le bandeau, qui masque l'alerte.
      void auth.rereadAfterRefusal()
    },
  }
}
