import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { PORTFOLIO_CONTENT_REPOSITORY, usePortfolioContent } from '../../../src/application/portfolio/usePortfolioContent'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { PortfolioContentRepository } from '../../../src/domain/portfolio/repositories/PortfolioContentRepository'

function createStubRepository(): PortfolioContentRepository {
  return {
    getSiteIdentity: () => ({ brandName: 'Stub' }),
    getNavigationLinks: () => [{ label: 'Accueil', to: '/fr', isEnabled: true }],
    getHeroContent: () => ({
      eyebrow: '',
      titleLead: '',
      titleAccent: '',
      description: '',
      callsToAction: [],
      highlights: [],
    }),
    getAboutContent: () => ({
      site: { eyebrow: '', cards: [] },
      me: {
        eyebrow: '',
        technicalSubtitle: '',
        technicalCards: [],
        personalSubtitle: '',
        personalCards: [],
      },
    }),
    getFeaturedTechnologies: () => [],
    getAdditionalTechnologies: () => [],
    getQualityPrinciples: () => [],
    getQualityTraits: () => [],
    getStats: () => [],
  }
}

function mountWithComposable(repository?: PortfolioContentRepository) {
  let captured: ReturnType<typeof usePortfolioContent> | undefined

  const TestComponent = defineComponent({
    setup() {
      captured = usePortfolioContent()
      return () => h('div')
    },
  })

  mount(TestComponent, {
    global: {
      plugins: [createAppI18n()],
      provide: repository ? { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: repository } : {},
    },
  })

  return captured
}

describe('usePortfolioContent', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    expect(() => mountWithComposable()).toThrow(/PortfolioContentRepository/)
  })

  it('expose tout le contenu du portfolio, réactif à la locale, à partir du repository injecté', () => {
    const repository = createStubRepository()

    const result = mountWithComposable(repository)

    expect(result?.siteIdentity.value.brandName).toBe('Stub')
    expect(result?.navigationLinks.value).toHaveLength(1)
    expect(result?.aboutContent.value).toEqual(repository.getAboutContent('fr'))
    expect(result?.heroContent.value).toEqual(repository.getHeroContent('fr'))
  })
})
