import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AppLayout from '../../../src/presentation/layout/AppLayout.vue'
import LandingPage from '../../../src/presentation/pages/LandingPage.vue'
import AboutPage from '../../../src/presentation/pages/AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { AUTH_REPOSITORY } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { CV_REPOSITORY } from '../../../src/application/cv/useCvDownload'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

function createStubAuthRepository(): AuthRepository {
  return {
    login: async () => ({ username: 'jane' }),
    logout: async () => undefined,
    me: async () => null,
  }
}

function createStubCvRepository(): CvRepository {
  return {
    download: async () => ({ blob: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), filename: 'cv.pdf' }),
  }
}

async function mountLayout(initialPath = '/fr') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: LandingPage },
      { path: '/:locale(fr|en)/about', name: 'about', component: AboutPage },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  return mount(AppLayout, {
    global: {
      plugins: [router, createAppI18n()],
      provide: {
        [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
        [AUTH_REPOSITORY as symbol]: createStubAuthRepository(),
        [CV_REPOSITORY as symbol]: createStubCvRepository(),
      },
    },
  })
}

describe('AppLayout', () => {
  it("affiche l'en-tête (marque, navigation) autour de la page routée", async () => {
    const repository = new StaticPortfolioContentRepository()
    const wrapper = await mountLayout('/fr')

    expect(wrapper.text()).toContain(repository.getSiteIdentity().brandName)
    expect(wrapper.find('main').exists()).toBe(true)
  })

  it('expose un lien d\'évitement ciblant le contenu principal focusable', async () => {
    const wrapper = await mountLayout('/fr')

    const skipLink = wrapper.get('a.skip-link')
    expect(skipLink.attributes('href')).toBe('#main-content')

    const main = wrapper.get('main')
    expect(main.attributes('id')).toBe('main-content')
    expect(main.attributes('tabindex')).toBe('-1')
  })

  it("rend la landing page sur '/fr' et la page À propos sur '/fr/about'", async () => {
    const homeWrapper = await mountLayout('/fr')
    expect(homeWrapper.find('#hero').exists()).toBe(true)

    const aboutWrapper = await mountLayout('/fr/about')
    expect(aboutWrapper.find('#hero').exists()).toBe(false)
    expect(aboutWrapper.text()).toContain(new StaticPortfolioContentRepository().getAboutContent('fr').site.eyebrow)
  })
})
