/**
 * État de maintenance d'une version installée, tel que le backend le calcule.
 *
 * `unknown` n'est pas une valeur de repli commode : elle dit que la version
 * n'a été retrouvée dans aucun cycle publié. L'afficher comme telle vaut mieux
 * que de laisser croire à une fin de vie.
 */
export type SupportStatus = 'supported' | 'security_only' | 'eol' | 'unknown'

/** Une ligne du radar : un produit de la stack et ses échéances de support. */
export interface WatchedProduct {
  readonly slug: string
  readonly label: string
  /** Version installée. Nulle si le backend n'a pas su la déterminer. */
  readonly version: string | null
  readonly status: SupportStatus
  /** Cycle de vie auquel appartient la version installée, ex. « 8.5 ». */
  readonly cycle: string | null
  /** Dates de calendrier (AAAA-MM-JJ), sans heure ni fuseau. */
  readonly endOfActiveSupportFrom: string | null
  readonly eolFrom: string | null
  /** Dernier correctif publié sur le cycle, ex. « 8.5.10 ». */
  readonly latestVersion: string | null
  readonly hasNewerPatch: boolean
  readonly documentationUrl: string | null
}

/**
 * Le volet « cycles de vie », avec sa propre fraîcheur.
 *
 * `refreshedAt` est nul tant qu'aucun rafraîchissement n'a abouti — un état
 * normal sur une installation neuve, que la page doit savoir présenter sans le
 * confondre avec une panne.
 */
export interface ReleaseCyclesSnapshot {
  readonly products: readonly WatchedProduct[]
  /** Date ISO 8601 en UTC, ou null si jamais rafraîchi. */
  readonly refreshedAt: string | null
  /** « ok » ou « partial » : au moins une source avait échoué. */
  readonly sourceStatus: string | null
}

/**
 * Le volet « vulnérabilités », réduit à un décompte.
 *
 * Le détail — identifiants, paquets touchés, versions correctives — existe côté
 * serveur mais n'est servi qu'à l'administration : le publier reviendrait à
 * tendre au premier venu la carte des faiblesses du site.
 *
 * `packagesScanned` vaut null quand aucune analyse n'a eu lieu. C'est ce qui
 * distingue « rien trouvé » de « rien cherché », et empêche la page d'afficher
 * un zéro rassurant que personne n'a vérifié.
 */
export interface VulnerabilitiesSnapshot {
  readonly packagesScanned: number | null
  readonly affectedCount: number
  /** Date ISO 8601 en UTC, ou null si aucune analyse n'a abouti. */
  readonly checkedAt: string | null
}

/**
 * La réponse de GET /api/watch, structurée par volet.
 *
 * Chaque volet porte sa propre date : les deux instantanés sont distincts et
 * peuvent réussir ou échouer séparément. D'où le groupement, plutôt qu'une date
 * unique à la racine qui ne pourrait décrire que l'un des deux.
 */
export interface WatchContent {
  readonly releaseCycles: ReleaseCyclesSnapshot
  readonly vulnerabilities: VulnerabilitiesSnapshot
}
