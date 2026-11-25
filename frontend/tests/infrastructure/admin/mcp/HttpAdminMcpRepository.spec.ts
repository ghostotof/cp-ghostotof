import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminMcpRepository } from '../../../../src/infrastructure/admin/mcp/HttpAdminMcpRepository'
import { AdminMcpError, type AdminMcpErrorReason } from '../../../../src/domain/admin/mcp/errors/AdminMcpError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { AccessForbiddenError } from '../../../../src/domain/auth/errors/AccessForbiddenError'
import type { AdminMcpClientInput } from '../../../../src/domain/admin/mcp/repositories/AdminMcpRepository'

const API_BASE_URL = 'https://api.example.test'
const CLIENTS_URL = `${API_BASE_URL}/api/backoffice/mcp/clients`
const CONNECTIONS_URL = `${API_BASE_URL}/api/backoffice/mcp/connections`

const PREREGISTERED_ID = '019968a0-0000-7000-8000-0000000000a1'
const CIMD_ID = '019968a0-0000-7000-8000-0000000000a2'
const MISSING_ID = '019968a0-0000-7000-8000-0000000000ff'
const ACTIVE_CONNECTION_ID = '019968a0-0000-7000-8000-0000000000c1'
const REVOKED_CONNECTION_ID = '019968a0-0000-7000-8000-0000000000c2'

/** Une ligne `preregistered` telle que le serveur l'écrit (`BackofficeMcpClientResource`). */
const preregisteredRow = {
  id: PREREGISTERED_ID,
  clientId: 'client-de-demonstration',
  name: 'Client de démonstration',
  redirectUris: ['https://client.example.test/callback', 'http://127.0.0.1:33418/callback'],
  registration: 'preregistered',
  registeredAt: '2026-11-20T10:00:00+00:00',
  activeConnections: 2,
  shadowsVerifiedDomain: false,
}

/** Une ligne `cimd` : son `clientId` est l'URL de son document de métadonnées. */
const cimdRow = {
  id: CIMD_ID,
  clientId: 'https://cimd.example.test/client.json',
  name: 'Client déclaré par document',
  redirectUris: ['https://cimd.example.test/callback'],
  registration: 'cimd',
  registeredAt: '2026-11-21T08:30:00+00:00',
  activeConnections: 0,
  shadowsVerifiedDomain: false,
}

/** Une connexion active, puis une révoquée : `expiresAt` reste rendue pour les deux (décision de l'auteur). */
const activeConnectionRow = {
  id: ACTIVE_CONNECTION_ID,
  account: 'compte-a',
  clientId: 'client-de-demonstration',
  clientName: 'Client de démonstration',
  clientRegistration: 'preregistered',
  grantedAt: '2026-11-22T09:00:00+00:00',
  codeExchanged: true,
  lastUsedAt: null,
  expiresAt: '2026-12-22T09:00:00+00:00',
  state: 'active',
  revokedAt: null,
  revocationCause: null,
}

