import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpContactRepository } from '../../../src/infrastructure/contact/HttpContactRepository'
import { ContactSubmissionFailedError } from '../../../src/domain/contact/errors/ContactSubmissionFailedError'

function stubFetch(response: Partial<Response>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => response as Response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('HttpContactRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('submit() envoie une requête POST publique, sans cookie ni header CSRF', async () => {
    const fetchMock = stubFetch({ ok: true, status: 202 } as Response)

    await new HttpContactRepository('https://api.example.test').submit({
      name: 'Jane Doe',
      email: 'jane@example.com',
      message: 'Bonjour !',
      honeypot: '',
    })

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/contact', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: 'Jane Doe', email: 'jane@example.com', message: 'Bonjour !', website: '' }),
    })
  })

  it('submit() transmet le honeypot sous la clé "website" attendue par le backend', async () => {
    const fetchMock = stubFetch({ ok: true, status: 202 } as Response)

    await new HttpContactRepository('https://api.example.test').submit({
      name: 'Bot',
      email: 'bot@example.com',
      message: 'Spam',
      honeypot: 'http://spam.example',
    })

    const body = JSON.parse((fetchMock.mock.calls[0]?.[1] as { body: string }).body)
    expect(body.website).toBe('http://spam.example')
  })

  it('submit() lève ContactSubmissionFailedError sur une réponse en erreur (422, 5xx...)', async () => {
    stubFetch({ ok: false, status: 422 } as Response)

    await expect(
      new HttpContactRepository('https://api.example.test').submit({
        name: '',
        email: 'not-an-email',
        message: '',
        honeypot: '',
      }),
    ).rejects.toThrow(ContactSubmissionFailedError)
  })
})
