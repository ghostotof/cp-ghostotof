import { describe, expect, it } from 'vitest'
import type { RouteLocationNormalizedLoaded } from 'vue-router'
import { routeLocale } from '../../../src/presentation/router/routeLocale'
import { signInAgainLocation } from '../../../src/presentation/router/sessionEnded'
import { loginLocation } from '../../../src/presentation/router/loginLocation'
import { switchAccountLocation } from '../../../src/presentation/router/reauthentication'

/**
 * Les deux constructeurs du lien vers la connexion (#748, R6, Std3 et Std7) : avant eux, l'en-tête, le bandeau et la
 * page « Mes connexions MCP » composaient chacun leur lien, la langue lue tantôt dans la route, tantôt dans i18n.
 */

/** Le sous-ensemble de la route courante que lisent les constructeurs ; protégée (`requiresAuth`) par défaut. */
function routeAt(
  fullPath: string,
  locale?: unknown,
  requiresAuth = true,
): Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params' | 'meta'> {
  // Le cast : un paramètre de route n'est jamais `42` ni `null` pour le routeur, mais la fonction doit s'en défendre.
  return {
    fullPath,
    params: (undefined === locale ? {} : { locale }) as RouteLocationNormalizedLoaded['params'],
    meta: requiresAuth ? { requiresAuth: true } : {},
  }
}

describe('routeLocale', () => {
  it('rend la langue de la route quand elle est prise en charge', () => {
    expect(routeLocale(routeAt('/en/about', 'en'), 'fr')).toBe('en')
  })

  it("rend la langue de repli sans paramètre de langue (la page 404 n'en a pas)", () => {
    expect(routeLocale(routeAt('/oups'), 'en')).toBe('en')
  })

  it.each(['de', '', 42, ['fr', 'en'], null])('rend la langue de repli quand le paramètre vaut %j', (value) => {
    expect(routeLocale(routeAt('/x', value), 'fr')).toBe('fr')
  })
})

describe('loginLocation', () => {
  it('les deux drapeaux : expired=1 pour une session finie, reauth=1 pour une réauthentification', () => {
    const route = routeAt('/en/admin/x?a=1#f', 'en')

    expect(loginLocation(route, 'fr', 'session-ended')).toEqual({
      name: 'login',
      params: { locale: 'en' },
      query: { redirect: '/en/admin/x?a=1#f', expired: '1' },
    })
    expect(loginLocation(route, 'fr', 'reauthentication')).toEqual({
      name: 'login',
      params: { locale: 'en' },
      query: { redirect: '/en/admin/x?a=1#f', reauth: '1' },
    })
  })

  it("hors route protégée : pas de redirect, le chemin d'une page non revue ne part pas dans l'URL (F8)", () => {
    expect(loginLocation(routeAt('/fr/contact?x=1', 'fr', false), 'en', 'session-ended')).toEqual({
      name: 'login',
      params: { locale: 'fr' },
      query: { expired: '1' },
    })
  })
})

describe('signInAgainLocation', () => {
  it('la langue de la route, le chemin complet en retour, expired=1', () => {
    expect(signInAgainLocation(routeAt('/en/admin/x?a=1#f', 'en'), 'fr')).toEqual({
      name: 'login',
      params: { locale: 'en' },
      query: { redirect: '/en/admin/x?a=1#f', expired: '1' },
    })
  })

  it('sans langue dans la route, la langue de repli', () => {
    expect(signInAgainLocation(routeAt('/oups'), 'en')).toEqual({
      name: 'login',
      params: { locale: 'en' },
      query: { redirect: '/oups', expired: '1' },
    })
  })
})

describe('hors route protégée (F8)', () => {
  it.each([
    ['signInAgainLocation', signInAgainLocation, { expired: '1' }],
    ['switchAccountLocation', switchAccountLocation, { reauth: '1' }],
  ])("%s : pas de redirect, le drapeau seul", (_name, build, query) => {
    expect(build(routeAt('/fr/contact?x=1', 'fr', false), 'en')).toEqual({
      name: 'login',
      params: { locale: 'fr' },
      query,
    })
  })
})

describe('switchAccountLocation', () => {
  it('reauth=1 au lieu de expired=1, la même langue et le même retour', () => {
    expect(switchAccountLocation(routeAt('/fr/oauth/consent?state=s', 'fr'), 'en')).toEqual({
      name: 'login',
      params: { locale: 'fr' },
      query: { redirect: '/fr/oauth/consent?state=s', reauth: '1' },
    })
  })
})

describe('qui compose ces liens', () => {
  const sources = import.meta.glob('../../../src/**/*.{vue,ts}', {
    query: '?raw',
    import: 'default',
    eager: true,
  }) as Record<string, string>

  /** Chemins (relatifs à `src/`) des fichiers dont la source correspond au motif. */
  function filesMatching(pattern: RegExp): string[] {
    return Object.entries(sources)
      .filter(([, source]) => pattern.test(source))
      .map(([path]) => path.replace('../../../src/', ''))
      .sort()
  }

  it("loginLocation n'importe ni sessionEnded ni reauthentication", () => {
    // Les constantes des deux drapeaux sont déclarées dans `loginLocation.ts`, que les deux autres modules importent :
    // un seul sens, aucun cycle.
    const source = sources['../../../src/presentation/router/loginLocation.ts']
    expect(source).toBeDefined()
    expect(source).not.toMatch(/from\s+'\.\/(?:sessionEnded|reauthentication)'/)
  })

  it("aucun autre fichier ne compose ces liens : la requête n'est nommée que dans son module", () => {
    // LoginPage et le routeur lisent par `isSessionEnded` / `isReauthentication`, jamais par le nom du paramètre.
    // `loginLocation.ts` est le constructeur commun (R16) : il déclare les constantes et pose le drapeau ; les deux
    // autres modules les lisent pour `isSessionEnded` / `isReauthentication`.
    expect(filesMatching(/\bSESSION_ENDED_(?:QUERY|VALUE)\b/)).toEqual([
      'presentation/router/loginLocation.ts',
      'presentation/router/sessionEnded.ts',
    ])
    expect(filesMatching(/\bREAUTH_(?:QUERY|VALUE)\b/)).toEqual([
      'presentation/router/loginLocation.ts',
      'presentation/router/reauthentication.ts',
    ])
  })
})
