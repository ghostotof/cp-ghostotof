/**
 * Forme à plat éditable d'un incident, distincte du contrat public (id, locale,
 * position et groupe de traduction en plus).
 *
 * `position` est en **lecture seule** depuis la spec 0004 (D3) : elle ne se
 * saisit plus, seul `PUT …/order` l'écrit. Elle reste exposée ici parce que
 * `groupByTranslationGroup` s'en sert pour ordonner les lignes du tableau.
 *
 * `translationGroup` (D1) est l'identifiant partagé par les entrées d'un même
 * contenu dans des langues différentes : c'est lui, et non l'id, qui sert de
 * clé d'ordre.
 */
export interface AdminIncident {
  readonly id: string
  readonly locale: string
  readonly translationGroup: string
  readonly title: string
  readonly version: string
  readonly occurredAt: string
  readonly impact: string
  readonly rootCause: string
  readonly resolution: string
  readonly invariant: string
  readonly position: number
}
