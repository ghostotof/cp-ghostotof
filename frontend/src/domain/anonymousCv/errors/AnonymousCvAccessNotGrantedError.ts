/**
 * Levée sur un 401/403 : le visiteur n'a pas (encore) le palier de base
 * (ADR 0003 D6). Distincte d'AnonymousCvUnavailableError pour que la
 * présentation propose l'action qui débloque l'accès plutôt qu'un message
 * d'erreur générique.
 */
export class AnonymousCvAccessNotGrantedError extends Error {
  constructor() {
    super("L'accès au CV sans identité n'a pas encore été obtenu.")
    this.name = 'AnonymousCvAccessNotGrantedError'
  }
}
