import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminWatchedProductRepository } from '../../../../src/infrastructure/admin/watch/HttpAdminWatchedProductRepository'
import { AdminWatchedProductError } from '../../../../src/domain/admin/watch/errors/AdminWatchedProductError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'

const API_BASE_URL = 'https://api.example.test'
const POSTGRES_ID = '019968a0-0000-7000-8000-000000000001'
const PHP_ID = '019968a0-0000-7000-8000-000000000002'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/watch/products`

const API_PRODUCT = {
  id: POSTGRES_ID, slug: 'postgresql', label: 'PostgreSQL', versionSource: 'manual', version: '18.4', position: 0,
}
const INPUT = { slug: 'nginx', label: 'nginx', versionSource: 'manual', version: '1.30.4' }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminWatchedProductRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET sans header CSRF et mappe la position en lecture', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [API_PRODUCT]))
    vi.stubGlobal('fetch', fetchMock)

    const products = await new HttpAdminWatchedProductRepository(API_BASE_URL).list()

    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, expect.objectContaining({ credentials: 'include' }))
    expect(products).toEqual([API_PRODUCT])
  })

  /**
   * Spec 0004 D3 : le corps envoyé ne porte jamais `position` — c'est le type
   * d'entrée qui l'interdit, et ce test le pince au niveau du fil.
   */
  it('create() envoie POST avec le header CSRF et sans position', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(201, { ...API_PRODUCT, ...INPUT, position: 3 }))
    vi.stubGlobal('fetch', fetchMock)

    const created = await new HttpAdminWatchedProductRepository(API_BASE_URL).create(INPUT)

    expect(fetchMock).toHaveBeenCalledWith(
      BASE_PATH,
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify(INPUT),
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
      }),
    )
    expect(created.position).toBe(3)
  })

  it('reorder() envoie PUT …/order avec la liste complète des ids, sous la clé « ids »', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminWatchedProductRepository(API_BASE_URL).reorder([PHP_ID, POSTGRES_ID])

    expect(fetchMock).toHaveBeenCalledWith(
      `${BASE_PATH}/order`,
      expect.objectContaining({ method: 'PUT', body: JSON.stringify({ ids: [PHP_ID, POSTGRES_ID] }) }),
    )
  })

  it.each([
    ['/errors/unknown-order-entry'],
    ['/errors/incomplete-order'],
  ])('traduit un 422 %s en AdminOrderError « stale-order »', async (problemType) => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { type: problemType, detail: 'Ordre obsolète.' })))

    const error = await new HttpAdminWatchedProductRepository(API_BASE_URL).reorder([POSTGRES_ID]).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('stale-order')
  })

  it('traduit toute autre erreur d\'ordre en AdminOrderError « unknown »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(500, {})))

    const error = await new HttpAdminWatchedProductRepository(API_BASE_URL).reorder([POSTGRES_ID]).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminOrderError)
    expect((error as AdminOrderError).reason).toBe('unknown')
  })

  it('lève une erreur « conflict » sur 409, distincte d\'une erreur de saisie', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(409, { detail: 'Le produit "nginx" est déjà surveillé.' })))

    const error = await new HttpAdminWatchedProductRepository(API_BASE_URL).create(INPUT).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminWatchedProductError)
    expect((error as AdminWatchedProductError).reason).toBe('conflict')
  })
})
