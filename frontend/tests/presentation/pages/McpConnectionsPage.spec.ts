/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import McpConnectionsPage from '../../../src/presentation/pages/McpConnectionsPage.vue'
import { MCP_CONNECTIONS_REPOSITORY } from '../../../src/application/mcp/useMcpConnections'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { McpConnectionsRepository } from '../../../src/domain/mcp/repositories/McpConnectionsRepository'
import type { McpConnectionList, McpConnectionSummary } from '../../../src/domain/mcp/entities/McpConnection'
import { McpConnectionNotFoundError } from '../../../src/domain/mcp/errors/McpConnectionNotFoundError'
import { McpConnectionsRequestFailedError } from '../../../src/domain/mcp/errors/McpConnectionsRequestFailedError'
import { SessionEndedError } from '../../../src/domain/auth/errors/SessionEndedError'
import { AccessForbiddenError } from '../../../src/domain/auth/errors/AccessForbiddenError'
import { AUTH_REPOSITORY } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { ANONYMOUS_SESSION, BASE_ACCESS_SESSION, type AuthSession } from '../../../src/domain/auth/entities/AuthSession'
import type { Locale } from '../../../src/domain/portfolio/entities/Locale'
import { expectNoAccessibilityViolation } from '../../support/axe'
import { listOf } from '../../support/mcpConnectionList'
import { signInAgain } from '../../support/signInAgain'

enableAutoUnmount(afterEach)

// Seuls `setTimeout`/`clearTimeout` sont simulés : `flushPromises` s'appuie sur `setImmediate`,
// qui doit rester réel. Le délai d'annonce (focus d'abord, annonce ensuite) est ainsi piloté
// par le test, et rétabli pour les suivants.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

/** axe-core s'appuie sur de vraies minuteries : il ne tourne pas sous les faux minuteurs. */
async function auditAccessibility(wrapper: VueWrapper): Promise<void> {
  vi.useRealTimers()
  await expectNoAccessibilityViolation(wrapper)
}

/** Laisse passer le délai entre le déplacement du focus et l'écriture de l'annonce. */
async function letTheAnnouncementThrough(): Promise<void> {
  await vi.runAllTimersAsync()
  await flushPromises()
}

const StubPage = { template: '<div />' }

/** Active et utilisée (rafraîchie) : un des deux boutons « Révoquer » de la liste. */
const ACTIVE: McpConnectionSummary = {
  id: '0194f6a0-0000-7000-8000-000000000001',
  client: { id: 'claude-code', name: 'Claude Code' },
  grantedAt: '2026-10-28T09:00:00+01:00',
  codeExchanged: true,
  lastUsedAt: '2026-10-28T10:30:00+01:00',
  expiresAt: '2026-11-27T09:00:00+01:00',
  state: 'active',
  revokedAt: null,
  revocationCause: null,
}

/** Code échangé, jetons reçus, mais pas encore rafraîchie : `last_used_at` n'est écrit qu'au rafraîchissement. */
const NEVER_USED: McpConnectionSummary = {
  ...ACTIVE,
  id: '0194f6a0-0000-7000-8000-000000000002',
  client: { id: 'other-client', name: 'Autre Client' },
  lastUsedAt: null,
}

/** L'accord n'a jamais abouti : le code n'a pas été échangé, le client n'a reçu aucun jeton. */
const ABANDONED: McpConnectionSummary = {
  ...ACTIVE,
  id: '0194f6a0-0000-7000-8000-000000000006',
  client: { id: 'abandoned-client', name: 'Client Abandonné' },
  codeExchanged: false,
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
  // Une connexion révoquée n'a plus d'échéance : le serveur l'écrit `null`.
  expiresAt: null,
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
  me: ReturnType<typeof vi.fn>
}

interface Options {
  locale?: Locale
  list?: ReturnType<typeof vi.fn>
  revoke?: ReturnType<typeof vi.fn>
  confirmAnswer?: boolean
  /** `/api/me`, relu sur un refus réel : le palier de base par défaut. */
  me?: Mock<() => Promise<AuthSession>>
}

/** Le texte du refus réel, tel que dit à la personne (#748, R7). */
const REFUSAL_FR =
  "Le serveur refuse cette demande à votre session : votre compte n'a peut-être pas, ou plus, l'accès de confiance, ou la session doit être rouverte. Changez de compte, ou reconnectez-vous au même, pour continuer."
