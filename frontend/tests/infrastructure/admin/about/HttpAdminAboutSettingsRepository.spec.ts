import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAboutSettingsRepository } from '../../../../src/infrastructure/admin/about/HttpAdminAboutSettingsRepository'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'
import { UNSUPPORTED_LOCALE_MESSAGE } from '../../../../src/infrastructure/admin/shared/supportedLocale'

const API_BASE_URL = 'https://api.example.test'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const SETTINGS_BODY = {
  locale: 'fr',
  siteEyebrow: 'À propos de ce site',
  meEyebrow: 'À propos de moi',
  technicalSubtitle: 'Techniquement',
  personalSubtitle: 'Humainement',
  hobbiesSubtitle: 'En dehors du travail',
}

describe('HttpAdminAboutSettingsRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('get() appelle GET /settings/{locale}, sans header CSRF, et mappe la réponse', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, SETTINGS_BODY))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)
    const settings = await repository.get('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/about/settings/fr`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(settings).toEqual(SETTINGS_BODY)
  })

  it('update() envoie PUT vers /settings/{locale} avec le header CSRF et le corps JSON', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, SETTINGS_BODY))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)
    await repository.update('fr', {
      siteEyebrow: 'À propos de ce site',
      meEyebrow: 'À propos de moi',
      technicalSubtitle: 'Techniquement',
      personalSubtitle: 'Humainement',
      hobbiesSubtitle: 'En dehors du travail',
    })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/settings/fr`,
      expect.objectContaining({
        method: 'PUT',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
        body: JSON.stringify(SETTINGS_BODY),
      }),
    )
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)
    const error = await repository.get('fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'siteEyebrow', message: 'This value should not be blank.' }] })))

    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)
    const error = await repository
      .update('fr', { siteEyebrow: '', meEyebrow: 'x', technicalSubtitle: 'x', personalSubtitle: 'x', hobbiesSubtitle: 'x' })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('validation')
    expect((error as AdminAboutError).message).toBe('This value should not be blank.')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)
    const error = await repository.get('fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('unknown')
  })

  // Le backend n'émet que `fr` et `en` : une autre langue est une rupture du contrat, que le dépôt dit à la frontière
  // (Q1 (A) : la raison `unknown`, aucune clé de traduction nouvelle) plutôt que de la laisser entrer dans une entité `Locale`.
  it("une langue que le site ne porte pas rend l'erreur 'unknown', en lecture comme en réponse d'écriture (#748, R214)", async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { ...SETTINGS_BODY, locale: 'de' })))
    const repository = new HttpAdminAboutSettingsRepository(API_BASE_URL)

    const getError = await repository.get('fr').catch((caught: unknown) => caught)
    const updateError = await repository
      .update('fr', {
        siteEyebrow: 'a',
        meEyebrow: 'b',
        technicalSubtitle: 'c',
        personalSubtitle: 'd',
        hobbiesSubtitle: 'e',
      })
      .catch((caught: unknown) => caught)

    for (const error of [getError, updateError]) {
      expect(error).toBeInstanceOf(AdminAboutError)
      expect((error as AdminAboutError).reason).toBe('unknown')
      expect((error as AdminAboutError).message).toBe(UNSUPPORTED_LOCALE_MESSAGE)
    }
  })
})
