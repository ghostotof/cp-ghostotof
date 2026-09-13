/**
 * Levée quand le CV sans identité ne peut pas être récupéré, pour une raison
 * autre que l'absence d'accès (cf. AnonymousCvAccessNotGrantedError) —
 * réseau, 5xx… Erreur de domaine plutôt qu'une exception HTTP brute : la
 * présentation n'a pas à connaître le code de statut.
 */
export class AnonymousCvUnavailableError extends Error {
  constructor() {
    super("Le CV sans identité n'est pas disponible.")
    this.name = 'AnonymousCvUnavailableError'
  }
}
