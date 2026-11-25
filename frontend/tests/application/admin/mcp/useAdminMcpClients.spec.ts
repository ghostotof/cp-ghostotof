import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_MCP_REPOSITORY, useAdminMcpClients } from '../../../../src/application/admin/mcp/useAdminMcpClients'
import type { AdminMcpClient } from '../../../../src/domain/admin/mcp/entities/AdminMcpClient'
import type {
  AdminMcpClientInput,
  AdminMcpClientRevision,
  AdminMcpRepository,
} from '../../../../src/domain/admin/mcp/repositories/AdminMcpRepository'
import { AdminMcpError } from '../../../../src/domain/admin/mcp/errors/AdminMcpError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { AccessForbiddenError } from '../../../../src/domain/auth/errors/AccessForbiddenError'

const CLIENT_ID = '019968a0-0000-7000-8000-000000000101'
const OTHER_CLIENT_ID = '019968a0-0000-7000-8000-000000000102'

const CLIENT: AdminMcpClient = {
  id: CLIENT_ID,
  clientId: 'client-de-test',
  name: 'Client de test',
  redirectUris: ['https://client.example/callback'],
  registration: 'preregistered',
  registeredAt: '2026-11-20T10:00:00+00:00',
  activeConnections: 2,
  shadowsVerifiedDomain: false,
}

const OTHER_CLIENT: AdminMcpClient = {
  ...CLIENT,
  id: OTHER_CLIENT_ID,
  clientId: 'https://autre.example/client.json',
  name: 'Autre client',
  registration: 'cimd',
  activeConnections: 0,
}

const INPUT: AdminMcpClientInput = { clientId: 'client-neuf', name: 'Client neuf', redirectUris: ['https://neuf.example/callback'] }
const REVISION: AdminMcpClientRevision = { name: 'Client renommé', redirectUris: ['https://client.example/retour'] }

