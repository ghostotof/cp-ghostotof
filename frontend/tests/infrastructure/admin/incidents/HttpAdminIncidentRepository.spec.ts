import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminIncidentRepository } from '../../../../src/infrastructure/admin/incidents/HttpAdminIncidentRepository'
import { AdminIncidentError } from '../../../../src/domain/admin/incidents/errors/AdminIncidentError'

const API_BASE_URL = 'https://api.example.test'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/incidents`

const API_INCIDENT = {
  id: 1, locale: 'fr', title: 'RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Impact.', rootCause: 'Cause.', resolution: 'Résolution.', invariant: 'Règle.', position: 0,
}
const INPUT = {
  locale: 'fr', title: 'RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Impact.', rootCause: 'Cause.', resolution: 'Résolution.', invariant: 'Règle.', position: 0,
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminIncidentRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET avec credentials include et sans header CSRF', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [API_INCIDENT]))
    vi.stubGlobal('fetch', fetchMock)

    const incidents = await new HttpAdminIncidentRepository(API_BASE_URL).list()

    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, { method: 'GET', credentials: 'include' })
    expect(incidents).toEqual([API_INCIDENT])
  })

  it('create() envoie POST avec le corps JSON, date ISO comprise', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(201, API_INCIDENT))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminIncidentRepository(API_BASE_URL).create(INPUT)

    expect(fetchMock).toHaveBeenCalledWith(
      BASE_PATH,
      expect.objectContaining({ method: 'POST', credentials: 'include', body: JSON.stringify(INPUT) }),
    )
  })

  it('update() cible l\'id dans le chemin', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, API_INCIDENT))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminIncidentRepository(API_BASE_URL).update(7, INPUT)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/7`, expect.objectContaining({ method: 'PUT' }))
  })

  it('remove() envoie DELETE sur l\'id', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminIncidentRepository(API_BASE_URL).remove(7)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/7`, expect.objectContaining({ method: 'DELETE' }))
  })

  it('traduit un 404 en erreur de domaine « not-found »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const error = await new HttpAdminIncidentRepository(API_BASE_URL).update(99, INPUT).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminIncidentError)
    expect((error as AdminIncidentError).reason).toBe('not-found')
  })

  it('traduit un 422 en erreur de domaine « validation » — invariant vide, par exemple', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'invariant', message: 'Cette valeur ne doit pas être vide.' }] })),
    )

    const error = await new HttpAdminIncidentRepository(API_BASE_URL)
      .create({ ...INPUT, invariant: '' })
      .catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminIncidentError)
    expect((error as AdminIncidentError).reason).toBe('validation')
  })
})
