import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_MCP_REPOSITORY } from '../../../../src/application/admin/mcp/useAdminMcpClients'
import { useAdminMcpConnections } from '../../../../src/application/admin/mcp/useAdminMcpConnections'
import type { AdminMcpConnection } from '../../../../src/domain/admin/mcp/entities/AdminMcpConnection'
import type { AdminMcpRepository } from '../../../../src/domain/admin/mcp/repositories/AdminMcpRepository'
import { AdminMcpError } from '../../../../src/domain/admin/mcp/errors/AdminMcpError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { AccessForbiddenError } from '../../../../src/domain/auth/errors/AccessForbiddenError'

const ACTIVE: AdminMcpConnection = {
  id: '019968a0-0000-7000-8000-000000000201',
  account: 'compte-de-test',
  clientId: 'client-de-test',
  clientName: 'Client de test',
  clientRegistration: 'preregistered',
  grantedAt: '2026-11-20T10:00:00+00:00',
  codeExchanged: true,
  lastUsedAt: null,
  expiresAt: '2026-12-20T10:00:00+00:00',
  state: 'active',
  revokedAt: null,
  revocationCause: null,
}

const REVOKED: AdminMcpConnection = {
  ...ACTIVE,
  id: '019968a0-0000-7000-8000-000000000202',
  state: 'revoked',
  revokedAt: '2026-11-21T10:00:00+00:00',
  revocationCause: 'person',
}

/** Une promesse que le test règle quand il le décide : l'ordre des réponses devient celui du test. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void; reject: (reason: unknown) => void } {
  let resolve: (value: T) => void = () => undefined
  let reject: (reason: unknown) => void = () => undefined
  const promise = new Promise<T>((settle, fail) => {
    resolve = settle
    reject = fail
  })

  return { promise, resolve, reject }
}

function createStubRepository(overrides: Partial<AdminMcpRepository> = {}): AdminMcpRepository {
  return {
    listClients: vi.fn(async () => []),
    registerClient: vi.fn(async () => Promise.reject(new Error('not under test'))),
    reviseClient: vi.fn(async () => Promise.reject(new Error('not under test'))),
    removeClient: vi.fn(async () => undefined),
    listConnections: vi.fn(async () => [ACTIVE, REVOKED]),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminMcpRepository) {
  let captured: ReturnType<typeof useAdminMcpConnections> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminMcpConnections()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_MCP_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminMcpConnections() did not run during mount')
  }

  return captured
}

describe('useAdminMcpConnections', () => {
  it("lève une erreur explicite si le dépôt n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminMcpConnections()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/AdminMcpRepository/)
  })

  it('isLoaded reste faux tant que la lecture du montage n’a pas répondu, puis les connexions sont posées dans l’ordre du serveur', async () => {
    const pending = deferred<readonly AdminMcpConnection[]>()
    const repository = createStubRepository({ listConnections: vi.fn(() => pending.promise) })
    const composable = mountWithComposable(repository)

    expect(repository.listConnections).toHaveBeenCalledTimes(1)
    expect(repository.listClients).not.toHaveBeenCalled()
    expect(composable.isLoading.value).toBe(true)
    expect(composable.isLoaded.value).toBe(false)
    expect(composable.connections.value).toEqual([])

    pending.resolve([REVOKED, ACTIVE])
    await flushPromises()

    expect(composable.isLoaded.value).toBe(true)
    expect(composable.isLoading.value).toBe(false)
    expect(composable.connections.value).toEqual([REVOKED, ACTIVE])
    expect(composable.errorMessage.value).toBeNull()
  })

  it('un échec de lecture lève hasError et pose errorMessage avec la raison du contexte', async () => {
    const repository = createStubRepository({
      listConnections: vi.fn(async () => Promise.reject(new AdminMcpError('validation', 'Réponse refusée'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.isLoaded.value).toBe(false)
    expect(composable.errorMessage.value).toBeInstanceOf(AdminMcpError)
    expect(composable.errorMessage.value?.reason).toBe('validation')
  })

  it('ramène un échec de lecture étranger au contexte à la raison unknown', async () => {
    const repository = createStubRepository({ listConnections: vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.errorMessage.value?.reason).toBe('unknown')
  })

  it.each([
    ['une session finie', () => new SessionEndedError()],
    ['un refus réel', () => new AccessForbiddenError()],
  ])('ne pose ni hasError ni errorMessage pour %s : le bandeau ou l’alerte du layout l’annonce seul', async (_label, refusal) => {
    const repository = createStubRepository({ listConnections: vi.fn(async () => Promise.reject(refusal())) })
    const composable = mountWithComposable(repository)
    await flushPromises()

    expect(composable.hasError.value).toBe(false)
    expect(composable.isLoaded.value).toBe(false)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('load() relit la liste, et une relecture réussie efface l’erreur de la précédente', async () => {
    const repository = createStubRepository({
      listConnections: vi.fn(async () => Promise.reject(new AdminMcpError('unknown', 'Panne'))),
    })
    const composable = mountWithComposable(repository)
    await flushPromises()
    expect(composable.errorMessage.value?.reason).toBe('unknown')
    vi.mocked(repository.listConnections).mockResolvedValue([ACTIVE])

    await composable.load()

    expect(repository.listConnections).toHaveBeenCalledTimes(2)
    expect(composable.hasError.value).toBe(false)
    expect(composable.errorMessage.value).toBeNull()
    expect(composable.connections.value).toEqual([ACTIVE])
  })

  it('l’échec tardif d’une lecture périmée ne pose aucune erreur sur la liste que la dernière lecture a posée', async () => {
    const first = deferred<readonly AdminMcpConnection[]>()
    const repository = createStubRepository({ listConnections: vi.fn(() => first.promise) })
    const composable = mountWithComposable(repository)
    vi.mocked(repository.listConnections).mockResolvedValue([ACTIVE])

    // « Réessayer » pendant la lecture du montage : la seconde répond d'abord, la première échoue ensuite.
    await composable.load()
    first.reject(new AdminMcpError('unknown', 'Panne'))
    await flushPromises()

    expect(composable.connections.value).toEqual([ACTIVE])
    expect(composable.hasError.value).toBe(false)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('deux échecs qui se croisent : la raison montrée reste celle de la dernière lecture lancée', async () => {
    const first = deferred<readonly AdminMcpConnection[]>()
    const repository = createStubRepository({ listConnections: vi.fn(() => first.promise) })
    const composable = mountWithComposable(repository)
    vi.mocked(repository.listConnections).mockRejectedValue(new AdminMcpError('validation', 'Réponse refusée'))

    // La seconde lecture échoue d'abord ; l'échec de la première, périmée, arrive ensuite avec une autre raison.
    await composable.load()
    first.reject(new AdminMcpError('unknown', 'Panne'))
    await flushPromises()

    expect(composable.hasError.value).toBe(true)
    expect(composable.errorMessage.value?.reason).toBe('validation')
  })
})