/** Une promesse que le test résout quand il le décide : ce qui se passe avant la réponse du serveur devient observable. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve: (value: T) => void = () => undefined
  const promise = new Promise<T>((settle) => {
    resolve = settle
  })

  return { promise, resolve }
}

function createStubRepository(overrides: Partial<AdminMcpRepository> = {}): AdminMcpRepository {
  return {
    listClients: vi.fn(async () => [CLIENT]),
    registerClient: vi.fn(async () => CLIENT),
    reviseClient: vi.fn(async () => CLIENT),
    removeClient: vi.fn(async () => undefined),
    listConnections: vi.fn(async () => []),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminMcpRepository) {
  let captured: ReturnType<typeof useAdminMcpClients> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminMcpClients()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_MCP_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminMcpClients() did not run during mount')
  }

  return captured
}

describe('useAdminMcpClients', () => {
  it("lève une erreur explicite si le dépôt n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminMcpClients()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/AdminMcpRepository/)
  })

  it('isLoaded reste faux tant que la lecture du montage n’a pas répondu, puis la liste est posée', async () => {
    const pending = deferred<readonly AdminMcpClient[]>()
    const repository = createStubRepository({ listClients: vi.fn(() => pending.promise) })
    const composable = mountWithComposable(repository)

    // La lecture part au montage, sans attendre la page.
    expect(repository.listClients).toHaveBeenCalledTimes(1)
    expect(composable.isLoading.value).toBe(true)
    expect(composable.isLoaded.value).toBe(false)
    expect(composable.clients.value).toEqual([])

    pending.resolve([CLIENT, OTHER_CLIENT])
    await flushPromises()

    expect(composable.isLoaded.value).toBe(true)
    expect(composable.isLoading.value).toBe(false)
    expect(composable.clients.value).toEqual([CLIENT, OTHER_CLIENT])
  })

  it('hasError passe à vrai si la lecture échoue, sans poser d’erreur de mutation', async () => {
    const repository = createStubRepository({ listClients: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)

    await composable.load()

    expect(composable.hasError.value).toBe(true)
    expect(composable.isLoaded.value).toBe(false)
    expect(composable.errorMessage.value).toBeNull()
  })

  describe('register()', () => {
    it('envoie la saisie, relit la liste et rend vrai', async () => {
      const repository = createStubRepository()
      const composable = mountWithComposable(repository)
      await flushPromises()
      vi.mocked(repository.listClients).mockResolvedValue([CLIENT, OTHER_CLIENT])

      const succeeded = await composable.register(INPUT)

      expect(succeeded).toBe(true)
      expect(repository.registerClient).toHaveBeenCalledWith(INPUT)
      expect(repository.listClients).toHaveBeenCalledTimes(2)
      expect(composable.clients.value).toEqual([CLIENT, OTHER_CLIENT])
      expect(composable.errorMessage.value).toBeNull()
    })

    it('rend faux et pose errorMessage avec sa raison quand l’identifiant est déjà pris, sans toucher la liste', async () => {
      const repository = createStubRepository({
        registerClient: vi.fn(async () => Promise.reject(new AdminMcpError('client-id-taken', 'Identifiant déjà pris'))),
      })
      const composable = mountWithComposable(repository)
      await flushPromises()

      const succeeded = await composable.register(INPUT)

      expect(succeeded).toBe(false)
      expect(composable.errorMessage.value?.reason).toBe('client-id-taken')
      expect(repository.listClients).toHaveBeenCalledTimes(1)
      expect(composable.clients.value).toEqual([CLIENT])
    })
  })

  describe('revise()', () => {
    it('envoie la révision du client désigné par son UUID, relit la liste et rend vrai', async () => {
      const repository = createStubRepository()
      const composable = mountWithComposable(repository)
      await flushPromises()

      const succeeded = await composable.revise(CLIENT_ID, REVISION)

      expect(succeeded).toBe(true)
      expect(repository.reviseClient).toHaveBeenCalledWith(CLIENT_ID, REVISION)
      expect(repository.listClients).toHaveBeenCalledTimes(2)
      expect(composable.errorMessage.value).toBeNull()
    })

    it.each(['declared-by-document', 'id-of-verified-domain', 'validation'] as const)(
      'rend faux et garde la raison %s pour que la page la traduise',
      async (reason) => {
        const repository = createStubRepository({
          reviseClient: vi.fn(async () => Promise.reject(new AdminMcpError(reason, 'Refusé'))),
        })
        const composable = mountWithComposable(repository)
        await flushPromises()

        const succeeded = await composable.revise(CLIENT_ID, REVISION)

        expect(succeeded).toBe(false)
        expect(composable.errorMessage.value?.reason).toBe(reason)
        expect(repository.listClients).toHaveBeenCalledTimes(1)
      },
    )
  })

  describe('remove()', () => {
    it('supprime le client puis relit la liste, dont le décompte des connexions a changé, et rend vrai', async () => {
      const repository = createStubRepository({ listClients: vi.fn(async () => [CLIENT, OTHER_CLIENT]) })
      const composable = mountWithComposable(repository)
      await flushPromises()
      vi.mocked(repository.listClients).mockResolvedValue([OTHER_CLIENT])

      const succeeded = await composable.remove(CLIENT_ID)

      expect(succeeded).toBe(true)
      expect(repository.removeClient).toHaveBeenCalledWith(CLIENT_ID)
      expect(repository.listClients).toHaveBeenCalledTimes(2)
      expect(composable.clients.value).toEqual([OTHER_CLIENT])
      expect(composable.errorMessage.value).toBeNull()
    })

    it('rend faux et pose errorMessage quand la suppression échoue, sans relire la liste', async () => {
      const repository = createStubRepository({
        removeClient: vi.fn(async () => Promise.reject(new AdminMcpError('not-found', 'Client inconnu'))),
      })
      const composable = mountWithComposable(repository)
      await flushPromises()

      const succeeded = await composable.remove(CLIENT_ID)

      expect(succeeded).toBe(false)
      expect(composable.errorMessage.value).toBeInstanceOf(AdminMcpError)
      expect(composable.errorMessage.value?.reason).toBe('not-found')
      expect(repository.listClients).toHaveBeenCalledTimes(1)
      expect(composable.clients.value).toEqual([CLIENT])
    })

    it('ramène un échec étranger au contexte à la raison unknown', async () => {
      const repository = createStubRepository({ removeClient: vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))) })
      const composable = mountWithComposable(repository)
      await flushPromises()

      const succeeded = await composable.remove(CLIENT_ID)

      expect(succeeded).toBe(false)
      expect(composable.errorMessage.value?.reason).toBe('unknown')
    })

    it.each([
      ['une session finie', () => new SessionEndedError()],
      ['un refus réel', () => new AccessForbiddenError()],
    ])('rend faux sans poser d’erreur pour %s : le bandeau ou l’alerte du layout l’annonce seul', async (_label, refusal) => {
      const repository = createStubRepository({ removeClient: vi.fn(async () => Promise.reject(refusal())) })
      const composable = mountWithComposable(repository)
      await flushPromises()

      const succeeded = await composable.remove(CLIENT_ID)

      expect(succeeded).toBe(false)
      expect(composable.errorMessage.value).toBeNull()
    })
  })

  it('une mutation réussie efface l’erreur de la précédente', async () => {
    const repository = createStubRepository({
      removeClient: vi.fn(async () => Promise.reject(new AdminMcpError('not-found', 'Client inconnu'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()
    await composable.remove(CLIENT_ID)
    expect(composable.errorMessage.value?.reason).toBe('not-found')

    const succeeded = await composable.register(INPUT)

    expect(succeeded).toBe(true)
    expect(composable.errorMessage.value).toBeNull()
  })
})
