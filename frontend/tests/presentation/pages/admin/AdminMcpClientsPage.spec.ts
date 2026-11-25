import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import AdminMcpClientsPage from '../../../../src/presentation/pages/admin/AdminMcpClientsPage.vue'
import BaseTextInput from '../../../../src/presentation/ui/BaseTextInput.vue'
import BaseTextarea from '../../../../src/presentation/ui/BaseTextarea.vue'
import { ADMIN_MCP_REPOSITORY } from '../../../../src/application/admin/mcp/useAdminMcpClients'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminMcpClient } from '../../../../src/domain/admin/mcp/entities/AdminMcpClient'
import type { AdminMcpRepository } from '../../../../src/domain/admin/mcp/repositories/AdminMcpRepository'
import { AdminMcpError, type AdminMcpErrorReason } from '../../../../src/domain/admin/mcp/errors/AdminMcpError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { markSessionExpired } from '../../../../src/application/auth/useAuth'
import { signInAgain } from '../../../support/signInAgain'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import { buttonLabelled } from '../../../support/buttonLabelled'
import { renderedMessage } from '../../../support/renderedMessage'
import fr from '../../../../src/infrastructure/i18n/locales/fr.json'

// Des noms neutres et des domaines `*.example` : aucun client réel, aucun compte réel.
const PREREGISTERED: AdminMcpClient = {
  id: '019968a0-0000-7000-8000-000000000201',
  clientId: 'client-de-test',
  name: 'Client de test',
  redirectUris: ['https://client.example/callback', 'https://client.example/retour'],
  registration: 'preregistered',
  registeredAt: '2026-11-20T10:00:00+00:00',
  activeConnections: 3,
  shadowsVerifiedDomain: false,
}

const DECLARED: AdminMcpClient = {
  id: '019968a0-0000-7000-8000-000000000202',
  clientId: 'https://declare.example/client.json',
  name: 'Client déclaré',
  redirectUris: ['https://declare.example/callback'],
  registration: 'cimd',
  registeredAt: '2026-11-21T10:00:00+00:00',
  activeConnections: 1,
  shadowsVerifiedDomain: false,
}

/** Une ligne pré-enregistrée écrite avant que son domaine entre dans la liste autorisée : elle le masque (R4). */
const SHADOWING: AdminMcpClient = {
  id: '019968a0-0000-7000-8000-000000000203',
  clientId: 'https://verifie.example/client.json',
  name: 'Client masquant',
  redirectUris: ['https://verifie.example/callback'],
  registration: 'preregistered',
  registeredAt: '2026-11-22T10:00:00+00:00',
  activeConnections: 0,
  shadowsVerifiedDomain: true,
}

const TEXTS = fr.admin.mcpClients

function createStubRepository(overrides: Partial<AdminMcpRepository> = {}): AdminMcpRepository {
  return {
    listClients: vi.fn(async () => [PREREGISTERED, DECLARED, SHADOWING]),
    registerClient: vi.fn(async () => PREREGISTERED),
    reviseClient: vi.fn(async () => PREREGISTERED),
    removeClient: vi.fn(async () => undefined),
    listConnections: vi.fn(async () => []),
    ...overrides,
  }
}

async function mountPage(repository: AdminMcpRepository = createStubRepository()): Promise<VueWrapper> {
  const wrapper = mount(AdminMcpClientsPage, {
    global: {
      plugins: [createAppI18n()],
      provide: { [ADMIN_MCP_REPOSITORY as symbol]: repository },
    },
  })
  await flushPromises()

  return wrapper
}

/** La ligne du tableau qui porte `text` (l'identifiant d'un client), ou une erreur qui le nomme. */
function rowFor(wrapper: VueWrapper, text: string): DOMWrapper<HTMLTableRowElement> {
  const row = wrapper.findAll<HTMLTableRowElement>('tbody tr').find((candidate) => candidate.text().includes(text))
  if (!row) throw new Error(`Ligne « ${text} » introuvable.`)
  return row
}

