import { afterEach, describe, expect, it } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'
import { applySeoMeta } from '../../../src/presentation/router/seo'

function fakeRoute(overrides: Partial<RouteLocationNormalized>): RouteLocationNormalized {
  return {
    name: 'home',
    path: '/fr',
    params: { locale: 'fr' },
    meta: {},
    ...overrides,
  } as RouteLocationNormalized
}

function robotsContent(): string | null {
  return document.querySelector<HTMLMetaElement>('meta[name="robots"]')?.getAttribute('content') ?? null
}

describe('applySeoMeta — robots', () => {
  afterEach(() => {
    document.head.querySelectorAll('meta[name="robots"], link').forEach((node) => node.remove())
  })

  it('pose noindex, nofollow quand meta.noindex est vrai', () => {
    applySeoMeta(fakeRoute({ name: 'set-password', path: '/fr/set-password/abc', meta: { noindex: true } }))

    expect(robotsContent()).toBe('noindex, nofollow')
  })

  it('pose index, follow sur une page de contenu ordinaire', () => {
    applySeoMeta(fakeRoute({ name: 'about', path: '/fr/about' }))

    expect(robotsContent()).toBe('index, follow')
  })
})

/**
 * Audit A7 : le repli `set-password/:token?` porte encore le jeton dans
 * `to.path` le temps d'un rendu (la page nettoie l'URL juste après). Le
 * canonical et les hreflang sont construits depuis `to.path` : sans
 * `meta.canonicalPath`, le jeton serait recopié dans le DOM.
 */
describe('applySeoMeta — canonical/hreflang', () => {
  const TOKEN = 'c'.repeat(64)

  afterEach(() => {
    document.head.querySelectorAll('meta[name="robots"], link').forEach((node) => node.remove())
  })

  function linkHrefs(): string[] {
    return Array.from(document.head.querySelectorAll('link')).map((link) => link.getAttribute('href') ?? '')
  }

  it('construit canonical et hreflang depuis le chemin de la route par défaut', () => {
    applySeoMeta(fakeRoute({ name: 'about', path: '/fr/about' }))

    expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(`${window.location.origin}/fr/about`)
    expect(document.querySelector('link[rel="alternate"][hreflang="en"]')?.getAttribute('href')).toBe(
      `${window.location.origin}/en/about`,
    )
    expect(document.querySelector('link[rel="alternate"][hreflang="x-default"]')?.getAttribute('href')).toBe(
      `${window.location.origin}/fr/about`,
    )
  })

  it('meta.canonicalPath remplace le chemin réel : le jeton du repli ne fuit dans aucun lien', () => {
    applySeoMeta(
      fakeRoute({
        name: 'set-password',
        path: `/en/set-password/${TOKEN}`,
        params: { locale: 'en', token: TOKEN },
        meta: { noindex: true, canonicalPath: 'set-password' },
      }),
    )

    const hrefs = linkHrefs()
    expect(hrefs.length).toBe(4)
    for (const href of hrefs) {
      expect(href).not.toContain(TOKEN)
    }
    expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(
      `${window.location.origin}/en/set-password`,
    )
    expect(document.querySelector('link[rel="alternate"][hreflang="fr"]')?.getAttribute('href')).toBe(
      `${window.location.origin}/fr/set-password`,
    )
  })
})
