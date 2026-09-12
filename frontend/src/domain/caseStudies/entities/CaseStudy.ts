/**
 * ADR 0003 D5 : une étude de cas technique anonymisée — un problème, ses
 * contraintes, la solution retenue, ses compromis, un résultat mesuré. Jamais
 * de nom de client : c'est précisément ce qui permet à ce contenu de rester
 * non identifiant.
 *
 * Chaque champ de texte est du texte brut (paragraphes séparés par une ligne
 * vide), jamais du HTML — même raison que Contribution.body : le contenu
 * vient d'un champ de saisie du backoffice.
 */
export interface CaseStudy {
  readonly title: string
  readonly problem: string
  readonly solution: string
  readonly tradeoffs: string
  readonly measuredResult: string
}
