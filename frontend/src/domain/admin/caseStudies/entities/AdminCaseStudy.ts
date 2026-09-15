/**
 * Forme à plat éditable d'une étude de cas, distincte de CaseStudy (contrat
 * public en lecture seule) : celle-ci porte l'id,
 * la locale, la position et le groupe de traduction, indispensables à un
 * formulaire d'édition et absents du contrat public.
 *
 * `position` est en **lecture seule** depuis la spec 0004 (D3) : elle ne se
 * saisit plus, seul `PUT …/order` l'écrit. Elle reste exposée ici parce que
 * `groupByTranslationGroup` s'en sert pour ordonner les lignes du tableau.
 *
 * `translationGroup` (D1) est l'identifiant partagé par les entrées d'un même
 * contenu dans des langues différentes : c'est lui, et non l'id, qui sert de
 * clé d'ordre. Contenu du palier de base (ADR 0003 D5) : rien de nominatif ne
 * transite ici, ni dans le domaine ni sur le réseau.
 */
export interface AdminCaseStudy {
  readonly id: string
  readonly locale: string
  readonly translationGroup: string
  readonly title: string
  readonly problem: string
  readonly solution: string
  readonly tradeoffs: string
  readonly measuredResult: string
  readonly position: number
}
