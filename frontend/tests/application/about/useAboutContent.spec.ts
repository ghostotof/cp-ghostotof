import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ABOUT_CONTENT_REPOSITORY, useAboutContent } from '../../../src/application/about/useAboutContent'
import type { AboutContentRepository } from '../../../src/domain/about/repositories/AboutContentRepository'
import { createAppI18n } from '../../../src/presentation/i18n'

const STUB_CONTENT = {
  site: { eyebrow: 'À propos', cards: [] },
  me: {
    eyebrow: 'Moi',
    technicalSubtitle: '',
    technicalCards: [],
    personalSubtitle: '',
    personalCards: [],
    hobbiesSubtitle: '',
    hobbiesCards: [],
  },
}

function createStubRepository(overrides: Partial<AboutContentRepository> = {}): AboutContentRepository {
  return {
    get: vi.fn(async () => STUB_CONTENT),
    ...overrides,
  }
}

function mountWithComposable(repository: AboutContentRepository) {
  let captured: ReturnType<typeof useAboutContent> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAboutContent()
      return () => h('div')
    },
  })

  mount(Probe, {
    global: {
      plugins: [createAppI18n()],
      provide: { [ABOUT_CONTENT_REPOSITORY as symbol]: repository },
    },
  })

  if (!captured) {
    throw new Error('useAboutContent() did not run during mount')
  }

  return captured
}

describe('useAboutContent', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAboutContent()
        return () => h('div')
      },
    })

    expect(() => mount(Probe, { global: { plugins: [createAppI18n()] } })).toThrow(/AboutContentRepository/)
  })

  it('charge le contenu au montage', async () => {
    const repository = createStubRepository()
    const { aboutContent, isLoading, hasError } = mountWithComposable(repository)

    expect(isLoading.value).toBe(true)
    await flushPromises()

    expect(repository.get).toHaveBeenCalledWith('fr')
    expect(aboutContent.value).toEqual(STUB_CONTENT)
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
