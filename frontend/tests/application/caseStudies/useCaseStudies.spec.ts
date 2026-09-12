import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { CASE_STUDY_REPOSITORY, useCaseStudies } from '../../../src/application/caseStudies/useCaseStudies'
import type { CaseStudyRepository } from '../../../src/domain/caseStudies/repositories/CaseStudyRepository'
import { CaseStudiesAccessNotGrantedError } from '../../../src/domain/caseStudies/errors/CaseStudiesAccessNotGrantedError'
import { createAppI18n } from '../../../src/presentation/i18n'

const CASE_STUDY = {
  title: 'Titre',
  problem: 'Problème.',
  solution: 'Solution.',
  tradeoffs: 'Compromis.',
  measuredResult: 'Résultat.',
}

function createStubRepository(overrides: Partial<CaseStudyRepository> = {}): CaseStudyRepository {
  return {
    list: vi.fn(async () => [CASE_STUDY]),
    ...overrides,
  }
}

function mountWithComposable(repository: CaseStudyRepository) {
  let captured: ReturnType<typeof useCaseStudies> | undefined

  const Host = defineComponent({
    setup() {
      captured = useCaseStudies()
      return () => h('div')
    },
  })

  const i18n = createAppI18n()
  mount(Host, {
    global: {
      plugins: [i18n],
      provide: { [CASE_STUDY_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('Le composable n\'a pas été capturé.')
  }

  return { ...captured, i18n }
}

describe('useCaseStudies', () => {
  it('charge les études de cas au montage, pour la locale courante', async () => {
    const repository = createStubRepository()
    const { caseStudies, isLoading, hasError, needsAccess } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(caseStudies.value).toEqual([CASE_STUDY])
    expect(isLoading.value).toBe(false)
    expect(hasError.value).toBe(false)
    expect(needsAccess.value).toBe(false)
  })

  it('bascule needsAccess (pas hasError) sur CaseStudiesAccessNotGrantedError', async () => {
    const repository = createStubRepository({
      list: vi.fn(async () => Promise.reject(new CaseStudiesAccessNotGrantedError())),
    })
    const { needsAccess, hasError, isLoading } = mountWithComposable(repository)
    await flushPromises()

    expect(needsAccess.value).toBe(true)
    expect(hasError.value).toBe(false)
    expect(isLoading.value).toBe(false)
  })

  it('bascule hasError (pas needsAccess) sur une autre erreur', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const { hasError, needsAccess } = mountWithComposable(repository)
    await flushPromises()

    expect(hasError.value).toBe(true)
    expect(needsAccess.value).toBe(false)
  })

  it('reload() relance la récupération (ex. après avoir obtenu le palier de base)', async () => {
    const repository = createStubRepository({
      list: vi
        .fn()
        .mockRejectedValueOnce(new CaseStudiesAccessNotGrantedError())
        .mockResolvedValueOnce([CASE_STUDY]),
    })
    const { needsAccess, caseStudies, reload } = mountWithComposable(repository)
    await flushPromises()
    expect(needsAccess.value).toBe(true)

    await reload()

    expect(needsAccess.value).toBe(false)
    expect(caseStudies.value).toEqual([CASE_STUDY])
    expect(repository.list).toHaveBeenCalledTimes(2)
  })

  it('recharge au changement de locale', async () => {
    const repository = createStubRepository()
    const { i18n } = mountWithComposable(repository)
    await flushPromises()

    i18n.global.locale.value = 'en'
    await flushPromises()

    expect(repository.list).toHaveBeenLastCalledWith('en')
  })

  it('échoue explicitement si le repository n\'a pas été fourni', () => {
    const Host = defineComponent({
      setup() {
        useCaseStudies()
        return () => h('div')
      },
    })

    expect(() => mount(Host, { global: { plugins: [createAppI18n()] } })).toThrow(/CaseStudyRepository/)
  })
})
