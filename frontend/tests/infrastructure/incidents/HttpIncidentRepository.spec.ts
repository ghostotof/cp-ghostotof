import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpIncidentRepository } from '../../../src/infrastructure/incidents/HttpIncidentRepository'
import { IncidentsUnavailableError } from '../../../src/domain/incidents/errors/IncidentsUnavailableError'

const API_BASE_URL = 'https://api.example.test'

const PAYLOAD = [
  {
    title: 'RabbitMQ en CrashLoopBackOff',
    version: 'v0.5.0',
    occurredAt: '2026-09-03',
    impact: 'Formulaire de contact en 500.',
    rootCause: 'Cookie Erlang accessible au groupe.',
    resolution: 'Correction du mode du fichier.',
    invariant: 'Un securityContext se valide par un déploiement réel.',
  },
]

describe('HttpIncidentRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('list() appelle GET /api/incidents/{locale} sans credentials (endpoint public)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => [] }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpIncidentRepository(API_BASE_URL).list('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/incidents/fr`, { method: 'GET' })
  })

  it('list() suit la locale demandée', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => [] }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpIncidentRepository(API_BASE_URL).list('en')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/incidents/en`, { method: 'GET' })
  })

  it('list() renvoie les incidents tels que servis par l\'API', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => PAYLOAD }) as unknown as Response))

    expect(await new HttpIncidentRepository(API_BASE_URL).list('fr')).toEqual(PAYLOAD)
  })

  it('list() lève une erreur de domaine quand la réponse est en échec', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => [] }) as unknown as Response))

    await expect(new HttpIncidentRepository(API_BASE_URL).list('fr')).rejects.toBeInstanceOf(IncidentsUnavailableError)
  })
})
