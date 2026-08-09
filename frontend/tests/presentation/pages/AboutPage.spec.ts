import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AboutPage from '../../../src/presentation/pages/AboutPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

function mountAboutPage() {
  return mount(AboutPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('AboutPage', () => {
  it("rend la section « À propos de ce site » avec son titre et ses cartes (locale par défaut)", () => {
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    expect(wrapper.text()).toContain(about.site.eyebrow)
    for (const card of about.site.cards) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it("rend la section « À propos de moi », avec un volet technique et un volet humain", () => {
    const repository = new StaticPortfolioContentRepository()
    const about = repository.getAboutContent('fr')
    const wrapper = mountAboutPage()

    expect(wrapper.text()).toContain(about.me.eyebrow)
    expect(wrapper.text()).toContain(about.me.technicalSubtitle)
    expect(wrapper.text()).toContain(about.me.personalSubtitle)
    for (const card of [...about.me.technicalCards, ...about.me.personalCards]) {
      expect(wrapper.text()).toContain(card.title)
      expect(wrapper.text()).toContain(card.description)
    }
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    const wrapper = mountAboutPage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })
})
