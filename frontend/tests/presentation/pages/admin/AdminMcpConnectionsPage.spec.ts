import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import AdminMcpConnectionsPage from '../../../../src/presentation/pages/admin/AdminMcpConnectionsPage.vue'
import { ADMIN_MCP_REPOSITORY } from '../../../../src/application/admin/mcp/useAdminMcpClients'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminMcpConnection } from '../../../../src/domain/admin/mcp/entities/AdminMcpConnection'
import type { AdminMcpRepository } from '../../../../src/domain/admin/mcp/repositories/AdminMcpRepository'
import { AdminMcpError } from '../../../../src/domain/admin/mcp/errors/AdminMcpError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { markSessionExpired } from '../../../../src/application/auth/useAuth'
import { signInAgain } from '../../../support/signInAgain'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import fr from '../../../../src/infrastructure/i18n/locales/fr.json'

// Des comptes aux noms neutres, des clients de test et des domaines `*.example` : aucun compte ni client réel.
const ACTIVE: AdminMcpConnection = {
  id: '019968a0-0000-7000-8000-000000000301',
  account: 'compte-actif',
  clientId: 'client-de-test',
  clientName: 'Client de test',
  clientRegistration: 'preregistered',
  grantedAt: '2026-11-20T09:05:00+00:00',
  codeExchanged: true,
  lastUsedAt: '2026-11-22T14:30:00+00:00',
  expiresAt: '2026-12-22T14:30:00+00:00',
  state: 'active',
  revokedAt: null,
  revocationCause: null,
}

/** Un accord abandonné : le code n'a jamais été échangé, la connexion est échue au bout d'une heure. */
const NEVER_COMPLETED: AdminMcpConnection = {
  id: '019968a0-0000-7000-8000-000000000302',
  account: 'compte-abandonne',
  clientId: 'https://declare.example/client.json',
  clientName: 'Client déclaré',
  clientRegistration: 'cimd',
  grantedAt: '2026-11-21T09:00:00+00:00',
  codeExchanged: false,
  lastUsedAt: null,
  expiresAt: '2026-11-21T10:00:00+00:00',
  state: 'expired',
  revokedAt: null,
  revocationCause: null,
}

/** Des jetons délivrés, jamais renouvelés : `lastUsedAt` ne s'écrit qu'au renouvellement. */
const NOT_RENEWED: AdminMcpConnection = {
  ...ACTIVE,
  id: '019968a0-0000-7000-8000-000000000303',
  account: 'compte-non-renouvele',
  lastUsedAt: null,
}

const REVOKED: AdminMcpConnection = {
  ...ACTIVE,
  id: '019968a0-0000-7000-8000-000000000304',
  account: 'compte-revoque',
  expiresAt: '2026-12-20T09:05:00+00:00',
  state: 'revoked',
  revokedAt: '2026-11-23T08:00:00+00:00',
  revocationCause: 'password-changed',
}

/** Une cause qu'un backend plus récent écrirait, inconnue de la table des libellés. */
const UNKNOWN_CAUSE: AdminMcpConnection = {
  ...REVOKED,
  id: '019968a0-0000-7000-8000-000000000305',
  account: 'compte-cause-inconnue',
  revocationCause: 'cause-a-venir',
}

/** Une cause qui ne vit qu'au journal aujourd'hui (D-B), lisible si une ligne la porte un jour. */
const CLIENT_REMOVED: AdminMcpConnection = {
  ...REVOKED,
  id: '019968a0-0000-7000-8000-000000000306',
  account: 'compte-client-supprime',
  revocationCause: 'client-removed',
}

/** Révoquée par la personne elle-même : la table partagée lui parle (« par vous »), la page parle à l'administrateur (R13). */
const REVOKED_BY_PERSON: AdminMcpConnection = {
  ...REVOKED,
  id: '019968a0-0000-7000-8000-000000000307',
  account: 'compte-revoque-par-la-personne',
  revocationCause: 'person',
}

const CONNECTIONS = [ACTIVE, NEVER_COMPLETED, NOT_RENEWED, REVOKED, UNKNOWN_CAUSE, CLIENT_REMOVED, REVOKED_BY_PERSON]

const TEXTS = fr.admin.mcpConnections

