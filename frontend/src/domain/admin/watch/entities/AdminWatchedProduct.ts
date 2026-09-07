/**
 * Un produit surveillé, tel que le backoffice le manipule.
 *
 * Distinct de `WatchedProduct` (lecture publique) : celui-ci porte l'id et la
 * source de version, nécessaires au formulaire d'édition.
 */
export interface AdminWatchedProduct {
  readonly id: number
  /** Identifiant du produit chez endoflife.date. Immuable après création. */
  readonly slug: string
  readonly label: string
  /** « manual », « runtime_php » ou « runtime_symfony ». */
  readonly versionSource: string
  /** Renseignée pour la seule source « manual », nulle pour les autres. */
  readonly version: string | null
  readonly position: number
}