const REFUSAL_EN =
  'The server refuses this request to your session: your account may not have, or may no longer have, trusted access, or the session needs to be reopened. Switch account, or sign in again to the same one, to continue.'

async function mountPage(options: Options = {}): Promise<Harness> {
  const locale = options.locale ?? 'fr'
  const list = options.list ?? vi.fn(async () => listOf([ACTIVE, NEVER_USED, EXPIRED, REVOKED_BY_PERSON]))
  const revoke = options.revoke ?? vi.fn(async () => undefined)
  const repository = { list, revoke } as unknown as McpConnectionsRepository
  // Un refus réel relit /api/me (`checkAuth`) : un faux dépôt d'auth, qui répond le palier de base.
  const me = options.me ?? vi.fn<() => Promise<AuthSession>>(async () => BASE_ACCESS_SESSION)
  const authRepository: AuthRepository = { login: vi.fn(), logout: vi.fn(), hasWriteToken: () => true, me }

  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(options.confirmAnswer ?? true)

  const i18n = createAppI18n()
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      // `requiresAuth`, comme la vraie route : sans lui le lien de connexion n'a pas de `redirect` (#748, R16, F8).
      { path: '/:locale(fr|en)/mcp-connections', name: 'mcp-connections', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/connect-your-assistant', name: 'connect-assistant', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push(`/${locale}/mcp-connections`)
  await router.isReady()

  const wrapper = mount(McpConnectionsPage, {
    // Rattaché au document : le focus n'existe pas dans un arbre détaché.
    attachTo: document.body,
    global: {
      plugins: [router, i18n],
      provide: { [MCP_CONNECTIONS_REPOSITORY as symbol]: repository, [AUTH_REPOSITORY as symbol]: authRepository },
    },
  })
  await flushPromises()

  return { wrapper, list, revoke, confirm, me }
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

    // Échangée mais pas encore rafraîchie : « jamais utilisée » serait faux (#650, R5a).
    expect(rowOf(wrapper, 'Autre Client').text()).toContain('Pas encore renouvelée')
    expect(rowOf(wrapper, 'Autre Client').text()).not.toContain('Jamais')
    expect(rowOf(wrapper, 'Client Échu').text()).toContain('Échue')
  })

  it('liste les connexions en anglais', async () => {
    const { wrapper } = await mountPage({ locale: 'en' })

    expect(wrapper.get('h1').text()).toBe('My MCP connections')
    const active = normalized(rowOf(wrapper, 'Claude Code').text())
    expect(active).toContain('Oct 28, 2026')
    expect(active).toContain('9:00 AM')
    expect(active).toContain('Active')
    expect(rowOf(wrapper, 'Autre Client').text()).toContain('Not renewed yet')
    expect(rowOf(wrapper, 'Client Échu').text()).toContain('Expired')
    expect(rowOf(wrapper, 'Client Révoqué').text()).toContain('Revoked by you')
  })

  it('distingue un accord abandonné (code jamais échangé) d\'une connexion échangée pas encore rafraîchie', async () => {
    const list = vi.fn(async () => listOf([ABANDONED, NEVER_USED, ACTIVE]))
    const { wrapper } = await mountPage({ list })

    expect(rowOf(wrapper, 'Client Abandonné').text()).toContain('Jamais aboutie')
    expect(rowOf(wrapper, 'Client Abandonné').text()).not.toContain('Pas encore renouvelée')
    expect(rowOf(wrapper, 'Autre Client').text()).toContain('Pas encore renouvelée')
    expect(rowOf(wrapper, 'Autre Client').text()).not.toContain('Jamais aboutie')
    // Utilisée : la date, ni l'un ni l'autre.
    expect(rowOf(wrapper, 'Claude Code').text()).not.toContain('Jamais aboutie')
    expect(rowOf(wrapper, 'Claude Code').text()).not.toContain('Pas encore renouvelée')
  })

  it('distingue les deux cas en anglais', async () => {
    const list = vi.fn(async () => listOf([ABANDONED, NEVER_USED]))
    const { wrapper } = await mountPage({ locale: 'en', list })

    expect(rowOf(wrapper, 'Client Abandonné').text()).toContain('Never completed')
    expect(rowOf(wrapper, 'Autre Client').text()).toContain('Not renewed yet')
  })

  it("n'affiche aucune échéance pour une connexion révoquée, et dit pourquoi la case est vide", async () => {
    const { wrapper } = await mountPage()

    const revoked = rowOf(wrapper, 'Client Révoqué')
    const expiryCell = revoked.findAll('td')[2]
    expect(expiryCell?.find('time').exists()).toBe(false)
    expect(expiryCell?.text()).toContain('Sans échéance')
    // Les autres lignes gardent la leur.
    expect(rowOf(wrapper, 'Claude Code').findAll('td')[2]?.find('time').exists()).toBe(true)
    expect(rowOf(wrapper, 'Client Échu').findAll('td')[2]?.find('time').exists()).toBe(true)
  })

  it("dit « No expiry » en anglais pour une connexion révoquée", async () => {
    const { wrapper } = await mountPage({ locale: 'en' })

    expect(rowOf(wrapper, 'Client Révoqué').findAll('td')[2]?.text()).toContain('No expiry')
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

  describe('le tableau (#748)', () => {
    it("le conteneur défilant du tableau est positionné : rien de masqué n'en sort à 320 px", async () => {
      const { wrapper } = await mountPage()

      // `visually-hidden` est en `position: absolute` : sans ancêtre positionné dans le conteneur
      // défilant, l'en-tête « Actions » et « Sans échéance » se placent hors de lui et élargissent la page.
      expect(wrapper.get('.table-responsive').classes()).toContain('position-relative')
    })

    it('historique coupé : le tableau est décrit par la mention', async () => {
      const list = vi.fn(async () => listOf([ACTIVE, REVOKED_BY_PERSON], true))
      const { wrapper } = await mountPage({ list })

      const noteId = wrapper.get('[data-testid="terminated-truncated"]').attributes('id')
      expect(noteId).toBeTruthy()
      expect(wrapper.get('table').attributes('aria-describedby')).toBe(noteId)
    })

    it("historique entier : le tableau n'a pas d'aria-describedby", async () => {
      const { wrapper } = await mountPage()

      // Une référence vers un id absent est une faute : la mention n'est pas rendue ici.
      expect(wrapper.get('table').attributes('aria-describedby')).toBeUndefined()
    })

    it('le conteneur ne défile jamais à la verticale : overflow-y hidden en CSS scopé', () => {
      // jsdom ne calcule aucune mise en page : la garde lit le source du composant (la mesure réelle est faite au navigateur).
      const source = readFileSync(resolve(import.meta.dirname, '../../../src/presentation/pages/McpConnectionsPage.vue'), 'utf8')
      const style = /<style scoped>([\s\S]*?)<\/style>/.exec(source)?.[1] ?? ''

      expect(style).toMatch(/\.table-responsive\s*\{[^}]*overflow-y:\s*hidden/)
    })
  })

  it("avec la mention d'un historique coupé : aucune violation d'accessibilité détectable", async () => {
    const list = vi.fn(async () => listOf([ACTIVE, REVOKED_BY_PERSON], true))
    const { wrapper } = await mountPage({ list })

    await auditAccessibility(wrapper)
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
    await letTheAnnouncementThrough()

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
    await letTheAnnouncementThrough()

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

  it('pendant le premier chargement : un message d\'état, ni tableau ni état vide, et aucun aria-busy sur la section', async () => {
    const list = vi.fn(() => new Promise<McpConnectionList>(() => undefined))
    const { wrapper } = await mountPage({ list })

    // `aria-busy` sur un ancêtre de la région `status` la ferait taire (#748, constat 11).
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false)
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
    const { wrapper } = await mountPage({ list: vi.fn(async () => listOf([ACTIVE, NEVER_USED, ABANDONED, EXPIRED, REVOKED_BY_PERSON, REVOKED_UNKNOWN_CAUSE])) })

    await auditAccessibility(wrapper)
  })

  it('état vide : aucune violation d\'accessibilité détectable', async () => {
    const { wrapper } = await mountPage({ list: vi.fn(async () => listOf([])) })

    await auditAccessibility(wrapper)
  })

  it('erreur de chargement : aucune violation d\'accessibilité détectable', async () => {
    const list = vi.fn().mockRejectedValue(new McpConnectionsRequestFailedError())
    const { wrapper } = await mountPage({ list })

    await auditAccessibility(wrapper)
  })

  describe('focus et annonces (#748)', () => {
    /** La liste telle que le serveur la rend une fois « Claude Code » révoquée. */
    const AFTER_REVOCATION = listOf([{ ...ACTIVE, state: 'revoked', expiresAt: null, revokedAt: '2026-10-28T11:00:00+01:00', revocationCause: 'person' }, NEVER_USED])

    it("après une révocation, le titre a le focus avant que l'issue soit annoncée", async () => {
      const list = vi.fn().mockResolvedValueOnce(listOf([ACTIVE, NEVER_USED])).mockResolvedValue(AFTER_REVOCATION)
      const { wrapper } = await mountPage({ list })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()

      // Le focus d'abord : une annonce écrite en même temps pourrait être coupée par la lecture du titre.
      expect(document.activeElement).toBe(wrapper.get('h1').element)
      expect(wrapper.get('[role="status"]').text()).toBe('')

      await letTheAnnouncementThrough()

      expect(wrapper.get('[role="status"]').text()).toContain('révoquée')
    })

    it("le démontage annule l'annonce en attente", async () => {
      const list = vi.fn().mockResolvedValueOnce(listOf([ACTIVE, NEVER_USED])).mockResolvedValue(AFTER_REVOCATION)
      const { wrapper } = await mountPage({ list })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()
      expect(vi.getTimerCount()).toBe(1)

      wrapper.unmount()

      expect(vi.getTimerCount()).toBe(0)
      await expect(letTheAnnouncementThrough()).resolves.toBeUndefined()
    })

    it("une révocation qui aboutit après le démontage ne programme aucune annonce", async () => {
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
      wrapper.unmount()
      finish()
      await flushPromises()

      expect(vi.getTimerCount()).toBe(0)
    })

    it("une seconde révocation annule l'annonce de la première", async () => {
      const list = vi.fn().mockResolvedValueOnce(listOf([ACTIVE, NEVER_USED])).mockResolvedValue(AFTER_REVOCATION)
      const { wrapper } = await mountPage({ list })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()
      await rowOf(wrapper, 'Autre Client').get('button').trigger('click')
      await flushPromises()

      // Une seule annonce en attente, jamais deux écritures qui se doublent.
      expect(vi.getTimerCount()).toBe(1)
    })

    it("« Réessayer » efface l'issue d'une révocation déjà annoncée", async () => {
      // La révocation aboutit, la relecture qui la suit échoue : l'issue « révoquée » est annoncée,
      // puis la personne relance la lecture.
      let succeed: (list: McpConnectionList) => void = () => undefined
      const list = vi
        .fn()
        .mockResolvedValueOnce(listOf([ACTIVE, NEVER_USED]))
        .mockRejectedValueOnce(new McpConnectionsRequestFailedError())
        .mockImplementationOnce(
          () =>
            new Promise<McpConnectionList>((resolve) => {
              succeed = resolve
            }),
        )
      const { wrapper } = await mountPage({ list })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()
      await letTheAnnouncementThrough()
      expect(wrapper.get('[role="status"]').text()).toContain('révoquée')

      await wrapper.get('[role="alert"] button').trigger('click')
      await flushPromises()

      // Pendant la relecture : la région ne dit plus « révoquée » (elle dit le chargement).
      expect(wrapper.get('[role="status"]').text()).not.toContain('révoquée')

      succeed(listOf([ACTIVE]))
      await flushPromises()
      await letTheAnnouncementThrough()

      // Après : l'issue d'avant n'est pas revenue, une nouvelle lecture n'est pas une révocation.
      expect(wrapper.get('[role="status"]').text()).toBe('')
    })

    it('échec de la révocation : le focus reste sur le bouton « Révoquer »', async () => {
      // Un navigateur retire le focus d'un bouton qui devient `disabled` : on le rejoue.
      const revoke = vi.fn(async () => {
        ;(document.activeElement as HTMLElement | null)?.blur()
        throw new McpConnectionsRequestFailedError()
      })
      const { wrapper } = await mountPage({ revoke })
      const button = rowOf(wrapper, 'Claude Code').get('button')
      ;(button.element as HTMLElement).focus()

      await button.trigger('click')
      await flushPromises()

      expect(wrapper.get('[role="alert"]').text()).toContain("n'a pas pu être révoquée")
      expect(document.activeElement).toBe(button.element)
    })

    it('session finie à la révocation : le focus passe au titre, où l\'alerte et son lien sont lus', async () => {
      const revoke = vi.fn(async () => {
        ;(document.activeElement as HTMLElement | null)?.blur()
        throw new SessionEndedError()
      })
      const { wrapper } = await mountPage({ revoke })
      const button = rowOf(wrapper, 'Claude Code').get('button')
      ;(button.element as HTMLElement).focus()

      await button.trigger('click')
      await flushPromises()

      expect(document.activeElement).toBe(wrapper.get('h1').element)
    })

    it('« Réessayer » réussi : le focus passe au titre', async () => {
      let succeed: (list: McpConnectionList) => void = () => undefined
      const list = vi
        .fn()
        .mockRejectedValueOnce(new McpConnectionsRequestFailedError())
        .mockImplementationOnce(
          () =>
            new Promise<McpConnectionList>((resolve) => {
              succeed = resolve
            }),
        )
      const { wrapper } = await mountPage({ list })
      const retry = wrapper.get('[role="alert"] button')
      ;(retry.element as HTMLElement).focus()

      await retry.trigger('click')
      await flushPromises()
      // Le bouton a disparu pendant la relecture : le focus est tombé sur <body>.
      expect(wrapper.find('[role="alert"] button').exists()).toBe(false)

      succeed(listOf([ACTIVE]))
      await flushPromises()

      expect(rows(wrapper)).toHaveLength(1)
      expect(document.activeElement).toBe(wrapper.get('h1').element)
    })

    it('« Réessayer » en échec : le focus passe au nouveau « Réessayer »', async () => {
      let fail: (error: Error) => void = () => undefined
      const list = vi
        .fn()
        .mockRejectedValueOnce(new McpConnectionsRequestFailedError())
        .mockImplementationOnce(
          () =>
            new Promise<McpConnectionList>((_resolve, reject) => {
              fail = reject
            }),
        )
      const { wrapper } = await mountPage({ list })
      const retry = wrapper.get('[role="alert"] button')
      ;(retry.element as HTMLElement).focus()

      await retry.trigger('click')
      await flushPromises()
      expect(wrapper.find('[role="alert"] button').exists()).toBe(false)

      fail(new McpConnectionsRequestFailedError())
      await flushPromises()

      const renewed = wrapper.get('[role="alert"] button')
      expect(renewed.element).not.toBe(retry.element)
      expect(document.activeElement).toBe(renewed.element)
    })

    it("la confirmation nomme le client et la date de l'accord, en français", async () => {
      const { wrapper, confirm } = await mountPage()

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()

      const question = normalized(String(confirm.mock.calls[0]?.[0]))
      expect(question).toContain('Claude Code')
      expect(question).toContain('accordée le')
      expect(question).toContain('28 oct. 2026')
    })

    it("la confirmation nomme le client et la date de l'accord, en anglais", async () => {
      const { wrapper, confirm } = await mountPage({ locale: 'en' })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()

      const question = normalized(String(confirm.mock.calls[0]?.[0]))
      expect(question).toContain('Claude Code')
      expect(question).toContain('granted on')
      expect(question).toContain('Oct 28, 2026')
    })

    it("pendant la relecture qui suit une révocation, c'est le tableau qui est occupé, pas la région d'état", async () => {
      let finish: (list: McpConnectionList) => void = () => undefined
      const list = vi
        .fn()
        .mockResolvedValueOnce(listOf([ACTIVE, NEVER_USED]))
        .mockImplementationOnce(
          () =>
            new Promise<McpConnectionList>((resolve) => {
              finish = resolve
            }),
        )
      const { wrapper } = await mountPage({ list })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()

      expect(wrapper.get('.table-responsive').attributes('aria-busy')).toBe('true')
      const status = wrapper.get('[role="status"]').element
      for (let node: Element | null = status; null !== node; node = node.parentElement) {
        expect(node.getAttribute('aria-busy')).not.toBe('true')
      }

      finish(AFTER_REVOCATION)
      await flushPromises()
      expect(wrapper.get('.table-responsive').attributes('aria-busy')).toBe('false')
    })
  })

  describe('session finie (401/403 du serveur)', () => {
    it('au chargement : dit que la session a pris fin et propose de se reconnecter, vers cette page', async () => {
      const list = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ list })

      const alert = wrapper.get('[role="alert"]')
      expect(alert.text()).toContain('Votre session a pris fin')
      const link = alert.get('a')
      expect(link.text()).toContain('Se reconnecter')
      const href = link.attributes('href') ?? ''
      expect(href.startsWith('/fr/login?')).toBe(true)
      const query = new URLSearchParams(href.split('?')[1])
      expect(query.get('redirect')).toBe('/fr/mcp-connections')
      // La page de connexion dit pourquoi elle s'ouvre.
      expect(query.get('expired')).toBe('1')
      // Réessayer enverrait la même requête sans session : le bouton n'a pas de sens.
      expect(alert.find('button').exists()).toBe(false)
      expect(wrapper.text()).not.toContain("n'ont pas pu être chargées")
    })

    it("session finie : le lien de reconnexion s'ouvre dans un nouvel onglet et ramène ici", async () => {
      const list = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ list })

      // Dans le même onglet, la personne quitterait la page : un nouvel onglet, dit au texte (WCAG 3.2.5) et à l'œil (#748, R6).
      const link = wrapper.get('[role="alert"] a')
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener')
      expect(link.text()).toBe("Se reconnecter (s'ouvre dans un nouvel onglet)")
      expect(link.find('svg[aria-hidden="true"]').exists()).toBe(true)
      expect(new URLSearchParams((link.attributes('href') ?? '').split('?')[1]).get('redirect')).toBe('/fr/mcp-connections')
    })

    it('en anglais, le lien garde la langue de la page', async () => {
      const list = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ locale: 'en', list })

      const alert = wrapper.get('[role="alert"]')
      expect(alert.text()).toContain('Your session has ended')
      expect(alert.get('a').text()).toBe('Sign in again (opens in a new tab)')
      expect(alert.get('a').attributes('href')).toMatch(/^\/en\/login\?/)
    })

    it("pendant une révocation : même message et même lien, et pas l'alerte « n'a pas pu être révoquée »", async () => {
      const revoke = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ revoke })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()

      const alerts = wrapper.findAll('[role="alert"]')
      expect(alerts).toHaveLength(1)
      expect(alerts[0]?.text()).toContain('Votre session a pris fin')
      expect(new URLSearchParams((alerts[0]?.get('a').attributes('href') ?? '').split('?')[1]).get('redirect')).toBe('/fr/mcp-connections')
      expect(wrapper.text()).not.toContain("n'a pas pu être révoquée")
    })

    it("révocation refusée pour session finie, puis retour : aucune alerte « révocation échouée » que personne n'a déclenchée", async () => {
      const revoke = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ revoke })
      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()
      expect(wrapper.text()).toContain('Votre session a pris fin')

      // Le compte revient (autre onglet) : la liste est relue, et l'issue de la révocation refusée s'efface avec le refus.
      await signInAgain()
      await flushPromises()

      expect(wrapper.text()).not.toContain('Votre session a pris fin')
      expect(wrapper.text()).not.toContain("n'a pas pu être révoquée")
      expect(wrapper.findAll('[role="alert"]')).toHaveLength(0)
      expect(rows(wrapper)).toHaveLength(4)
    })

    it('session finie à la révocation : tous les boutons « Révoquer » sont désactivés', async () => {
      const revoke = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ revoke })

      await rowOf(wrapper, 'Claude Code').get('button').trigger('click')
      await flushPromises()

      const buttons = wrapper.findAll('tbody button')
      expect(buttons).toHaveLength(2)
      // Se reconnecter est le seul remède : une nouvelle tentative partirait sans session.
      for (const button of buttons) {
        expect(button.attributes('disabled')).toBeDefined()
      }
    })

    it("un autre échec de chargement garde le message générique et le bouton « Réessayer », sans lien de reconnexion", async () => {
      const list = vi.fn().mockRejectedValue(new McpConnectionsRequestFailedError())
      const { wrapper } = await mountPage({ list })

      const alert = wrapper.get('[role="alert"]')
      expect(alert.text()).toContain("n'ont pas pu être chargées")
      expect(alert.find('a').exists()).toBe(false)
      expect(alert.find('button').exists()).toBe(true)
    })

    it('session finie : aucune violation d\'accessibilité détectable', async () => {
      const list = vi.fn().mockRejectedValue(new SessionEndedError())
      const { wrapper } = await mountPage({ list })

      await auditAccessibility(wrapper)
    })
  })

  describe('refus réel (403 qui ne finit pas la session, #748, R7)', () => {
    it.each<[Locale, string]>([
      ['fr', REFUSAL_FR],
      ['en', REFUSAL_EN],
    ])(
      '%s : message neutre, « Changer de compte » vers login?reauth=1&redirect=<ce chemin>, aucun « Réessayer »',
      async (locale, message) => {
        const list = vi.fn().mockRejectedValue(new AccessForbiddenError())
        const { wrapper } = await mountPage({ locale, list })

        const alert = wrapper.get('[role="alert"]')
        expect(normalized(alert.text())).toContain(message)
        // Ni l'échec générique, ni sa nouvelle tentative : la même requête prendrait le même refus.
        expect(wrapper.text()).not.toContain("n'ont pas pu être chargées")
        expect(wrapper.text()).not.toContain('could not be loaded')
        expect(alert.find('button').exists()).toBe(false)
        const link = alert.get('a')
        expect(link.text()).toBe('fr' === locale ? 'Changer de compte' : 'Switch account')
        // Même onglet : la conversation, la saisie d'une autre page ne sont pas ici en jeu, le flux est un changement de compte.
        expect(link.attributes('target')).toBeUndefined()
        const href = link.attributes('href') ?? ''
        expect(href.startsWith(`/${locale}/login?`)).toBe(true)
        const query = new URLSearchParams(href.split('?')[1])
        expect(query.get('reauth')).toBe('1')
        expect(query.get('redirect')).toBe(`/${locale}/mcp-connections`)
      },
    )

    it("le refus relit /api/me une fois, sans marquer la session finie", async () => {
      const list = vi.fn().mockRejectedValue(new AccessForbiddenError())
      const { wrapper, me } = await mountPage({ list })

      expect(wrapper.find('[role="alert"]').exists()).toBe(true)
      expect(me).toHaveBeenCalledTimes(1)
    })

    it('refus à la révocation : la liste reste, tous les « Révoquer » sont désactivés, le focus passe au titre', async () => {
      const revoke = vi.fn(async () => {
        ;(document.activeElement as HTMLElement | null)?.blur()
        throw new AccessForbiddenError()
      })
      const { wrapper } = await mountPage({ revoke })
      const button = rowOf(wrapper, 'Claude Code').get('button')
      ;(button.element as HTMLElement).focus()

      await button.trigger('click')
      await flushPromises()

      const alerts = wrapper.findAll('[role="alert"]')
      expect(alerts).toHaveLength(1)
      expect(normalized(alerts[0]?.text() ?? '')).toContain(REFUSAL_FR)
      expect(wrapper.text()).not.toContain("n'a pas pu être révoquée")
      expect(rows(wrapper)).toHaveLength(4)
      for (const revokeButton of wrapper.findAll('tbody button')) {
        expect(revokeButton.attributes('disabled')).toBeDefined()
      }
      expect(document.activeElement).toBe(wrapper.get('h1').element)
    })

    it("refus réel puis /api/me en 401 : la page dit la session finie, avec le lien de reconnexion et non « Changer de compte » (R15)", async () => {
      const list = vi.fn().mockRejectedValue(new AccessForbiddenError())
      const me = vi.fn<() => Promise<AuthSession>>(async () => ANONYMOUS_SESSION)
      const { wrapper } = await mountPage({ list, me })

      const alert = wrapper.get('[role="alert"]')
      expect(alert.text()).toContain('Votre session a pris fin')
      const link = alert.get('a')
      expect(link.text()).toContain('Se reconnecter')
      expect(link.attributes('target')).toBe('_blank')
      expect(new URLSearchParams((link.attributes('href') ?? '').split('?')[1]).get('reauth')).toBeNull()
      expect(wrapper.text()).not.toContain('Changer de compte')

      // Rétablit la session partagée pour les tests suivants. Le retour relit la liste (toujours refusée) : `/api/me`
      // répond désormais le palier de base, sans quoi cette relecture finirait la session une seconde fois.
      me.mockResolvedValue(BASE_ACCESS_SESSION)
      await signInAgain()
    })

    it('un refus ne se confond pas avec une session finie : pas de lien en nouvel onglet', async () => {
      const list = vi.fn().mockRejectedValue(new AccessForbiddenError())
      const { wrapper } = await mountPage({ list })

      expect(wrapper.text()).not.toContain('Votre session a pris fin')
      expect(wrapper.find('a[target="_blank"]').exists()).toBe(false)
    })

    it('refus : aucune violation d\'accessibilité détectable', async () => {
      const list = vi.fn().mockRejectedValue(new AccessForbiddenError())
      const { wrapper } = await mountPage({ list })

      await auditAccessibility(wrapper)
    })
  })
})
