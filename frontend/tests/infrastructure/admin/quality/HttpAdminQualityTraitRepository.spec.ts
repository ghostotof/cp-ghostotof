import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminQualityTraitRepository } from '../../../../src/infrastructure/admin/quality/HttpAdminQualityTraitRepository'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const API_BASE_URL = 'https://api.example.test'
const TRAIT_ID = '019968a0-0000-7000-8000-000000000031'
const UPDATED_TRAIT_ID = '019968a0-0000-7000-8000-000000000032'
const MISSING_TRAIT_ID = '019968a0-0000-7000-8000-000000000999'
const GROUP_ONE = '019968b0-0000-7000-8000-0000000000d1'
const GROUP_TWO = '019968b0-0000-7000-8000-0000000000d2'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/quality/traits`

const API_TRAIT = { id: TRAIT_ID, locale: 'fr', translationGroup: GROUP_ONE, label: 'Testé', position: 0 }
const INPUT = { locale: 'fr' as const, translationGroup: null, label: 'Documenté' }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminQualityTraitRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET sans filtre de locale, sans header CSRF, et mappe le groupe de traduction', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [API_TRAIT]))
    vi.stubGlobal('fetch', fetchMock)

    const traits = await new HttpAdminQualityTraitRepository(API_BASE_URL).list()

    // Spec 0004, D8 : le tableau affiche toutes les langues, donc plus aucun `?locale=`.
    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, { method: 'GET', credentials: 'include' })
    expect(traits).toEqual([{ id: TRAIT_ID, locale: 'fr', translationGroup: GROUP_ONE, label: 'Testé', position: 0 }])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(201, { ...API_TRAIT, id: UPDATED_TRAIT_ID })))

    await new HttpAdminQualityTraitRepository(API_BASE_URL).create(INPUT)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      BASE_PATH,
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { ...API_TRAIT, id: UPDATED_TRAIT_ID })))

    await new HttpAdminQualityTraitRepository(API_BASE_URL).update(UPDATED_TRAIT_ID, INPUT)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${BASE_PATH}/${UPDATED_TRAIT_ID}`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))

    await new HttpAdminQualityTraitRepository(API_BASE_URL).remove(UPDATED_TRAIT_ID)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${BASE_PATH}/${UPDATED_TRAIT_ID}`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('reorder() envoie PUT …/order avec la liste complète des groupes', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminQualityTraitRepository(API_BASE_URL).reorder([GROUP_TWO, GROUP_ONE])

    expect(fetchMock).toHaveBeenCalledWith(
      `${BASE_PATH}/order`,
      expect.objectContaining({ method: 'PUT', body: JSON.stringify({ groups: [GROUP_TWO, GROUP_ONE] }) }),
    )
  })

  it.each([
    ['/errors/unknown-order-entry'],
    ['/errors/incomplete-order'],
  ])('traduit un 422 %s en AdminOrderError « stale-order »', async (problemType) => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { type: problemType, detail: 'Ordre obsolète.' })))

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL).reorder([GROUP_ONE]).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
  })

  it('traduit toute autre erreur d\'ordre en AdminOrderError « unknown »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(500, {})))

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL).reorder([GROUP_ONE]).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('unknown')
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL)
      .update(MISSING_TRAIT_ID, INPUT)
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('not-found')
  })

  it('traduit un 409 en erreur de domaine « translation-already-exists »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(409, { type: '/errors/translation-already-exists', detail: 'Déjà traduit.' })),
    )

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL)
      .create({ ...INPUT, translationGroup: GROUP_TWO })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('translation-already-exists')
  })

  it('traduit un 422 /errors/unknown-translation-group en son propre motif, distinct de la validation', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { type: '/errors/unknown-translation-group', detail: 'Groupe inconnu.' })),
    )

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL)
      .create({ ...INPUT, translationGroup: GROUP_TWO })
      .catch((caught: unknown) => caught)

    expect((error as AdminQualityError).reason).toBe('unknown-translation-group')
  })

  it('lève une erreur "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'label', message: 'This value should not be blank.' }] })))

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL)
      .create({ ...INPUT, label: '' })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('validation')
    expect((error as AdminQualityError).message).toBe('This value should not be blank.')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const error = await new HttpAdminQualityTraitRepository(API_BASE_URL).list().catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('unknown')
  })
})
