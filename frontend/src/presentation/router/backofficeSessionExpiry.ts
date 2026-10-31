import { lastAccountName, markSessionExpired, sessionStamp, type UseAuthResult } from '../../application/auth/useAuth'
import { markBackofficeAccessRefused, readRefusalScope } from '../../application/admin/shared/backofficeAccessRefusal'
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
 * porte le périmètre de refus, que chaque effacement de l'alerte avance (chemin ou requête changés, fin de session,
 * démontage) ; le 403 d'une page quittée n'affiche aucune alerte sur la suivante et ne relit rien. Il porte aussi l'époque
 * de montage (R112) : une lecture partie avant le montage de l'alerte est du chargement de la page d'arrivée. **Une écriture refusée compte toujours** (R38) : le timbre porte `{ kind: 'write' }`, la
 * personne a fait le geste et doit apprendre son échec, au besoin sur la page où elle est allée ; le comptage **retient le
 * compte du timbre** (R76), et un refus dont le compte de l'envoi diffère de celui de la réception n'est ni compté ni relu, lecture ou
 * écriture. La règle de l'identité du compte et ses limites sont écrites une fois, dans
 * `.claude/rules/frontend-session-refus.md` (sous-puce « the account »).
 * La relecture est branchée ici, côté composition, et non dans chaque composable : ils
 * n'appellent pas `useAuth()` (treize composables d'administration, dont aucun test ne fournit `AUTH_REPOSITORY`).
 *
 * Construite par `main.ts` avec le `useAuth()` de l'application (`app.runWithContext`) : `rereadAfterRefusal` a besoin
 * du dépôt d'authentification, que `inject` ne donne qu'avec un contexte.
 */
export function createBackofficeSessionReaction(auth: Pick<UseAuthResult, 'rereadAfterRefusal'>): BackofficeSessionReaction {
  return {
    // Une écriture ne relève ni périmètre ni époque : son refus n'est jamais périmé (R38). Une lecture relève les deux (R112).
    stampAtSend: (kind: BackofficeSendKind): BackofficeSendStamp => ({
      session: sessionStamp(),
      account: lastAccountName(),
      refusal: 'write' === kind ? { kind: 'write' } : readRefusalScope(),
    }),
    ended: (sentAt: BackofficeSendStamp): boolean => markSessionExpired(sentAt.session),
    refused: (sentAt: BackofficeSendStamp): void => {
      // Jugé sur le compte, non sur la génération (qui ne juge plus que `ended`) : elle avance aussi à une fin de session, à un
      // retour du même compte ou à une bascule de palier. La règle de l'identité du compte et ses limites sont écrites une
      // fois, dans `.claude/rules/frontend-session-refus.md` (sous-puce « the account »).
      if (sentAt.account !== lastAccountName()) {
        return
      }

      // Une lecture partie avant une navigation (CR3-8) : ni comptée ni relue, la page qui l'a émise n'existe plus.
      if (!markBackofficeAccessRefused(sentAt.refusal, sentAt.account)) {
        return
      }

      // Jamais rejetée (un rejet de `/api/me` est avalé) ; son rendu est sans objet ici : une session finie
      // est annoncée par le bandeau, qui masque l'alerte.
      void auth.rereadAfterRefusal()
    },
  }
}
