import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import AdminAboutPage from '../../../../src/presentation/pages/admin/AdminAboutPage.vue'
import { ADMIN_ABOUT_SETTINGS_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutSettings'
import { ADMIN_ABOUT_SITE_CARD_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutSiteCards'
import { ADMIN_ABOUT_ME_CARD_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutMeCards'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminAboutSettingsRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutSettingsRepository'
import type { AdminAboutSiteCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutSiteCardRepository'
import type { AdminAboutMeCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutMeCardRepository'
import type { AdminAboutSettings } from '../../../../src/domain/admin/about/entities/AdminAboutSettings'
import type { AdminAboutSiteCard } from '../../../../src/domain/admin/about/entities/AdminAboutSiteCard'
import type {
  AdminAboutMeCard,
  AdminAboutMeCardCategory,
} from '../../../../src/domain/admin/about/entities/AdminAboutMeCard'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const SETTINGS: AdminAboutSettings = {
  locale: 'fr',
  siteEyebrow: 'À propos de ce site',
  meEyebrow: 'À propos de moi',
  technicalSubtitle: 'Techniquement',
  personalSubtitle: 'Humainement',
  hobbiesSubtitle: 'En dehors du travail',
}

/**
 * Trois groupes par tableau, dont un sans traduction : côté cartes « site »
 * c'est l'anglais qui manque, côté cartes « moi » c'est le français. Les deux
 * sens sont ainsi couverts — le tableau tire ses langues de `SUPPORTED_LOCALES`
 * (D2), jamais de 'fr'/'en' en dur —, et le groupe solitaire anglais donne au
 * formulaire français une vraie option « Version de » à proposer.
 */
const S_GROUP_ONE = '019968b0-0000-7000-8000-0000000000a1'
const S_GROUP_TWO = '019968b0-0000-7000-8000-0000000000a2'
const S_GROUP_THREE = '019968b0-0000-7000-8000-0000000000a3'

const SITE_FR_ONE: AdminAboutSiteCard = {
  id: '019968a0-0000-7000-8000-0000000000a1', locale: 'fr', translationGroup: S_GROUP_ONE,
  title: 'Architecture', description: 'Description architecture', iconKey: 'layers', position: 0,
}
const SITE_EN_ONE: AdminAboutSiteCard = {
  ...SITE_FR_ONE, id: '019968a0-0000-7000-8000-0000000000a2', locale: 'en',
  title: 'Architecture (en)', description: 'Architecture description',
}
const SITE_FR_TWO: AdminAboutSiteCard = {
  id: '019968a0-0000-7000-8000-0000000000a3', locale: 'fr', translationGroup: S_GROUP_TWO,
  title: 'Stack technique', description: 'Description stack', iconKey: 'server', position: 1,
}
const SITE_FR_THREE: AdminAboutSiteCard = {
  id: '019968a0-0000-7000-8000-0000000000a4', locale: 'fr', translationGroup: S_GROUP_THREE,
  title: 'Sécurité', description: 'Description sécurité', iconKey: 'shield', position: 2,
}
const SITE_EN_THREE: AdminAboutSiteCard = {
  ...SITE_FR_THREE, id: '019968a0-0000-7000-8000-0000000000a5', locale: 'en', title: 'Security',
}

const ALL_SITE_CARDS = [SITE_FR_ONE, SITE_EN_ONE, SITE_FR_TWO, SITE_FR_THREE, SITE_EN_THREE]

/** Les trois catégories, dans l'ordre où la section les rend. */
const CATEGORIES = ['technical', 'personal', 'hobby'] as const

interface MeFixture {
  readonly category: AdminAboutMeCardCategory
  readonly groups: readonly [string, string, string]
  /** Titres : français du groupe 1, anglais du groupe 1, anglais du groupe 2 (solitaire). */
  readonly titles: readonly [string, string, string]
  readonly lonelyIconKey: string
  readonly cards: readonly AdminAboutMeCard[]
}

/**
 * Un jeu de trois groupes par catégorie, bâti sur le même moule : le groupe 2
 * n'existe qu'en anglais, ce qui lui vaut « Traduction manquante » et
 * « Créer la version FR », et en fait la seule option « Version de » d'un
 * formulaire français.
 */
function meFixture(
  category: AdminAboutMeCardCategory,
  suffix: string,
  titles: readonly [string, string, string, string, string],
  lonelyIconKey: string,
): MeFixture {
  const groups = [
    `019968b0-0000-7000-8000-00000000${suffix}1`,
    `019968b0-0000-7000-8000-00000000${suffix}2`,
    `019968b0-0000-7000-8000-00000000${suffix}3`,
  ] as const

  const base = { category, description: 'Description', iconKey: 'code' }

  return {
    category,
    groups,
    titles: [titles[0], titles[1], titles[2]],
    lonelyIconKey,
    cards: [
      { ...base, id: `019968a0-0000-7000-8000-00000000${suffix}1`, locale: 'fr', translationGroup: groups[0], title: titles[0], position: 0 },
      { ...base, id: `019968a0-0000-7000-8000-00000000${suffix}2`, locale: 'en', translationGroup: groups[0], title: titles[1], position: 0 },
      { ...base, id: `019968a0-0000-7000-8000-00000000${suffix}3`, locale: 'en', translationGroup: groups[1], title: titles[2], iconKey: lonelyIconKey, position: 1 },
      { ...base, id: `019968a0-0000-7000-8000-00000000${suffix}4`, locale: 'fr', translationGroup: groups[2], title: titles[3], position: 2 },
      { ...base, id: `019968a0-0000-7000-8000-00000000${suffix}5`, locale: 'en', translationGroup: groups[2], title: titles[4], position: 2 },
    ],
  }
}

const ME_FIXTURES: Record<AdminAboutMeCardCategory, MeFixture> = {
  technical: meFixture('technical', 'b', ['Dev senior', 'Senior developer', 'Continuous watch', 'Revue de code', 'Code review'], 'binoculars'),
  personal: meFixture('personal', 'c', ['Curieux', 'Curious', 'Patient (en)', 'Rigoureux', 'Rigorous'], 'hourglass'),
  hobby: meFixture('hobby', 'd', ['Musique', 'Music', 'Hiking', 'Cuisine', 'Cooking'], 'mountain'),
}

const ALL_ME_CARDS = CATEGORIES.flatMap((category) => ME_FIXTURES[category].cards)

const TRANSLATED_SETTINGS = {
  siteEyebrow: 'About this site',
  meEyebrow: 'About me',
  technicalSubtitle: 'Technically',
  personalSubtitle: 'Personally',
  hobbiesSubtitle: 'Outside work',
}

function createSettingsRepository(overrides: Partial<AdminAboutSettingsRepository> = {}): AdminAboutSettingsRepository {
  return {
    get: vi.fn(async () => SETTINGS),
    update: vi.fn(async () => SETTINGS),
    ...overrides,
  }
}

function createSiteCardRepository(overrides: Partial<AdminAboutSiteCardRepository> = {}): AdminAboutSiteCardRepository {
  return {
    list: vi.fn(async () => ALL_SITE_CARDS),
    create: vi.fn(async () => SITE_FR_TWO),
    update: vi.fn(async () => SITE_FR_TWO),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createMeCardRepository(overrides: Partial<AdminAboutMeCardRepository> = {}): AdminAboutMeCardRepository {
  return {
    list: vi.fn(async () => ALL_ME_CARDS),
    create: vi.fn(async () => ME_FIXTURES.technical.cards[0]),
    update: vi.fn(async () => ME_FIXTURES.technical.cards[0]),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createTranslationRepository(
  fields: Record<string, string> = TRANSLATED_SETTINGS,
  overrides: Partial<AdminTranslationRepository> = {},
): AdminTranslationRepository {
  return {
    translate: vi.fn(async () => ({ sourceLocale: 'fr' as const, targetLocale: 'en' as const, fields })),
    ...overrides,
  }
}

const ELSEWHERE = defineComponent({ setup: () => () => h('p', 'ailleurs') })

/**
 * La page est montée **par le routeur** : `onBeforeRouteLeave` n'existe que
 * dans un composant rendu par une `RouterView`, et la garde de sortie (D6) est
 * précisément l'un des points à vérifier.
 */
async function mountPage(
  settingsRepository: AdminAboutSettingsRepository = createSettingsRepository(),
  siteCardRepository: AdminAboutSiteCardRepository = createSiteCardRepository(),
  meCardRepository: AdminAboutMeCardRepository = createMeCardRepository(),
  translationRepository: AdminTranslationRepository = createTranslationRepository(),
  options: { attach?: boolean } = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/about', component: AdminAboutPage },
      { path: '/admin/ailleurs', component: ELSEWHERE },
    ],
  })
  await router.push('/admin/about')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, {
    attachTo: options.attach ? document.body : undefined,
    global: {
      plugins: [createAppI18n(), router],
      provide: {
        [ADMIN_ABOUT_SETTINGS_REPOSITORY as symbol]: settingsRepository,
        [ADMIN_ABOUT_SITE_CARD_REPOSITORY as symbol]: siteCardRepository,
        [ADMIN_ABOUT_ME_CARD_REPOSITORY as symbol]: meCardRepository,
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translationRepository,
      },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

/** Index des tableaux : les cartes « site », puis une catégorie de cartes « moi » par tableau. */
const SITE = 0
const ME_TABLE: Record<AdminAboutMeCardCategory, number> = { technical: 1, personal: 2, hobby: 3 }

/** Index des panneaux : sélecteur de langue des réglages, réglages, cartes « site », cartes « moi ». */
const SETTINGS_PANEL = 1
const SITE_PANEL = 2
const ME_PANEL = 3

/** Index des formulaires et des boutons de traduction : réglages, cartes « site », cartes « moi ». */
const SETTINGS_FORM = 0
const SITE_FORM = 1
const ME_FORM = 2

function valueOf(wrapper: VueWrapper, id: string): string {
  return (wrapper.get(id).element as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value
}

function tableOf(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  return wrapper.findAll('table')[which]
}

function rowsOf(wrapper: VueWrapper, which: number): DOMWrapper<Element>[] {
  return tableOf(wrapper, which).findAll('tbody tr')
}

/** Les lignes internes d'un tableau : une par langue de chaque groupe. */
function linesOf(wrapper: VueWrapper, which: number): DOMWrapper<Element>[] {
  return tableOf(wrapper, which).findAll('tbody tr td:nth-child(2) > div')
}

/** Les boutons d'une langue sont dans la dernière cellule, au même index que sa ligne de contenu. */
function actionsForLine(wrapper: VueWrapper, which: number, text: string): DOMWrapper<Element> {
  const index = linesOf(wrapper, which).findIndex((candidate) => candidate.text().includes(text))
  if (index < 0) throw new Error(`Ligne introuvable pour « ${text} ».`)
  return tableOf(wrapper, which).findAll('tbody tr td:last-child > div')[index]
}

function buttonLabelled(scope: VueWrapper | DOMWrapper<Element>, label: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll('button').find((candidate) => candidate.text() === label)
  if (!button) throw new Error(`Bouton « ${label} » introuvable.`)
  return button as DOMWrapper<HTMLButtonElement>
}

function panelOf(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  return wrapper.findAll('.surface-panel')[which]
}

/** La zone d'une catégorie de cartes « moi » : son titre, sa barre d'ordre et son tableau. */
function meSectionOf(wrapper: VueWrapper, category: AdminAboutMeCardCategory): DOMWrapper<Element> {
  return panelOf(wrapper, ME_PANEL).findAll('section')[CATEGORIES.indexOf(category)]
}

/** La zone d'ordre d'un tableau : le panneau entier pour les cartes « site », la section pour une catégorie. */
function orderScopeOf(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  return SITE === which ? panelOf(wrapper, SITE_PANEL) : meSectionOf(wrapper, CATEGORIES[which - 1])
}

function formOf(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  return wrapper.findAll('form')[which]
}

function translateButton(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  const button = wrapper.findAll('button').filter((candidate) => candidate.text().includes('Proposer la version'))[which]
  if (!button) throw new Error('Bouton de traduction introuvable.')
  return button
}

function optionsOf(wrapper: VueWrapper, id: string): string[] {
  return wrapper.get(id).findAll('option').map((option) => option.text())
}

/** Glisse une ligne sur une autre, comme le ferait la souris. */
async function dragRow(wrapper: VueWrapper, which: number, from: number, to: number): Promise<void> {
  const rows = rowsOf(wrapper, which)
  await rows[from].trigger('dragstart')
  await rows[to].trigger('dragover')
  await rows[to].trigger('drop')
}

describe('AdminAboutPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('tableaux toutes langues', () => {
    it('charge les deux collections sans filtre : ni locale, ni catégorie (spec 0004, D8)', async () => {
      const siteCardRepository = createSiteCardRepository()
      const meCardRepository = createMeCardRepository()
      await mountPage(createSettingsRepository(), siteCardRepository, meCardRepository)

      expect(siteCardRepository.list).toHaveBeenCalledWith()
      expect(meCardRepository.list).toHaveBeenCalledWith()
    })

    it('rend quatre tableaux : les cartes « site », puis une catégorie de cartes « moi » par tableau', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.findAll('table')).toHaveLength(4)
      for (const category of CATEGORIES) {
        expect(meSectionOf(wrapper, category).findAll('table')).toHaveLength(1)
      }
    })

    it('cartes « site » : une ligne par groupe, un badge par langue, et la traduction manquante', async () => {
      const { wrapper } = await mountPage()

      expect(rowsOf(wrapper, SITE)).toHaveLength(3)
      expect(tableOf(wrapper, SITE).text()).toContain('Architecture')
      expect(tableOf(wrapper, SITE).text()).toContain('Architecture (en)')

      const badges = rowsOf(wrapper, SITE)[0].findAll('.badge').map((badge) => badge.text())
      expect(badges).toEqual(['FR', 'EN'])

      const missing = rowsOf(wrapper, SITE)[1]
      expect(missing.text()).toContain('Stack technique')
      expect(missing.text()).toContain('Traduction manquante')
      expect(buttonLabelled(missing, 'Créer la version EN').exists()).toBe(true)
    })

    it.each(CATEGORIES)(
      'cartes « moi » (%s) : une ligne par groupe, un badge par langue, et la traduction manquante',
      async (category) => {
        const { wrapper } = await mountPage()
        const fixture = ME_FIXTURES[category]
        const which = ME_TABLE[category]

        expect(rowsOf(wrapper, which)).toHaveLength(3)
        expect(tableOf(wrapper, which).text()).toContain(fixture.titles[0])
        expect(tableOf(wrapper, which).text()).toContain(fixture.titles[1])

        const badges = rowsOf(wrapper, which)[0].findAll('.badge').map((badge) => badge.text())
        expect(badges).toEqual(['FR', 'EN'])

        const missing = rowsOf(wrapper, which)[1]
        expect(missing.text()).toContain(fixture.titles[2])
        expect(missing.text()).toContain('Traduction manquante')
        expect(buttonLabelled(missing, 'Créer la version FR').exists()).toBe(true)
      },
    )

    it("ne recharge rien quand une langue de formulaire change : elle ne pilote que son formulaire", async () => {
      const siteCardRepository = createSiteCardRepository()
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository, meCardRepository)
      vi.mocked(siteCardRepository.list).mockClear()
      vi.mocked(meCardRepository.list).mockClear()

      await wrapper.get('#admin-about-site-card-locale').setValue('en')
      await wrapper.get('#admin-about-me-card-locale').setValue('en')
      await flushPromises()

      expect(siteCardRepository.list).not.toHaveBeenCalled()
      expect(meCardRepository.list).not.toHaveBeenCalled()
    })

    it('rend le message « aucune carte » par catégorie vide, sans barre d\'ordre', async () => {
      // Une catégorie vide est un cas ordinaire depuis qu'il y a trois tableaux :
      // elle doit le dire, et surtout ne pas proposer d'enregistrer un ordre
      // qui n'existe pas.
      const meCardRepository = createMeCardRepository({ list: vi.fn(async () => ME_FIXTURES.technical.cards) })
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)

      expect(meSectionOf(wrapper, 'technical').findAll('table')).toHaveLength(1)
      for (const category of ['personal', 'hobby'] as const) {
        const section = meSectionOf(wrapper, category)
        expect(section.findAll('table')).toHaveLength(0)
        expect(section.text()).toContain('Aucune carte enregistrée dans cette catégorie.')
        expect(section.text()).not.toContain("Enregistrer l'ordre")
      }
    })

    it("n'affiche plus aucune colonne ni champ de position", async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.find('#admin-about-site-card-position').exists()).toBe(false)
      expect(wrapper.find('#admin-about-me-card-position').exists()).toBe(false)
      for (const which of [SITE, ...Object.values(ME_TABLE)]) {
        expect(tableOf(wrapper, which).find('thead').text()).not.toContain('Position')
      }
    })
  })

  describe('ordre des cartes « site »', () => {
    it("glisse la première ligne sur la troisième puis enregistre l'ordre des groupes", async () => {
      const siteCardRepository = createSiteCardRepository()
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository, meCardRepository)

      await dragRow(wrapper, SITE, 0, 2)
      expect(panelOf(wrapper, SITE_PANEL).text()).toContain('Ordre · modifié, non enregistré')

      vi.mocked(siteCardRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, SITE_PANEL), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(siteCardRepository.reorder).toHaveBeenCalledWith([S_GROUP_TWO, S_GROUP_THREE, S_GROUP_ONE])
      expect(siteCardRepository.list).toHaveBeenCalledOnce()
      // Les brouillons sont indépendants : aucune catégorie de cartes « moi »
      // n'a bougé, donc leur endpoint n'est pas appelé.
      expect(meCardRepository.reorder).not.toHaveBeenCalled()
      expect(panelOf(wrapper, SITE_PANEL).text()).toContain('Ordre · à jour')
    })

    it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
      const { wrapper } = await mountPage(undefined, undefined, undefined, undefined, { attach: true })

      const handle = rowsOf(wrapper, SITE)[0].get('button')
      handle.element.focus()
      await handle.trigger('keydown', { key: 'ArrowDown' })
      await flushPromises()

      expect(rowsOf(wrapper, SITE)[1].text()).toContain('Architecture')
      expect(rowsOf(wrapper, SITE)[1].get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
      expect(document.activeElement).toBe(rowsOf(wrapper, SITE)[1].get('button').element)

      wrapper.unmount()
    })

    it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
      const siteCardRepository = createSiteCardRepository({
        reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository)

      await dragRow(wrapper, SITE, 0, 2)
      vi.mocked(siteCardRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, SITE_PANEL), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(siteCardRepository.list).toHaveBeenCalledOnce()
      const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
      expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
      expect(panelOf(wrapper, SITE_PANEL).text()).toContain('Ordre · à jour')
    })
  })

  describe('ordre des cartes « moi », une catégorie à la fois', () => {
    it.each(CATEGORIES)(
      "%s : glisse la première ligne sur la troisième puis n'enregistre que cette catégorie",
      async (category) => {
        const siteCardRepository = createSiteCardRepository()
        const meCardRepository = createMeCardRepository()
        const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository, meCardRepository)
        const fixture = ME_FIXTURES[category]
        const scope = () => meSectionOf(wrapper, category)

        await dragRow(wrapper, ME_TABLE[category], 0, 2)
        expect(scope().text()).toContain('Ordre · modifié, non enregistré')

        vi.mocked(meCardRepository.list).mockClear()
        await buttonLabelled(scope(), "Enregistrer l'ordre").trigger('click')
        await flushPromises()

        // La catégorie accompagne les clés : c'est elle le périmètre de l'ordre,
        // et les deux autres tables ne sont ni lues ni écrites.
        expect(meCardRepository.reorder).toHaveBeenCalledOnce()
        expect(meCardRepository.reorder).toHaveBeenCalledWith(
          [fixture.groups[1], fixture.groups[2], fixture.groups[0]],
          category,
        )
        expect(meCardRepository.list).toHaveBeenCalledOnce()
        expect(siteCardRepository.reorder).not.toHaveBeenCalled()
        expect(scope().text()).toContain('Ordre · à jour')
      },
    )

    it("les autres catégories gardent leur barre d'ordre à jour pendant qu'une est modifiée", async () => {
      const { wrapper } = await mountPage()

      await dragRow(wrapper, ME_TABLE.technical, 0, 2)

      expect(meSectionOf(wrapper, 'technical').text()).toContain('Ordre · modifié, non enregistré')
      expect(meSectionOf(wrapper, 'personal').text()).toContain('Ordre · à jour')
      expect(meSectionOf(wrapper, 'hobby').text()).toContain('Ordre · à jour')
    })

    it.each(CATEGORIES)(
      '%s : déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position',
      async (category) => {
        const { wrapper } = await mountPage(undefined, undefined, undefined, undefined, { attach: true })
        const which = ME_TABLE[category]

        const handle = rowsOf(wrapper, which)[0].get('button')
        handle.element.focus()
        await handle.trigger('keydown', { key: 'ArrowDown' })
        await flushPromises()

        expect(rowsOf(wrapper, which)[1].text()).toContain(ME_FIXTURES[category].titles[0])
        expect(rowsOf(wrapper, which)[1].get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
        expect(document.activeElement).toBe(rowsOf(wrapper, which)[1].get('button').element)

        wrapper.unmount()
      },
    )

    it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
      const meCardRepository = createMeCardRepository({
        reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)

      await dragRow(wrapper, ME_TABLE.technical, 0, 2)
      vi.mocked(meCardRepository.list).mockClear()
      await buttonLabelled(meSectionOf(wrapper, 'technical'), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(meCardRepository.list).toHaveBeenCalledOnce()
      const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
      expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
      expect(meSectionOf(wrapper, 'technical').text()).toContain('Ordre · à jour')
    })
  })

  describe('verrouillage', () => {
    it.each([
      [SITE, 'Architecture (en)', 'Stack technique', 'Créer la version EN'],
      [ME_TABLE.technical, 'Senior developer', 'Continuous watch', 'Créer la version FR'],
    ])(
      "un brouillon du tableau %i verrouille toute la page, et Annuler la libère",
      async (which, editableTitle, lonelyTitle, createVersionLabel) => {
        const { wrapper } = await mountPage()

        // Le verrou est **global à la page** : un brouillon d'ordre, où qu'il
        // soit, désactive les mutations des trois formulaires et des quatre
        // tableaux. Un état, une aide, une règle.
        await dragRow(wrapper, which, 0, 2)

        // L'aide est rendue visible, jamais portée par un `title` : Bootstrap pose
        // `pointer-events: none` sur `.btn:disabled`, donc l'infobulle d'un bouton
        // désactivé ne s'affiche jamais. Les boutons la désignent par aria-describedby.
        const hint = wrapper.get('#admin-order-locked-hint')
        expect(hint.text()).toBe("Enregistrez ou annulez l'ordre d'abord.")

        const edit = buttonLabelled(actionsForLine(wrapper, which, editableTitle), 'Modifier')
        expect(edit.attributes('disabled')).toBeDefined()
        expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
        expect(
          buttonLabelled(actionsForLine(wrapper, which, lonelyTitle), 'Supprimer').attributes('disabled'),
        ).toBeDefined()
        // Par tableau et non par index de ligne : le glisser-déposer vient de
        // déplacer le groupe sans traduction.
        expect(buttonLabelled(tableOf(wrapper, which), createVersionLabel).attributes('disabled')).toBeDefined()

        // Les trois formulaires, réglages compris : enregistrer y rechargerait
        // une liste et perdrait le brouillon.
        for (const form of [SETTINGS_FORM, SITE_FORM, ME_FORM]) {
          expect(buttonLabelled(formOf(wrapper, form), 'Enregistrer').attributes('disabled')).toBeDefined()
          // Un appel au modèle produirait un brouillon que le formulaire
          // verrouillé ne pourrait pas enregistrer : du quota dépensé pour rien
          // (ADR 0004).
          expect(translateButton(wrapper, form).attributes('disabled')).toBeDefined()
        }

        await buttonLabelled(orderScopeOf(wrapper, which), 'Annuler').trigger('click')

        expect(
          buttonLabelled(actionsForLine(wrapper, which, editableTitle), 'Modifier').attributes('disabled'),
        ).toBeUndefined()
        expect(buttonLabelled(formOf(wrapper, SETTINGS_FORM), 'Enregistrer').attributes('disabled')).toBeUndefined()
        expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
      },
    )
  })

  describe('« Créer la version » et rattachement', () => {
    it("carte « site » : ouvre une création rattachée, l'icône recopiée, la prose vide, la langue basculée", async () => {
      const { wrapper } = await mountPage()

      await buttonLabelled(rowsOf(wrapper, SITE)[1], 'Créer la version EN').trigger('click')

      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe('Ajouter une carte')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('en')
      expect(valueOf(wrapper, '#admin-about-site-card-translation-group')).toBe(S_GROUP_TWO)
      expect(valueOf(wrapper, '#admin-about-site-card-icon-key')).toBe('server')
      expect(valueOf(wrapper, '#admin-about-site-card-title')).toBe('')
      expect(valueOf(wrapper, '#admin-about-site-card-description')).toBe('')
    })

    it.each(CATEGORIES)(
      "carte « moi » (%s) : ouvre une création rattachée, catégorie et icône recopiées, la prose vide",
      async (category) => {
        const { wrapper } = await mountPage()
        const fixture = ME_FIXTURES[category]

        await buttonLabelled(rowsOf(wrapper, ME_TABLE[category])[1], 'Créer la version FR').trigger('click')

        expect(wrapper.findAll('h2')[ME_FORM].text()).toBe('Ajouter une carte')
        expect(valueOf(wrapper, '#admin-about-me-card-locale')).toBe('fr')
        // La catégorie est un champ non-prose recopié : sans elle, la version
        // créée atterrirait dans une autre catégorie que son groupe.
        expect(valueOf(wrapper, '#admin-about-me-card-category')).toBe(category)
        expect(valueOf(wrapper, '#admin-about-me-card-translation-group')).toBe(fixture.groups[1])
        expect(valueOf(wrapper, '#admin-about-me-card-icon-key')).toBe(fixture.lonelyIconKey)
        expect(valueOf(wrapper, '#admin-about-me-card-title')).toBe('')
        expect(valueOf(wrapper, '#admin-about-me-card-description')).toBe('')
      },
    )

    it("renvoie toujours le groupe lu lors d'une modification, pour ne pas détacher la traduction", async () => {
      const siteCardRepository = createSiteCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository)

      // Modifier l'entrée EN bascule aussi la langue du formulaire : sans cela,
      // l'enregistrement réécrirait l'entrée anglaise en français.
      await buttonLabelled(actionsForLine(wrapper, SITE, 'Architecture (en)'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('en')
      expect(valueOf(wrapper, '#admin-about-site-card-translation-group')).toBe(S_GROUP_ONE)

      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(siteCardRepository.update).toHaveBeenCalledWith(
        SITE_EN_ONE.id,
        expect.objectContaining({ locale: 'en', translationGroup: S_GROUP_ONE }),
      )
    })

    it("un geste dans une section ne change jamais la langue du formulaire de l'autre", async () => {
      const siteCardRepository = createSiteCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository)

      // Régression : avec un sélecteur de langue unique et partagé par les trois
      // formulaires, éditer une entrée anglaise dans un panneau basculait la
      // langue des autres — et la carte française en cours d'édition se serait
      // enregistrée en anglais, sans avertissement ni index unique pour l'arrêter
      // (une entrée solitaire n'a pas de sœur qui occupe déjà la locale).
      await buttonLabelled(actionsForLine(wrapper, SITE, 'Stack technique'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('fr')

      await buttonLabelled(actionsForLine(wrapper, ME_TABLE.technical, 'Senior developer'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, '#admin-about-me-card-locale')).toBe('en')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('fr')
      // La langue des réglages, elle, n'a pas non plus bougé.
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')

      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(siteCardRepository.update).toHaveBeenCalledWith(
        SITE_FR_TWO.id,
        expect.objectContaining({ locale: 'fr', title: SITE_FR_TWO.title }),
      )
    })

    it('envoie « aucune » pour une entrée solitaire — un non-geste côté serveur', async () => {
      const siteCardRepository = createSiteCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository)

      // SITE_FR_TWO est seul dans son groupe : le sélecteur affiche « aucune » et
      // le formulaire envoie donc `translationGroup: null`. Ce n'est pas un
      // détachement — `ContentPlacement::detach` traite une entrée seule en non-geste
      // quand l'entrée n'a pas de sœur (`count($members) === 1`), ce que pince
      // `ContentPlacementTest::testDetachingAnEntryWithoutTranslationsDoesNothing`.
      // Ces deux tests forment le contrat entre les deux moitiés : les casser
      // séparément doit être impossible sans que l'un des deux vire au rouge.
      await buttonLabelled(actionsForLine(wrapper, SITE, 'Stack technique'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, '#admin-about-site-card-translation-group')).toBe('')

      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(siteCardRepository.update).toHaveBeenCalledWith(
        SITE_FR_TWO.id,
        expect.objectContaining({ translationGroup: null }),
      )
    })

    it("« Version de » d'une carte « moi » ne propose que la catégorie du formulaire, et se vide si elle change", async () => {
      const { wrapper } = await mountPage()

      // Un groupe appartient à une seule catégorie : proposer les autres serait
      // proposer un rattachement que le serveur refuse (422).
      expect(optionsOf(wrapper, '#admin-about-me-card-translation-group')).toEqual([
        '— aucune —',
        `EN · ${ME_FIXTURES.technical.titles[2]}`,
      ])

      await wrapper.get('#admin-about-me-card-translation-group').setValue(ME_FIXTURES.technical.groups[1])
      await wrapper.get('#admin-about-me-card-category').setValue('hobby')

      expect(optionsOf(wrapper, '#admin-about-me-card-translation-group')).toEqual([
        '— aucune —',
        `EN · ${ME_FIXTURES.hobby.titles[2]}`,
      ])
      // Le rattachement retenu est remis à zéro : sans cela le sélecteur
      // afficherait une valeur absente de ses options et l'enregistrement
      // partirait vers un 422.
      expect(valueOf(wrapper, '#admin-about-me-card-translation-group')).toBe('')
    })

    it("n'envoie jamais de position dans le corps d'une écriture", async () => {
      const siteCardRepository = createSiteCardRepository()
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository, meCardRepository)

      await buttonLabelled(rowsOf(wrapper, SITE)[1], 'Créer la version EN').trigger('click')
      await wrapper.get('#admin-about-site-card-title').setValue('Technical stack')
      await wrapper.get('#admin-about-site-card-description').setValue('Stack description')
      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      const sitePayload = vi.mocked(siteCardRepository.create).mock.calls[0][0]
      expect(sitePayload).not.toHaveProperty('position')
      expect(sitePayload.translationGroup).toBe(S_GROUP_TWO)

      await buttonLabelled(rowsOf(wrapper, ME_TABLE.hobby)[1], 'Créer la version FR').trigger('click')
      await wrapper.get('#admin-about-me-card-title').setValue('Randonnée')
      await wrapper.get('#admin-about-me-card-description').setValue('Description randonnée')
      await formOf(wrapper, ME_FORM).trigger('submit.prevent')
      await flushPromises()

      const mePayload = vi.mocked(meCardRepository.create).mock.calls[0][0]
      expect(mePayload).not.toHaveProperty('position')
      expect(mePayload.translationGroup).toBe(ME_FIXTURES.hobby.groups[1])
      expect(mePayload.category).toBe('hobby')
    })

    it('affiche un message traduit si une mutation échoue', async () => {
      const siteCardRepository = createSiteCardRepository({
        create: vi.fn(async () => Promise.reject(new AdminAboutError('translation-already-exists', 'Déjà traduit'))),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository)

      await buttonLabelled(rowsOf(wrapper, SITE)[1], 'Créer la version EN').trigger('click')
      await wrapper.get('#admin-about-site-card-title').setValue('Technical stack')
      await wrapper.get('#admin-about-site-card-description').setValue('Stack description')
      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(formOf(wrapper, SITE_FORM).get('[role="alert"]').text()).toBe(
        'Ce contenu a déjà une version dans cette langue.',
      )
    })

    it('supprime une entrée après confirmation', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)

      await buttonLabelled(actionsForLine(wrapper, ME_TABLE.personal, 'Curious'), 'Supprimer').trigger('click')
      await flushPromises()

      expect(meCardRepository.remove).toHaveBeenCalledWith(ME_FIXTURES.personal.cards[1].id)
    })
  })

  describe('assistant de traduction', () => {
    it('propose un bouton par formulaire : réglages, cartes « site », cartes « moi »', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.findAll('button').filter((b) => b.text().includes('Proposer la version'))).toHaveLength(3)
    })

    it("carte « site » : envoie les seuls champs de prose, jamais la clé d'icône", async () => {
      const translation = createTranslationRepository()
      const { wrapper } = await mountPage(undefined, undefined, undefined, translation)

      await buttonLabelled(actionsForLine(wrapper, SITE, 'Stack technique'), 'Modifier').trigger('click')
      await translateButton(wrapper, SITE_FORM).trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
        title: SITE_FR_TWO.title,
        description: SITE_FR_TWO.description,
      })
    })

    it('carte « site » : bascule le formulaire (et non la page) en création rattachée au groupe source', async () => {
      const siteCardRepository = createSiteCardRepository()
      const translation = createTranslationRepository({ title: 'Technical stack', description: 'Stack description' })
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository, undefined, translation)

      await buttonLabelled(actionsForLine(wrapper, SITE, 'Stack technique'), 'Modifier').trigger('click')
      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe('Modifier la carte')
      vi.mocked(siteCardRepository.list).mockClear()

      await translateButton(wrapper, SITE_FORM).trigger('click')
      await flushPromises()

      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe('Ajouter une carte')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('en')
      // La langue de page (celle des réglages) n'a pas bougé, et aucune liste
      // n'a été rechargée : le tableau affiche déjà les deux langues.
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
      expect(siteCardRepository.list).not.toHaveBeenCalled()
      expect(valueOf(wrapper, '#admin-about-site-card-translation-group')).toBe(S_GROUP_TWO)
      expect(valueOf(wrapper, '#admin-about-site-card-title')).toBe('Technical stack')
      expect(valueOf(wrapper, '#admin-about-site-card-icon-key')).toBe('server')
      expect(siteCardRepository.create).not.toHaveBeenCalled()
      expect(siteCardRepository.update).not.toHaveBeenCalled()

      const banner = formOf(wrapper, SITE_FORM).get('[role="status"]')
      expect(banner.text()).toContain('Brouillon généré par IA')
      expect(banner.text()).toContain('FR')

      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(siteCardRepository.create).toHaveBeenCalledWith({
        locale: 'en',
        translationGroup: S_GROUP_TWO,
        title: 'Technical stack',
        description: 'Stack description',
        iconKey: 'server',
      })
    })

    it('carte « moi » : bascule le formulaire en création rattachée, catégorie et icône conservées', async () => {
      const meCardRepository = createMeCardRepository()
      const translation = createTranslationRepository({ title: 'Veille continue', description: 'Description veille' })
      const { wrapper } = await mountPage(
        createSettingsRepository(),
        createSiteCardRepository(),
        meCardRepository,
        translation,
      )
      const fixture = ME_FIXTURES.technical

      // L'entrée solitaire est ici l'anglaise : l'assistant part donc de l'EN
      // vers le FR, dans l'autre sens que pour les cartes « site ».
      await buttonLabelled(actionsForLine(wrapper, ME_TABLE.technical, fixture.titles[2]), 'Modifier').trigger('click')
      expect(valueOf(wrapper, '#admin-about-me-card-locale')).toBe('en')

      await translateButton(wrapper, ME_FORM).trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('en', 'fr', {
        title: fixture.titles[2],
        description: 'Description',
      })
      expect(wrapper.findAll('h2')[ME_FORM].text()).toBe('Ajouter une carte')
      expect(valueOf(wrapper, '#admin-about-me-card-locale')).toBe('fr')
      expect(valueOf(wrapper, '#admin-about-me-card-category')).toBe('technical')
      expect(valueOf(wrapper, '#admin-about-me-card-translation-group')).toBe(fixture.groups[1])

      await formOf(wrapper, ME_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(meCardRepository.update).not.toHaveBeenCalled()
      expect(meCardRepository.create).toHaveBeenCalledWith({
        locale: 'fr',
        translationGroup: fixture.groups[1],
        category: 'technical',
        title: 'Veille continue',
        description: 'Description veille',
        iconKey: fixture.lonelyIconKey,
      })
    })

    it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(async () => {
          throw new AdminTranslationError('rate-limited', 'Quota.')
        }),
      })
      const { wrapper } = await mountPage(undefined, undefined, undefined, translation)

      await buttonLabelled(actionsForLine(wrapper, SITE, 'Stack technique'), 'Modifier').trigger('click')
      await translateButton(wrapper, SITE_FORM).trigger('click')
      await flushPromises()

      expect(formOf(wrapper, SITE_FORM).get('[role="alert"]').text()).toContain('Quota horaire')
      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe('Modifier la carte')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('fr')
      expect(valueOf(wrapper, '#admin-about-site-card-title')).toBe(SITE_FR_TWO.title)
    })
  })

  describe('réglages (singleton par locale)', () => {
    it('charge et pré-remplit les réglages de la langue de page', async () => {
      const settingsRepository = createSettingsRepository()
      const { wrapper } = await mountPage(settingsRepository)

      expect(settingsRepository.get).toHaveBeenCalledWith('fr')
      expect(valueOf(wrapper, '#admin-about-site-eyebrow')).toBe('À propos de ce site')
    })

    it('affiche un message si le chargement des réglages échoue', async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async () => Promise.reject(new Error('unavailable'))),
      })
      const { wrapper } = await mountPage(settingsRepository)

      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="alert"]').exists()).toBe(true)
    })

    it("recharge les seuls réglages quand la langue de page change : les cartes n'en dépendent plus", async () => {
      const settingsRepository = createSettingsRepository()
      const siteCardRepository = createSiteCardRepository()
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(settingsRepository, siteCardRepository, meCardRepository)
      vi.mocked(settingsRepository.get).mockClear()
      vi.mocked(siteCardRepository.list).mockClear()
      vi.mocked(meCardRepository.list).mockClear()

      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()

      // Un singleton par locale : choisir une langue, c'est choisir
      // l'enregistrement à éditer, donc en charger un autre.
      expect(settingsRepository.get).toHaveBeenCalledWith('en')
      expect(siteCardRepository.list).not.toHaveBeenCalled()
      expect(meCardRepository.list).not.toHaveBeenCalled()
    })

    it('enregistre les réglages via son formulaire', async () => {
      const settingsRepository = createSettingsRepository()
      const { wrapper } = await mountPage(settingsRepository)

      await wrapper.get('#admin-about-site-eyebrow').setValue('Nouveau titre')
      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('fr', {
        siteEyebrow: 'Nouveau titre',
        meEyebrow: 'À propos de moi',
        technicalSubtitle: 'Techniquement',
        personalSubtitle: 'Humainement',
        hobbiesSubtitle: 'En dehors du travail',
      })
    })

    it('assistant : bascule la page en EN et applique le brouillon APRÈS le rechargement des réglages', async () => {
      const settingsRepository = createSettingsRepository()
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
      vi.mocked(settingsRepository.get).mockClear()

      await translateButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
        siteEyebrow: 'À propos de ce site',
        meEyebrow: 'À propos de moi',
        technicalSubtitle: 'Techniquement',
        personalSubtitle: 'Humainement',
        hobbiesSubtitle: 'En dehors du travail',
      })
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(settingsRepository.get).toHaveBeenCalledWith('en')
      // Le rechargement des réglages EN (le stub rend les valeurs FR) ne doit
      // pas écraser le brouillon : celui-ci est appliqué une fois la locale chargée.
      expect(valueOf(wrapper, '#admin-about-site-eyebrow')).toBe('About this site')
      expect(valueOf(wrapper, '#admin-about-hobbies-subtitle')).toBe('Outside work')
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain('Brouillon généré par IA')
      expect(settingsRepository.update).not.toHaveBeenCalled()

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('en', TRANSLATED_SETTINGS)
    })
  })

  describe('garde de sortie et accessibilité', () => {
    it('demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const { wrapper, router } = await mountPage()

      await dragRow(wrapper, ME_TABLE.hobby, 0, 2)
      await router.push('/admin/ailleurs')
      await flushPromises()

      expect(confirmSpy).toHaveBeenCalledOnce()
      expect(router.currentRoute.value.path).toBe('/admin/about')
    })

    it('quitte la route sans rien demander quand les quatre ordres sont à jour', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const { router } = await mountPage()

      await router.push('/admin/ailleurs')
      await flushPromises()

      expect(confirmSpy).not.toHaveBeenCalled()
      expect(router.currentRoute.value.path).toBe('/admin/ailleurs')
    })

    it("ne présente aucune violation d'accessibilité, les quatre tableaux groupés rendus", async () => {
      const { wrapper } = await mountPage()

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
