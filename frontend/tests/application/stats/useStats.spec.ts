import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { STATS_REPOSITORY, useStats } from '../../../src/application/stats/useStats'
import type { StatsRepository } from '../../../src/domain/stats/repositories/StatsRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

const STUB_STATS = [{ value: '+50K', label: 'Lignes de code', iconKey: 'code' }]

function createStubRepository(overrides: Partial<StatsRepository> = {}): StatsRepository {
  return {
    list: vi.fn(async () => STUB_STATS),
    ...overrides,
  }
}

function mountWithComposable(repository: StatsRepository) {
  let captured: ReturnType<typeof useStats> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useStats()
      return () => h('div')
    },
  })

  mount(Probe, {
    global: {
      plugins: [createAppI18n()],
      provide: { [STATS_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('useStats() did not run during mount')
  }

  return captured
}

describe('useStats', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useStats()
        return () => h('div')
      },
    })

    expect(() => mount(Probe, { global: { plugins: [createAppI18n()] } })).toThrow(/StatsRepository/)
  })

  it('charge les statistiques au montage', async () => {
    const repository = createStubRepository()
    const { stats, isLoading, hasError } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(stats.value).toEqual(STUB_STATS)
    expect(isLoading.value).toBe(false)
    expect(hasError.value).toBe(false)
  })

  it('bascule hasError à true si le repository échoue, sans lever', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const { hasError, isLoading } = mountWithComposable(repository)

    await flushPromises()

    expect(hasError.value).toBe(true)
    expect(isLoading.value).toBe(false)
  })
})
