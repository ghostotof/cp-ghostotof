import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme à plat éditable d'une carte "À propos de ce site", distincte du DTO
 * public imbriqué AboutCard (sans id/locale/position).
 *
 * `position` est en **lecture seule** depuis la spec 0004 (D3) : elle ne se
 * saisit plus, seul `PUT …/order` l'écrit. Elle reste exposée ici parce que
 * `groupByTranslationGroup` s'en sert pour ordonner les lignes du tableau.
 *
 * `translationGroup` (D1) est l'identifiant partagé par les entrées d'un même
 * contenu dans des langues différentes : c'est lui, et non l'id, qui sert de
 * clé d'ordre.
 */
export interface AdminAboutSiteCard {
  readonly id: string
  readonly locale: Locale
  readonly translationGroup: string
  readonly title: string
  readonly description: string
  readonly iconKey: string | null
  readonly position: number
}
