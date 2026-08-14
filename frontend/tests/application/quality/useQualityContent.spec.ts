import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { QUALITY_CONTENT_REPOSITORY, useQualityContent } from '../../../src/application/quality/useQualityContent'
import type { QualityContentRepository } from '../../../src/domain/quality/repositories/QualityContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

const STUB_CONTENT = {
  principles: [{ title: 'DDD', description: 'Description', iconKey: 'boxes' }],
  traits: [{ label: 'Architecture propre' }],
}

function createStubRepository(overrides: Partial<QualityContentRepository> = {}): QualityContentRepository {
  return {
    get: vi.fn(async () => STUB_CONTENT),
    ...overrides,
  }
}

function mountWithComposable(repository: QualityContentRepository) {
  let captured: ReturnType<typeof useQualityContent> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useQualityContent()
      return () => h('div')
    },
  })

  mount(Probe, {
    global: {
      plugins: [createAppI18n()],
      provide: { [QUALITY_CONTENT_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('useQualityContent() did not run during mount')
  }

  return captured
}

describe('useQualityContent', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useQualityContent()
        return () => h('div')
      },
    })

    expect(() => mount(Probe, { global: { plugins: [createAppI18n()] } })).toThrow(/QualityContentRepository/)
  })

  it('charge les principes et traits au montage', async () => {
    const repository = createStubRepository()
    const { principles, traits, isLoading, hasError } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.get).toHaveBeenCalledWith('fr')
    expect(principles.value).toEqual(STUB_CONTENT.principles)
    expect(traits.value).toEqual(STUB_CONTENT.traits)
    expect(isLoading.value).toBe(false)
    expect(hasError.value).toBe(false)
  })

  it('bascule hasError à true si le repository échoue, sans lever', async () => {
    const repository = createStubRepository({ get: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const { hasError, isLoading } = mountWithComposable(repository)

    await flushPromises()

    expect(hasError.value).toBe(true)
    expect(isLoading.value).toBe(false)
  })
})
