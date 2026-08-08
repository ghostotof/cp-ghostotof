import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LandingPage from './LandingPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../i18n'

function mountLandingPage() {
  return mount(LandingPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('LandingPage', () => {
  it('assemble les sections dans l\'ordre attendu (hero puis technologies)', () => {
    const wrapper = mountLandingPage()

    const sectionIds = wrapper.findAll('section').map((section) => section.attributes('id'))

    expect(sectionIds.indexOf('hero')).toBeLessThan(sectionIds.indexOf('technologies'))
  })

  it('affiche le contenu de chaque section fournie par le repository (locale par défaut)', () => {
    const repository = new StaticPortfolioContentRepository()
    const wrapper = mountLandingPage()

    expect(wrapper.text()).toContain(repository.getHeroContent('fr').titleLead)
    expect(wrapper.text()).toContain(repository.getFeaturedTechnologies('fr')[0]?.name)
  })
})
