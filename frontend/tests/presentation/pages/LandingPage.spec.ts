import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LandingPage from '../../../src/presentation/pages/LandingPage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { QUALITY_CONTENT_REPOSITORY } from '../../../src/application/quality/useQualityContent'
import { STATS_REPOSITORY } from '../../../src/application/stats/useStats'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { QualityContentRepository } from '../../../src/domain/quality/repositories/QualityContentRepository'
import type { StatsRepository } from '../../../src/domain/stats/repositories/StatsRepository'

const STUB_QUALITY_CONTENT = {
  principles: [{ title: 'DDD', description: 'Description DDD', iconKey: 'boxes' }],
  traits: [{ label: 'Architecture propre' }],
}

const STUB_STATS = [{ value: '+50K', label: 'Lignes de code', iconKey: 'code' }]

function createStubQualityContentRepository(
  overrides: Partial<QualityContentRepository> = {},
): QualityContentRepository {
  return {
    get: vi.fn(async () => STUB_QUALITY_CONTENT),
    ...overrides,
  }
}

function createStubStatsRepository(overrides: Partial<StatsRepository> = {}): StatsRepository {
  return {
    list: vi.fn(async () => STUB_STATS),
    ...overrides,
  }
}

function mountLandingPage(
  qualityContentRepository: QualityContentRepository = createStubQualityContentRepository(),
  statsRepository: StatsRepository = createStubStatsRepository(),
) {
  return mount(LandingPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
        [QUALITY_CONTENT_REPOSITORY as symbol]: qualityContentRepository,
        [STATS_REPOSITORY as symbol]: statsRepository,
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

  it('affiche les principes/traits de qualité et les statistiques une fois chargés', async () => {
    const wrapper = mountLandingPage()
    await flushPromises()

    expect(wrapper.text()).toContain(STUB_QUALITY_CONTENT.principles[0].title)
    expect(wrapper.text()).toContain(STUB_QUALITY_CONTENT.traits[0].label)
    expect(wrapper.text()).toContain(STUB_STATS[0].value)
  })

  it('affiche un message d\'erreur générique si la récupération de la qualité échoue', async () => {
    const repository = createStubQualityContentRepository({ get: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = mountLandingPage(repository)
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]').length).toBeGreaterThan(0)
  })

  it('affiche un message d\'erreur générique si la récupération des statistiques échoue', async () => {
    const repository = createStubStatsRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = mountLandingPage(undefined, repository)
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]').length).toBeGreaterThan(0)
  })
})