const revokedConnectionRow = {
  id: REVOKED_CONNECTION_ID,
  account: 'compte-b',
  clientId: 'https://cimd.example.test/client.json',
  clientName: 'Client déclaré par document',
  clientRegistration: 'cimd',
  grantedAt: '2026-11-10T09:00:00+00:00',
  codeExchanged: false,
  lastUsedAt: '2026-11-11T09:00:00+00:00',
  expiresAt: '2026-12-10T09:00:00+00:00',
  state: 'revoked',
  revokedAt: '2026-11-12T09:00:00+00:00',
  revocationCause: 'person',
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** Remplace `fetch` par une doublure qui répond toujours ce statut et ce corps ; la rend pour inspecter l'appel. */
function respondWith(status: number, body: unknown) {
  const fetchMock = vi.fn(async () => jsonResponse(status, body))
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

/** Attend le rejet et rend la raison de l'`AdminMcpError` levée (le test échoue si c'est une autre erreur). */
async function rejectionReason(promise: Promise<unknown>): Promise<AdminMcpErrorReason> {
  const error = await promise.then(
    () => null,
    (caught: unknown) => caught,
  )
  expect(error).toBeInstanceOf(AdminMcpError)

  return error instanceof AdminMcpError ? error.reason : 'unknown'
}

describe('HttpAdminMcpRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  describe('listClients()', () => {
    it('lit GET /api/backoffice/mcp/clients avec les cookies et rend chaque client tel que le serveur l\'écrit', async () => {
      const fetchMock = respondWith(200, [preregisteredRow, cimdRow])

      const clients = await new HttpAdminMcpRepository(API_BASE_URL).listClients()

      expect(fetchMock).toHaveBeenCalledWith(CLIENTS_URL, { method: 'GET', credentials: 'include' })
      expect(clients).toEqual([preregisteredRow, cimdRow])
    })

    it('rend une ligne pré-enregistrée qui masque un domaine vérifié avec son drapeau', async () => {
      respondWith(200, [{ ...preregisteredRow, shadowsVerifiedDomain: true }])

      const [client] = await new HttpAdminMcpRepository(API_BASE_URL).listClients()

      expect(client?.shadowsVerifiedDomain).toBe(true)
    })

    it('ne refuse pas une ligne cimd qui porterait le drapeau à vrai : le serveur juge, le frontend ne recalcule rien (R6)', async () => {
      respondWith(200, [{ ...cimdRow, shadowsVerifiedDomain: true }])

      const [client] = await new HttpAdminMcpRepository(API_BASE_URL).listClients()

      expect(client).toEqual({ ...cimdRow, shadowsVerifiedDomain: true })
    })

    it('testAnUnknownRegistrationIsRefused : un mode d\'enregistrement inconnu fait échouer la liste en `unknown`, jamais converti', async () => {
      respondWith(200, [preregisteredRow, { ...cimdRow, registration: 'dynamic' }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('refuse un drapeau shadowsVerifiedDomain qui n\'est pas un booléen (R6)', async () => {
      respondWith(200, [{ ...preregisteredRow, shadowsVerifiedDomain: 'false' }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('refuse une redirection qui n\'est pas une chaîne', async () => {
      respondWith(200, [{ ...preregisteredRow, redirectUris: ['https://client.example.test/callback', 42] }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('refuse un compte de connexions actives qui n\'est pas un entier positif ou nul', async () => {
      respondWith(200, [{ ...preregisteredRow, activeConnections: -1 }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('refuse un corps qui n\'est pas une liste', async () => {
      respondWith(200, { clients: [preregisteredRow] })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('refuse un 200 qui n\'est pas du JSON (un proxy qui répond du HTML)', async () => {
      vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>maintenance</html>', { status: 200 })))

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('lit un statut inattendu (500) comme `unknown`', async () => {
      respondWith(500, { detail: 'Internal Server Error' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listClients())).toBe('unknown')
    })

    it('laisse traverser la session finie (401) levée par BackofficeHttpClient, jamais traduite', async () => {
      respondWith(401, { code: 401, message: 'Expired JWT Token' })

      const refused = new HttpAdminMcpRepository(API_BASE_URL).listClients()

      await expect(refused).rejects.toBeInstanceOf(SessionEndedError)
      await expect(refused).rejects.not.toBeInstanceOf(AdminMcpError)
    })

    it('laisse traverser le refus réel (403) levé par BackofficeHttpClient, jamais traduit', async () => {
      respondWith(403, { detail: 'Access Denied.' })

      const refused = new HttpAdminMcpRepository(API_BASE_URL).listClients()

      await expect(refused).rejects.toBeInstanceOf(AccessForbiddenError)
      await expect(refused).rejects.not.toBeInstanceOf(AdminMcpError)
    })
  })

  describe('registerClient()', () => {
    const input: AdminMcpClientInput = {
      clientId: 'client-de-demonstration',
      name: 'Client de démonstration',
      redirectUris: ['https://client.example.test/callback'],
    }

    it('envoie POST avec le jeton CSRF et rend le client créé (aucune connexion active)', async () => {
      const created = { ...preregisteredRow, redirectUris: [...input.redirectUris], activeConnections: 0 }
      const fetchMock = respondWith(201, created)

      const client = await new HttpAdminMcpRepository(API_BASE_URL).registerClient(input)

      expect(fetchMock).toHaveBeenCalledWith(
        CLIENTS_URL,
        expect.objectContaining({
          method: 'POST',
          credentials: 'include',
          headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value', 'Content-Type': 'application/json' }),
          body: JSON.stringify(input),
        }),
      )
      expect(client).toEqual(created)
    })

    it('construit le corps champ par champ : une clé en trop de l\'objet reçu ne part pas (le serveur la refuserait, 400)', async () => {
      const fetchMock = respondWith(201, { ...preregisteredRow, activeConnections: 0 })
      // Un objet plus large que l'entrée : TypeScript l'accepte hors d'un littéral.
      const wider = { ...input, registration: 'cimd', id: MISSING_ID }

      await new HttpAdminMcpRepository(API_BASE_URL).registerClient(wider)

      expect(fetchMock).toHaveBeenCalledWith(
        CLIENTS_URL,
        expect.objectContaining({
          body: JSON.stringify({ clientId: input.clientId, name: input.name, redirectUris: input.redirectUris }),
        }),
      )
    })

    it('lit un 409 sans type de problème comme `client-id-taken`', async () => {
      respondWith(409, { detail: 'Identifiant déjà pris, localisé.' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).registerClient(input))).toBe('client-id-taken')
    })

    it('lit un 422 à violations comme `validation`, les messages joints', async () => {
      respondWith(422, {
        detail: 'redirectUris: invalide',
        violations: [
          { propertyPath: 'clientId', message: 'Premier message.' },
          { propertyPath: 'redirectUris[0]', message: 'Second message.' },
        ],
      })

      const error = await new HttpAdminMcpRepository(API_BASE_URL).registerClient(input).catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(AdminMcpError)
      expect(error instanceof AdminMcpError ? [error.reason, error.message] : null).toEqual([
        'validation',
        'Premier message. Second message.',
      ])
    })

    it('lit un 422 de type `client-id-of-verified-domain` comme `id-of-verified-domain`, quelle que soit la méthode (R5)', async () => {
      respondWith(422, { type: '/errors/client-id-of-verified-domain', detail: 'Localisé.' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).registerClient(input))).toBe('id-of-verified-domain')
    })

    it('lit un 400 (corps refusé) comme `unknown`', async () => {
      respondWith(400, { detail: 'Extra attributes are not allowed.' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).registerClient(input))).toBe('unknown')
    })

    it('refuse une réponse de création au mode d\'enregistrement inconnu', async () => {
      respondWith(201, { ...preregisteredRow, registration: 'dynamic', activeConnections: 0 })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).registerClient(input))).toBe('unknown')
    })
  })

  describe('reviseClient()', () => {
    const revision = { name: 'Nouveau nom', redirectUris: ['https://client.example.test/autre-callback'] }

    it('envoie PUT /clients/{id} avec { name, redirectUris } seulement, et rend le client révisé', async () => {
      const revised = { ...preregisteredRow, ...revision }
      const fetchMock = respondWith(200, revised)
      // Une clé en trop (`clientId` ne se révise pas) ne part pas.
      const wider = { ...revision, clientId: 'autre-identifiant' }

      const client = await new HttpAdminMcpRepository(API_BASE_URL).reviseClient(PREREGISTERED_ID, wider)

      expect(fetchMock).toHaveBeenCalledWith(
        `${CLIENTS_URL}/${PREREGISTERED_ID}`,
        expect.objectContaining({
          method: 'PUT',
          credentials: 'include',
          headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
          body: JSON.stringify(revision),
        }),
      )
      expect(client).toEqual(revised)
    })

    it('lit un 404 comme `not-found` (client supprimé depuis la lecture)', async () => {
      respondWith(404, { detail: 'Not Found' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).reviseClient(MISSING_ID, revision))).toBe('not-found')
    })

    it('lit un 409 de type `client-declared-by-document` comme `declared-by-document`', async () => {
      respondWith(409, { type: '/errors/client-declared-by-document', detail: 'Localisé.' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).reviseClient(CIMD_ID, revision))).toBe(
        'declared-by-document',
      )
    })

    it('lit un 422 de type `client-id-of-verified-domain` comme `id-of-verified-domain`', async () => {
      respondWith(422, { type: '/errors/client-id-of-verified-domain', detail: 'Localisé.' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).reviseClient(PREREGISTERED_ID, revision))).toBe(
        'id-of-verified-domain',
      )
    })

    it('lit un 422 à violations comme `validation`', async () => {
      respondWith(422, { violations: [{ propertyPath: 'name', message: 'Le nom est requis.' }] })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).reviseClient(PREREGISTERED_ID, revision))).toBe(
        'validation',
      )
    })
  })

  describe('removeClient()', () => {
    it('envoie DELETE /clients/{id} avec le jeton CSRF et se résout sur le 204', async () => {
      const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
      vi.stubGlobal('fetch', fetchMock)

      await expect(new HttpAdminMcpRepository(API_BASE_URL).removeClient(CIMD_ID)).resolves.toBeUndefined()

      expect(fetchMock).toHaveBeenCalledWith(
        `${CLIENTS_URL}/${CIMD_ID}`,
        expect.objectContaining({
          method: 'DELETE',
          credentials: 'include',
          headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
        }),
      )
    })

    it('lit un 404 comme `not-found`', async () => {
      respondWith(404, { detail: 'Not Found' })

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).removeClient(MISSING_ID))).toBe('not-found')
    })
  })

  describe('listConnections()', () => {
    it('lit GET /api/backoffice/mcp/connections et rend chaque connexion dans l\'ordre du serveur', async () => {
      const fetchMock = respondWith(200, [activeConnectionRow, revokedConnectionRow])

      const connections = await new HttpAdminMcpRepository(API_BASE_URL).listConnections()

      expect(fetchMock).toHaveBeenCalledWith(CONNECTIONS_URL, { method: 'GET', credentials: 'include' })
      expect(connections).toEqual([activeConnectionRow, revokedConnectionRow])
    })

    it('refuse un état de connexion inconnu, jamais converti', async () => {
      respondWith(200, [{ ...activeConnectionRow, state: 'suspended' }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listConnections())).toBe('unknown')
    })

    it('refuse un mode d\'enregistrement de client inconnu', async () => {
      respondWith(200, [{ ...activeConnectionRow, clientRegistration: 'dynamic' }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listConnections())).toBe('unknown')
    })

    it('refuse une échéance nulle : le backoffice la rend toujours, révoquée comprise', async () => {
      respondWith(200, [{ ...revokedConnectionRow, expiresAt: null }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listConnections())).toBe('unknown')
    })

    it('refuse un `codeExchanged` qui n\'est pas un booléen', async () => {
      respondWith(200, [{ ...activeConnectionRow, codeExchanged: 1 }])

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listConnections())).toBe('unknown')
    })

    it('lit un statut inattendu (500) comme `unknown`', async () => {
      respondWith(500, {})

      expect(await rejectionReason(new HttpAdminMcpRepository(API_BASE_URL).listConnections())).toBe('unknown')
    })
  })
})