/** Les cellules `td` d'une ligne, dans l'ordre des colonnes qui suivent le compte (en-tête de ligne). */
const CELL = { client: 0, grantedAt: 1, lastUsedAt: 2, expiresAt: 3, state: 4, cause: 5 } as const

function createStubRepository(overrides: Partial<AdminMcpRepository> = {}): AdminMcpRepository {
  return {
    listClients: vi.fn(async () => []),
    registerClient: vi.fn(async () => {
      throw new Error('La page des connexions n’enregistre aucun client.')
    }),
    reviseClient: vi.fn(async () => {
      throw new Error('La page des connexions ne révise aucun client.')
    }),
    removeClient: vi.fn(async () => undefined),
    listConnections: vi.fn(async () => CONNECTIONS),
    ...overrides,
  }
}

async function mountPage(repository: AdminMcpRepository = createStubRepository()): Promise<VueWrapper> {
  const wrapper = mount(AdminMcpConnectionsPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [ADMIN_MCP_REPOSITORY as symbol]: repository },
    },
  })
  await flushPromises()

  return wrapper
}

/** La ligne du tableau dont l'en-tête de ligne est `account`, ou une erreur qui le nomme. */
function rowFor(wrapper: VueWrapper, account: string): DOMWrapper<HTMLTableRowElement> {
  const row = wrapper.findAll<HTMLTableRowElement>('tbody tr').find((candidate) => candidate.get('th').text() === account)
  if (!row) throw new Error(`Ligne « ${account} » introuvable.`)
  return row
}

function cellOf(wrapper: VueWrapper, account: string, column: keyof typeof CELL): DOMWrapper<Element> {
  const cell = rowFor(wrapper, account).findAll('td')[CELL[column]]
  if (!cell) throw new Error(`Colonne « ${column} » introuvable sur la ligne « ${account} ».`)
  return cell
}

