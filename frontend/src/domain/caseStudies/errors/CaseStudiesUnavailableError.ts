/**
 * Levée quand la liste des études de cas ne peut pas être récupérée, pour
 * une raison autre que l'absence d'accès (cf. CaseStudiesAccessNotGrantedError) —
 * réseau, 5xx… Erreur de domaine plutôt qu'une exception HTTP brute : la
 * présentation n'a pas à connaître le code de statut.
 */
export class CaseStudiesUnavailableError extends Error {
  constructor() {
    super('Les études de cas ne sont pas disponibles.')
    this.name = 'CaseStudiesUnavailableError'
  }
}
