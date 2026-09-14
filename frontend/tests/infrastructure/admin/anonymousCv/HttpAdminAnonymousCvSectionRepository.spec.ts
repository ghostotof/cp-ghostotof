import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAnonymousCvSectionRepository } from '../../../../src/infrastructure/admin/anonymousCv/HttpAdminAnonymousCvSectionRepository'
import { AdminAnonymousCvSectionError } from '../../../../src/domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const API_BASE_URL = 'https://api.example.test'
const SECTION_ID = '019968a0-0000-7000-8000-000000000051'
const TARGET_SECTION_ID = '019968a0-0000-7000-8000-000000000052'
const MISSING_SECTION_ID = '019968a0-0000-7000-8000-000000000999'
const GROUP_ONE = '019968b0-0000-7000-8000-0000000000b1'
const GROUP_TWO = '019968b0-0000-7000-8000-0000000000b2'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/anonymous-cv`

const API_SECTION = {
  id: SECTION_ID, locale: 'fr', translationGroup: GROUP_ONE, title: 'Backend', skills: 'Symfony',
  yearsOfExperience: 12, achievements: 'Réalisations.', position: 0,
}
const INPUT = {
  locale: 'fr', translationGroup: null, title: 'Backend', skills: 'Symfony', yearsOfExperience: 12,
  achievements: 'Réalisations.',
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminAnonymousCvSectionRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET avec credentials include et sans header CSRF', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [API_SECTION]))
    vi.stubGlobal('fetch', fetchMock)

    const sections = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).list()

    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, { method: 'GET', credentials: 'include' })
    expect(sections).toEqual([API_SECTION])
  })

  it('create() envoie POST avec le corps JSON, groupe de traduction compris et sans position', async () => {
    let sent: RequestInit | undefined
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      sent = init
      return jsonResponse(201, API_SECTION)
    })
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).create({ ...INPUT, translationGroup: GROUP_TWO })

    const body = JSON.parse(sent?.body as string) as Record<string, unknown>
    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, expect.objectContaining({ method: 'POST', credentials: 'include' }))
    expect(body.translationGroup).toBe(GROUP_TWO)
    expect(body).not.toHaveProperty('position')
  })

  it("update() cible l'id dans le chemin", async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, API_SECTION))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).update(TARGET_SECTION_ID, INPUT)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/${TARGET_SECTION_ID}`, expect.objectContaining({ method: 'PUT' }))
  })

  it("remove() envoie DELETE sur l'id", async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).remove(TARGET_SECTION_ID)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/${TARGET_SECTION_ID}`, expect.objectContaining({ method: 'DELETE' }))
  })

  it('reorder() envoie PUT …/order avec la liste complète des groupes', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).reorder([GROUP_TWO, GROUP_ONE])

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

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).reorder([GROUP_ONE]).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
  })

  it('traduit toute autre erreur d\'ordre en AdminOrderError « unknown »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(500, {})))

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).reorder([GROUP_ONE]).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('unknown')
  })

  it('traduit un 404 en erreur de domaine « not-found »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL)
      .update(MISSING_SECTION_ID, INPUT)
      .catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminAnonymousCvSectionError)
    expect((error as AdminAnonymousCvSectionError).reason).toBe('not-found')
  })

  it('traduit un 409 en erreur de domaine « translation-already-exists »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(409, { type: '/errors/translation-already-exists', detail: 'Déjà traduit.' })),
    )

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL)
      .create({ ...INPUT, translationGroup: GROUP_TWO })
      .catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminAnonymousCvSectionError)
    expect((error as AdminAnonymousCvSectionError).reason).toBe('translation-already-exists')
  })

  it('traduit un 422 /errors/unknown-translation-group en son propre motif, distinct de la validation', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { type: '/errors/unknown-translation-group', detail: 'Groupe inconnu.' })),
    )

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL)
      .create({ ...INPUT, translationGroup: GROUP_TWO })
      .catch((c: unknown) => c)

    expect((error as AdminAnonymousCvSectionError).reason).toBe('unknown-translation-group')
  })

  it('traduit un 422 en erreur de domaine « validation » — réalisations vides, par exemple', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'achievements', message: 'Cette valeur ne doit pas être vide.' }] })),
    )

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL)
      .create({ ...INPUT, achievements: '' })
      .catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminAnonymousCvSectionError)
    expect((error as AdminAnonymousCvSectionError).reason).toBe('validation')
    expect((error as AdminAnonymousCvSectionError).message).toBe('Cette valeur ne doit pas être vide.')
  })
})
