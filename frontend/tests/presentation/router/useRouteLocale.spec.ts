import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, type ComputedRef } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useRouteLocale } from '../../../src/presentation/router/useRouteLocale'
import { createAppI18n } from '../../../src/presentation/i18n'
import { isSupportedLocale, type Locale } from '../../../src/domain/portfolio/entities/Locale'
import { setUnsupportedLocale } from '../../support/unsupportedLocale'

const StubPage = { template: '<div />' }

/** La langue que `useRouteLocale()` rend sur `path`, la langue d'interface posée à `interfaceLocale` (jugée ou non). */
async function pageLocaleOn(path: string, interfaceLocale: string): Promise<Locale> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:pathMatch(.*)*', name: 'not-found', component: StubPage },
    ],
  })
  await router.push(path)
  await router.isReady()

  const i18n = createAppI18n()
  if (isSupportedLocale(interfaceLocale)) {
    i18n.global.locale.value = interfaceLocale
  } else {
    setUnsupportedLocale(i18n, interfaceLocale)
  }

  const captured: { pageLocale?: ComputedRef<Locale> } = {}
  const Probe = defineComponent({
    setup() {
      captured.pageLocale = useRouteLocale()
      return {}
    },
    template: '<div />',
  })
  mount(Probe, { global: { plugins: [router, i18n] } })

  if (undefined === captured.pageLocale) {
    throw new Error('useRouteLocale() n’a pas été appelé')
  }
  return captured.pageLocale.value
}

describe('useRouteLocale', () => {
  it('rend la langue de la route quand elle en porte une (#748, R227)', async () => {
    expect(await pageLocaleOn('/en', 'fr')).toBe('en')
  })

  it('la langue de la route l’emporte sur une langue d’interface hors du site (#748, R227)', async () => {
    expect(await pageLocaleOn('/en', 'de')).toBe('en')
  })

  it('sans `:locale`, rend la langue d’interface (#748, R227)', async () => {
    expect(await pageLocaleOn('/frogs', 'en')).toBe('en')
  })

  it('sans `:locale` sous une langue hors du site, rend `fr` (#748, R227)', async () => {
    expect(await pageLocaleOn('/frogs', 'de')).toBe('fr')
  })
})
