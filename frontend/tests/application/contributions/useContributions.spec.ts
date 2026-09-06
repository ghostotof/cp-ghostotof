import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import {
  CONTRIBUTION_REPOSITORY,
  useContributions,
} from '../../../src/application/contributions/useContributions'
import type { ContributionRepository } from '../../../src/domain/contributions/repositories/ContributionRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

const CONTRIBUTION = {
  title: 'Retry de transport, re-prompt de validation',
  project: 'symfony/ai',
  reference: 'Issue #1688',
  url: 'https://github.com/symfony/ai/issues/1688',
  summary: 'Deux opérations sous un seul mot.',
  body: 'Premier paragraphe.',
}

function createStubRepository(overrides: Partial<ContributionRepository> = {}): ContributionRepository {
  return {
    list: vi.fn(async () => [CONTRIBUTION]),
    ...overrides,
  }
}

function mountWithComposable(repository: ContributionRepository) {
  let captured: ReturnType<typeof useContributions> | undefined

  const Host = defineComponent({
    setup() {
      captured = useContributions()
      return () => h('div')
    },
  })

  const i18n = createAppI18n()
  mount(Host, {
    global: {
      plugins: [i18n],
      provide: { [CONTRIBUTION_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('Le composable n\'a pas été capturé.')
  }

  return { ...captured, i18n }
}

describe('useContributions', () => {
  it('charge les contributions au montage, pour la locale courante', async () => {
    const repository = createStubRepository()
    const { contributions, isLoading, hasError } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(contributions.value).toEqual([CONTRIBUTION])
    expect(isLoading.value).toBe(false)
    expect(hasError.value).toBe(false)
  })

  it('recharge au changement de locale', async () => {
    const repository = createStubRepository()
    const { i18n } = mountWithComposable(repository)
    await flushPromises()

    i18n.global.locale.value = 'en'
    await flushPromises()

    expect(repository.list).toHaveBeenLastCalledWith('en')
  })

  it('bascule en erreur sans faire remonter l\'exception', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const { contributions, isLoading, hasError } = mountWithComposable(repository)
    await flushPromises()

    expect(hasError.value).toBe(true)
    expect(isLoading.value).toBe(false)
    expect(contributions.value).toEqual([])
  })

  it('échoue explicitement si le repository n\'a pas été fourni', () => {
    const Host = defineComponent({
      setup() {
        useContributions()
        return () => h('div')
      },
    })

    expect(() => mount(Host, { global: { plugins: [createAppI18n()] } })).toThrow(/ContributionRepository/)
  })
})
