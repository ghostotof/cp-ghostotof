import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_QUALITY_PRINCIPLE_REPOSITORY, useAdminQualityPrinciples } from '../../../../src/application/admin/quality/useAdminQualityPrinciples'
import type { AdminQualityPrincipleRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import type { AdminQualityPrinciple } from '../../../../src/domain/admin/quality/entities/AdminQualityPrinciple'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'

const PRINCIPLE: AdminQualityPrinciple = { id: 1, locale: 'fr', title: 'DDD', description: 'Description', iconKey: 'boxes', position: 0 }

function createStubRepository(overrides: Partial<AdminQualityPrincipleRepository> = {}): AdminQualityPrincipleRepository {
  return {
    list: vi.fn(async () => [PRINCIPLE]),
    create: vi.fn(async () => PRINCIPLE),
    update: vi.fn(async () => PRINCIPLE),
    remove: vi.fn(async () => undefined),
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

  it('load(locale) charge la liste filtrée par locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    await composable.load('fr')

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(composable.principles.value).toEqual([PRINCIPLE])
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

    await composable.create({ locale: 'fr', title: 'SOLID', description: 'D', iconKey: 'columns-3', position: 1 })

    expect(repository.create).toHaveBeenCalledWith({ locale: 'fr', title: 'SOLID', description: 'D', iconKey: 'columns-3', position: 1 })
    expect(repository.list).toHaveBeenCalledWith('fr')
  })

  it('update() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({ update: vi.fn(async () => Promise.reject(new AdminQualityError('not-found', 'Introuvable'))) })
    const composable = mountWithComposable(repository)
    await composable.load('fr')

    await composable.update(1, { locale: 'fr', title: 'x', description: 'x', iconKey: 'x', position: 0 })

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
