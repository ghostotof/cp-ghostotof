import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Les trois catégories, dans l'ordre où la page publique les présente. Nommées
 * ici plutôt qu'écrites en dur dans les `.vue` : le backoffice en tire ses trois
 * tableaux et son sélecteur, exactement comme il tire ses badges de langue de
 * `SUPPORTED_LOCALES` (spec 0004, D2 — aucune énumération recopiée).
 */
export const ME_CARD_CATEGORIES = ['technical', 'personal', 'hobby'] as const

export type AdminAboutMeCardCategory = (typeof ME_CARD_CATEGORIES)[number]

/**
 * Forme à plat éditable d'une carte "À propos de moi", distincte du DTO
 * public imbriqué AboutCard : celle-ci porte en plus `category`, qui range la
 * carte dans technicalCards/personalCards/hobbiesCards côté affichage public.
 *
 * `position` est en **lecture seule** depuis la spec 0004 (D3) : elle ne se
 * saisit plus, seul `PUT …/order` l'écrit. Le périmètre d'ordre de ces cartes
 * est la **catégorie**, toutes langues confondues — d'où un tableau, un
 * brouillon et un enregistrement par catégorie côté backoffice.
 *
 * `translationGroup` (D1) est l'identifiant partagé par les entrées d'un même
 * contenu dans des langues différentes ; un groupe appartient à une seule
 * catégorie (le serveur refuse un rattachement qui la traverserait, 422).
 */
export interface AdminAboutMeCard {
  readonly id: string
  readonly locale: Locale
  readonly translationGroup: string
  readonly category: AdminAboutMeCardCategory
  readonly title: string
  readonly description: string
  readonly iconKey: string | null
  readonly position: number
}
