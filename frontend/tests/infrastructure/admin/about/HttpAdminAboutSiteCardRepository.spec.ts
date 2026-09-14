import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAboutSiteCardRepository } from '../../../../src/infrastructure/admin/about/HttpAdminAboutSiteCardRepository'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const API_BASE_URL = 'https://api.example.test'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/about/site-cards`
const CARD_ID = '019968a0-0000-7000-8000-000000000011'
const UPDATED_CARD_ID = '019968a0-0000-7000-8000-000000000012'
const MISSING_CARD_ID = '019968a0-0000-7000-8000-000000000999'
const GROUP_ONE = '019968b0-0000-7000-8000-0000000000c1'
const GROUP_TWO = '019968b0-0000-7000-8000-0000000000c2'

const INPUT = { locale: 'fr' as const, translationGroup: null, title: 'Stack', description: 'D', iconKey: 'server' }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminAboutSiteCardRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET sans filtre de locale (spec 0004, D8) et mappe la réponse (iconKey absent => null)', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(200, [
        { id: CARD_ID, locale: 'fr', translationGroup: GROUP_ONE, title: 'Architecture', description: 'D', position: 0 },
      ]),
    )
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const cards = await repository.list()

    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, { method: 'GET', credentials: 'include' })
    expect(cards).toEqual([
      {
        id: CARD_ID,
        locale: 'fr',
        translationGroup: GROUP_ONE,
        title: 'Architecture',
        description: 'D',
        iconKey: null,
        position: 0,
      },
    ])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON, sans position', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse(201, {
          id: UPDATED_CARD_ID,
          locale: 'fr',
          translationGroup: GROUP_ONE,
          title: 'Stack',
          description: 'D',
          iconKey: 'server',
          position: 1,
        }),
      ),
    )

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    await repository.create(INPUT)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      BASE_PATH,
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify(INPUT),
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
      }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse(200, {
          id: UPDATED_CARD_ID,
          locale: 'fr',
          translationGroup: GROUP_ONE,
          title: 'Stack',
          description: 'D',
          iconKey: 'server',
          position: 1,
        }),
      ),
    )

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    await repository.update(UPDATED_CARD_ID, INPUT)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${BASE_PATH}/${UPDATED_CARD_ID}`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    await repository.remove(UPDATED_CARD_ID)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${BASE_PATH}/${UPDATED_CARD_ID}`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('reorder() envoie PUT …/order avec la liste complète des groupes', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAboutSiteCardRepository(API_BASE_URL).reorder([GROUP_TWO, GROUP_ONE])

    expect(fetchMock).toHaveBeenCalledWith(
      `${BASE_PATH}/order`,
      expect.objectContaining({ method: 'PUT', body: JSON.stringify({ groups: [GROUP_TWO, GROUP_ONE] }) }),
    )
  })

  it.each([['/errors/unknown-order-entry'], ['/errors/incomplete-order']])(
    'traduit un 422 %s en AdminOrderError « stale-order »',
    async (problemType) => {
      vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { type: problemType, detail: 'Ordre obsolète.' })))

      const error = await new HttpAdminAboutSiteCardRepository(API_BASE_URL)
        .reorder([GROUP_ONE])
        .catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(AdminOrderError)
      expect((error as AdminOrderError).reason).toBe('stale-order')
    },
  )

  it("traduit toute autre erreur d'ordre en AdminOrderError « unknown »", async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(500, {})))

    const error = await new HttpAdminAboutSiteCardRepository(API_BASE_URL)
      .reorder([GROUP_ONE])
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('unknown')
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const error = await repository.update(MISSING_CARD_ID, INPUT).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('not-found')
  })

  it('traduit un 409 en erreur de domaine « translation-already-exists »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(409, { type: '/errors/translation-already-exists', detail: 'Déjà traduit.' })),
    )

    const error = await new HttpAdminAboutSiteCardRepository(API_BASE_URL)
      .create({ ...INPUT, translationGroup: GROUP_TWO })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('translation-already-exists')
  })

  it('traduit un 422 « unknown-translation-group » en ce motif plutôt qu\'en « validation »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { type: '/errors/unknown-translation-group', detail: 'Groupe inconnu.' })),
    )

    const error = await new HttpAdminAboutSiteCardRepository(API_BASE_URL)
      .create({ ...INPUT, translationGroup: GROUP_TWO })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('unknown-translation-group')
  })

  it('lève une erreur "validation" sur 422', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse(422, { violations: [{ propertyPath: 'title', message: 'This value should not be blank.' }] }),
      ),
    )

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const error = await repository.create({ ...INPUT, title: '' }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('validation')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const error = await repository.list().catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('unknown')
  })
})
