/**
 * `to` est une cible de route Vue Router au format chaîne (ex. "/", "/a-propos",
 * "/#technologies" pour une ancre in-page sur la landing page) — jamais un objet
 * ou un type importé de vue-router, pour que ce fichier de domaine reste
 * indépendant de tout framework.
 */
export interface NavigationLink {
  readonly label: string
  readonly to: string
  readonly isEnabled: boolean
}
