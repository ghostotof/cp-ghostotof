import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_QUALITY_TRAIT_REPOSITORY, useAdminQualityTraits } from '../../../../src/application/admin/quality/useAdminQualityTraits'
import type { AdminQualityTraitRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityTraitRepository'
import type { AdminQualityTrait } from '../../../../src/domain/admin/quality/entities/AdminQualityTrait'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'

const TRAIT: AdminQualityTrait = { id: 1, locale: 'fr', label: 'Testé', position: 0 }

function createStubRepository(overrides: Partial<AdminQualityTraitRepository> = {}): AdminQualityTraitRepository {
  return {
    list: vi.fn(async () => [TRAIT]),
    create: vi.fn(async () => TRAIT),
    update: vi.fn(async () => TRAIT),
    remove: vi.fn(async () => undefined),
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

  it('load(locale) charge la liste filtrée par locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    await composable.load('fr')

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(composable.traits.value).toEqual([TRAIT])
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)

    await composable.load('fr')

    expect(composable.hasError.value).toBe(true)
  })

  it('create() appelle le repository puis recharge la locale courante', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load('fr')
    vi.mocked(repository.list).mockClear()

    await composable.create({ locale: 'fr', label: 'Documenté', position: 1 })

    expect(repository.create).toHaveBeenCalledWith({ locale: 'fr', label: 'Documenté', position: 1 })
    expect(repository.list).toHaveBeenCalledWith('fr')
  })

  it('update() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({ update: vi.fn(async () => Promise.reject(new AdminQualityError('not-found', 'Introuvable'))) })
    const composable = mountWithComposable(repository)
    await composable.load('fr')

    await composable.update(1, { locale: 'fr', label: 'x', position: 0 })

    expect(composable.errorMessage.value?.reason).toBe('not-found')
  })

  it('remove() appelle le repository puis recharge la locale courante', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load('fr')
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledWith('fr')
  })
})
