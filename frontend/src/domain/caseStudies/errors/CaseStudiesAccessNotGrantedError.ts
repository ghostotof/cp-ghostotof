/**
 * Levée sur un 403 : le visiteur est authentifié (ou non) mais n'a pas
 * ROLE_USER (ADR 0003 D6, palier de base). Distincte de
 * CaseStudiesUnavailableError pour que la présentation propose l'action qui
 * débloque l'accès plutôt qu'un message d'erreur générique.
 */
export class CaseStudiesAccessNotGrantedError extends Error {
  constructor() {
    super("L'accès aux études de cas n'a pas encore été obtenu.")
    this.name = 'CaseStudiesAccessNotGrantedError'
  }
}
