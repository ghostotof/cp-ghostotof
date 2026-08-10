import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AppFooter from '../../../src/presentation/layout/AppFooter.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { SiteIdentity } from '../../../src/domain/portfolio/entities/SiteIdentity'

const siteIdentity: SiteIdentity = { brandName: 'CP-Ghostotof' }
const StubPage = { template: '<div />' }

/**
 * AppFooter dépend de Vue Router (liens vers les mentions légales et la
 * politique de confidentialité, dont la locale suit la route courante) : on
 * lui fournit une vraie instance, même pattern que AppHeader.spec.ts.
 */
async function mountFooter(initialPath = '/fr') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/legal-notice', name: 'legal-notice', component: StubPage },
      { path: '/:locale(fr|en)/privacy-policy', name: 'privacy-policy', component: StubPage },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  return mount(AppFooter, {
    props: { siteIdentity },
    global: { plugins: [router, createAppI18n()] },
  })
}

describe('AppFooter', () => {
  it('affiche un lien vers les mentions légales et un lien vers la politique de confidentialité', async () => {
    const wrapper = await mountFooter()

    expect(wrapper.find('a[href="/fr/legal-notice"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/fr/privacy-policy"]').exists()).toBe(true)
  })

  it('construit les liens légaux avec la locale de la route courante', async () => {
    const wrapper = await mountFooter('/en')

    expect(wrapper.find('a[href="/en/legal-notice"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/en/privacy-policy"]').exists()).toBe(true)
  })

  it('affiche le copyright avec le nom de marque fourni', async () => {
    const wrapper = await mountFooter()

    expect(wrapper.text()).toContain('CP-Ghostotof')
  })
})
