import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpCvRepository } from '../../../src/infrastructure/cv/HttpCvRepository'
import { CvUnavailableError } from '../../../src/domain/cv/errors/CvUnavailableError'

function stubFetch(response: Partial<Response>): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => response as Response),
  )
}

describe('HttpCvRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('download() envoie une requête GET authentifiée par cookie (credentials: include)', async () => {
    const fetchMock = vi.fn(async () => ({
      ok: true,
      headers: new Headers({ 'Content-Disposition': 'attachment; filename="cv.pdf"' }),
      blob: async () => new Blob(['%PDF-1.4']),
    }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpCvRepository('https://api.example.test').download()

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/cv', {
      method: 'GET',
      credentials: 'include',
    })
  })

  it('download() extrait le nom de fichier du header Content-Disposition', async () => {
    stubFetch({
      ok: true,
      headers: new Headers({ 'Content-Disposition': 'attachment; filename="cv.pdf"' }),
      blob: async () => new Blob(['%PDF-1.4']),
    } as unknown as Response)

    const result = await new HttpCvRepository('https://api.example.test').download()

    expect(result.filename).toBe('cv.pdf')
  })

  it("download() retombe sur un nom de fichier générique si l'en-tête est absent", async () => {
    stubFetch({
      ok: true,
      headers: new Headers(),
      blob: async () => new Blob(['%PDF-1.4']),
    } as unknown as Response)

    const result = await new HttpCvRepository('https://api.example.test').download()

    expect(result.filename).toBe('cv.pdf')
  })

  it('download() lève CvUnavailableError sur une réponse en erreur (401, 404...)', async () => {
    stubFetch({ ok: false, status: 401, headers: new Headers() } as unknown as Response)

    await expect(new HttpCvRepository('https://api.example.test').download()).rejects.toThrow(CvUnavailableError)
  })
})
