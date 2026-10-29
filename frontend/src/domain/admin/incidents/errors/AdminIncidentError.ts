/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon message
 * traduit (cf. i18n `admin.incidents.errors.*`) sans connaître le transport.
 *
 * Les deux motifs liés au groupe de traduction (spec 0004, D1/D3) sont
 * distingués de `validation` parce que le backend les distingue lui aussi, par
 * le `type` stable du problem+json : `translation-already-exists` (409, la
 * locale visée existe déjà dans ce groupe) et `unknown-translation-group`
 * (422, le groupe n'appartient pas au périmètre). Les confondre avec un 422 de
 * validation laisserait l'admin devant « certains champs sont invalides »
 * alors qu'aucun champ ne l'est.
 */
export type AdminIncidentErrorReason =
  | 'not-found'
  | 'validation'
  | 'translation-already-exists'
  | 'unknown-translation-group'
  | 'unknown'

export class AdminIncidentError extends Error {
  readonly reason: AdminIncidentErrorReason

  constructor(reason: AdminIncidentErrorReason, message: string) {
    super(message)
    this.name = 'AdminIncidentError'
    this.reason = reason
  }
}
