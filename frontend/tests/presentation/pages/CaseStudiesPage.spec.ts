import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import CaseStudiesPage from '../../../src/presentation/pages/CaseStudiesPage.vue'
import { CASE_STUDY_REPOSITORY } from '../../../src/application/caseStudies/useCaseStudies'
import { BASE_ACCESS_REPOSITORY } from '../../../src/application/baseAccess/useBaseAccess'
import type { CaseStudyRepository } from '../../../src/domain/caseStudies/repositories/CaseStudyRepository'
import type { BaseAccessRepository } from '../../../src/domain/baseAccess/repositories/BaseAccessRepository'
import type { CaseStudy } from '../../../src/domain/caseStudies/entities/CaseStudy'
import { CaseStudiesAccessNotGrantedError } from '../../../src/domain/caseStudies/errors/CaseStudiesAccessNotGrantedError'
import { BaseAccessError } from '../../../src/domain/baseAccess/errors/BaseAccessError'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

const CASE_STUDY: CaseStudy = {
  title: 'Un cache partagé qui servait des réponses à la mauvaise organisation',
  problem: 'Fuite occasionnelle de données entre organisations sous forte charge.',
  solution: 'Clé de cache incluant systématiquement `l\'identifiant de tenant`.',
  tradeoffs: 'Complexité de clé accrue.',
  measuredResult: 'Zéro fuite sur 3 mois de production.',
}

function createStubCaseStudyRepository(overrides: Partial<CaseStudyRepository> = {}): CaseStudyRepository {
  return {
    list: vi.fn(async () => [CASE_STUDY]),
    ...overrides,
  }
}

function createStubBaseAccessRepository(overrides: Partial<BaseAccessRepository> = {}): BaseAccessRepository {
  return {
    grant: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountPage(
  caseStudyRepository: CaseStudyRepository = createStubCaseStudyRepository(),
  baseAccessRepository: BaseAccessRepository = createStubBaseAccessRepository(),
) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })

  return mount(CaseStudiesPage, {
    global: {
      plugins: [router, createAppI18n()],
      provide: {
        [CASE_STUDY_REPOSITORY as symbol]: caseStudyRepository,
        [BASE_ACCESS_REPOSITORY as symbol]: baseAccessRepository,
      },
    },
  })
}

describe('CaseStudiesPage', () => {
  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    expect(mountPage().find('h1').exists()).toBe(true)
  })

  it('affiche un message de chargement pendant la récupération', () => {
    expect(mountPage().text()).toContain('Chargement des études de cas')
  })

  it('affiche titre, problème, solution, compromis et résultat mesuré une fois chargée', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain(CASE_STUDY.title)
    expect(wrapper.text()).toContain(CASE_STUDY.problem)
    expect(wrapper.text()).toContain(CASE_STUDY.tradeoffs)
    expect(wrapper.text()).toContain(CASE_STUDY.measuredResult)
  })

  it("rend la solution comme du texte, jamais du HTML (v-html interdit)", async () => {
    const repository = createStubCaseStudyRepository({
      list: vi.fn(async () => [{ ...CASE_STUDY, solution: 'Avant <img src=x onerror="alert(1)"> après.' }]),
    })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">')
  })

  it('affiche un état vide explicite quand aucune étude de cas n\'est publiée', async () => {
    const repository = createStubCaseStudyRepository({ list: vi.fn(async () => []) })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.text()).toContain('Aucune étude de cas publiée')
  })

  it('affiche un message d\'erreur générique si la récupération échoue pour une autre raison', async () => {
    const repository = createStubCaseStudyRepository({ list: vi.fn(async () => Promise.reject(new Error('boom'))) })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  describe('palier de base non encore obtenu (403)', () => {
    function mountNeedingAccess(baseAccessRepository?: BaseAccessRepository) {
      const caseStudyRepository = createStubCaseStudyRepository({
        list: vi
          .fn()
          .mockRejectedValueOnce(new CaseStudiesAccessNotGrantedError())
          .mockResolvedValue([CASE_STUDY]),
      })

      return mountPage(caseStudyRepository, baseAccessRepository)
    }

    it("propose une action explicite plutôt qu'une erreur muette", async () => {
      const wrapper = mountNeedingAccess()
      await flushPromises()

      expect(wrapper.find('button').exists()).toBe(true)
      expect(wrapper.find('article').exists()).toBe(false)
    })

    it("un clic sur l'action obtient l'accès puis affiche le contenu", async () => {
      const wrapper = mountNeedingAccess()
      await flushPromises()

      await wrapper.get('button').trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain(CASE_STUDY.title)
    })

    it('un 429 sur l\'action affiche le message de limite de débit', async () => {
      const baseAccessRepository = createStubBaseAccessRepository({
        grant: vi.fn(async () => Promise.reject(new BaseAccessError('rate-limited', 'Too many attempts'))),
      })
      const wrapper = mountNeedingAccess(baseAccessRepository)
      await flushPromises()

      await wrapper.get('button').trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain('Trop de tentatives')
    })
  })

  /**
   * Audit du DOM rendu, complémentaire du lint d'accessibilité — même
   * précaution que ContributionsPage : jsdom ne calcule pas le contraste.
   */
  it("ne présente aucune violation d'accessibilité détectable", async () => {
    const wrapper = mountPage()
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})
