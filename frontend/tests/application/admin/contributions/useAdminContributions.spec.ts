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
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const CONTRIBUTION_ID = '019968a0-0000-7000-8000-000000000005'
const GROUP_ID = '019968b0-0000-7000-8000-000000000005'
const CONTRIBUTION: AdminContribution = {
  id: CONTRIBUTION_ID, locale: 'fr', translationGroup: GROUP_ID, title: 'Un lock npm', project: 'symfony/ai',
  reference: 'PR #42', url: 'https://example.test/pr/42', summary: 'Résumé.', body: 'Corps.', position: 0,
}
const INPUT = {
  locale: 'fr', translationGroup: null, title: 'Un lock npm', project: 'symfony/ai', reference: 'PR #42',
  url: 'https://example.test/pr/42', summary: 'Résumé.', body: 'Corps.',
}

function createStubRepository(overrides: Partial<AdminContributionRepository> = {}): AdminContributionRepository {
  return {
    list: vi.fn(async () => [CONTRIBUTION]),
    create: vi.fn(async () => CONTRIBUTION),
    update: vi.fn(async () => CONTRIBUTION),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
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
  if (!captured) throw new Error('Le composable n\'a pas été capturé.')
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

    await composable.remove(CONTRIBUTION_ID)

    expect(repository.remove).toHaveBeenCalledWith(CONTRIBUTION_ID)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('expose la raison de l\'échec d\'une mutation sans vider la liste chargée', async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminContributionError('validation', 'invalid'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(CONTRIBUTION_ID, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('validation')
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

  it('délègue reorder() au repository sans recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.reorder([GROUP_ID])

    expect(repository.reorder).toHaveBeenCalledWith([GROUP_ID])
    // useOrderDraft recharge lui-même après un enregistrement réussi ; le faire
    // ici aussi doublerait l'appel.
    expect(repository.list).not.toHaveBeenCalled()
  })

  it('laisse remonter l\'AdminOrderError telle quelle, sans la convertir', async () => {
    const repository = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    const error = await composable.reorder([GROUP_ID]).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
    expect(composable.errorMessage.value).toBeNull()
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
