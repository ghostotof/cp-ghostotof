/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon message
 * traduit (cf. i18n `admin.incidents.errors.*`) sans connaître le transport.
 */
export type AdminIncidentErrorReason = 'not-found' | 'validation' | 'unknown'

export class AdminIncidentError extends Error {
  readonly reason: AdminIncidentErrorReason

  constructor(reason: AdminIncidentErrorReason, message: string) {
    super(message)
    this.name = 'AdminIncidentError'
    this.reason = reason
  }
}
