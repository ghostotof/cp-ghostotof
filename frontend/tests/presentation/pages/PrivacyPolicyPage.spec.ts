import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PrivacyPolicyPage from '../../../src/presentation/pages/PrivacyPolicyPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

function mountPrivacyPolicyPage() {
  return mount(PrivacyPolicyPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('PrivacyPolicyPage', () => {
  it('affiche le titre de la page et sa date de dernière mise à jour (locale par défaut)', () => {
    const repository = new StaticPortfolioContentRepository()
    const privacyPolicy = repository.getPrivacyPolicyContent('fr')
    const wrapper = mountPrivacyPolicyPage()

    expect(wrapper.text()).toContain(privacyPolicy.title)
    expect(wrapper.text()).toContain(privacyPolicy.lastUpdated)
  })

  it('détaille les cookies strictement nécessaires posés par le site (BEARER et XSRF-TOKEN)', () => {
    const wrapper = mountPrivacyPolicyPage()

    expect(wrapper.text()).toContain('BEARER')
    expect(wrapper.text()).toContain('XSRF-TOKEN')
  })

  it("mentionne le droit d'exercer ses droits RGPD via l'adresse de contact du site", () => {
    const wrapper = mountPrivacyPolicyPage()

    expect(wrapper.text()).toContain('contact@cp-ghostotof.com')
    expect(wrapper.text()).toContain('CNIL')
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    const wrapper = mountPrivacyPolicyPage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })

  it('rend chaque section de la politique sous forme de h2, sans saut de niveau après le h1', () => {
    const repository = new StaticPortfolioContentRepository()
    const privacyPolicy = repository.getPrivacyPolicyContent('fr')
    const wrapper = mountPrivacyPolicyPage()

    const h2Titles = wrapper.findAll('h2').map((h2) => h2.text())
    for (const section of privacyPolicy.sections) {
      expect(h2Titles).toContain(section.heading)
    }
  })
})
