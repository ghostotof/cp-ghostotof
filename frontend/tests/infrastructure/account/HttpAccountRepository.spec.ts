import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAccountRepository } from '../../../src/infrastructure/account/HttpAccountRepository'
import { PasswordSetupLinkError } from '../../../src/domain/account/errors/PasswordSetupLinkError'

const API_BASE_URL = 'https://api.example.test'
const TOKEN = 'deadbeef00'

describe('HttpAccountRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('validateSetupToken() appelle GET /api/account/password-setup/{token} sans credentials et résout sur 200', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ valid: true }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN)

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/account/password-setup/${TOKEN}`, { method: 'GET' })
  })

  it('validateSetupToken() lève "invalid" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 404 })))

    const error = await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(PasswordSetupLinkError)
    expect((error as PasswordSetupLinkError).reason).toBe('invalid')
  })

  it('validateSetupToken() lève "expired" sur 410', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 410 })))

    const error = await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN).catch((caught: unknown) => caught)

    expect((error as PasswordSetupLinkError).reason).toBe('expired')
  })

  it('validateSetupToken() lève "rate-limited" sur 429', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 429 })))

    const error = await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN).catch((caught: unknown) => caught)

    expect((error as PasswordSetupLinkError).reason).toBe('rate-limited')
  })

  it('completePasswordSetup() appelle POST /{token} avec le corps JSON et résout sur 204', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAccountRepository(API_BASE_URL).completePasswordSetup(TOKEN, 'NewSecurePass1')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/account/password-setup/${TOKEN}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: 'NewSecurePass1' }),
    })
  })

  it('completePasswordSetup() lève "weak-password" sur 422', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 422 })))

    const error = await new HttpAccountRepository(API_BASE_URL)
      .completePasswordSetup(TOKEN, 'short')
      .catch((caught: unknown) => caught)

    expect((error as PasswordSetupLinkError).reason).toBe('weak-password')
  })

  it('completePasswordSetup() lève "expired" sur 410 (jeton déjà consommé)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 410 })))

    const error = await new HttpAccountRepository(API_BASE_URL)
      .completePasswordSetup(TOKEN, 'NewSecurePass1')
      .catch((caught: unknown) => caught)

    expect((error as PasswordSetupLinkError).reason).toBe('expired')
  })

  it('lève "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const error = await new HttpAccountRepository(API_BASE_URL).validateSetupToken(TOKEN).catch((caught: unknown) => caught)

    expect((error as PasswordSetupLinkError).reason).toBe('unknown')
  })
})
