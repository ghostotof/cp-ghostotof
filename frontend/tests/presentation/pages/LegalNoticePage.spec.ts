import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LegalNoticePage from '../../../src/presentation/pages/LegalNoticePage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

function mountLegalNoticePage() {
  return mount(LegalNoticePage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository() },
    },
  })
}

describe('LegalNoticePage', () => {
  it('affiche le titre de la page et sa date de dernière mise à jour (locale par défaut)', () => {
    const repository = new StaticPortfolioContentRepository()
    const legalNotice = repository.getLegalNoticeContent('fr')
    const wrapper = mountLegalNoticePage()

    expect(wrapper.text()).toContain(legalNotice.title)
    expect(wrapper.text()).toContain(legalNotice.lastUpdated)
  })

  it("précise le statut d'éditeur non professionnel, sans divulguer d'identité publique (cohérent avec l'objectif de confidentialité avant authentification)", () => {
    const wrapper = mountLegalNoticePage()

    expect(wrapper.text()).toContain('non professionnel')
    expect(wrapper.text()).toContain('contact@cp-ghostotof.com')
  })

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    const wrapper = mountLegalNoticePage()

    expect(wrapper.find('h1').exists()).toBe(true)
  })

  it("rend chaque section légale sous forme de h2, sans saut de niveau après le h1", () => {
    const repository = new StaticPortfolioContentRepository()
    const legalNotice = repository.getLegalNoticeContent('fr')
    const wrapper = mountLegalNoticePage()

    const h2Titles = wrapper.findAll('h2').map((h2) => h2.text())
    for (const section of legalNotice.sections) {
      expect(h2Titles).toContain(section.heading)
    }
  })
})
