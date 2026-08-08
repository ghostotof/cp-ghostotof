import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { PORTFOLIO_CONTENT_REPOSITORY, usePortfolioContent } from './usePortfolioContent'
import type { PortfolioContentRepository } from '../../domain/portfolio/repositories/PortfolioContentRepository'

function createStubRepository(): PortfolioContentRepository {
  return {
    getSiteIdentity: () => ({ brandName: 'Stub', cvDownloadLabel: 'CV', cvDownloadHref: '/cv.pdf' }),
    getNavigationLinks: () => [{ label: 'Accueil', href: '#hero', isEnabled: true }],
    getHeroContent: () => ({
      eyebrow: '',
      titleLead: '',
      titleAccent: '',
      description: '',
      callsToAction: [],
      highlights: [],
    }),
    getAboutContent: () => ({ eyebrow: '', titleLead: '', titleAccent: '', paragraphs: [], values: [] }),
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
    global: repository ? { provide: { [PORTFOLIO_CONTENT_REPOSITORY as symbol]: repository } } : {},
  })

  return captured
}

describe('usePortfolioContent', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    expect(() => mountWithComposable()).toThrow(/PortfolioContentRepository/)
  })

  it('expose tout le contenu du portfolio à partir du repository injecté', () => {
    const repository = createStubRepository()

    const result = mountWithComposable(repository)

    expect(result?.siteIdentity.brandName).toBe('Stub')
    expect(result?.navigationLinks).toHaveLength(1)
    expect(result?.aboutContent).toEqual(repository.getAboutContent())
    expect(result?.heroContent).toEqual(repository.getHeroContent())
  })
})