/** Les boutons d'une ligne, par leur texte : l'absence d'un bouton se juge sur la liste, pas sur un `find` muet. */
function buttonTextsOf(row: DOMWrapper<Element>): string[] {
  return row.findAll('button').map((button) => button.text())
}

async function fillForm(wrapper: VueWrapper, values: { clientId?: string; name: string; redirects: string }): Promise<void> {
  if (undefined !== values.clientId) await wrapper.get('#admin-mcp-client-id').setValue(values.clientId)
  await wrapper.get('#admin-mcp-client-name').setValue(values.name)
  await wrapper.get('#admin-mcp-client-redirects').setValue(values.redirects)
}

async function submitForm(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('AdminMcpClientsPage', () => {
  describe('le tableau', () => {
    it('porte une légende et des en-têtes de colonne', async () => {
      const wrapper = await mountPage()

      expect(wrapper.get('table caption').text()).toBe(TEXTS.caption)
      const headers = wrapper.findAll('thead th')
      expect(headers.length).toBeGreaterThan(0)
      for (const header of headers) expect(header.attributes('scope')).toBe('col')
    })

    it('traduit le mode d’enregistrement de chaque ligne', async () => {
      const wrapper = await mountPage()

      expect(rowFor(wrapper, PREREGISTERED.clientId).text()).toContain(TEXTS.registrations.preregistered)
      expect(rowFor(wrapper, PREREGISTERED.clientId).text()).not.toContain(TEXTS.registrations.cimd)
      expect(rowFor(wrapper, DECLARED.clientId).text()).toContain(TEXTS.registrations.cimd)
      expect(rowFor(wrapper, DECLARED.clientId).text()).not.toContain(TEXTS.registrations.preregistered)
    })

    it('montre le nom, chaque redirection et le décompte des connexions actives', async () => {
      const wrapper = await mountPage()
      const row = rowFor(wrapper, PREREGISTERED.clientId)

      expect(row.text()).toContain(PREREGISTERED.name)
      for (const uri of PREREGISTERED.redirectUris) expect(row.text()).toContain(uri)
      expect(row.findAll('li')).toHaveLength(PREREGISTERED.redirectUris.length)
      expect(row.text()).toContain(String(PREREGISTERED.activeConnections))
    })
  })

  describe('les avertissements', () => {
    it('dit en permanence, au-dessus du tableau, ce que coupe une suppression, et la note des clients CIMD', async () => {
      const wrapper = await mountPage()

      const warning = wrapper.findAll('p').find((paragraph) => paragraph.text() === TEXTS.removalWarning)
      expect(warning, 'avertissement de suppression absent').toBeDefined()
      // Au-dessus du tableau : dans l'ordre du document, l'avertissement précède le tableau.
      const table = wrapper.get('table').element
      expect(warning?.element.compareDocumentPosition(table)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
      expect(wrapper.text()).toContain(TEXTS.cimdNote)
    })

    it('garde l’avertissement quand la liste est vide', async () => {
      const wrapper = await mountPage(createStubRepository({ listClients: vi.fn(async () => []) }))

      expect(wrapper.text()).toContain(TEXTS.empty)
      expect(wrapper.text()).toContain(TEXTS.removalWarning)
    })
  })

  describe('la suppression', () => {
    // Une forme par décompte (R12) : les trois fixtures portent 3, 1 et 0 connexions actives. Le texte attendu est rendu
    // avec le même nombre de pluriel que la page doit passer ; une page qui l'oublierait choisirait une autre forme.
    it.each([
      ['plusieurs connexions', PREREGISTERED],
      ['une connexion', DECLARED],
      ['aucune connexion', SHADOWING],
    ])('demande confirmation avec le nom et le décompte (%s) ; un refus ne supprime rien', async (_case, client) => {
      const repository = createStubRepository()
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const wrapper = await mountPage(repository)

      await buttonLabelled(rowFor(wrapper, client.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      expect(confirmSpy).toHaveBeenCalledWith(
        renderedMessage(
          'fr',
          'admin.mcpClients.confirmRemove',
          { name: client.name, count: client.activeConnections },
          client.activeConnections,
        ),
      )
      expect(repository.removeClient).not.toHaveBeenCalled()
    })

    // Le garde-fou des cas ci-dessus : un catalogue sans formes plurielles rendrait le même texte pour 0, 1 et 3, et
    // ces cas passeraient sans rien vérifier de la grammaire. Les deux langues portent leurs trois formes.
    it.each(['fr', 'en'] as const)('le catalogue %s porte trois formes distinctes de la confirmation', (locale) => {
      const forms = [0, 1, 3].map((count) =>
        renderedMessage(locale, 'admin.mcpClients.confirmRemove', { name: PREREGISTERED.name, count }, count),
      )

      expect(new Set(forms).size).toBe(3)
    })

    it('supprime par l’UUID de la ligne, jamais par l’identifiant public, puis relit la liste', async () => {
      const repository = createStubRepository()
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountPage(repository)

      await buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      expect(repository.removeClient).toHaveBeenCalledWith(DECLARED.id)
      expect(repository.listClients).toHaveBeenCalledTimes(2)
    })

    it('vide le formulaire quand le client supprimé est celui qu’on modifiait', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountPage()

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      expect(wrapper.get('h2').text()).toBe(TEXTS.edit)
      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      expect(wrapper.get('h2').text()).toBe(TEXTS.create)
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe('')
    })

    it('dit l’échec d’une suppression près du tableau, hors du formulaire', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const repository = createStubRepository({
        removeClient: vi.fn(async () => {
          throw new AdminMcpError('not-found', 'x')
        }),
      })
      const wrapper = await mountPage(repository)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      const alerts = wrapper.findAll('[role="alert"]')
      expect(alerts).toHaveLength(1)
      expect(alerts[0]?.text()).toBe(TEXTS.errors['not-found'])
      expect(wrapper.get('form').find('[role="alert"]').exists()).toBe(false)
    })
  })

  describe('les gestes offerts par ligne', () => {
    it('n’offre pas « Modifier » sur une ligne CIMD, dont le document fait foi ; « Supprimer » reste', async () => {
      const wrapper = await mountPage()
      const buttons = buttonTextsOf(rowFor(wrapper, DECLARED.clientId))

      expect(buttons).not.toContain(TEXTS.editAction)
      expect(buttons).toContain(TEXTS.deleteAction)
    })

    it('signale par un texte la ligne qui masque un domaine vérifié, sans « Modifier », « Supprimer » restant (R4)', async () => {
      const wrapper = await mountPage()
      const row = rowFor(wrapper, SHADOWING.clientId)

      expect(row.text()).toContain(TEXTS.shadowsVerifiedDomain)
      expect(buttonTextsOf(row)).not.toContain(TEXTS.editAction)
      expect(buttonTextsOf(row)).toContain(TEXTS.deleteAction)
    })

    it('offre « Modifier » sur une ligne pré-enregistrée qui ne masque rien, sans le signal', async () => {
      const wrapper = await mountPage()
      const row = rowFor(wrapper, PREREGISTERED.clientId)

      expect(row.text()).not.toContain(TEXTS.shadowsVerifiedDomain)
      expect(buttonTextsOf(row)).toContain(TEXTS.editAction)
      expect(buttonTextsOf(row)).toContain(TEXTS.deleteAction)
    })
  })

  describe('le formulaire', () => {
    it('saisit l’identifiant et le nom par BaseTextInput, les redirections par BaseTextarea', async () => {
      const wrapper = await mountPage()

      const inputIds = wrapper.findAllComponents(BaseTextInput).map((input) => input.props('id'))
      expect(inputIds).toEqual(['admin-mcp-client-id', 'admin-mcp-client-name'])
      const textareas = wrapper.findAllComponents(BaseTextarea)
      expect(textareas.map((textarea) => textarea.props('id'))).toEqual(['admin-mcp-client-redirects'])
      expect(textareas[0]?.props('label')).toBe(TEXTS.redirectUrisLabel)
    })

    it('enregistre un client : une redirection par ligne, lignes vides et blancs écartés, puis vide le formulaire', async () => {
      const repository = createStubRepository()
      const wrapper = await mountPage(repository)

      await fillForm(wrapper, {
        clientId: 'client-neuf',
        name: 'Client neuf',
        redirects: '  https://neuf.example/callback  \n\n   \nhttps://neuf.example/retour\r\n',
      })
      await submitForm(wrapper)

      // Le corps exact : `toEqual` refuse une clé en trop (`registration`, que le serveur refuserait par un 400).
      expect(repository.registerClient).toHaveBeenCalledWith({
        clientId: 'client-neuf',
        name: 'Client neuf',
        redirectUris: ['https://neuf.example/callback', 'https://neuf.example/retour'],
      })
      expect((wrapper.get('#admin-mcp-client-id').element as HTMLInputElement).value).toBe('')
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe('')
      expect((wrapper.get('#admin-mcp-client-redirects').element as HTMLTextAreaElement).value).toBe('')
    })

    it('révise le nom et les redirections d’un client pré-enregistré, sans renvoyer son identifiant', async () => {
      const repository = createStubRepository()
      const wrapper = await mountPage(repository)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')

      expect(wrapper.get('h2').text()).toBe(TEXTS.edit)
      // L'identifiant public ne se révise pas : il est montré, jamais proposé à la saisie.
      expect(wrapper.find('#admin-mcp-client-id').exists()).toBe(false)
      expect(wrapper.get('form').text()).toContain(PREREGISTERED.clientId)
      expect(wrapper.get('form').text()).toContain(TEXTS.clientIdImmutableHelp)
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe(PREREGISTERED.name)
      expect((wrapper.get('#admin-mcp-client-redirects').element as HTMLTextAreaElement).value).toBe(
        PREREGISTERED.redirectUris.join('\n'),
      )

      await fillForm(wrapper, { name: 'Client renommé', redirects: 'https://client.example/nouveau' })
      await submitForm(wrapper)

      expect(repository.reviseClient).toHaveBeenCalledWith(PREREGISTERED.id, {
        name: 'Client renommé',
        redirectUris: ['https://client.example/nouveau'],
      })
      expect(repository.registerClient).not.toHaveBeenCalled()
      expect(wrapper.get('h2').text()).toBe(TEXTS.create)
    })

    it('« Annuler » quitte la modification sans rien envoyer', async () => {
      const repository = createStubRepository()
      const wrapper = await mountPage(repository)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      // La page n'a qu'un « Annuler », celui du formulaire (`get` rend un wrapper sans `exists`, que l'aide ne prend pas).
      await buttonLabelled(wrapper, TEXTS.cancel).trigger('click')

      expect(wrapper.get('h2').text()).toBe(TEXTS.create)
      expect(wrapper.find('#admin-mcp-client-id').exists()).toBe(true)
      expect(repository.reviseClient).not.toHaveBeenCalled()
    })

    it('une 422 affiche les violations du serveur dans le formulaire, désignées par les champs, la saisie gardée', async () => {
      // Le message est celui que l'adaptateur compose des violations du serveur : une valeur de fixture, pas du catalogue.
      const violations = 'Au moins une redirection est requise.'
      const repository = createStubRepository({
        registerClient: vi.fn(async () => {
          throw new AdminMcpError('validation', violations)
        }),
      })
      const wrapper = await mountPage(repository)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: '' })
      await submitForm(wrapper)

      const alert = wrapper.get('form').get('[role="alert"]')
      expect(alert.text()).toContain(TEXTS.errors.validation)
      expect(alert.text()).toContain(violations)
      const alertId = alert.attributes('id')
      expect(alertId).toBeTruthy()
      for (const field of ['#admin-mcp-client-id', '#admin-mcp-client-name', '#admin-mcp-client-redirects']) {
        expect(wrapper.get(field).attributes('aria-describedby')).toBe(alertId)
      }
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe('Client neuf')
    })

    it('ne désigne aucune alerte tant qu’aucune n’est affichée', async () => {
      const wrapper = await mountPage()

      for (const field of ['#admin-mcp-client-id', '#admin-mcp-client-name', '#admin-mcp-client-redirects']) {
        expect(wrapper.get(field).attributes('aria-describedby')).toBeUndefined()
      }
    })

    const REASONS: readonly Exclude<AdminMcpErrorReason, 'validation'>[] = [
      'client-id-taken',
      'declared-by-document',
      'id-of-verified-domain',
      'not-found',
      'unknown',
    ]

    it.each(REASONS)('traduit la raison « %s » dans le formulaire, sans le message brut', async (reason) => {
      const repository = createStubRepository({
        registerClient: vi.fn(async () => {
          throw new AdminMcpError(reason, 'message technique')
        }),
      })
      const wrapper = await mountPage(repository)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      await submitForm(wrapper)

      const alert = wrapper.get('form').get('[role="alert"]')
      expect(alert.text()).toBe(TEXTS.errors[reason])
      expect(alert.text()).not.toContain('message technique')
      expect((wrapper.get('#admin-mcp-client-id').element as HTMLInputElement).value).toBe('client-neuf')
    })

    it('vide le formulaire après un enregistrement réussi même si la relecture échoue (R10)', async () => {
      let reads = 0
      const repository = createStubRepository({
        listClients: vi.fn(async () => {
          reads += 1
          if (reads > 1) throw new AdminMcpError('unknown', 'x')
          return [PREREGISTERED]
        }),
      })
      const wrapper = await mountPage(repository)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      await submitForm(wrapper)

      expect((wrapper.get('#admin-mcp-client-id').element as HTMLInputElement).value).toBe('')
      expect(wrapper.get('form').find('[role="alert"]').exists()).toBe(false)
      expect(wrapper.text()).toContain(TEXTS.loadError)
    })
  })

  describe('le chargement', () => {
    it('annonce l’échec de la lecture de la liste', async () => {
      const wrapper = await mountPage(
        createStubRepository({
          listClients: vi.fn(async () => {
            throw new AdminMcpError('unknown', 'x')
          }),
        }),
      )

      expect(wrapper.get('[role="alert"]').text()).toBe(TEXTS.loadError)
      expect(wrapper.find('table').exists()).toBe(false)
    })

    describe('non chargée (#748, R4)', () => {
      afterEach(async () => {
        await signInAgain()
      })

      it('première lecture en session finie : pas de « Aucun client », la mention « non chargé »', async () => {
        const wrapper = await mountPage(
          createStubRepository({
            listClients: vi.fn(async () => {
              markSessionExpired()
              throw new SessionEndedError()
            }),
          }),
        )

        // « Aucun client » serait une affirmation que personne n'a vérifiée.
        expect(wrapper.text()).not.toContain(TEXTS.empty)
        expect(wrapper.text()).toContain(fr.admin.notLoaded)
        expect(wrapper.find('[role="alert"]').exists()).toBe(false)
      })
    })
  })

  describe('l’accessibilité', () => {
    it('ne présente aucune violation, tableau des trois modes de ligne rendu', async () => {
      const wrapper = await mountPage()

      await expectNoAccessibilityViolation(wrapper)
    })

    it('ne présente aucune violation, en modification avec une 422 affichée', async () => {
      const repository = createStubRepository({
        reviseClient: vi.fn(async () => {
          throw new AdminMcpError('validation', 'Dix redirections au plus.')
        }),
      })
      const wrapper = await mountPage(repository)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await submitForm(wrapper)

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
