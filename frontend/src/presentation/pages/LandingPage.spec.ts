import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LandingPage from './LandingPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../infrastructure/portfolio/StaticPortfolioContentRepository'

function mountLandingPage() {
  return mount(LandingPage, {
    global: {
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

  it('affiche le contenu de chaque section fournie par le repository', () => {
    const repository = new StaticPortfolioContentRepository()
    const wrapper = mountLandingPage()

    expect(wrapper.text()).toContain(repository.getHeroContent().titleLead)
    expect(wrapper.text()).toContain(repository.getFeaturedTechnologies()[0]?.name)
  })
})
