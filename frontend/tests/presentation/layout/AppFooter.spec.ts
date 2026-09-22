import { afterEach, describe, expect, it, vi } from 'vitest'
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
  afterEach(() => {
    vi.unstubAllEnvs()
  })

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

  describe('version déployée', () => {
    it("n'affiche rien quand la version est inconnue (npm run dev)", async () => {
      vi.stubEnv('VITE_APP_VERSION', '')
      const wrapper = await mountFooter()

      expect(wrapper.find('[data-testid="app-version"]').exists()).toBe(false)
    })

    it('affiche la version de la release en lien vers sa page GitHub, le build en infobulle', async () => {
      vi.stubEnv('VITE_APP_VERSION', '0.17.0-2c86b65')
      const wrapper = await mountFooter()

      const link = wrapper.find('a[data-testid="app-version"]')
      expect(link.exists()).toBe(true)
      expect(link.text()).toContain('v0.17.0')
      expect(link.attributes('href')).toBe('https://github.com/ghostotof/cp-ghostotof/releases/tag/v0.17.0')
      expect(link.attributes('title')).toContain('2c86b65')
      // Lien externe : même annonce que BaseButton pour les lecteurs d'écran.
      expect(link.attributes('target')).toBe('_blank')
      expect(link.find('.visually-hidden').text()).toBe('(nouvelle fenêtre)')
    })

    it("affiche un build local (tag = SHA) en texte, sans lien : il n'a pas de release", async () => {
      vi.stubEnv('VITE_APP_VERSION', 'a1b2c3d')
      const wrapper = await mountFooter()

      const version = wrapper.find('[data-testid="app-version"]')
      expect(version.element.tagName).toBe('SPAN')
      expect(version.text()).toContain('a1b2c3d')
      expect(version.text()).not.toContain('va1b2c3d')
      expect(wrapper.find('a[data-testid="app-version"]').exists()).toBe(false)
    })
  })
})
