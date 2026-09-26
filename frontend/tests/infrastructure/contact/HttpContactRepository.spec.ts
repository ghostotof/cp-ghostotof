import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpContactRepository } from '../../../src/infrastructure/contact/HttpContactRepository'
import { ContactSubmissionFailedError } from '../../../src/domain/contact/errors/ContactSubmissionFailedError'
import { ContactRateLimitedError } from '../../../src/domain/contact/errors/ContactRateLimitedError'
import { ContactValidationError } from '../../../src/domain/contact/errors/ContactValidationError'

function stubFetch(response: Partial<Response>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => response as Response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

const VALID_INPUT = {
  name: 'Jane Doe',
  email: 'jane@example.com',
  message: 'Bonjour, je vous contacte au sujet de...',
  honeypot: '',
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

  it('submit() résout sans erreur sur le 202 renvoyé par le backend (envoi asynchrone accepté)', async () => {
    stubFetch({ ok: true, status: 202 } as Response)

    await expect(new HttpContactRepository('https://api.example.test').submit(VALID_INPUT)).resolves.toBeUndefined()
  })

  it('submit() lève ContactValidationError sur un 422, en portant les propertyPath refusés', async () => {
    // Corps problem+json réellement renvoyé par API Platform. Les libellés du
    // backend sont en français en dur : on ne retient que `propertyPath`, la
    // page portant ses propres libellés traduits (issue #236).
    stubFetch({
      ok: false,
      status: 422,
      json: async () => ({
        detail: 'message: Votre message est trop court.',
        violations: [{ propertyPath: 'message', message: 'Votre message est trop court.' }],
      }),
    } as Partial<Response> as Response)

    const error = await new HttpContactRepository('https://api.example.test')
      .submit({ ...VALID_INPUT, message: 'trop court' })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ContactValidationError)
    expect((error as ContactValidationError).violations.map((violation) => violation.propertyPath)).toEqual(['message'])
  })

  it('submit() lève ContactValidationError avec des violations vides si le corps du 422 est absent ou illisible', async () => {
    // Un 422 sans corps exploitable reste un refus de saisie : le dégrader en
    // échec générique redirait « réessayez plus tard » à un visiteur qui doit
    // corriger sa saisie.
    stubFetch({
      ok: false,
      status: 422,
      json: async () => {
        throw new SyntaxError('Unexpected end of JSON input')
      },
    } as Partial<Response> as Response)

    const error = await new HttpContactRepository('https://api.example.test')
      .submit(VALID_INPUT)
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ContactValidationError)
    expect((error as ContactValidationError).violations).toEqual([])
  })

  it('submit() ignore les entrées de violations mal formées plutôt que de les propager', async () => {
    stubFetch({
      ok: false,
      status: 422,
      json: async () => ({ violations: [{ propertyPath: 'name' }, null, { propertyPath: 'email', message: 'x' }] }),
    } as Partial<Response> as Response)

    const error = await new HttpContactRepository('https://api.example.test')
      .submit(VALID_INPUT)
      .catch((caught: unknown) => caught)

    expect((error as ContactValidationError).violations.map((violation) => violation.propertyPath)).toEqual(['email'])
  })

  it('submit() lève ContactRateLimitedError sur un 429', async () => {
    stubFetch({ ok: false, status: 429 } as Response)

    await expect(new HttpContactRepository('https://api.example.test').submit(VALID_INPUT)).rejects.toThrow(
      ContactRateLimitedError,
    )
  })

  it('submit() lève ContactSubmissionFailedError sur une panne serveur (500)', async () => {
    stubFetch({ ok: false, status: 500 } as Response)

    await expect(new HttpContactRepository('https://api.example.test').submit(VALID_INPUT)).rejects.toThrow(
      ContactSubmissionFailedError,
    )
  })
})
