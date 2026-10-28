import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import McpConnectionsPage from '../../../src/presentation/pages/McpConnectionsPage.vue'
import { MCP_CONNECTIONS_REPOSITORY } from '../../../src/application/mcp/useMcpConnections'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { McpConnectionsRepository } from '../../../src/domain/mcp/repositories/McpConnectionsRepository'
import type { McpConnectionList, McpConnectionSummary } from '../../../src/domain/mcp/entities/McpConnection'
import { McpConnectionNotFoundError } from '../../../src/domain/mcp/errors/McpConnectionNotFoundError'
import { McpConnectionsRequestFailedError } from '../../../src/domain/mcp/errors/McpConnectionsRequestFailedError'
import type { Locale } from '../../../src/domain/portfolio/entities/Locale'
import { expectNoAccessibilityViolation } from '../../support/axe'
import { listOf } from '../../support/mcpConnectionList'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.restoreAllMocks()
})

const StubPage = { template: '<div />' }

/** Active, jamais utilisée sous ce nom : le seul bouton « Révoquer » de la liste. */
const ACTIVE: McpConnectionSummary = {
  id: '0194f6a0-0000-7000-8000-000000000001',
  client: { id: 'claude-code', name: 'Claude Code' },
  grantedAt: '2026-10-28T09:00:00+01:00',
  lastUsedAt: '2026-10-28T10:30:00+01:00',
  expiresAt: '2026-11-27T09:00:00+01:00',
  state: 'active',
  revokedAt: null,
  revocationCause: null,
}

const NEVER_USED: McpConnectionSummary = {
  ...ACTIVE,
  id: '0194f6a0-0000-7000-8000-000000000002',
  client: { id: 'other-client', name: 'Autre Client' },
  lastUsedAt: null,
}

/** Échue mais pas encore marquée par la purge : l'état est `expired`, sans cause de révocation. */
const EXPIRED: McpConnectionSummary = {
  ...ACTIVE,
  id: '0194f6a0-0000-7000-8000-000000000003',
  client: { id: 'old-client', name: 'Client Échu' },
  grantedAt: '2026-08-01T09:00:00+02:00',
  expiresAt: '2026-08-31T09:00:00+02:00',
  state: 'expired',
}

const REVOKED_BY_PERSON: McpConnectionSummary = {
  ...ACTIVE,
  id: '0194f6a0-0000-7000-8000-000000000004',
  client: { id: 'revoked-client', name: 'Client Révoqué' },
  state: 'revoked',
  revokedAt: '2026-10-28T11:00:00+01:00',
  revocationCause: 'person',
}

const REVOKED_UNKNOWN_CAUSE: McpConnectionSummary = {
  ...REVOKED_BY_PERSON,
  id: '0194f6a0-0000-7000-8000-000000000005',
  client: { id: 'future-client', name: 'Client Futur' },
  // Une cause qu'un backend plus récent aurait ajoutée : la page ne la connaît pas.
  revocationCause: 'cause-from-the-future',
}

interface Harness {
  wrapper: VueWrapper
  list: ReturnType<typeof vi.fn>
  revoke: ReturnType<typeof vi.fn>
  confirm: ReturnType<typeof vi.spyOn>
}

interface Options {
  locale?: Locale
  list?: ReturnType<typeof vi.fn>
  revoke?: ReturnType<typeof vi.fn>
  confirmAnswer?: boolean
}

async function mountPage(options: Options = {}): Promise<Harness> {
  const locale = options.locale ?? 'fr'
  const list = options.list ?? vi.fn(async () => listOf([ACTIVE, NEVER_USED, EXPIRED, REVOKED_BY_PERSON]))
  const revoke = options.revoke ?? vi.fn(async () => undefined)
  const repository = { list, revoke } as unknown as McpConnectionsRepository

  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(options.confirmAnswer ?? true)

  const i18n = createAppI18n()
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/mcp-connections', name: 'mcp-connections', component: StubPage },
      { path: '/:locale(fr|en)/connect-your-assistant', name: 'connect-assistant', component: StubPage },
    ],
  })
  await router.push(`/${locale}/mcp-connections`)
  await router.isReady()

  const wrapper = mount(McpConnectionsPage, {
    // Rattaché au document : le focus n'existe pas dans un arbre détaché.
    attachTo: document.body,
    global: { plugins: [router, i18n], provide: { [MCP_CONNECTIONS_REPOSITORY as symbol]: repository } },
  })
  await flushPromises()

  return { wrapper, list, revoke, confirm }
}

