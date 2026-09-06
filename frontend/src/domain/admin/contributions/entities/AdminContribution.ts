/**
 * Forme à plat éditable d'une contribution, distincte de Contribution
 * (contrat public en lecture seule) : celle-ci porte l'id, la locale et la
 * position, indispensables à un formulaire d'édition et absents du contrat
 * public.
 */
export interface AdminContribution {
  readonly id: number
  readonly locale: string
  readonly title: string
  readonly project: string
  readonly reference: string
  readonly url: string
  readonly summary: string
  readonly body: string
  readonly position: number
}
