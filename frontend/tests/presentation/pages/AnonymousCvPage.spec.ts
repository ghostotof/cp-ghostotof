import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import AnonymousCvPage from '../../../src/presentation/pages/AnonymousCvPage.vue'
import { ANONYMOUS_CV_REPOSITORY } from '../../../src/application/anonymousCv/useAnonymousCv'
import { BASE_ACCESS_REPOSITORY } from '../../../src/application/baseAccess/useBaseAccess'
import type { AnonymousCvRepository } from '../../../src/domain/anonymousCv/repositories/AnonymousCvRepository'
import type { BaseAccessRepository } from '../../../src/domain/baseAccess/repositories/BaseAccessRepository'
import type { AnonymousCvSection } from '../../../src/domain/anonymousCv/entities/AnonymousCvSection'
import { AnonymousCvAccessNotGrantedError } from '../../../src/domain/anonymousCv/errors/AnonymousCvAccessNotGrantedError'
import { BaseAccessError } from '../../../src/domain/baseAccess/errors/BaseAccessError'
import { createAppI18n } from '../../../src/presentation/i18n'
import { AUTH_REPOSITORY, markBaseAccessGranted, useAuth } from '../../../src/application/auth/useAuth'
import { sessionFor } from '../../support/authSession'
import { expectNoAccessibilityViolation } from '../../support/axe'

const SECTION: AnonymousCvSection = {
  title: 'Backend PHP / Symfony',
  skills: 'Symfony 7, Doctrine ORM, `API Platform`',
  yearsOfExperience: 12,
  achievements: "Conception d'une API multi-tenant.\n\nMigration d'un monolithe en contextes bornés.",
}

function createStubAnonymousCvRepository(overrides: Partial<AnonymousCvRepository> = {}): AnonymousCvRepository {
  return {
    list: vi.fn(async () => [SECTION]),
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
  anonymousCvRepository: AnonymousCvRepository = createStubAnonymousCvRepository(),
  baseAccessRepository: BaseAccessRepository = createStubBaseAccessRepository(),
) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })

  return mount(AnonymousCvPage, {
    global: {
      plugins: [router, createAppI18n()],
      provide: {
        [ANONYMOUS_CV_REPOSITORY as symbol]: anonymousCvRepository,
        [BASE_ACCESS_REPOSITORY as symbol]: baseAccessRepository,
      },
    },
  })
}

/** Même précaution que CaseStudiesPage.spec : l'état d'auth est un singleton de module. */
async function primeAnonymous(): Promise<void> {
  const Probe = defineComponent({
    setup() {
      return { auth: useAuth() }
    },
    template: '<div />',
  })
  const wrapper = mount(Probe, {
    global: {
      provide: {
        [AUTH_REPOSITORY as symbol]: {
          login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER'] })),
          logout: vi.fn(async () => undefined),
          me: vi.fn(async () => sessionFor(null)),
        },
      },
    },
  })
  await wrapper.vm.auth.checkAuth()
  wrapper.unmount()
}

describe('AnonymousCvPage', () => {
  beforeEach(primeAnonymous)

  it('utilise un titre de niveau page (h1), la page étant routée indépendamment', () => {
    expect(mountPage().find('h1').exists()).toBe(true)
  })

  it('affiche un message de chargement pendant la récupération', () => {
    expect(mountPage().text()).toContain('Chargement du CV sans identité')
  })

  it("affiche domaine, ancienneté (pluriel), compétences et réalisations en paragraphes une fois chargée", async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain(SECTION.title)
    expect(wrapper.text()).toContain("12 ans d'expérience")
    expect(wrapper.text()).toContain('Symfony 7, Doctrine ORM')
    expect(wrapper.find('code').text()).toBe('API Platform')
    expect(wrapper.findAll('p').filter((p) => p.text().startsWith('Migration'))).toHaveLength(1)
  })

  it("accorde l'ancienneté au singulier pour 1 an", async () => {
    const wrapper = mountPage(createStubAnonymousCvRepository({ list: vi.fn(async () => [{ ...SECTION, yearsOfExperience: 1 }]) }))
    await flushPromises()

    expect(wrapper.text()).toContain("1 an d'expérience")
    expect(wrapper.text()).not.toContain('1 ans')
  })

  it('rend les réalisations comme du texte, jamais du HTML (v-html interdit)', async () => {
    const repository = createStubAnonymousCvRepository({
      list: vi.fn(async () => [{ ...SECTION, achievements: 'Avant <img src=x onerror="alert(1)"> après.' }]),
    })
    const wrapper = mountPage(repository)
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">')
  })

  it("affiche un état vide explicite quand aucune section n'est publiée", async () => {
    const wrapper = mountPage(createStubAnonymousCvRepository({ list: vi.fn(async () => []) }))
    await flushPromises()

    expect(wrapper.text()).toContain('Aucune section publiée')
  })

  it("affiche un message d'erreur générique si la récupération échoue pour une autre raison", async () => {
    const wrapper = mountPage(createStubAnonymousCvRepository({ list: vi.fn(async () => Promise.reject(new Error('boom'))) }))
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  describe('palier de base non encore obtenu', () => {
    function mountNeedingAccess(baseAccessRepository?: BaseAccessRepository) {
      const repository = createStubAnonymousCvRepository({
        list: vi.fn().mockRejectedValueOnce(new AnonymousCvAccessNotGrantedError()).mockResolvedValue([SECTION]),
      })

      return mountPage(repository, baseAccessRepository)
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

      expect(wrapper.text()).toContain(SECTION.title)
    })

    it("un 429 sur l'action affiche le message de limite de débit", async () => {
      const wrapper = mountNeedingAccess(
        createStubBaseAccessRepository({
          grant: vi.fn(async () => Promise.reject(new BaseAccessError('rate-limited', 'Too many attempts'))),
        }),
      )
      await flushPromises()

      await wrapper.get('button').trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain('Trop de tentatives')
    })

    it("l'accès obtenu ailleurs (CTA de l'en-tête) recharge le contenu sans clic sur la page", async () => {
      const wrapper = mountNeedingAccess()
      await flushPromises()
      expect(wrapper.find('article').exists()).toBe(false)

      markBaseAccessGranted()
      await flushPromises()

      expect(wrapper.text()).toContain(SECTION.title)
    })
  })

  it("ne présente aucune violation d'accessibilité détectable", async () => {
    const wrapper = mountPage()
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})
