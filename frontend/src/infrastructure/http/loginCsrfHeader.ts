/**
 * En-tête exigé par le backend (LoginCsrfRequestListener, issue #76) sur les
 * deux routes anonymes qui posent un cookie BEARER : /api/login_check et
 * /api/account/base-access. Un formulaire HTML cross-site ne peut pas poser
 * d'en-tête personnalisé, un fetch() du SPA le pose sans peine — c'est ce qui
 * empêche un site tiers de faire remplacer le jeton d'un visiteur connecté
 * par un autre (login-CSRF). Sa présence protège, pas sa valeur.
 *
 * Partagé plutôt que dupliqué : les deux appels doivent rester d'accord avec
 * le backend, et l'oubli d'un côté se traduit par un 403 en production.
 */
export const LOGIN_CSRF_HEADER = { 'X-Requested-With': 'fetch' } as const
