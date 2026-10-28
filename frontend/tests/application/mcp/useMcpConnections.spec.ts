import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { MCP_CONNECTIONS_REPOSITORY, useMcpConnections } from '../../../src/application/mcp/useMcpConnections'
import { authState, markBaseAccessGranted, markSessionExpired } from '../../../src/application/auth/useAuth'
import type { McpConnectionsRepository } from '../../../src/domain/mcp/repositories/McpConnectionsRepository'
import type { McpConnectionSummary } from '../../../src/domain/mcp/entities/McpConnection'
import { McpConnectionNotFoundError } from '../../../src/domain/mcp/errors/McpConnectionNotFoundError'
import { McpConnectionsRequestFailedError } from '../../../src/domain/mcp/errors/McpConnectionsRequestFailedError'
import { SessionExpiredError } from '../../../src/domain/mcp/errors/SessionExpiredError'
import { listOf } from '../../support/mcpConnectionList'

enableAutoUnmount(afterEach)

function connection(id: string, state: McpConnectionSummary['state'] = 'active'): McpConnectionSummary {
  return {
    id,
    client: { id: 'claude-code', name: 'Claude Code' },
    grantedAt: '2026-10-28T09:00:00+01:00',
    codeExchanged: true,
    lastUsedAt: null,
    expiresAt: 'revoked' === state ? null : '2026-11-27T09:00:00+01:00',
    state,
    revokedAt: 'revoked' === state ? '2026-10-28T11:00:00+01:00' : null,
    revocationCause: 'revoked' === state ? 'person' : null,
  }
}

const A = connection('a')
const B = connection('b')

