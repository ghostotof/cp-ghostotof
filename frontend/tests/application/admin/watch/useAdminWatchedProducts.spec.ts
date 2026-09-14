import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import {
  ADMIN_WATCHED_PRODUCT_REPOSITORY,
  useAdminWatchedProducts,
} from '../../../../src/application/admin/watch/useAdminWatchedProducts'
import type { AdminWatchedProduct } from '../../../../src/domain/admin/watch/entities/AdminWatchedProduct'
import type { AdminWatchedProductRepository } from '../../../../src/domain/admin/watch/repositories/AdminWatchedProductRepository'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const POSTGRES_ID = '019968a0-0000-7000-8000-000000000001'
const POSTGRES: AdminWatchedProduct = {
  id: POSTGRES_ID, slug: 'postgresql', label: 'PostgreSQL', versionSource: 'manual', version: '18.4', position: 0,
}

function createStubRepository(overrides: Partial<AdminWatchedProductRepository> = {}): AdminWatchedProductRepository {
  return {
    list: vi.fn(async () => [POSTGRES]),
    create: vi.fn(async () => POSTGRES),
    update: vi.fn(async () => POSTGRES),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminWatchedProductRepository) {
  let captured: ReturnType<typeof useAdminWatchedProducts> | undefined
  const Host = defineComponent({
    setup() {
      captured = useAdminWatchedProducts()
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [ADMIN_WATCHED_PRODUCT_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error('Le composable n\'a pas été capturé.')
  return captured
}

describe('useAdminWatchedProducts', () => {
  it('charge la liste au montage', async () => {
    const composable = mountWithComposable(createStubRepository())
    await flushPromises()

    expect(composable.products.value).toEqual([POSTGRES])
    expect(composable.isLoading.value).toBe(false)
  })

  it('délègue reorder() au repository sans recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await flushPromises()
    vi.mocked(repository.list).mockClear()

    await composable.reorder([POSTGRES_ID])

    expect(repository.reorder).toHaveBeenCalledWith([POSTGRES_ID])
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

    const error = await composable.reorder([POSTGRES_ID]).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
    expect(composable.errorMessage.value).toBeNull()
  })
})
