import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import {
  EXPERIENCE_TECHNOLOGY_REPOSITORY,
  useExperienceTechnologies,
} from '../../../src/application/experience/useExperienceTechnologies'
import type { ExperienceTechnologyRepository } from '../../../src/domain/experience/repositories/ExperienceTechnologyRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

function createStubRepository(overrides: Partial<ExperienceTechnologyRepository> = {}): ExperienceTechnologyRepository {
  return {
    list: vi.fn(async () => [{ name: 'PHP', years: 13.5, duration: '~13,5 ans' }]),
    ...overrides,
  }
}

function mountWithComposable(repository: ExperienceTechnologyRepository) {
  let captured: ReturnType<typeof useExperienceTechnologies> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useExperienceTechnologies()
      return () => h('div')
    },
  })

  mount(Probe, {
    global: {
      plugins: [createAppI18n()],
      provide: { [EXPERIENCE_TECHNOLOGY_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('useExperienceTechnologies() did not run during mount')
  }

  return captured
}

describe('useExperienceTechnologies', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useExperienceTechnologies()
        return () => h('div')
      },
    })

    expect(() => mount(Probe, { global: { plugins: [createAppI18n()] } })).toThrow(/ExperienceTechnologyRepository/)
  })

  it('charge les technologies au montage', async () => {
    const repository = createStubRepository()
    const { technologies, isLoading, hasError } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.list).toHaveBeenCalledWith('fr')
    expect(technologies.value).toEqual([{ name: 'PHP', years: 13.5, duration: '~13,5 ans' }])
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
