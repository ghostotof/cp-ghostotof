/**
 * ADR 0003 D5 : une section du « CV sans identité » — un domaine de
 * compétence, les technologies qui le composent, l'ancienneté, et surtout ce
 * qui a été réalisé avec. Ni nom, ni employeur, ni client : c'est cette
 * absence qui rend le contenu non identifiant, et elle se décide à la saisie.
 *
 * `skills` et `achievements` sont du texte brut (paragraphes séparés par une
 * ligne vide), jamais du HTML — même raison que CaseStudy : le contenu vient
 * d'un champ de saisie du backoffice.
 */
export interface AnonymousCvSection {
  readonly title: string
  readonly skills: string
  readonly yearsOfExperience: number
  readonly achievements: string
}
