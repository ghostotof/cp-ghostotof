import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

async function mountPage(
  repository: AdminMcpRepository = createStubRepository(),
  attachTo?: HTMLElement,
): Promise<VueWrapper> {
  const wrapper = mount(AdminMcpClientsPage, {
    // Attachée au document quand le test juge le focus : `document.activeElement` ne voit pas un arbre détaché.
    attachTo,
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

/** Une promesse que le test règle à la main : la mutation ou la relecture reste en vol le temps d'une assertion. */
function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve: (value: T) => void = () => undefined
  const promise = new Promise<T>((settle) => {
    resolve = settle
  })

  return { promise, resolve }
}

/**
 * Ce que fait le navigateur au bouton qui se désactive sous le focus : il le lui ôte, ce que jsdom ne fait pas. Appelé par
 * une mutation simulée, il oblige la page à rendre le focus elle-même.
 */
function dropFocusLikeABrowser(): void {
  if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
}

/** Le titre du formulaire, premier `h2` de la page : « Pré-enregistrer un client MCP » ou « Modifier le client MCP ». */
function formHeading(wrapper: VueWrapper): DOMWrapper<HTMLHeadingElement> {
  const heading = wrapper.findAll<HTMLHeadingElement>('h2')[0]
  if (!heading) throw new Error('Titre du formulaire introuvable.')
  return heading
}

/** Le titre de la liste, lu par son texte ; un test y pose le focus pour figurer une personne partie hors du formulaire. */
function listHeading(wrapper: VueWrapper): DOMWrapper<HTMLHeadingElement> {
  const heading = wrapper.findAll<HTMLHeadingElement>('h2').find((candidate) => candidate.text() === TEXTS.listTitle)
  if (!heading) throw new Error('Titre de la liste introuvable.')
  return heading
}

/** Le texte de la région d'annonce, présente dès le montage et vide tant que rien n'est annoncé. */
function statusText(wrapper: VueWrapper): string {
  return wrapper.get('[role="status"]').text()
}

/** Clique un bouton qui a le focus, comme au clavier : le test juge ensuite où le focus est allé. */
async function focusAndClick(button: DOMWrapper<HTMLButtonElement>): Promise<void> {
  button.element.focus()
  await button.trigger('click')
  await flushPromises()
}

/** Laisse passer le délai entre le déplacement du focus et l'annonce (`useFocusThenAnnounce`). */
async function letTheAnnouncementThrough(): Promise<void> {
  await vi.runAllTimersAsync()
  await flushPromises()
}

/**
 * Ce que fait le navigateur à une frappe dans un champ en lecture seule ou désactivé : il la refuse, ce que `setValue` de
 * VTU ne fait pas (il écrit `value` quoi qu'il arrive). Rend `true` si la frappe est acceptée : un test juge ainsi qu'une
 * saisie faite pendant une mutation est refusée sur le moment, au lieu d'être effacée en silence par le succès.
 */
async function typeLikeABrowser<T extends HTMLInputElement | HTMLTextAreaElement>(
  // `Pick` : `wrapper.get()` rend un `DOMWrapper` privé d'`exists`, que le helper n'emploie pas.
  field: Pick<DOMWrapper<T>, 'element' | 'setValue'>,
  text: string,
): Promise<boolean> {
  if (field.element.readOnly || field.element.disabled) return false
  await field.setValue(text)
  return true
}

/** Le texte de ce que désigne l'`aria-describedby` d'un champ (des ids séparés par des blancs), `''` s'il ne désigne rien. */
function describedByText(wrapper: VueWrapper, field: Pick<DOMWrapper<Element>, 'attributes'>): string {
  const ids = (field.attributes('aria-describedby') ?? '').split(/\s+/).filter((id) => '' !== id)
  return ids.map((id) => wrapper.get(`#${id}`).text()).join(' ')
}

/** Les champs de saisie du formulaire : trois en création, deux en révision (l'identifiant n'y est qu'un texte). */
function formFields(wrapper: VueWrapper): DOMWrapper<HTMLInputElement | HTMLTextAreaElement>[] {
  return wrapper.get('form').findAll<HTMLInputElement | HTMLTextAreaElement>('input, textarea')
}

/** Les gestes qui écrivent : « Modifier » et « Supprimer » de chaque ligne, « Enregistrer » du formulaire. */
function mutationButtons(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement>[] {
  return [...wrapper.findAll<HTMLButtonElement>('tbody button'), buttonLabelled(wrapper, TEXTS.save)]
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

    it('vide aussi le formulaire quand la suppression du client en révision échoue en « n’existe plus »', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountPage(
        createStubRepository({
          removeClient: vi.fn(async () => {
            throw new AdminMcpError('not-found', 'x')
          }),
        }),
      )

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      expect(formHeading(wrapper).text()).toBe(TEXTS.edit)
      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      // Le client n'existe plus : une révision partirait sur un 404.
      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe('')
      // L'échec reste dit près du tableau, pas effacé avec le formulaire.
      const alerts = wrapper.findAll('[role="alert"]')
      expect(alerts).toHaveLength(1)
      expect(alerts[0]?.text()).toBe(TEXTS.errors['not-found'])
      expect(wrapper.get('form').find('[role="alert"]').exists()).toBe(false)
    })

    it('garde la révision quand la suppression du client en révision échoue pour une autre raison', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountPage(
        createStubRepository({
          removeClient: vi.fn(async () => {
            throw new AdminMcpError('unknown', 'x')
          }),
        }),
      )

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      // Le client existe peut-être encore : la saisie en cours n'est pas jetée.
      expect(formHeading(wrapper).text()).toBe(TEXTS.edit)
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe(PREREGISTERED.name)
      expect(wrapper.get('[role="alert"]').text()).toBe(TEXTS.errors.unknown)
    })

    it('relit la liste quand la suppression échoue en « n’existe plus » : la ligne fantôme disparaît, l’échec reste dit', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const reread = deferred<readonly AdminMcpClient[]>()
      let reads = 0
      const repository = createStubRepository({
        listClients: vi.fn(() => {
          reads += 1
          return 1 === reads ? Promise.resolve([PREREGISTERED, DECLARED, SHADOWING]) : reread.promise
        }),
        removeClient: vi.fn(async () => {
          dropFocusLikeABrowser()
          throw new AdminMcpError('not-found', 'x')
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await focusAndClick(buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction))

      // Le 404 dit que la liste montrée n'est plus celle du serveur : elle est relue, sans quoi la ligne fantôme
      // garderait ses boutons et chaque nouveau clic recevrait le même 404.
      expect(repository.listClients).toHaveBeenCalledTimes(2)
      // Pendant la relecture, le tableau reste monté et marqué occupé (jamais « Chargement »), l'échec déjà dit.
      expect(wrapper.text()).not.toContain(TEXTS.loading)
      expect(wrapper.get('table').element.parentElement?.getAttribute('aria-busy')).toBe('true')
      expect(wrapper.get('[role="alert"]').text()).toBe(TEXTS.errors['not-found'])

      reread.resolve([PREREGISTERED, SHADOWING])
      await flushPromises()

      expect(wrapper.text()).not.toContain(DECLARED.clientId)
      // La relecture n'efface pas l'échec : il reste dit au-dessus du tableau.
      expect(wrapper.get('[role="alert"]').text()).toBe(TEXTS.errors['not-found'])
      // Le bouton cliqué est parti avec sa ligne : le focus va au titre de la liste, jamais sur `body`.
      expect(document.activeElement).toBe(listHeading(wrapper).element)
    })
  })

  describe('une mutation à la fois', () => {
    it('pendant une suppression en vol, « Modifier », « Supprimer » et « Enregistrer » sont désactivés', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const pending = deferred<undefined>()
      const wrapper = await mountPage(createStubRepository({ removeClient: vi.fn(() => pending.promise) }))

      await buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction).trigger('click')
      await flushPromises()

      // Trois « Supprimer », un « Modifier » (la seule ligne révisable), « Enregistrer ».
      expect(mutationButtons(wrapper)).toHaveLength(5)
      for (const button of mutationButtons(wrapper)) expect(button.attributes('disabled'), button.text()).toBeDefined()

      pending.resolve(undefined)
      await flushPromises()

      for (const button of mutationButtons(wrapper)) expect(button.attributes('disabled'), button.text()).toBeUndefined()
    })

    it('pendant une révision en vol, les gestes du tableau et « Annuler » sont désactivés', async () => {
      const pending = deferred<AdminMcpClient>()
      const wrapper = await mountPage(createStubRepository({ reviseClient: vi.fn(() => pending.promise) }))

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await submitForm(wrapper)

      // « Annuler » viderait le formulaire sous la révision, et son échec ne se dirait nulle part.
      const gestures = [...mutationButtons(wrapper), buttonLabelled(wrapper, TEXTS.cancel)]
      for (const button of gestures) expect(button.attributes('disabled'), button.text()).toBeDefined()

      pending.resolve(PREREGISTERED)
      await flushPromises()

      for (const button of mutationButtons(wrapper)) expect(button.attributes('disabled'), button.text()).toBeUndefined()
    })

    it('un double clic sur « Supprimer » n’envoie qu’une suppression, sans dire que le client n’existe plus', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
      let calls = 0
      const repository = createStubRepository({
        // Le serveur répondrait 404 au second DELETE : le client vient d'être supprimé par le premier.
        removeClient: vi.fn(async () => {
          calls += 1
          if (calls > 1) throw new AdminMcpError('not-found', 'x')
        }),
      })
      const wrapper = await mountPage(repository)
      const button = buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction)

      // Les deux clics partent avant que Vue ne rende le bouton désactivé : seule la garde du code refuse le second.
      await Promise.all([button.trigger('click'), button.trigger('click')])
      await flushPromises()

      expect(repository.removeClient).toHaveBeenCalledTimes(1)
      expect(confirmSpy).toHaveBeenCalledTimes(1)
      expect(wrapper.text()).not.toContain(TEXTS.errors['not-found'])
    })
  })

  describe('le focus et l’annonce après un geste', () => {
    beforeEach(() => {
      // Seuls `setTimeout` et `clearTimeout` sont simulés : `flushPromises` (setImmediate) reste réel.
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('une suppression réussie porte le focus sur le titre de la liste, puis l’annonce', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      let reads = 0
      const repository = createStubRepository({
        listClients: vi.fn(async () => {
          reads += 1
          return 1 === reads ? [PREREGISTERED, DECLARED, SHADOWING] : [PREREGISTERED, SHADOWING]
        }),
        removeClient: vi.fn(async () => {
          dropFocusLikeABrowser()
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await focusAndClick(buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction))

      // La ligne et son bouton ont disparu : le focus va au titre de la liste, jamais sur `body`.
      expect(wrapper.text()).not.toContain(DECLARED.clientId)
      expect(document.activeElement).toBe(listHeading(wrapper).element)
      // L'annonce suit le déplacement du focus, pour ne pas être coupée par la lecture du titre.
      expect(statusText(wrapper)).toBe('')
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.removed)
    })

    it('garde le tableau monté, marqué occupé, pendant la relecture qui suit une suppression', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const reread = deferred<readonly AdminMcpClient[]>()
      let reads = 0
      const repository = createStubRepository({
        listClients: vi.fn(() => {
          reads += 1
          return 1 === reads ? Promise.resolve([PREREGISTERED, DECLARED, SHADOWING]) : reread.promise
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await focusAndClick(buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction))

      expect(wrapper.text()).not.toContain(TEXTS.loading)
      expect(wrapper.get('table').element.parentElement?.getAttribute('aria-busy')).toBe('true')
      // Jamais `aria-busy` sur un ancêtre de la région d'annonce : il la ferait taire.
      expect(wrapper.get('[role="status"]').element.closest('[aria-busy="true"]')).toBeNull()

      reread.resolve([PREREGISTERED, SHADOWING])
      await flushPromises()

      expect(wrapper.get('table').element.parentElement?.getAttribute('aria-busy')).toBe('false')
      expect(wrapper.text()).not.toContain(DECLARED.clientId)
    })

    it('un enregistrement réussi porte le focus sur le titre du formulaire, puis l’annonce', async () => {
      const repository = createStubRepository({
        registerClient: vi.fn(async () => {
          dropFocusLikeABrowser()
          return PREREGISTERED
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      buttonLabelled(wrapper, TEXTS.save).element.focus()
      await submitForm(wrapper)

      expect(document.activeElement).toBe(formHeading(wrapper).element)
      expect(statusText(wrapper)).toBe('')
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.registered)
    })

    it('une révision réussie porte le focus sur le titre du formulaire, revenu à la création, puis l’annonce', async () => {
      const repository = createStubRepository({
        reviseClient: vi.fn(async () => {
          dropFocusLikeABrowser()
          return PREREGISTERED
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      buttonLabelled(wrapper, TEXTS.save).element.focus()
      await submitForm(wrapper)

      expect(document.activeElement).toBe(formHeading(wrapper).element)
      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.revised)
    })

    it('« Modifier » porte le focus sur le titre du formulaire, devenu celui de la modification', async () => {
      const wrapper = await mountPage(createStubRepository(), document.body)

      await focusAndClick(buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction))

      expect(document.activeElement).toBe(formHeading(wrapper).element)
      expect(formHeading(wrapper).text()).toBe(TEXTS.edit)
    })

    it('« Annuler », qui disparaît sous le focus, le rend au titre du formulaire', async () => {
      const wrapper = await mountPage(createStubRepository(), document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await focusAndClick(buttonLabelled(wrapper, TEXTS.cancel))

      expect(wrapper.findAll('button').map((button) => button.text())).not.toContain(TEXTS.cancel)
      expect(document.activeElement).toBe(formHeading(wrapper).element)
      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
    })

    it('l’échec d’une suppression rend le focus au bouton cliqué, sans rien annoncer', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const repository = createStubRepository({
        removeClient: vi.fn(async () => {
          dropFocusLikeABrowser()
          throw new AdminMcpError('unknown', 'x')
        }),
      })
      const wrapper = await mountPage(repository, document.body)
      const button = buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction)

      await focusAndClick(button)

      expect(document.activeElement).toBe(button.element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe('')
    })

    it('l’échec d’un enregistrement rend le focus à « Enregistrer », sans rien annoncer', async () => {
      const repository = createStubRepository({
        registerClient: vi.fn(async () => {
          dropFocusLikeABrowser()
          throw new AdminMcpError('unknown', 'x')
        }),
      })
      const wrapper = await mountPage(repository, document.body)
      const save = buttonLabelled(wrapper, TEXTS.save)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      save.element.focus()
      await submitForm(wrapper)

      expect(document.activeElement).toBe(save.element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe('')
    })

    it('un nouveau geste efface l’annonce du précédent', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const repository = createStubRepository({
        registerClient: vi.fn(async () => {
          throw new AdminMcpError('unknown', 'x')
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await focusAndClick(buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction))
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.removed)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      await submitForm(wrapper)

      // Un enregistrement refusé ne laisse pas lire « supprimé » à côté de son alerte.
      expect(statusText(wrapper)).toBe('')
    })

    it('une suppression réussie laisse le focus à la personne partie saisir dans le formulaire, et l’annonce quand même', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const pending = deferred<undefined>()
      const wrapper = await mountPage(createStubRepository({ removeClient: vi.fn(() => pending.promise) }), document.body)

      await focusAndClick(buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction))
      // Pendant l'attente, la personne saisit le nom d'un nouveau client : la suppression d'un autre client ne touche pas
      // au formulaire, ses champs restent ouverts à la saisie, sans indice d'attente (C-S6, tour 2).
      const name = wrapper.get<HTMLInputElement>('#admin-mcp-client-name')
      name.element.focus()
      expect(name.attributes('readonly')).toBeUndefined()
      expect(name.attributes('aria-describedby')).toBeUndefined()
      expect(await typeLikeABrowser(name, 'Client en cours de sai')).toBe(true)
      pending.resolve(undefined)
      await flushPromises()

      // Le focus ne lui est pas repris au milieu d'un mot : l'issue est dite sans le déplacer.
      expect(document.activeElement).toBe(name.element)
      expect(name.element.value).toBe('Client en cours de sai')
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.removed)
    })

    it('une suppression réussie porte au titre de la liste le focus resté dans la ligne disparue', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      let reads = 0
      const repository = createStubRepository({
        listClients: vi.fn(async () => {
          reads += 1
          return 1 === reads ? [PREREGISTERED, DECLARED, SHADOWING] : [PREREGISTERED, SHADOWING]
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      // Un navigateur qui laisse le focus au bouton désactivé : il part avec la ligne supprimée.
      await focusAndClick(buttonLabelled(rowFor(wrapper, DECLARED.clientId), TEXTS.deleteAction))

      expect(wrapper.text()).not.toContain(DECLARED.clientId)
      expect(document.activeElement).toBe(listHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.removed)
    })

    it('un enregistrement réussi laisse le focus à la personne partie ailleurs pendant l’attente, et l’annonce quand même', async () => {
      const pending = deferred<AdminMcpClient>()
      const wrapper = await mountPage(createStubRepository({ registerClient: vi.fn(() => pending.promise) }), document.body)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      buttonLabelled(wrapper, TEXTS.save).element.focus()
      await submitForm(wrapper)
      // Pendant l'attente, la personne retourne au champ des redirections.
      const redirects = wrapper.get<HTMLTextAreaElement>('#admin-mcp-client-redirects')
      redirects.element.focus()
      pending.resolve(PREREGISTERED)
      await flushPromises()

      expect(document.activeElement).toBe(redirects.element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.registered)
    })

    it('un enregistrement réussi porte au titre du formulaire le focus resté sur « Enregistrer »', async () => {
      const wrapper = await mountPage(createStubRepository(), document.body)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      // Un navigateur qui laisse le focus au bouton désactivé : il est sur les boutons du formulaire, que le succès remplace.
      buttonLabelled(wrapper, TEXTS.save).element.focus()
      await submitForm(wrapper)

      expect(document.activeElement).toBe(formHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.registered)
    })

    it('une révision en échec « n’existe plus » quitte la révision, dit l’échec dans le formulaire et porte le focus à son titre', async () => {
      let reads = 0
      const repository = createStubRepository({
        listClients: vi.fn(async () => {
          reads += 1
          return 1 === reads ? [PREREGISTERED, DECLARED, SHADOWING] : [DECLARED, SHADOWING]
        }),
        reviseClient: vi.fn(async () => {
          dropFocusLikeABrowser()
          throw new AdminMcpError('not-found', 'x')
        }),
      })
      const wrapper = await mountPage(repository, document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      buttonLabelled(wrapper, TEXTS.save).element.focus()
      await submitForm(wrapper)

      // Le client a disparu : le formulaire revient à la création, sans quoi chaque essai recevrait le même 404.
      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(wrapper.find('#admin-mcp-client-id').exists()).toBe(true)
      expect((wrapper.get('#admin-mcp-client-name').element as HTMLInputElement).value).toBe('')
      // L'échec reste dit dans le formulaire, désigné par ses champs.
      const alert = wrapper.get('form').get('[role="alert"]')
      expect(alert.text()).toBe(TEXTS.errors['not-found'])
      expect(wrapper.get('#admin-mcp-client-name').attributes('aria-describedby')).toBe(alert.attributes('id'))
      // La liste relue ne montre plus la ligne fantôme.
      expect(repository.listClients).toHaveBeenCalledTimes(2)
      expect(wrapper.findAll('tbody tr').some((row) => row.text().includes(PREREGISTERED.clientId))).toBe(false)
      // Le formulaire a changé de mode sous la personne : son titre le dit, jamais `body`.
      expect(document.activeElement).toBe(formHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe('')
    })
  })

  // C-S6, tour 2. Point 1 : le focus reste dans le champ où la personne tape pendant l'appel (tour 1), mais le succès
  // vide le formulaire ; les champs passent en lecture seule, jamais désactivés, tant que l'issue peut le vider. Point 2 :
  // quand le formulaire repasse de la révision à la création sous la personne, un focus perdu ou resté dans le
  // formulaire va à son titre ; un focus parti ailleurs y reste, et l'annonce dit le retour à la création.
  describe('la saisie pendant une mutation et le retour à la création', () => {
    beforeEach(() => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('pendant un enregistrement en vol, les champs passent en lecture seule, gardent le focus et disent d’attendre', async () => {
      const pending = deferred<AdminMcpClient>()
      const wrapper = await mountPage(createStubRepository({ registerClient: vi.fn(() => pending.promise) }), document.body)

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      buttonLabelled(wrapper, TEXTS.save).element.focus()
      await submitForm(wrapper)
      // Pendant l'attente, la personne retourne au nom et commence le client suivant.
      const name = wrapper.get<HTMLInputElement>('#admin-mcp-client-name')
      name.element.focus()

      expect(formFields(wrapper)).toHaveLength(3)
      for (const field of formFields(wrapper)) {
        const id = field.attributes('id') ?? ''
        // `readonly` et jamais `disabled` : un champ désactivé perdrait le focus, et se lirait moins bien.
        expect(field.attributes('readonly'), `#${id} : readonly`).toBeDefined()
        expect(field.attributes('disabled'), `#${id} : jamais disabled`).toBeUndefined()
        expect(describedByText(wrapper, field), `#${id} : l'indice d'attente`).toBe(TEXTS.pendingReadonly)
      }
      // La frappe est refusée sur le moment, au lieu d'être effacée en silence par le succès.
      expect(await typeLikeABrowser(name, 'Client suiv')).toBe(false)
      expect(document.activeElement).toBe(name.element)

      pending.resolve(PREREGISTERED)
      await flushPromises()

      for (const field of formFields(wrapper)) {
        expect(field.attributes('readonly'), field.attributes('id')).toBeUndefined()
        expect(field.attributes('aria-describedby'), field.attributes('id')).toBeUndefined()
      }
      expect(wrapper.text()).not.toContain(TEXTS.pendingReadonly)
      // Une création réussie ne change pas de mode : le focus reste dans le champ (R15), rouvert à la saisie.
      expect(document.activeElement).toBe(name.element)
      expect(await typeLikeABrowser(name, 'Client suivant')).toBe(true)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.registered)
    })

    it('pendant une révision en vol, les champs passent en lecture seule ; le succès, revenu à la création, porte au titre le focus resté dans un champ', async () => {
      const pending = deferred<AdminMcpClient>()
      const wrapper = await mountPage(createStubRepository({ reviseClient: vi.fn(() => pending.promise) }), document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await submitForm(wrapper)
      const redirects = wrapper.get<HTMLTextAreaElement>('#admin-mcp-client-redirects')
      redirects.element.focus()

      expect(formFields(wrapper)).toHaveLength(2)
      for (const field of formFields(wrapper)) {
        expect(field.attributes('readonly'), field.attributes('id')).toBeDefined()
        expect(field.attributes('disabled'), field.attributes('id')).toBeUndefined()
        expect(describedByText(wrapper, field), field.attributes('id')).toBe(TEXTS.pendingReadonly)
      }
      expect(await typeLikeABrowser(redirects, 'https://autre.example/callback')).toBe(false)

      pending.resolve(PREREGISTERED)
      await flushPromises()

      // Le formulaire a changé de mode sous la personne, qui était dans un de ses champs : son titre le dit.
      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(document.activeElement).toBe(formHeading(wrapper).element)
      expect(statusText(wrapper)).toBe('')
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.revised)
    })

    it('une révision réussie laisse le focus à la personne partie hors du formulaire, et dit le retour à la création', async () => {
      const pending = deferred<AdminMcpClient>()
      const wrapper = await mountPage(createStubRepository({ reviseClient: vi.fn(() => pending.promise) }), document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await submitForm(wrapper)
      // Pendant l'attente, la personne part relire la liste.
      listHeading(wrapper).element.focus()
      pending.resolve(PREREGISTERED)
      await flushPromises()

      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(document.activeElement).toBe(listHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(`${TEXTS.outcome.revised} ${TEXTS.backToCreation}`)
    })

    it('une révision en échec « n’existe plus » laisse le focus à la personne partie hors du formulaire, et dit le retour à la création', async () => {
      const gate = deferred<undefined>()
      const wrapper = await mountPage(
        createStubRepository({
          reviseClient: vi.fn(async () => {
            await gate.promise
            throw new AdminMcpError('not-found', 'x')
          }),
        }),
        document.body,
      )

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await submitForm(wrapper)
      listHeading(wrapper).element.focus()
      gate.resolve(undefined)
      await flushPromises()

      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(wrapper.get('form').get('[role="alert"]').text()).toBe(TEXTS.errors['not-found'])
      expect(document.activeElement).toBe(listHeading(wrapper).element)
      await letTheAnnouncementThrough()
      // L'alerte dit l'échec ; la région d'annonce, seulement ce que l'alerte ne dit pas : le formulaire a changé de mode.
      expect(statusText(wrapper)).toBe(TEXTS.backToCreation)
    })

    // Point 3 : comme un 404, ces deux refus typés ne tombent que sur une ligne périmée. La relecture la montre telle que
    // le serveur la tient désormais : déclarée par son document, ou masquant un domaine vérifié, sans « Modifier ».
    const STALE_ROWS: Record<'declared-by-document' | 'id-of-verified-domain', AdminMcpClient> = {
      'declared-by-document': { ...PREREGISTERED, registration: 'cimd' },
      'id-of-verified-domain': { ...PREREGISTERED, shadowsVerifiedDomain: true },
    }

    it.each(['declared-by-document', 'id-of-verified-domain'] as const)(
      'une révision refusée en %s quitte la révision, relit la liste, garde l’échec dit dans le formulaire et porte le focus perdu au titre',
      async (reason) => {
        let reads = 0
        const repository = createStubRepository({
          listClients: vi.fn(async () => {
            reads += 1
            return 1 === reads ? [PREREGISTERED, DECLARED, SHADOWING] : [STALE_ROWS[reason], DECLARED, SHADOWING]
          }),
          reviseClient: vi.fn(async () => {
            dropFocusLikeABrowser()
            throw new AdminMcpError(reason, 'x')
          }),
        })
        const wrapper = await mountPage(repository, document.body)

        await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
        buttonLabelled(wrapper, TEXTS.save).element.focus()
        await submitForm(wrapper)

        // Chaque nouvel essai recevrait le même refus : le formulaire revient à la création.
        expect(formHeading(wrapper).text()).toBe(TEXTS.create)
        expect(wrapper.find('#admin-mcp-client-id').exists()).toBe(true)
        expect(wrapper.get<HTMLInputElement>('#admin-mcp-client-name').element.value).toBe('')
        // L'échec reste dit dans le formulaire, désigné par ses champs.
        const alert = wrapper.get('form').get('[role="alert"]')
        expect(alert.text()).toBe(TEXTS.errors[reason])
        expect(describedByText(wrapper, wrapper.get('#admin-mcp-client-name'))).toBe(alert.text())
        // La liste relue ne propose plus « Modifier » sur la ligne.
        expect(repository.listClients).toHaveBeenCalledTimes(2)
        expect(buttonTextsOf(rowFor(wrapper, PREREGISTERED.clientId))).not.toContain(TEXTS.editAction)
        expect(document.activeElement).toBe(formHeading(wrapper).element)
        await letTheAnnouncementThrough()
        expect(statusText(wrapper)).toBe('')
      },
    )

    it('pendant la suppression du client en révision, les champs passent en lecture seule ; le succès porte au titre du formulaire le focus resté dans un champ', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const pending = deferred<undefined>()
      const wrapper = await mountPage(createStubRepository({ removeClient: vi.fn(() => pending.promise) }), document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await focusAndClick(buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction))
      // Pendant l'attente, la personne revient au nom du client qu'elle révisait : le succès viderait sa saisie.
      const name = wrapper.get<HTMLInputElement>('#admin-mcp-client-name')
      name.element.focus()

      for (const field of formFields(wrapper)) {
        expect(field.attributes('readonly'), field.attributes('id')).toBeDefined()
        expect(describedByText(wrapper, field), field.attributes('id')).toBe(TEXTS.pendingReadonly)
      }
      expect(await typeLikeABrowser(name, 'Nom corrigé')).toBe(false)

      pending.resolve(undefined)
      await flushPromises()

      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(document.activeElement).toBe(formHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.removed)
    })

    it('une suppression réussie du client en révision porte au titre du formulaire, revenu à la création, le focus perdu', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountPage(
        createStubRepository({
          removeClient: vi.fn(async () => {
            dropFocusLikeABrowser()
          }),
        }),
        document.body,
      )

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await focusAndClick(buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction))

      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(document.activeElement).toBe(formHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(TEXTS.outcome.removed)
    })

    it('une suppression réussie du client en révision laisse le focus à la personne partie hors du formulaire, et dit l’issue et le retour à la création', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const pending = deferred<undefined>()
      const wrapper = await mountPage(createStubRepository({ removeClient: vi.fn(() => pending.promise) }), document.body)

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await focusAndClick(buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction))
      listHeading(wrapper).element.focus()
      pending.resolve(undefined)
      await flushPromises()

      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      expect(document.activeElement).toBe(listHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe(`${TEXTS.outcome.removed} ${TEXTS.backToCreation}`)
    })

    it('une suppression du client en révision en échec « n’existe plus » porte au titre du formulaire le focus perdu, sans rien annoncer', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const wrapper = await mountPage(
        createStubRepository({
          removeClient: vi.fn(async () => {
            dropFocusLikeABrowser()
            throw new AdminMcpError('not-found', 'x')
          }),
        }),
        document.body,
      )

      await buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.editAction).trigger('click')
      await focusAndClick(buttonLabelled(rowFor(wrapper, PREREGISTERED.clientId), TEXTS.deleteAction))

      expect(formHeading(wrapper).text()).toBe(TEXTS.create)
      // L'échec reste dit au-dessus du tableau ; le titre du formulaire dit le nouveau mode.
      expect(wrapper.get('[role="alert"]').text()).toBe(TEXTS.errors['not-found'])
      expect(document.activeElement).toBe(formHeading(wrapper).element)
      await letTheAnnouncementThrough()
      expect(statusText(wrapper)).toBe('')
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

    it('ne présente aucune violation, champs en lecture seule pendant un envoi en vol', async () => {
      const pending = deferred<AdminMcpClient>()
      const wrapper = await mountPage(createStubRepository({ registerClient: vi.fn(() => pending.promise) }))

      await fillForm(wrapper, { clientId: 'client-neuf', name: 'Client neuf', redirects: 'https://neuf.example/callback' })
      await submitForm(wrapper)

      // L'indice désigné par les champs existe : un IDREF vers rien serait une faute.
      expect(wrapper.get('#admin-mcp-client-name').attributes('readonly')).toBeDefined()
      await expectNoAccessibilityViolation(wrapper)
      pending.resolve(PREREGISTERED)
      await flushPromises()
    })
  })
})
