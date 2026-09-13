/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon message
 * traduit (cf. i18n `admin.anonymousCv.errors.*`) sans connaître le transport.
 */
export type AdminAnonymousCvSectionErrorReason = 'not-found' | 'validation' | 'unknown'

export class AdminAnonymousCvSectionError extends Error {
  readonly reason: AdminAnonymousCvSectionErrorReason

  constructor(reason: AdminAnonymousCvSectionErrorReason, message: string) {
    super(message)
    this.name = 'AdminAnonymousCvSectionError'
    this.reason = reason
  }
}