function mountComposable(repository: McpConnectionsRepository) {
  let captured: ReturnType<typeof useMcpConnections> | undefined
  const Probe = defineComponent({
    setup() {
      captured = useMcpConnections()
      return () => h('div')
    },
  })
  mount(Probe, { global: { provide: { [MCP_CONNECTIONS_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useMcpConnections() did not run during mount')
  }

  return captured
}

describe('useMcpConnections', () => {
  beforeEach(() => {
    // Une session « connectée » à ramener à anonyme : markSessionExpired() en est le seul témoin observable.
    markSessionExpired()
    markBaseAccessGranted()
    expect(authState.tier).toBe('base')
  })

  it("lève une erreur explicite si le repository n'a pas été fourni", () => {
    const Probe = defineComponent({
      setup() {
        useMcpConnections()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/McpConnectionsRepository/)
  })

  it('est en chargement avant tout appel', () => {
    const connections = mountComposable({ list: vi.fn(async () => listOf([])), revoke: vi.fn() })

    expect(connections.status.value).toBe('loading')
    expect(connections.connections.value).toEqual([])
    expect(connections.revokingId.value).toBeNull()
    expect(connections.lastOutcome.value).toBeNull()
    expect(connections.terminatedTruncated.value).toBe(false)
    expect(connections.sessionExpired.value).toBe(false)
  })

  it('load() charge la liste puis passe à ready', async () => {
    const connections = mountComposable({ list: vi.fn(async () => listOf([A, B])), revoke: vi.fn() })

    await connections.load()

    expect(connections.status.value).toBe('ready')
    expect(connections.connections.value).toEqual([A, B])
  })

  it("load() garde l'indication de troncature du serveur, sans la recalculer", async () => {
    const connections = mountComposable({ list: vi.fn(async () => listOf([A, B], true)), revoke: vi.fn() })

    await connections.load()

    expect(connections.terminatedTruncated.value).toBe(true)
    expect(connections.connections.value).toEqual([A, B])
  })

  it("la troncature suit la dernière lecture : relue après une révocation, elle peut tomber", async () => {
    const list = vi.fn().mockResolvedValueOnce(listOf([A, B], true)).mockResolvedValueOnce(listOf([A], false))
    const connections = mountComposable({ list, revoke: vi.fn(async () => undefined) })
    await connections.load()

    await connections.revoke('b')

    expect(connections.terminatedTruncated.value).toBe(false)
  })

  it("load() en échec passe à error et vide la liste", async () => {
    const list = vi.fn().mockResolvedValue(listOf([A])).mockRejectedValueOnce(new McpConnectionsRequestFailedError())
    const connections = mountComposable({ list, revoke: vi.fn() })

    await connections.load()

    expect(connections.status.value).toBe('error')
    expect(connections.connections.value).toEqual([])
  })

  it("load() en échec n'affiche pas la mention d'une liste qu'il n'a plus", async () => {
    const list = vi.fn().mockResolvedValueOnce(listOf([A], true)).mockRejectedValueOnce(new McpConnectionsRequestFailedError())
    const connections = mountComposable({ list, revoke: vi.fn() })
    await connections.load()

    await connections.load()

    expect(connections.status.value).toBe('error')
    expect(connections.terminatedTruncated.value).toBe(false)
  })

  it('load() sur une session expirée marque la session expirée', async () => {
    const connections = mountComposable({ list: vi.fn().mockRejectedValue(new SessionExpiredError()), revoke: vi.fn() })

    await connections.load()

    expect(connections.status.value).toBe('error')
    expect(authState.tier).toBe('anonymous')
  })

  it('load() sur une session expirée le dit à la page (sessionExpired), qu\'un autre échec ne dit pas', async () => {
    const list = vi.fn().mockRejectedValueOnce(new SessionExpiredError()).mockRejectedValueOnce(new McpConnectionsRequestFailedError()).mockResolvedValue(listOf([A]))
    const connections = mountComposable({ list, revoke: vi.fn() })

    await connections.load()
    expect(connections.sessionExpired.value).toBe(true)

    // La lecture suivante échoue pour une autre raison : l'indication ne survit pas à la lecture qui la corrige.
    await connections.load()
    expect(connections.status.value).toBe('error')
    expect(connections.sessionExpired.value).toBe(false)

    await connections.load()
    expect(connections.status.value).toBe('ready')
    expect(connections.sessionExpired.value).toBe(false)
  })

  it('une session expirée pendant la révocation est dite à la page aussi', async () => {
    const connections = mountComposable({
      list: vi.fn().mockResolvedValue(listOf([A])),
      revoke: vi.fn().mockRejectedValue(new SessionExpiredError()),
    })
    await connections.load()

    await connections.revoke('a')

    expect(connections.sessionExpired.value).toBe(true)
    expect(connections.lastOutcome.value).toBe('failed')
  })

  it("deux load() qui se croisent : la réponse la plus ancienne n'écrase pas la plus récente (#650, R5a)", async () => {
    const resolvers: Array<(list: ReturnType<typeof listOf>) => void> = []
    const list = vi.fn(() => new Promise<ReturnType<typeof listOf>>((resolve) => resolvers.push(resolve)))
    const connections = mountComposable({ list, revoke: vi.fn() })

    const older = connections.load()
    const newer = connections.load()
    // La plus récente répond d'abord, la plus ancienne ensuite — avec une liste périmée.
    resolvers[1]?.(listOf([B], false))
    await newer
    resolvers[0]?.(listOf([A], true))
    await older

    expect(connections.connections.value).toEqual([B])
    expect(connections.terminatedTruncated.value).toBe(false)
    expect(connections.status.value).toBe('ready')
  })

  it("une erreur de la lecture la plus ancienne n'écrase pas la liste de la plus récente", async () => {
    const settle: Array<{ resolve: (list: ReturnType<typeof listOf>) => void; reject: (error: Error) => void }> = []
    const list = vi.fn(() => new Promise<ReturnType<typeof listOf>>((resolve, reject) => settle.push({ resolve, reject })))
    const connections = mountComposable({ list, revoke: vi.fn() })

    const older = connections.load()
    const newer = connections.load()
    settle[1]?.resolve(listOf([B]))
    await newer
    settle[0]?.reject(new SessionExpiredError())
    await older

    expect(connections.status.value).toBe('ready')
    expect(connections.connections.value).toEqual([B])
    expect(connections.sessionExpired.value).toBe(false)
    // Une réponse périmée ne ferme pas la session de l'application non plus.
    expect(authState.tier).toBe('base')
  })

  it('revoke() révoque puis recharge la liste depuis le serveur, outcome revoked', async () => {
    const revoke = vi.fn(async () => undefined)
    const list = vi.fn().mockResolvedValueOnce(listOf([A, B])).mockResolvedValueOnce(listOf([A, connection('b', 'revoked')]))
    const connections = mountComposable({ list, revoke })
    await connections.load()

    await connections.revoke('b')

    expect(revoke).toHaveBeenCalledWith('b')
    expect(list).toHaveBeenCalledTimes(2)
    expect(connections.connections.value[1]?.state).toBe('revoked')
    expect(connections.lastOutcome.value).toBe('revoked')
    expect(connections.revokingId.value).toBeNull()
    expect(connections.status.value).toBe('ready')
  })

  it('un 404 donne already-gone et recharge la liste', async () => {
    const revoke = vi.fn().mockRejectedValue(new McpConnectionNotFoundError())
    const list = vi.fn().mockResolvedValueOnce(listOf([A, B])).mockResolvedValueOnce(listOf([A]))
    const connections = mountComposable({ list, revoke })
    await connections.load()

    await connections.revoke('b')

    expect(connections.lastOutcome.value).toBe('already-gone')
    expect(list).toHaveBeenCalledTimes(2)
    expect(connections.connections.value).toEqual([A])
    expect(connections.revokingId.value).toBeNull()
  })

  it('une erreur donne failed et laisse la liste inchangée, sans recharger', async () => {
    const revoke = vi.fn().mockRejectedValue(new McpConnectionsRequestFailedError())
    const list = vi.fn().mockResolvedValue(listOf([A, B]))
    const connections = mountComposable({ list, revoke })
    await connections.load()

    await connections.revoke('b')

    expect(connections.lastOutcome.value).toBe('failed')
    expect(list).toHaveBeenCalledTimes(1)
    expect(connections.connections.value).toEqual([A, B])
    expect(connections.status.value).toBe('ready')
    expect(connections.revokingId.value).toBeNull()
  })

  it('une session expirée pendant la révocation marque la session expirée, outcome failed', async () => {
    const connections = mountComposable({
      list: vi.fn().mockResolvedValue(listOf([A])),
      revoke: vi.fn().mockRejectedValue(new SessionExpiredError()),
    })
    await connections.load()

    await connections.revoke('a')

    expect(authState.tier).toBe('anonymous')
    expect(connections.lastOutcome.value).toBe('failed')
    expect(connections.revokingId.value).toBeNull()
  })

  it('une erreur inattendue du dépôt donne failed aussi', async () => {
    const connections = mountComposable({
      list: vi.fn().mockResolvedValue(listOf([A])),
      revoke: vi.fn().mockRejectedValue(new Error('boom')),
    })
    await connections.load()

    await connections.revoke('a')

    expect(connections.lastOutcome.value).toBe('failed')
  })

  it('le rechargement qui suit une révocation en échec garde la révocation acquise (outcome revoked)', async () => {
    const list = vi.fn().mockResolvedValueOnce(listOf([A])).mockRejectedValueOnce(new McpConnectionsRequestFailedError())
    const connections = mountComposable({ list, revoke: vi.fn(async () => undefined) })
    await connections.load()

    await connections.revoke('a')

    // La révocation a eu lieu : le message le dit, la liste affichée est en erreur.
    expect(connections.lastOutcome.value).toBe('revoked')
    expect(connections.status.value).toBe('error')
  })

  it('deux revoke() de suite, sans attendre : une seule requête, revokingId posé', async () => {
    let release: () => void = () => undefined
    const revoke = vi.fn(() => new Promise<void>((resolve) => { release = resolve }))
    const connections = mountComposable({ list: vi.fn().mockResolvedValue(listOf([A, B])), revoke })
    await connections.load()

    const first = connections.revoke('a')
    const second = connections.revoke('b')

    expect(connections.revokingId.value).toBe('a')
    release()
    await Promise.all([first, second])

    expect(revoke).toHaveBeenCalledOnce()
    expect(revoke).toHaveBeenCalledWith('a')
    expect(connections.revokingId.value).toBeNull()
  })

  it("remet lastOutcome à null au début d'une nouvelle révocation", async () => {
    const revoke = vi.fn().mockRejectedValueOnce(new McpConnectionsRequestFailedError()).mockImplementationOnce(() => new Promise(() => undefined))
    const connections = mountComposable({ list: vi.fn().mockResolvedValue(listOf([A])), revoke })
    await connections.load()
    await connections.revoke('a')
    expect(connections.lastOutcome.value).toBe('failed')

    void connections.revoke('a')

    expect(connections.lastOutcome.value).toBeNull()
  })
})
