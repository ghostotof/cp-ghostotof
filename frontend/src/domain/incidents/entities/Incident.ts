/**
 * Une panne de production, sa cause racine, et l'invariant qui en est sorti.
 *
 * La forme est celle d'un post-mortem et vient du backend : quatre champs
 * distincts plutôt qu'un texte libre, pour que chaque entrée réponde aux mêmes
 * questions dans le même ordre. `invariant` est obligatoire — c'est lui qui
 * distingue un journal d'incidents d'une liste d'échecs.
 */
export interface Incident {
  readonly title: string
  /** Version concernée, ex. « v0.5.0 ». */
  readonly version: string
  /** Date ISO (AAAA-MM-JJ), localisée à l'affichage. */
  readonly occurredAt: string
  readonly impact: string
  readonly rootCause: string
  readonly resolution: string
  readonly invariant: string
}
