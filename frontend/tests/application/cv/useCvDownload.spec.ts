import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { CV_REPOSITORY, useCvDownload } from '../../../src/application/cv/useCvDownload'
import type { CvRepository } from '../../../src/domain/cv/repositories/CvRepository'

function createStubRepository(overrides: Partial<CvRepository> = {}): CvRepository {
  return {
    download: vi.fn(async () => ({ blob: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), filename: 'cv.pdf' })),
    ...overrides,
  }
}

function mountWithComposable(repository: CvRepository) {
  let captured: ReturnType<typeof useCvDownload> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useCvDownload()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [CV_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useCvDownload() did not run during mount')
  }

  return captured
}

describe('useCvDownload', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useCvDownload()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/CvRepository/)
  })

  it('downloadCv() déclenche un téléchargement navigateur via une URL objet temporaire', async () => {
    const createObjectURL = vi.fn(() => 'blob:mock')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL })

    const repository = createStubRepository()
    const { downloadCv, isDownloading, hasError } = mountWithComposable(repository)

    const pending = downloadCv()
    expect(isDownloading.value).toBe(true)
    await pending

    expect(repository.download).toHaveBeenCalled()
    expect(createObjectURL).toHaveBeenCalled()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:mock')
    expect(isDownloading.value).toBe(false)
    expect(hasError.value).toBe(false)
  })

  it('downloadCv() bascule hasError à true si le repository échoue, sans lever', async () => {
    const repository = createStubRepository({ download: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const { downloadCv, isDownloading, hasError } = mountWithComposable(repository)

    await downloadCv()

    expect(hasError.value).toBe(true)
    expect(isDownloading.value).toBe(false)
  })
})
