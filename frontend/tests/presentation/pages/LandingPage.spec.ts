import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LandingPage from '../../../src/presentation/pages/LandingPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { QUALITY_CONTENT_REPOSITORY } from '../../../src/application/quality/useQualityContent'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { QualityContentRepository } from '../../../src/domain/quality/repositories/QualityContentRepository'

const STUB_QUALITY_CONTENT = {
  principles: [{ title: 'DDD', description: 'Description DDD', iconKey: 'boxes' }],
  traits: [{ label: 'Architecture propre' }],
}

function createStubQualityContentRepository(
  overrides: Partial<QualityContentRepository> = {},
): QualityContentRepository {
  return {
    get: vi.fn(async () => STUB_QUALITY_CONTENT),
    ...overrides,
  }
}

function mountLandingPage(
  qualityContentRepository: QualityContentRepository = createStubQualityContentRepository(),
) {
  return mount(LandingPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
        [QUALITY_CONTENT_REPOSITORY as symbol]: qualityContentRepository,
      },
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

  it('affiche les principes et traits de qualité une fois chargés', async () => {
    const wrapper = mountLandingPage()
    await flushPromises()

    expect(wrapper.text()).toContain(STUB_QUALITY_CONTENT.principles[0].title)
    expect(wrapper.text()).toContain(STUB_QUALITY_CONTENT.traits[0].label)
  })

  it('affiche un message d\'erreur générique si la récupération de la qualité échoue', async () => {
    const repository = createStubQualityContentRepository({ get: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = mountLandingPage(repository)
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]').length).toBeGreaterThan(0)
  })

  it('ne monte plus le bloc de statistiques', async () => {
    const wrapper = mountLandingPage()
    await flushPromises()

    // Régression : l'accueil affichait « +50K lignes de code », « ∞ Passion »…
    // Des métriques de vanité sur une page qui parle par ailleurs de revue de
    // code et d'analyse statique — et le nombre de lignes est une mesure
    // discréditée. Le bloc a été retiré ; la page ne doit plus solliciter le
    // StatsRepository, dont l'absence d'injection ferait échouer ce montage.
    expect(wrapper.text()).not.toContain('Lignes de code')
  })
})
