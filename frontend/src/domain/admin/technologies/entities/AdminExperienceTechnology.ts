/**
 * Forme à plat éditable d'une technologie du classement, distincte de
 * ExperienceTechnology (contrat public en lecture seule, `duration` dérivé
 * et `relatedTechnology` imbriqué) : celle-ci reflète le DTO backoffice
 * (BackofficeExperienceTechnologyResource) tel quel.
 */
export interface AdminExperienceTechnology {
  readonly id: number
  readonly name: string
  readonly years: number
  readonly iconKey: string | null
  readonly relatedTechnologyName: string | null
}
