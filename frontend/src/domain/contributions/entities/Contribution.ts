/**
 * Une contribution technique publique, avec le raisonnement qui la porte.
 *
 * `body` est du texte brut dont les paragraphes sont séparés par une ligne
 * vide — jamais du HTML : le contenu vient d'un champ de saisie, et l'injecter
 * en `v-html` sur une page publique échangerait une mise en forme contre une
 * faille XSS. La présentation découpe les paragraphes elle-même.
 */
export interface Contribution {
  readonly title: string
  /** Dépôt hôte, ex. « symfony/ai ». */
  readonly project: string
  /** Référence lisible, ex. « Issue #1688 ». */
  readonly reference: string
  readonly url: string
  /** Chapeau : le problème posé, en une ou deux phrases. */
  readonly summary: string
  readonly body: string
}
