/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon message
 * traduit (cf. i18n `admin.caseStudies.errors.*`) sans connaître le transport.
 *
 * Les deux motifs liés au groupe de traduction (spec 0004, D1/D3) sont
 * distingués de `validation` parce que le backend les distingue lui aussi, par
 * le `type` stable du problem+json : `translation-already-exists` (409, la
 * locale visée existe déjà dans ce groupe) et `unknown-translation-group`
 * (422, le groupe n'appartient pas au périmètre). Les confondre avec un 422 de
 * validation laisserait l'admin devant « certains champs sont invalides »
 * alors qu'aucun champ ne l'est.
 */
export type AdminCaseStudyErrorReason =
  | 'not-found'
  | 'validation'
  | 'translation-already-exists'
  | 'unknown-translation-group'
  | 'unknown'

/**
 * Levée par AdminCaseStudyRepository en cas d'échec d'une opération
 * CRUD (validation, id inconnu, indisponibilité).
 */
export class AdminCaseStudyError extends Error {
  readonly reason: AdminCaseStudyErrorReason

  constructor(reason: AdminCaseStudyErrorReason, message: string) {
    super(message)
    this.name = 'AdminCaseStudyError'
    this.reason = reason
  }
}
