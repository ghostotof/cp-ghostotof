/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.quality.errors.*`) sans avoir à connaître
 * le détail du transport HTTP. Partagée entre principles et traits (même
 * bounded context, mêmes types d'erreur possibles).
 *
 * Les deux motifs liés au groupe de traduction (spec 0004, D1/D3) sont
 * distingués de `validation` parce que le backend les distingue lui aussi, par
 * le `type` stable du problem+json : `translation-already-exists` (409, la
 * locale visée existe déjà dans ce groupe) et `unknown-translation-group`
 * (422, le groupe n'appartient pas au périmètre). Les confondre avec un 422 de
 * validation laisserait l'admin devant « le formulaire contient des erreurs »
 * alors qu'aucun champ ne l'est.
 */
export type AdminQualityErrorReason =
  | 'not-found'
  | 'validation'
  | 'translation-already-exists'
  | 'unknown-translation-group'
  | 'unknown'

/**
 * Levée par AdminQualityPrincipleRepository/AdminQualityTraitRepository en
 * cas d'échec d'une opération CRUD (validation, id inconnu, indisponibilité).
 */
export class AdminQualityError extends Error {
  readonly reason: AdminQualityErrorReason

  constructor(reason: AdminQualityErrorReason, message: string) {
    super(message)
    this.name = 'AdminQualityError'
    this.reason = reason
  }
}
