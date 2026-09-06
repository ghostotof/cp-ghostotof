import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import {
  ADMIN_CONTRIBUTION_REPOSITORY,
  useAdminContributions,
} from '../../../../src/application/admin/contributions/useAdminContributions'
import type { AdminContribution } from '../../../../src/domain/admin/contributions/entities/AdminContribution'
import type { AdminContributionRepository } from '../../../../src/domain/admin/contributions/repositories/AdminContributionRepository'
import { AdminContributionError } from '../../../../src/domain/admin/contributions/errors/AdminContributionError'

const CONTRIBUTION: AdminContribution = {
  id: 1,
  locale: 'fr',
  title: 'Retry de transport',
  project: 'symfony/ai',
  reference: 'Issue #1688',
  url: 'https://github.com/symfony/ai/issues/1688',
  summary: 'Chapeau.',
  body: 'Corps.',
  position: 0,
}

const INPUT = {
  locale: 'fr',
  title: 'Retry de transport',
  project: 'symfony/ai',
  reference: 'Issue #1688',
  url: 'https://github.com/symfony/ai/issues/1688',
  summary: 'Chapeau.',
  body: 'Corps.',
  position: 0,
}

function createStubRepository(overrides: Partial<AdminContributionRepository> = {}): AdminContributionRepository {
  return {
    list: vi.fn(async () => [CONTRIBUTION]),
    create: vi.fn(async () => CONTRIBUTION),
    update: vi.fn(async () => CONTRIBUTION),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminContributionRepository) {
  let captured: ReturnType<typeof useAdminContributions> | undefined

  const Host = defineComponent({
    setup() {
      captured = useAdminContributions()
      return () => h('div')
    },
  })

  mount(Host, { global: { provide: { [ADMIN_CONTRIBUTION_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('Le composable n\'a pas été capturé.')
  }

  return captured
}

describe('useAdminContributions', () => {
  it('charge les contributions au montage', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledOnce()
    expect(composable.contributions.value).toEqual([CONTRIBUTION])
    expect(composable.isLoading.value).toBe(false)
  })

  it('recharge la liste après une création réussie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.create(INPUT)

    expect(repository.create).toHaveBeenCalledWith(INPUT)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('recharge la liste après une suppression réussie', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('expose la raison de l\'échec d\'une mutation sans vider la liste déjà chargée', async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminContributionError('not-found', 'gone'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(1, INPUT)

    // hasError est réservé à l'échec du chargement initial : une erreur de
    // formulaire ne doit pas faire disparaître une liste correctement chargée.
    expect(composable.errorMessage.value?.reason).toBe('not-found')
    expect(composable.hasError.value).toBe(false)
    expect(composable.contributions.value).toEqual([CONTRIBUTION])
  })

  it('bascule hasError quand le chargement initial échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('down'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.contributions.value).toEqual([])
  })

  it('échoue explicitement si le repository n\'a pas été fourni', () => {
    const Host = defineComponent({
      setup() {
        useAdminContributions()
        return () => h('div')
      },
    })

    expect(() => mount(Host)).toThrow(/AdminContributionRepository/)
  })
})
