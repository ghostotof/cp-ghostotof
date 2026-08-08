import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AppLayout from './AppLayout.vue'
import LandingPage from '../pages/LandingPage.vue'
import AboutPage from '../pages/AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../i18n'

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
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('AppLayout', () => {
  it("affiche l'en-tête (marque, navigation) autour de la page routée", async () => {
    const repository = new StaticPortfolioContentRepository()
    const wrapper = await mountLayout('/fr')

    expect(wrapper.text()).toContain(repository.getSiteIdentity('fr').brandName)
    expect(wrapper.find('main').exists()).toBe(true)
  })

  it("rend la landing page sur '/fr' et la page À propos sur '/fr/about'", async () => {
    const homeWrapper = await mountLayout('/fr')
    expect(homeWrapper.find('#hero').exists()).toBe(true)

    const aboutWrapper = await mountLayout('/fr/about')
    expect(aboutWrapper.find('#hero').exists()).toBe(false)
    expect(aboutWrapper.text()).toContain(new StaticPortfolioContentRepository().getAboutContent('fr').message)
  })
})