/** `Intl` sépare la date de l'heure par des espaces insécables, étroites ou non, selon la version d'ICU. */
function normalized(text: string): string {
  return text.replace(/[  ]/g, ' ')
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('AdminMcpConnectionsPage', () => {
  describe('le tableau', () => {
    it('porte une légende et ses en-têtes de colonne, dans l’ordre', async () => {
      const wrapper = await mountPage()

      expect(wrapper.get('table caption').text()).toBe(TEXTS.caption)
      const headers = wrapper.findAll('thead th')
      expect(headers.map((header) => header.text())).toEqual([
        TEXTS.columns.account,
        TEXTS.columns.client,
        TEXTS.columns.grantedAt,
        TEXTS.columns.lastUsedAt,
        TEXTS.columns.expiresAt,
        TEXTS.columns.state,
        TEXTS.columns.cause,
      ])
      for (const header of headers) expect(header.attributes('scope')).toBe('col')
    })

    it('nomme chaque ligne par son compte, en en-tête de ligne, dans l’ordre du serveur', async () => {
      const wrapper = await mountPage()

      const rowHeaders = wrapper.findAll('tbody th')
      expect(rowHeaders.map((header) => header.text())).toEqual(CONNECTIONS.map((connection) => connection.account))
      for (const header of rowHeaders) expect(header.attributes('scope')).toBe('row')
    })

    it('montre le nom, l’identifiant et le mode d’enregistrement du client', async () => {
      const wrapper = await mountPage()

      const preregistered = cellOf(wrapper, ACTIVE.account, 'client').text()
      expect(preregistered).toContain(ACTIVE.clientName)
      expect(preregistered).toContain(ACTIVE.clientId)
      expect(preregistered).toContain(fr.admin.mcpClients.registrations.preregistered)
      expect(preregistered).not.toContain(fr.admin.mcpClients.registrations.cimd)

      const declared = cellOf(wrapper, NEVER_COMPLETED.account, 'client').text()
      expect(declared).toContain(NEVER_COMPLETED.clientName)
      expect(declared).toContain(NEVER_COMPLETED.clientId)
      expect(declared).toContain(fr.admin.mcpClients.registrations.cimd)
      expect(declared).not.toContain(fr.admin.mcpClients.registrations.preregistered)
    })

    it('date l’octroi et le dernier usage, l’instant reçu gardé pour la machine', async () => {
      const wrapper = await mountPage()

      const granted = cellOf(wrapper, ACTIVE.account, 'grantedAt').get('time')
      expect(granted.attributes('datetime')).toBe(ACTIVE.grantedAt)
      // 09:05 UTC est 10:05 à Paris en novembre : la date est écrite dans le fuseau du site.
      expect(normalized(granted.text())).toContain('20 nov. 2026')

      const lastUsed = cellOf(wrapper, ACTIVE.account, 'lastUsedAt').get('time')
      expect(lastUsed.attributes('datetime')).toBe(ACTIVE.lastUsedAt)
      expect(normalized(lastUsed.text())).toContain('22 nov. 2026')
    })

    it('dit « Jamais aboutie » sans dernier usage quand le code n’a jamais été échangé', async () => {
      const wrapper = await mountPage()

      const cell = cellOf(wrapper, NEVER_COMPLETED.account, 'lastUsedAt')
      expect(cell.text()).toBe(fr.mcpConnections.neverCompleted)
      expect(cell.text()).not.toContain(fr.mcpConnections.notRenewedYet)
      expect(cell.find('time').exists()).toBe(false)
    })

    it('dit « Pas encore renouvelée » sans dernier usage quand le code a été échangé', async () => {
      const wrapper = await mountPage()

      const cell = cellOf(wrapper, NOT_RENEWED.account, 'lastUsedAt')
      expect(cell.text()).toBe(fr.mcpConnections.notRenewedYet)
      expect(cell.text()).not.toContain(fr.mcpConnections.neverCompleted)
      expect(cell.find('time').exists()).toBe(false)
    })

    it('date l’échéance d’une ligne qui n’est pas révoquée, sans « Échéance prévue »', async () => {
      const wrapper = await mountPage()

      for (const connection of [ACTIVE, NEVER_COMPLETED]) {
        const cell = cellOf(wrapper, connection.account, 'expiresAt')
        expect(cell.findAll('time').map((time) => time.attributes('datetime'))).toEqual([connection.expiresAt])
        expect(cell.text()).not.toContain(TEXTS.plannedExpiry)
        expect(cell.text()).not.toContain(TEXTS.revokedOn)
      }
    })

    it('dit d’une ligne révoquée quand elle l’a été, puis son « Échéance prévue », les deux datées (R3)', async () => {
      const wrapper = await mountPage()

      const cell = cellOf(wrapper, REVOKED.account, 'expiresAt')
      expect(cell.text()).toContain(TEXTS.revokedOn)
      expect(cell.text()).toContain(TEXTS.plannedExpiry)
      // La révocation d'abord, l'échéance qu'elle a devancée ensuite.
      expect(cell.findAll('time').map((time) => time.attributes('datetime'))).toEqual([REVOKED.revokedAt, REVOKED.expiresAt])
    })

    it('traduit l’état de chaque ligne', async () => {
      const wrapper = await mountPage()

      expect(cellOf(wrapper, ACTIVE.account, 'state').text()).toBe(TEXTS.states.active)
      expect(cellOf(wrapper, NEVER_COMPLETED.account, 'state').text()).toBe(TEXTS.states.expired)
      expect(cellOf(wrapper, REVOKED.account, 'state').text()).toBe(TEXTS.states.revoked)
    })

    it('traduit une cause connue, `client-removed` compris', async () => {
      const wrapper = await mountPage()

      expect(cellOf(wrapper, REVOKED.account, 'cause').text()).toBe(fr.mcpConnections.causes.passwordChanged)
      expect(cellOf(wrapper, CLIENT_REMOVED.account, 'cause').text()).toBe(fr.mcpConnections.causes.clientRemoved)
    })

    it('nomme la révocation par la personne du point de vue de l’administrateur, pas « par vous » (R13)', async () => {
      const wrapper = await mountPage()

      const cell = cellOf(wrapper, REVOKED_BY_PERSON.account, 'cause')
      expect(cell.text()).toBe(TEXTS.causes.person)
      // La garde négative lit la clé de la table partagée : celle-ci reformulée, la garde suivrait.
      expect(cell.text()).not.toBe(fr.mcpConnections.causes.person)
    })

    it('tombe sur le libellé générique pour une cause inconnue, jamais sur la cause brute', async () => {
      const wrapper = await mountPage()

      const cell = cellOf(wrapper, UNKNOWN_CAUSE.account, 'cause')
      expect(cell.text()).toBe(fr.mcpConnections.causes.unknown)
      expect(cell.text()).not.toContain('cause-a-venir')
    })

    it('ne montre qu’un tiret d’une ligne sans cause, et le dit en mots au lecteur d’écran', async () => {
      const wrapper = await mountPage()

      const cell = cellOf(wrapper, ACTIVE.account, 'cause')
      expect(cell.get('[aria-hidden="true"]').text()).toBe('—')
      expect(cell.get('.visually-hidden').text()).toBe(TEXTS.noCause)
    })

    it('n’offre aucun geste : la page est en lecture seule', async () => {
      const repository = createStubRepository()
      const wrapper = await mountPage(repository)

      expect(wrapper.findAll('button')).toHaveLength(0)
      expect(wrapper.find('form').exists()).toBe(false)
      expect(repository.listConnections).toHaveBeenCalledTimes(1)
      expect(repository.listClients).not.toHaveBeenCalled()
    })
  })

  describe('la note de borne', () => {
    it('dit en permanence, au-dessus du tableau, que les terminées affichées sont les 200 plus récentes', async () => {
      const wrapper = await mountPage()

      const note = wrapper.findAll('p').find((paragraph) => paragraph.text() === TEXTS.terminatedBound)
      expect(note, 'note de borne absente').toBeDefined()
      // Au-dessus du tableau : dans l'ordre du document, la note précède le tableau.
      const table = wrapper.get('table').element
      expect(note?.element.compareDocumentPosition(table)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
    })

    it('garde la note quand la liste est vide', async () => {
      const wrapper = await mountPage(createStubRepository({ listConnections: vi.fn(async () => []) }))

      expect(wrapper.text()).toContain(TEXTS.empty)
      expect(wrapper.text()).toContain(TEXTS.terminatedBound)
      expect(wrapper.find('table').exists()).toBe(false)
    })
  })

  describe('le chargement', () => {
    it('annonce la lecture en cours, sans état vide ni tableau', async () => {
      // Une lecture qui ne répond jamais : la page reste dans son état de chargement.
      const wrapper = await mountPage(createStubRepository({ listConnections: vi.fn(() => new Promise<never>(() => {})) }))

      expect(wrapper.text()).toContain(TEXTS.loading)
      expect(wrapper.text()).not.toContain(TEXTS.empty)
      expect(wrapper.find('table').exists()).toBe(false)
    })

    it('annonce l’échec de la lecture, sans état vide ni tableau', async () => {
      const wrapper = await mountPage(
        createStubRepository({
          listConnections: vi.fn(async () => {
            throw new AdminMcpError('unknown', 'x')
          }),
        }),
      )

      expect(wrapper.get('[role="alert"]').text()).toBe(TEXTS.loadError)
      expect(wrapper.text()).not.toContain(TEXTS.empty)
      expect(wrapper.find('table').exists()).toBe(false)
    })

    describe('non chargée (#748, R4)', () => {
      afterEach(async () => {
        await signInAgain()
      })

      it('première lecture en session finie : pas de « Aucune connexion », la mention « non chargé »', async () => {
        const wrapper = await mountPage(
          createStubRepository({
            listConnections: vi.fn(async () => {
              markSessionExpired()
              throw new SessionEndedError()
            }),
          }),
        )

        // « Aucune connexion » serait une affirmation que personne n'a vérifiée.
        expect(wrapper.text()).not.toContain(TEXTS.empty)
        expect(wrapper.text()).toContain(fr.admin.notLoaded)
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
        // La note de borne ne dépend pas de la lecture : elle reste.
        expect(wrapper.text()).toContain(TEXTS.terminatedBound)
      })
    })
  })

  describe('l’accessibilité', () => {
    it('ne présente aucune violation, tableau de toutes les sortes de ligne rendu', async () => {
      const wrapper = await mountPage()

      await expectNoAccessibilityViolation(wrapper)
    })

    it('ne présente aucune violation, liste vide', async () => {
      const wrapper = await mountPage(createStubRepository({ listConnections: vi.fn(async () => []) }))

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
