/** Forme à plat éditable d'une section du CV sans identité, distincte du contrat public (id, locale, position en plus). */
export interface AdminAnonymousCvSection {
  readonly id: number
  readonly locale: string
  readonly title: string
  readonly skills: string
  readonly yearsOfExperience: number
  readonly achievements: string
  readonly position: number
}
