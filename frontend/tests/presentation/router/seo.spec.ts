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
