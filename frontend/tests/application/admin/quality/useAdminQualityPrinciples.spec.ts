import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import {
  ADMIN_QUALITY_PRINCIPLE_REPOSITORY,
  useAdminQualityPrinciples,
} from '../../../../src/application/admin/quality/useAdminQualityPrinciples'
import type { AdminQualityPrincipleRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import type { AdminQualityPrinciple } from '../../../../src/domain/admin/quality/entities/AdminQualityPrinciple'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const PRINCIPLE_ID = '019968a0-0000-7000-8000-000000000006'
const GROUP_ID = '019968b0-0000-7000-8000-000000000006'
const PRINCIPLE: AdminQualityPrinciple = {
  id: PRINCIPLE_ID, locale: 'fr', translationGroup: GROUP_ID, title: 'DDD',
  description: 'Description', iconKey: 'boxes', position: 0,
}
const INPUT = { locale: 'fr' as const, translationGroup: null, title: 'SOLID', description: 'D', iconKey: 'columns-3' }

function createStubRepository(overrides: Partial<AdminQualityPrincipleRepository> = {}): AdminQualityPrincipleRepository {
  return {
    list: vi.fn(async () => [PRINCIPLE]),
    create: vi.fn(async () => PRINCIPLE),
    update: vi.fn(async () => PRINCIPLE),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminQualityPrincipleRepository) {
  let captured: ReturnType<typeof useAdminQualityPrinciples> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminQualityPrinciples()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_QUALITY_PRINCIPLE_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminQualityPrinciples() did not run during mount')
  }

  return captured
}

describe('useAdminQualityPrinciples', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminQualityPrinciples()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminQualityPrincipleRepository/)
  })

  it('charge la liste au montage, toutes langues confondues et sans argument de locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()

    // Spec 0004, D8 : plus de filtre de locale — le tableau les affiche toutes.
    expect(repository.list).toHaveBeenCalledWith()
    expect(composable.principles.value).toEqual([PRINCIPLE])
    expect(composable.isLoading.value).toBe(false)
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.principles.value).toEqual([])
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

    await composable.update(PRINCIPLE_ID, INPUT)

    expect(composable.errorMessage.value?.reason).toBe('not-found')
    expect(composable.hasError.value).toBe(false)
    expect(composable.principles.value).toEqual([PRINCIPLE])
  })

  it('remove() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.remove(PRINCIPLE_ID)

    expect(repository.remove).toHaveBeenCalledWith(PRINCIPLE_ID)
    expect(repository.list).toHaveBeenCalledOnce()
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
