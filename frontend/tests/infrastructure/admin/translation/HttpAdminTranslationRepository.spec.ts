import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminTranslationRepository } from '../../../../src/infrastructure/admin/translation/HttpAdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'

const API_BASE_URL = 'https://api.example.test'
const PATH = `${API_BASE_URL}/api/backoffice/translations`
const FIELDS = { title: 'Panne du broker RabbitMQ', impact: 'Le formulaire a répondu 500.' }
const TRANSLATED = { title: 'RabbitMQ broker outage', impact: 'The form answered 500.' }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function reasonOf(promise: Promise<unknown>): Promise<string> {
  const error = await promise.catch((c: unknown) => c)
  expect(error).toBeInstanceOf(AdminTranslationError)
  return (error as AdminTranslationError).reason
}

describe('HttpAdminTranslationRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('translate() envoie POST avec le dictionnaire, les cookies et le header CSRF, et rend le brouillon', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, { sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED }))
    vi.stubGlobal('fetch', fetchMock)

    const draft = await new HttpAdminTranslationRepository(API_BASE_URL).translate('fr', 'en', FIELDS)

    expect(fetchMock).toHaveBeenCalledWith(
      PATH,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
        body: JSON.stringify({ sourceLocale: 'fr', targetLocale: 'en', fields: FIELDS }),
      }),
    )
    expect(draft).toEqual({ sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED })
  })

  it('traduit un 422 en « validation », avec les violations dans le message', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'fields', message: 'Nom de champ invalide.' }] })))

    const error = await new HttpAdminTranslationRepository(API_BASE_URL).translate('fr', 'en', FIELDS).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminTranslationError)
    expect((error as AdminTranslationError).reason).toBe('validation')
    expect((error as AdminTranslationError).message).toContain('Nom de champ invalide.')
  })

  it('traduit un 429 en « rate-limited »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(429, { detail: 'Quota atteint.' })))

    expect(await reasonOf(new HttpAdminTranslationRepository(API_BASE_URL).translate('fr', 'en', FIELDS))).toBe('rate-limited')
  })

  it('traduit un 503 en « unavailable »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(503, { type: '/errors/translation-unavailable', detail: 'Indisponible.' })))

    expect(await reasonOf(new HttpAdminTranslationRepository(API_BASE_URL).translate('fr', 'en', FIELDS))).toBe('unavailable')
  })

  it('traduit tout autre échec en « unknown »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(500, {})))

    expect(await reasonOf(new HttpAdminTranslationRepository(API_BASE_URL).translate('fr', 'en', FIELDS))).toBe('unknown')
  })
})
