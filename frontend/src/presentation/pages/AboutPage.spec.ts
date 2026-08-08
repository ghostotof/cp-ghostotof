import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AboutPage from './AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../infrastructure/portfolio/StaticPortfolioContentRepository'

function mountAboutPage() {
  return mount(AboutPage, {
    global: {
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('AboutPage', () => {
  it("rend l'eyebrow, le titre et le message fournis par le repository", () => {
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent()
    const wrapper = mountAboutPage()

    expect(wrapper.text()).toContain(about.eyebrow)
    expect(wrapper.text()).toContain(about.title)
    expect(wrapper.text()).toContain(about.message)
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    const wrapper = mountAboutPage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })
})