function rows(wrapper: VueWrapper) {
  return wrapper.findAll('tbody tr')
}

function rowOf(wrapper: VueWrapper, clientName: string) {
  const row = rows(wrapper).find((candidate) => candidate.text().includes(clientName))
  if (undefined === row) {
    throw new Error(`No row for ${clientName}`)
  }

  return row
}

function normalized(text: string): string {
  return text.replace(/[  ]/g, ' ')
}

describe('McpConnectionsPage', () => {
  it('liste les connexions en français : client, dates à l\'heure de Paris, état', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.get('h1').text()).toBe('Mes connexions MCP')
    expect(rows(wrapper)).toHaveLength(4)

    const active = normalized(rowOf(wrapper, 'Claude Code').text())
    expect(active).toContain('28 oct. 2026')
    expect(active).toContain('09:00')
    expect(active).toContain('10:30')
    expect(active).toContain('27 nov. 2026')
    expect(active).toContain('Active')

    expect(rowOf(wrapper, 'Autre Client').text()).toContain('Jamais utilisée')
    expect(rowOf(wrapper, 'Client Échu').text()).toContain('Échue')
  })

  it('liste les connexions en anglais', async () => {
    const { wrapper } = await mountPage({ locale: 'en' })

    expect(wrapper.get('h1').text()).toBe('My MCP connections')
    const active = normalized(rowOf(wrapper, 'Claude Code').text())
    expect(active).toContain('Oct 28, 2026')
    expect(active).toContain('9:00 AM')
    expect(active).toContain('Active')
    expect(rowOf(wrapper, 'Autre Client').text()).toContain('Never used')
    expect(rowOf(wrapper, 'Client Échu').text()).toContain('Expired')
    expect(rowOf(wrapper, 'Client Révoqué').text()).toContain('Revoked by you')
  })

  it('est un vrai tableau : légende, en-têtes de colonne portant scope', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.get('table caption').text()).not.toBe('')
    const headers = wrapper.findAll('thead th')
    expect(headers.length).toBeGreaterThanOrEqual(5)
    for (const header of headers) {
      expect(header.attributes('scope')).toBe('col')
    }
  })

  it('dit pourquoi une connexion révoquée l\'est, et quand', async () => {
    const { wrapper } = await mountPage()

    const text = normalized(rowOf(wrapper, 'Client Révoqué').text())
    expect(text).toContain('Révoquée par vous')
    expect(text).toContain('28 oct. 2026')
    expect(text).toContain('11:00')
  })

  it.each([
    ['client', 'Révoquée par le client'],
    ['refresh-token-reuse', 'jeton de rafraîchissement'],
    ['code-replay', 'code d\'autorisation'],
    ['superseded', 'plus récente'],
    ['expired', 'Échue'],
    ['password-changed', 'mot de passe'],
    ['trusted-tier-withdrawn', 'palier de confiance'],
  ])('a un libellé pour la cause connue « %s »', async (cause, expected) => {
    const list = vi.fn(async () => listOf([{ ...REVOKED_BY_PERSON, revocationCause: cause }]))
    const { wrapper } = await mountPage({ list })

    expect(wrapper.get('tbody tr').text()).toContain(expected)
    expect(wrapper.get('tbody tr').text()).not.toContain('non reconnue')
  })

  it('affiche un libellé générique pour une cause inconnue', async () => {
    const list = vi.fn(async () => listOf([REVOKED_UNKNOWN_CAUSE, ACTIVE]))
    const { wrapper } = await mountPage({ list })

    const row = rowOf(wrapper, 'Client Futur')
    expect(row.text()).toContain('Révoquée')
    expect(row.text()).toContain('cause non reconnue')
    // La cause brute, inconnue du frontend, n'est pas écrite à l'écran, et rien n'a échoué.
    expect(row.text()).not.toContain('cause-from-the-future')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(rowOf(wrapper, 'Claude Code').exists()).toBe(true)
  })

  it("dit, quand le serveur a coupé l'historique, que seules les dernières connexions terminées sont affichées", async () => {
    const list = vi.fn(async () => listOf([ACTIVE, REVOKED_BY_PERSON], true))
    const { wrapper } = await mountPage({ list })

    const note = wrapper.get('[data-testid="terminated-truncated"]')
    expect(note.text()).toBe("L'historique est limité : seules les dernières connexions terminées sont affichées.")
    expect(rows(wrapper)).toHaveLength(2)
  })

  it("dit la même chose en anglais", async () => {
    const list = vi.fn(async () => listOf([ACTIVE, REVOKED_BY_PERSON], true))
    const { wrapper } = await mountPage({ locale: 'en', list })

    expect(wrapper.get('[data-testid="terminated-truncated"]').text()).toBe('History is limited: only the most recent ended connections are shown.')
  })

  it("n'affiche aucune mention quand l'historique n'est pas coupé", async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('[data-testid="terminated-truncated"]').exists()).toBe(false)
  })

  it("la mention n'est pas la région d'état : le seul role=\"status\" de la page reste vide", async () => {
    const list = vi.fn(async () => listOf([ACTIVE, REVOKED_BY_PERSON], true))
    const { wrapper } = await mountPage({ list })

    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.get('[role="status"]').text()).toBe('')
  })

  it("avec la mention d'un historique coupé : aucune violation d'accessibilité détectable", async () => {
    const list = vi.fn(async () => listOf([ACTIVE, REVOKED_BY_PERSON], true))
    const { wrapper } = await mountPage({ list })

    await expectNoAccessibilityViolation(wrapper)
  })

  it('affiche l\'état vide avec un lien vers « Brancher votre assistant »', async () => {
    const list = vi.fn(async () => listOf([]))
    const { wrapper } = await mountPage({ list })

    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).toContain('Aucune connexion')
    const link = wrapper.get('a[href="/fr/connect-your-assistant"]')
    expect(link.text()).toBe('Brancher votre assistant')
  })

  it('propose « Révoquer » sur les seules connexions actives, nommé par le client et la date', async () => {
    const { wrapper } = await mountPage()

    const buttons = wrapper.findAll('tbody button')
    expect(buttons).toHaveLength(2)
    expect(rowOf(wrapper, 'Client Échu').find('button').exists()).toBe(false)
    expect(rowOf(wrapper, 'Client Révoqué').find('button').exists()).toBe(false)

    const label = normalized(rowOf(wrapper, 'Claude Code').get('button').attributes('aria-label') ?? '')
    expect(label.startsWith('Révoquer')).toBe(true)
    expect(label).toContain('Claude Code')
    expect(label).toContain('28 oct. 2026')
    expect(rowOf(wrapper, 'Claude Code').get('button').text()).toBe('Révoquer')
  })

  it('confirmation acceptée : révoque, relit la liste et l\'annonce dans la région role="status"', async () => {
    const { wrapper, revoke, list, confirm } = await mountPage()

    await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
    await flushPromises()

    // La confirmation nomme le client.
    expect(confirm).toHaveBeenCalledOnce()
    expect(String(confirm.mock.calls[0]?.[0])).toContain('Claude Code')
    expect(revoke).toHaveBeenCalledExactlyOnceWith(ACTIVE.id)
    // Liste relue depuis le serveur : chargement initial, puis rechargement.
    expect(list).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="status"]').text()).toContain('révoquée')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('après une révocation, le focus passe sur le titre : le bouton cliqué a disparu', async () => {
    const { wrapper } = await mountPage({
      list: vi.fn().mockResolvedValueOnce(listOf([ACTIVE])).mockResolvedValue(listOf([{ ...ACTIVE, state: 'revoked', revokedAt: '2026-10-28T11:00:00+01:00', revocationCause: 'person' }])),
    })
    const button = wrapper.get('tbody button')
    ;(button.element as HTMLElement).focus()
    expect(document.activeElement).toBe(button.element)

    await button.trigger('click')
    await flushPromises()

    expect(wrapper.find('tbody button').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('h1').element)
  })

  it('confirmation refusée : aucun appel au serveur', async () => {
    const { wrapper, revoke, list } = await mountPage({ confirmAnswer: false })

    await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
    await flushPromises()

    expect(revoke).not.toHaveBeenCalled()
    expect(list).toHaveBeenCalledOnce()
    expect(wrapper.get('[role="status"]').text()).not.toContain('révoquée')
  })

  it('404 : dit que la connexion était déjà terminée, et relit la liste', async () => {
    const revoke = vi.fn(async () => {
      throw new McpConnectionNotFoundError()
    })
    const { wrapper, list } = await mountPage({ revoke })

    await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toContain('déjà terminée')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(list).toHaveBeenCalledTimes(2)
  })

  it('échec de la révocation : une alerte, la liste inchangée, un nouvel essai possible', async () => {
    const revoke = vi.fn(async () => {
      throw new McpConnectionsRequestFailedError()
    })
    const { wrapper, list } = await mountPage({ revoke })

    await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('n\'a pas pu être révoquée')
    expect(list).toHaveBeenCalledOnce()
    expect(rows(wrapper)).toHaveLength(4)
    expect(rowOf(wrapper, 'Claude Code').get('button').attributes('disabled')).toBeUndefined()
  })

  it('pendant une révocation, aucun bouton ne repart', async () => {
    let finish: () => void = () => undefined
    const revoke = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          finish = resolve
        }),
    )
    const { wrapper } = await mountPage({ revoke })

    await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
    await flushPromises()

    for (const button of wrapper.findAll('tbody button')) {
      expect(button.attributes('disabled')).toBeDefined()
    }
    expect(wrapper.get('[role="status"]').text()).not.toBe('')

    finish()
    await flushPromises()
  })

  it('erreur de chargement : une alerte et un bouton pour réessayer', async () => {
    const list = vi.fn().mockRejectedValueOnce(new McpConnectionsRequestFailedError()).mockResolvedValue(listOf([ACTIVE]))
    const { wrapper } = await mountPage({ list })

    expect(wrapper.get('[role="alert"]').text()).toContain('n\'ont pas pu être chargées')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Aucune connexion')

    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(list).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(rows(wrapper)).toHaveLength(1)
  })

  it('pendant le chargement : aria-busy, un message d\'état, ni tableau ni état vide', async () => {
    const list = vi.fn(() => new Promise<McpConnectionList>(() => undefined))
    const { wrapper } = await mountPage({ list })

    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.get('[role="status"]').text()).toContain('Chargement')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Aucune connexion')
  })

  it('une fois chargée, la région n\'est plus occupée', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toBe('')
  })

  it('liste : aucune violation d\'accessibilité détectable', async () => {
    const { wrapper } = await mountPage({ list: vi.fn(async () => listOf([ACTIVE, NEVER_USED, EXPIRED, REVOKED_BY_PERSON, REVOKED_UNKNOWN_CAUSE])) })

    await expectNoAccessibilityViolation(wrapper)
  })

  it('état vide : aucune violation d\'accessibilité détectable', async () => {
    const { wrapper } = await mountPage({ list: vi.fn(async () => listOf([])) })

    await expectNoAccessibilityViolation(wrapper)
  })

  it('erreur de chargement : aucune violation d\'accessibilité détectable', async () => {
    const list = vi.fn().mockRejectedValue(new McpConnectionsRequestFailedError())
    const { wrapper } = await mountPage({ list })

    await expectNoAccessibilityViolation(wrapper)
  })
})
