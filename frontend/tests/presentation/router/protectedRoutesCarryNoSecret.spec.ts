import { describe, expect, it } from 'vitest'
import { router } from '../../../src/presentation/router'

/**
 * Aucune route `requiresAuth` ne porte de secret dans sa requête ou son fragment (#748, R6, S6).
 *
 * Pourquoi : quand la session finit sur une page protégée, le lien de reconnexion (`SignInAgainLink`) s'ouvre dans
 * un nouvel onglet dont l'URL contient `redirect=<fullPath de la page>`. Ce `fullPath` part donc au serveur du
 * frontend (nginx le journalise) et dans l'historique du navigateur. Une route protégée dont la requête ou le
 * fragment porterait un secret (un jeton d'invitation, un code) le ferait voyager dans cette URL.
 *
 * Le seul secret porté par un fragment, le jeton de `set-password`, vit sur une route publique : le garde de route
 * ne la protège pas, aucun lien de reconnexion ne la nomme.
 *
 * Ajouter une route protégée oblige à la revoir ici, avec la raison pour laquelle sa requête et son fragment ne
 * portent aucun secret. Cette liste est lue sur le routeur de l'application, en lecture seule.
 */

const ADMIN_REASON = "le backoffice n'a ni requête ni fragment qui porte un secret : ses pages se naviguent par le chemin seul"

const REVIEWED_PROTECTED_ROUTES: Record<string, string> = {
  assistant: 'aucune requête, aucun fragment : la conversation vit en mémoire',
  'mcp-connections': 'aucune requête, aucun fragment : la liste se lit par la session',
  'oauth-consent':
    "`client_id`, `redirect_uri`, `state` et `code_challenge` ne sont pas des secrets : le défi PKCE est un condensat, " +
    "`state` la valeur anti-CSRF du client, et la navigation qui a ouvert la page les a déjà envoyés au serveur du frontend",
  'admin-technologies': ADMIN_REASON,
  'admin-about': ADMIN_REASON,
  'admin-quality': ADMIN_REASON,
  'admin-contributions': ADMIN_REASON,
  'admin-incidents': ADMIN_REASON,
  'admin-anonymous-cv': ADMIN_REASON,
  'admin-case-studies': ADMIN_REASON,
  'admin-watch': ADMIN_REASON,
  'admin-users': ADMIN_REASON,
}

/** Les routes nommées dont la correspondance résolue (méta fusionnée du parent) exige une session. */
function protectedRouteNames(): string[] {
  return router
    .getRoutes()
    .map((record) => record.name)
    .filter((name): name is string => 'string' === typeof name)
    .filter((name) => true === router.resolve({ name, params: { locale: 'fr', pathMatch: 'x' } }).meta.requiresAuth)
    .sort()
}

describe('routes protégées et secrets', () => {
  it('chaque route protégée est revue', () => {
    // Si ce test échoue : la route ajoutée est `requiresAuth`, donc son `fullPath` part dans `redirect`, donc dans
    // l'URL d'un nouvel onglet que le serveur du frontend journalise. Revoir sa requête et son fragment, puis
    // l'ajouter à REVIEWED_PROTECTED_ROUTES avec la raison pour laquelle ils ne portent aucun secret.
    expect(protectedRouteNames()).toEqual(Object.keys(REVIEWED_PROTECTED_ROUTES).sort())
  })

  it("set-password, qui porte un secret dans son fragment, n'est pas protégée", () => {
    const resolved = router.resolve({ name: 'set-password', params: { locale: 'fr' } })

    expect(resolved.meta.requiresAuth).not.toBe(true)
    expect(protectedRouteNames()).not.toContain('set-password')
  })
})
