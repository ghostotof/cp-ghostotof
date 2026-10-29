/**
 * Ce qu'une requête du backoffice relève **à l'envoi** et rend à la réaction **à la réception** : la couche
 * infrastructure ne connaît ni la session ni la navigation, elle ne fait que porter ce timbre d'un bout à l'autre.
 * Un timbre périmé (la session a changé, la personne a navigué) dit que la réponse est celle d'un état révolu.
 */
export interface BackofficeSendStamp {
  /** La génération de session courante (#748, R14). */
  readonly session: number
  /** Le périmètre de refus courant : il avance à chaque navigation (#748, R28, CR3-8). */
  readonly refusalScope: number
}

/**
 * La réaction du backoffice à ce que ses réponses disent de la session (voir `infrastructure/http/sessionAwareFetch.ts`),
 * posée une fois à la racine de composition. Elle vit dans le domaine parce que trois couches la nomment : la
 * présentation la construit, l'infrastructure l'appelle, et aucun fichier d'`infrastructure/` n'importe `application/`
 * (#748, R28, St3-4, St3-8).
 */
export interface BackofficeSessionReaction {
  /** L'état courant de la session et de la navigation, lu **avant** chaque envoi. */
  stampAtSend(): BackofficeSendStamp
  /**
   * Le verdict « session finie » d'une requête partie sous le timbre `sentAt`. Vrai : la session est finie
   * (elle vient de finir, ou l'était déjà), le client lève `SessionEndedError`. Faux : la session a été rétablie
   * depuis l'envoi, la réponse est celle d'une session révolue — le client la rend au dépôt, qui la traduit en
   * échec ordinaire.
   */
  ended(sentAt: BackofficeSendStamp): boolean
  /**
   * Un refus réel (#748, R17) : un 403 qui n'est pas une fin de session — une lecture, ou une écriture partie avec
   * son jeton. Appelé une fois par refus, juste avant que le client lève `AccessForbiddenError`, avec le timbre de
   * l'envoi : la réaction compte le refus (l'alerte d'`AdminLayout` le dit) et relit le palier — sauf si la
   * personne a navigué depuis l'envoi (le refus est celui d'une page quittée).
   */
  refused(sentAt: BackofficeSendStamp): void
}
