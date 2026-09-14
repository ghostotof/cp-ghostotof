import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import {
  ADMIN_QUALITY_TRAIT_REPOSITORY,
  useAdminQualityTraits,
} from '../../../../src/application/admin/quality/useAdminQualityTraits'
import type { AdminQualityTraitRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityTraitRepository'
import type { AdminQualityTrait } from '../../../../src/domain/admin/quality/entities/AdminQualityTrait'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const TRAIT_ID = '019968a0-0000-7000-8000-000000000007'
const GROUP_ID = '019968b0-0000-7000-8000-000000000007'
const TRAIT: AdminQualityTrait = { id: TRAIT_ID, locale: 'fr', translationGroup: GROUP_ID, label: 'Testé', position: 0 }
const INPUT = { locale: 'fr' as const, translationGroup: null, label: 'Documenté' }

function createStubRepository(overrides: Partial<AdminQualityTraitRepository> = {}): AdminQualityTraitRepository {
  return {
    list: vi.fn(async () => [TRAIT]),
    create: vi.fn(async () => TRAIT),
    update: vi.fn(async () => TRAIT),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminQualityTraitRepository) {
  let captured: ReturnType<typeof useAdminQualityTraits> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminQualityTraits()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_QUALITY_TRAIT_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminQualityTraits() did not run during mount')
  }

  return captured
}

describe('useAdminQualityTraits', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminQualityTraits()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminQualityTraitRepository/)
  })

  it('charge la liste au montage, toutes langues confondues et sans argument de locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    // Spec 0004, D8 : plus de filtre de locale — le tableau les affiche toutes.
    expect(repository.list).toHaveBeenCalledWith()
    expect(composable.traits.value).toEqual([TRAIT])
    expect(composable.isLoading.value).toBe(false)
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.traits.value).toEqual([])
  })

  it('create() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.create(INPUT)

    expect(repository.create).toHaveBeenCalledWith(INPUT)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('update() propage errorMessage sans vider la liste chargée', async () => {
    const repository = createStubRepository({
      update: vi.fn(async () => Promise.reject(new AdminQualityError('not-found', 'Introuvable'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    await composable.update(TRAIT_ID, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('not-found')
    expect(composable.hasError.value).toBe(false)
    expect(composable.traits.value).toEqual([TRAIT])
  })

  it('remove() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(TRAIT_ID)

    expect(repository.remove).toHaveBeenCalledWith(TRAIT_ID)
    expect(repository.list).toHaveBeenCalledOnce()
  })

  it('délègue reorder() au repository sans recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.reorder([GROUP_ID])

    expect(repository.reorder).toHaveBeenCalledWith([GROUP_ID])
    expect(repository.list).not.toHaveBeenCalled()
  })

  it("laisse remonter l'AdminOrderError telle quelle, sans la convertir", async () => {
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
})
