import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ExperiencePage from '../../../src/presentation/pages/ExperiencePage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

function mountExperiencePage() {
  return mount(ExperiencePage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('ExperiencePage', () => {
  it('affiche le titre et la description de la page (locale par défaut)', () => {
    const repository = new StaticPortfolioContentRepository()
    const experience = repository.getExperienceContent('fr')
    const wrapper = mountExperiencePage()

    expect(wrapper.text()).toContain(experience.eyebrow)
    expect(wrapper.text()).toContain(experience.description)
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    const wrapper = mountExperiencePage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })

  it('liste chaque technologie avec sa durée, dans une liste ordonnée', () => {
    const repository = new StaticPortfolioContentRepository()
    const experience = repository.getExperienceContent('fr')
    const wrapper = mountExperiencePage()

    expect(wrapper.find('ol').exists()).toBe(true)
    for (const technology of experience.technologies) {
      expect(wrapper.text()).toContain(technology.name)
      expect(wrapper.text()).toContain(technology.duration)
    }
  })

  it('classe les technologies par temps cumulé décroissant', () => {
    const repository = new StaticPortfolioContentRepository()
    const experience = repository.getExperienceContent('fr')
    const wrapper = mountExperiencePage()

    const renderedNames = wrapper.findAll('ol > li').map((item) => item.text())
    const expectedOrder = experience.technologies.map((technology) => technology.name)

    expect(renderedNames.map((text, index) => text.includes(expectedOrder[index]))).not.toContain(false)
  })
})
