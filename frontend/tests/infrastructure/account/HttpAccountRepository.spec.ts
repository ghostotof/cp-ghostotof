import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAccountRepository } from '../../../src/infrastructure/account/HttpAccountRepository'
import { PasswordSetupLinkError } from '../../../src/domain/account/errors/PasswordSetupLinkError'

const API_BASE_URL = 'https://api.example.test'
const TOKEN = 'deadbeef00'

/** URL et options du seul appel fait à `fetch` — échoue s'il y en a eu zéro ou plusieurs. */
function singleCall(fetchMock: ReturnType<typeof vi.fn>): { url: string; init: RequestInit } {
  expect(fetchMock).toHaveBeenCalledTimes(1)
  const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]

  return { url, init }
}

async function reasonOf(promise: Promise<void>): Promise<string> {
  const error = await promise.catch((caught: unknown) => caught)
  expect(error).toBeInstanceOf(PasswordSetupLinkError)

  return (error as PasswordSetupLinkError).reason
}

function stubStatus(status: number): void {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status })))
}

describe('HttpAccountRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('validateSetupToken()', () => {
    it('appelle POST /api/account/password-setup/validate, jeton dans le corps JSON, sans credentials, et résout sur 204', async () => {
      const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
      vi.stubGlobal('fetch', fetchMock)

      await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN)

      expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/account/password-setup/validate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN }),
      })
    })

    it("ne met jamais le jeton dans l'URL appelée (audit A7)", async () => {
      const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
      vi.stubGlobal('fetch', fetchMock)

      await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN)

      expect(singleCall(fetchMock).url).not.toContain(TOKEN)
    })

    it('lève "invalid" sur 404', async () => {
      stubStatus(404)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN))).toBe('invalid')
    })

    it('lève "expired" sur 410', async () => {
      stubStatus(410)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN))).toBe('expired')
    })

    it('lève "invalid" sur 422 : ici seul le jeton est validé (vide, trop long), c\'est donc un lien corrompu', async () => {
      stubStatus(422)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN))).toBe('invalid')
    })

    it('lève "rate-limited" sur 429', async () => {
      stubStatus(429)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN))).toBe('rate-limited')
    })

    it('lève "unknown" sur un statut inattendu', async () => {
      stubStatus(500)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN))).toBe('unknown')
    })
  })

  describe('completePasswordSetup()', () => {
    it('appelle POST /api/account/password-setup, jeton et mot de passe dans le corps JSON, et résout sur 204', async () => {
      const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
      vi.stubGlobal('fetch', fetchMock)

      await new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1')

      expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/account/password-setup`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN, password: 'NewSecurePass1' }),
      })
    })

    it("ne met jamais le jeton dans l'URL appelée (audit A7)", async () => {
      const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
      vi.stubGlobal('fetch', fetchMock)

      await new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1')

      expect(singleCall(fetchMock).url).not.toContain(TOKEN)
    })

    it('lève "invalid" sur 404', async () => {
      stubStatus(404)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1'))).toBe('invalid')
    })

    it('lève "expired" sur 410 (jeton déjà consommé)', async () => {
      stubStatus(410)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1'))).toBe('expired')
    })

    it('lève "weak-password" sur 422 : le jeton a déjà passé validate, reste le mot de passe', async () => {
      stubStatus(422)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'short'))).toBe('weak-password')
    })

    it('lève "rate-limited" sur 429', async () => {
      stubStatus(429)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1'))).toBe('rate-limited')
    })

    it('lève "unknown" sur un statut inattendu', async () => {
      stubStatus(503)

      expect(await reasonOf(new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1'))).toBe('unknown')
    })
  })
})
