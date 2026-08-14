import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_STATS_REPOSITORY, useAdminStats } from '../../../../src/application/admin/stats/useAdminStats'
import type { AdminStatsRepository } from '../../../../src/domain/admin/stats/repositories/AdminStatsRepository'
import type { AdminStat } from '../../../../src/domain/admin/stats/entities/AdminStat'
import { AdminStatsError } from '../../../../src/domain/admin/stats/errors/AdminStatsError'

const STAT: AdminStat = { id: 1, locale: 'fr', value: '+50K', label: 'Lignes de code', iconKey: 'code', position: 0 }

function createStubRepository(overrides: Partial<AdminStatsRepository> = {}): AdminStatsRepository {
  return {
    list: vi.fn(async () => [STAT]),
    create: vi.fn(async () => STAT),
    update: vi.fn(async () => STAT),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminStatsRepository) {
  let captured: ReturnType<typeof useAdminStats> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminStats()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_STATS_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminStats() did not run during mount')
  }

  return captured
}

describe('useAdminStats', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminStats()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminStatsRepository/)
  })

  it('load(locale) charge la liste filtrée par locale', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    expect(composable.isLoading.value).toBe(true)
    await composable.load('fr')

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(composable.stats.value).toEqual([STAT])
    expect(composable.isLoading.value).toBe(false)
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

    await composable.create({ locale: 'fr', value: '10+', label: 'Technologies', iconKey: 'box', position: 1 })

    expect(repository.create).toHaveBeenCalledWith({ locale: 'fr', value: '10+', label: 'Technologies', iconKey: 'box', position: 1 })
    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(composable.errorMessage.value).toBeNull()
  })

  it('update() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({ update: vi.fn(async () => Promise.reject(new AdminStatsError('not-found', 'Introuvable'))) })
    const composable = mountWithComposable(repository)
    await composable.load('fr')

    await composable.update(1, { locale: 'fr', value: 'x', label: 'x', iconKey: 'x', position: 0 })

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
