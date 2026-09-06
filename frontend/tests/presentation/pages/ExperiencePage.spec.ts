import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ExperiencePage from '../../../src/presentation/pages/ExperiencePage.vue'
import { PORTFOLIO_CONTENT_REPOSITORY } from '../../../src/application/portfolio/usePortfolioContent'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { EXPERIENCE_TECHNOLOGY_REPOSITORY } from '../../../src/application/experience/useExperienceTechnologies'
import type { ExperienceTechnologyRepository } from '../../../src/domain/experience/repositories/ExperienceTechnologyRepository'
import type { ExperienceTechnology } from '../../../src/domain/experience/entities/ExperienceTechnology'
import { createAppI18n } from '../../../src/presentation/i18n'

const STUB_TECHNOLOGIES: readonly ExperienceTechnology[] = [
  { name: 'PHP', years: 13.5, duration: '~13,5 ans', iconKey: 'php', isSecondary: false },
  { name: 'Symfony', years: 9.5, duration: '~9,5 ans', iconKey: 'symfony', isSecondary: false },
  { name: 'Docker', years: 6.5, duration: '~6,5 ans', iconKey: 'docker', isSecondary: false },
  { name: 'OCaml', years: 2, duration: '~2 ans', iconKey: 'ocaml', isSecondary: true },
  { name: 'Python', years: 0.5, duration: '~6 mois', iconKey: 'python', isSecondary: true },
]

function createStubExperienceTechnologyRepository(
  overrides: Partial<ExperienceTechnologyRepository> = {},
): ExperienceTechnologyRepository {
  return {
    list: vi.fn(async () => STUB_TECHNOLOGIES),
    ...overrides,
  }
}

function mountExperiencePage(experienceTechnologyRepository: ExperienceTechnologyRepository = createStubExperienceTechnologyRepository()) {
  return mount(ExperiencePage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [PORTFOLIO_CONTENT_REPOSITORY as symbol]: new StaticPortfolioContentRepository(),
        [EXPERIENCE_TECHNOLOGY_REPOSITORY as symbol]: experienceTechnologyRepository,
      },
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

  it('affiche un message de chargement pendant la récupération des technologies', () => {
    const wrapper = mountExperiencePage()

    expect(wrapper.find('ol').exists()).toBe(false)
    expect(wrapper.text()).toContain('Chargement')
  })

  it('liste chaque technologie du classement avec sa durée, dans une liste ordonnée, une fois chargée', async () => {
    const wrapper = mountExperiencePage()
    await flushPromises()

    expect(wrapper.find('ol').exists()).toBe(true)
    for (const technology of STUB_TECHNOLOGIES.filter((candidate) => !candidate.isSecondary)) {
      expect(wrapper.text()).toContain(technology.name)
      expect(wrapper.text()).toContain(technology.duration)
    }
  })

  describe('technologies repliées', () => {
    it('tient les technologies secondaires hors du classement chiffré', async () => {
      const wrapper = mountExperiencePage()
      await flushPromises()

      const rankedItems = wrapper.findAll('ol > li')

      expect(rankedItems).toHaveLength(3)
      expect(rankedItems.map((item) => item.text()).join(' ')).not.toContain('OCaml')
    })

    it('les énumère sur une seule ligne, sans jamais afficher leur durée', async () => {
      const wrapper = mountExperiencePage()
      await flushPromises()

      // Le cœur de la décision : « Python — ~6 mois » annonce publiquement
      // l'endroit où l'on débute. Le nom doit apparaître, la durée jamais.
      expect(wrapper.text()).toContain('Également pratiqués au fil du parcours :')
      expect(wrapper.text()).toContain('OCaml, Python')
      expect(wrapper.text()).not.toContain('~6 mois')
      expect(wrapper.text()).not.toContain('~2 ans')
    })

    it('masque la ligne quand aucune technologie n\'est repliée', async () => {
      const repository = createStubExperienceTechnologyRepository({
        list: vi.fn(async () => STUB_TECHNOLOGIES.filter((technology) => !technology.isSecondary)),
      })
      const wrapper = mountExperiencePage(repository)
      await flushPromises()

      expect(wrapper.text()).not.toContain('Également pratiqués au fil du parcours :')
    })

    it('calcule les barres de proportion sur le seul classement', async () => {
      const wrapper = mountExperiencePage()
      await flushPromises()

      // Sans cette exclusion, une technologie repliée à 20 ans écraserait
      // visuellement toutes les barres du classement.
      const widths = wrapper.findAll('.experience-bar__fill').map((bar) => bar.attributes('style'))

      expect(widths[0]).toContain('width: 100%')
    })
  })

  it('classe les technologies par temps cumulé décroissant', async () => {
    const wrapper = mountExperiencePage()
    await flushPromises()

    const renderedNames = wrapper.findAll('ol > li').map((item) => item.text())
    const expectedOrder = STUB_TECHNOLOGIES.map((technology) => technology.name)

    expect(renderedNames.map((text, index) => text.includes(expectedOrder[index]))).not.toContain(false)
  })

  it('affiche un message d\'erreur générique si la récupération des technologies échoue', async () => {
    const repository = createStubExperienceTechnologyRepository({
      list: vi.fn(async () => Promise.reject(new Error('unavailable'))),
    })
    const wrapper = mountExperiencePage(repository)
    await flushPromises()

    expect(wrapper.find('ol').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
