import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import AdminAboutPage from '../../../../src/presentation/pages/admin/AdminAboutPage.vue'
import TranslateEntryButton from '../../../../src/presentation/ui/admin/TranslateEntryButton.vue'
import BaseSelect from '../../../../src/presentation/ui/BaseSelect.vue'
import AdminAboutSettingsForm from '../../../../src/presentation/pages/admin/AdminAboutSettingsForm.vue'
import { ADMIN_ABOUT_SETTINGS_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutSettings'
import { ADMIN_ABOUT_SITE_CARD_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutSiteCards'
import { ADMIN_ABOUT_ME_CARD_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutMeCards'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import fr from '../../../../src/infrastructure/i18n/locales/fr.json'
import { HttpAdminAboutMeCardRepository } from '../../../../src/infrastructure/admin/about/HttpAdminAboutMeCardRepository'
import { BACKOFFICE_TEST_API_URL, stubBackofficeJson } from '../../../support/backofficeFetch'
import { markSessionExpired } from '../../../../src/application/auth/useAuth'
import { AccessForbiddenError } from '../../../../src/domain/auth/errors/AccessForbiddenError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { emitTranslateRightAfterMove } from '../../../support/entryFormLock'
import { signInAgain } from '../../../support/signInAgain'
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
import { ME_CARD_CATEGORIES } from '../../../../src/domain/admin/about/entities/AdminAboutMeCard'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import { proseFieldId } from '../../../support/proseFieldId'
import {
  ABOUT_SETTINGS_PROSE_FIELDS,
  ME_CARD_PROSE_FIELDS,
  SITE_CARD_PROSE_FIELDS,
} from '../../../../src/presentation/pages/admin/proseFields'
import {
  describedTexts,
  describeEntryFormLock,
  formCancelButton,
  textsDescribing,
  translateEntryButton,
} from '../../../support/entryFormLock'
import {
  ABOUT_LOCALE_HELP_FR,
  ENTRY_LOCK_PROPOSAL_FR,
  PROPOSE_VERSION_PREFIX_FR,
  SETTINGS_PROPOSAL_LOCKED_FR,
} from '../../../support/entryFormLockTexts'
import {
  EXPIRED_CALL_PENDING_FR,
  PROSE_READONLY_FR,
  proposalNoticeFrom,
  TRANSLATION_RATE_LIMITED_FR,
  TRANSLATION_UNAVAILABLE_FR,
} from '../../../support/adminTranslationTexts'
import { buttonLabelled, editButtonFor } from '../../../support/buttonLabelled'
import { createVersionLabel, ENTRY_FORM_TEXTS, ORDER_TEXTS, orderMovedLabel } from '../../../support/adminButtonTexts'

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

const SETTINGS_EN: AdminAboutSettings = { locale: 'en', ...TRANSLATED_SETTINGS }

/**
 * Des réglages **propres à chaque locale** (le stub ordinaire rend les valeurs françaises pour toutes, ce qui
 * cacherait un français montré sous l'anglais), dont la lecture anglaise tombe en session finie tant que
 * `session.ended` est vrai — la réaction du client du backoffice marque la session avant de lever l'erreur.
 */
function createLocaleAwareSettingsRepository(session: { ended: boolean }): AdminAboutSettingsRepository {
  return {
    get: vi.fn(async (locale: string) => {
      if ('en' === locale && session.ended) {
        markSessionExpired()
        throw new SessionEndedError()
      }

      return 'en' === locale ? SETTINGS_EN : SETTINGS
    }),
    update: vi.fn(async () => SETTINGS),
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

function optionsOf(wrapper: VueWrapper, id: string): string[] {
  return wrapper.get(id).findAll('option').map((option) => option.text())
}

/**
 * Le bouton « Modifier » de la ligne de `text` du tableau `which` : son libellé est lu du catalogue (une clé par tableau, « site »
 * ou « moi »), jamais écrit ici, pour qu'une retouche du libellé ne fasse pas rougir la moitié du fichier.
 */
const editButton = editButtonFor(actionsForLine, (which) =>
  SITE === which ? fr.admin.about.siteCard.editAction : fr.admin.about.meCard.editAction,
)

// Les libellés des boutons viennent du catalogue par `tests/support/adminButtonTexts.ts`, écrits une fois ; les textes propres
// à cette page se lisent ici de `fr.json` : retoucher un libellé ne fait pas rougir le fichier.
const FORM_TEXTS = ENTRY_FORM_TEXTS.about
const CREATE_EN_LABEL: string = createVersionLabel('en')
const CREATE_FR_LABEL: string = createVersionLabel('fr')
const SITE_CREATE: string = fr.admin.about.siteCard.create
const ME_CREATE: string = fr.admin.about.meCard.create

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
    vi.unstubAllGlobals()
  })

  // Le dépôt HTTP réel juge la langue de la réponse à la frontière : la page montre son alerte de chargement et l'entrée
  // dans une langue que le site ne porte pas n'atteint ni le tableau ni un formulaire (#748, R229, Sp18-3).
  it("une liste répondue avec une entrée en langue 'de' montre l'alerte de chargement, sans l'entrée (#748, R229)", async () => {
    const unsupportedEntry = {
      id: '019968a0-0000-7000-8000-0000000000e1', locale: 'de', translationGroup: '019968b0-0000-7000-8000-0000000000e2',
      category: 'technical', title: 'Dev senior', description: 'D', iconKey: null, position: 0,
    }
    stubBackofficeJson([unsupportedEntry])

    const { wrapper } = await mountPage(undefined, undefined, new HttpAdminAboutMeCardRepository(BACKOFFICE_TEST_API_URL))

    expect(wrapper.get('[role="alert"]').text()).toBe(fr.admin.about.loadError)
    expect(wrapper.text()).not.toContain(unsupportedEntry.title)
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
      expect(missing.text()).toContain(ORDER_TEXTS.missingTranslation)
      expect(buttonLabelled(missing, CREATE_EN_LABEL).exists()).toBe(true)
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
        expect(missing.text()).toContain(ORDER_TEXTS.missingTranslation)
        expect(buttonLabelled(missing, CREATE_FR_LABEL).exists()).toBe(true)
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
        expect(section.text()).toContain(fr.admin.about.meCard.empty)
        expect(section.text()).not.toContain(ORDER_TEXTS.save)
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
      expect(panelOf(wrapper, SITE_PANEL).text()).toContain(ORDER_TEXTS.statusDirty)

      vi.mocked(siteCardRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, SITE_PANEL), ORDER_TEXTS.save).trigger('click')
      await flushPromises()

      expect(siteCardRepository.reorder).toHaveBeenCalledWith([S_GROUP_TWO, S_GROUP_THREE, S_GROUP_ONE])
      expect(siteCardRepository.list).toHaveBeenCalledOnce()
      // Les brouillons sont indépendants : aucune catégorie de cartes « moi »
      // n'a bougé, donc leur endpoint n'est pas appelé.
      expect(meCardRepository.reorder).not.toHaveBeenCalled()
      expect(panelOf(wrapper, SITE_PANEL).text()).toContain(ORDER_TEXTS.statusClean)
    })

    it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
      const { wrapper } = await mountPage(undefined, undefined, undefined, undefined, { attach: true })

      const handle = rowsOf(wrapper, SITE)[0].get('button')
      handle.element.focus()
      await handle.trigger('keydown', { key: 'ArrowDown' })
      await flushPromises()

      expect(rowsOf(wrapper, SITE)[1].text()).toContain('Architecture')
      // Issue #170 F3 : l'annonce vit dans la barre du tableau, pas dans la ligne déplacée.
      expect(rowsOf(wrapper, SITE)[1].find('[role="status"]').exists()).toBe(false)
      expect(panelOf(wrapper, SITE_PANEL).get('[role="status"]').text()).toBe(orderMovedLabel(2, 3))
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
      await buttonLabelled(panelOf(wrapper, SITE_PANEL), ORDER_TEXTS.save).trigger('click')
      await flushPromises()

      expect(siteCardRepository.list).toHaveBeenCalledOnce()
      const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
      expect(alerts.some((text) => text.includes(ORDER_TEXTS.staleOrder))).toBe(true)
      expect(panelOf(wrapper, SITE_PANEL).text()).toContain(ORDER_TEXTS.statusClean)
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
        expect(scope().text()).toContain(ORDER_TEXTS.statusDirty)

        vi.mocked(meCardRepository.list).mockClear()
        await buttonLabelled(scope(), ORDER_TEXTS.save).trigger('click')
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
        expect(scope().text()).toContain(ORDER_TEXTS.statusClean)
      },
    )

    it("les autres catégories gardent leur barre d'ordre à jour pendant qu'une est modifiée", async () => {
      const { wrapper } = await mountPage()

      await dragRow(wrapper, ME_TABLE.technical, 0, 2)

      expect(meSectionOf(wrapper, 'technical').text()).toContain(ORDER_TEXTS.statusDirty)
      expect(meSectionOf(wrapper, 'personal').text()).toContain(ORDER_TEXTS.statusClean)
      expect(meSectionOf(wrapper, 'hobby').text()).toContain(ORDER_TEXTS.statusClean)
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
        // Issue #170 F3 : l'annonce vit dans la barre du tableau, pas dans la ligne déplacée.
        expect(rowsOf(wrapper, which)[1].find('[role="status"]').exists()).toBe(false)
        expect(meSectionOf(wrapper, category).get('[role="status"]').text()).toBe(orderMovedLabel(2, 3))
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
      await buttonLabelled(meSectionOf(wrapper, 'technical'), ORDER_TEXTS.save).trigger('click')
      await flushPromises()

      expect(meCardRepository.list).toHaveBeenCalledOnce()
      const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
      expect(alerts.some((text) => text.includes(ORDER_TEXTS.staleOrder))).toBe(true)
      expect(meSectionOf(wrapper, 'technical').text()).toContain(ORDER_TEXTS.statusClean)
    })
  })

  describe('verrouillage', () => {
    it.each([
      [SITE, 'Architecture (en)', 'Stack technique', CREATE_EN_LABEL],
      [ME_TABLE.technical, 'Senior developer', 'Continuous watch', CREATE_FR_LABEL],
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
        expect(hint.text()).toBe(ORDER_TEXTS.lockedHint)

        const edit = editButton(wrapper, which, editableTitle)
        expect(edit.attributes('disabled')).toBeDefined()
        expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
        expect(
          buttonLabelled(actionsForLine(wrapper, which, lonelyTitle), FORM_TEXTS.delete).attributes('disabled'),
        ).toBeDefined()
        // Par tableau et non par index de ligne : le glisser-déposer vient de
        // déplacer le groupe sans traduction.
        expect(buttonLabelled(tableOf(wrapper, which), createVersionLabel).attributes('disabled')).toBeDefined()

        // Les trois formulaires, réglages compris : enregistrer y rechargerait
        // une liste et perdrait le brouillon.
        for (const form of [SETTINGS_FORM, SITE_FORM, ME_FORM]) {
          expect(buttonLabelled(formOf(wrapper, form), FORM_TEXTS.save).attributes('disabled')).toBeDefined()
          // Un appel au modèle produirait une proposition que le formulaire
          // verrouillé ne pourrait pas enregistrer : du quota dépensé pour rien
          // (ADR 0004).
          expect(translateEntryButton(wrapper, form).attributes('disabled')).toBeDefined()
        }

        await buttonLabelled(orderScopeOf(wrapper, which), ORDER_TEXTS.cancel).trigger('click')

        expect(
          editButton(wrapper, which, editableTitle).attributes('disabled'),
        ).toBeUndefined()
        expect(buttonLabelled(formOf(wrapper, SETTINGS_FORM), FORM_TEXTS.save).attributes('disabled')).toBeUndefined()
        expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
      },
    )
  })

  describe('verrouillage de la traduction sans attendre le rendu', () => {
    // Chaque section lit son propre brouillon d'ordre tout de suite (comme celles de Qualité, R173) : un « translate » émis
    // entre un `move` et le rendu de la page est refusé. Sans cette lecture, `props.isLocked` est encore faux.
    it("cartes « site » : un ordre modifié refuse la traduction sans attendre le rendu (#748, R173b)", async () => {
      const translation = createTranslationRepository({ title: 'Architecture (en)', description: 'Architecture description' })
      const { wrapper } = await mountPage(undefined, undefined, undefined, translation)
      await editButton(wrapper, SITE, 'Stack technique').trigger('click')

      await emitTranslateRightAfterMove(wrapper, SITE, SITE_FORM)

      expect(translation.translate).not.toHaveBeenCalled()
      expect(formOf(wrapper, SITE_FORM).find('[role="status"]').exists()).toBe(false)
    })

    // Un cas par catégorie, lues de la liste que le domaine exporte : un `isAnyOrderDirty` qui n'en lirait qu'une resterait vert
    // si seule celle-là était exercée (#748, R187, Sp15-7).
    it.each(ME_CARD_CATEGORIES)(
      "cartes « moi » (%s) : l'ordre modifié de la catégorie refuse la traduction sans attendre le rendu (#748, R173b)",
      async (category) => {
        const translation = createTranslationRepository({ title: 'Senior developer', description: 'Description' })
        const { wrapper } = await mountPage(undefined, undefined, undefined, translation)
        await editButton(wrapper, ME_TABLE[category], ME_FIXTURES[category].titles[0]).trigger('click')

        await emitTranslateRightAfterMove(wrapper, ME_TABLE[category], ME_FORM)

        expect(translation.translate).not.toHaveBeenCalled()
        expect(formOf(wrapper, ME_FORM).find('[role="status"]').exists()).toBe(false)
      },
    )
  })

  describe('« Créer la version » et rattachement', () => {
    it("carte « site » : ouvre une création rattachée, l'icône recopiée, la prose vide, la langue basculée", async () => {
      const { wrapper } = await mountPage()

      await buttonLabelled(rowsOf(wrapper, SITE)[1], CREATE_EN_LABEL).trigger('click')

      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe(SITE_CREATE)
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

        await buttonLabelled(rowsOf(wrapper, ME_TABLE[category])[1], CREATE_FR_LABEL).trigger('click')

        expect(wrapper.findAll('h2')[ME_FORM].text()).toBe(ME_CREATE)
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
      await editButton(wrapper, SITE, 'Architecture (en)').trigger('click')
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
      await editButton(wrapper, SITE, 'Stack technique').trigger('click')
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('fr')

      await editButton(wrapper, ME_TABLE.technical, 'Senior developer').trigger('click')
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
      await editButton(wrapper, SITE, 'Stack technique').trigger('click')
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
        fr.admin.about.translationOfNone,
        `EN · ${ME_FIXTURES.technical.titles[2]}`,
      ])

      await wrapper.get('#admin-about-me-card-translation-group').setValue(ME_FIXTURES.technical.groups[1])
      await wrapper.get('#admin-about-me-card-category').setValue('hobby')

      expect(optionsOf(wrapper, '#admin-about-me-card-translation-group')).toEqual([
        fr.admin.about.translationOfNone,
        `EN · ${ME_FIXTURES.hobby.titles[2]}`,
      ])
      // Le rattachement retenu est remis à zéro : sans cela le sélecteur
      // afficherait une valeur absente de ses options et l'enregistrement
      // partirait vers un 422.
      expect(valueOf(wrapper, '#admin-about-me-card-translation-group')).toBe('')
    })

    /**
     * Issue #578 : une carte ne change pas de catégorie. Le sélecteur est
     * désactivé en édition, comme la langue (#170 R2), avec une aide visible qui
     * dit comment ranger la carte ailleurs : actif, le changer viderait « Version
     * de » (le watcher ci-dessus, pensé pour la création), le `PUT` partirait avec
     * `translationGroup: null` et le serveur détacherait la carte de sa traduction
     * en ignorant la catégorie (200).
     */
    it("modifier une carte « moi » qui a une traduction : la catégorie ne se change pas, l'enregistrement garde le lien (#578)", async () => {
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)
      const edited = ME_FIXTURES.technical.cards[0]

      // Le geste qui détachait la carte : il ne passe plus. Sur un champ
      // désactivé, Vue Test Utils n'émet pas le `change`, comme un navigateur
      // qui n'en laisse pas l'occasion à l'utilisateur ; ce qui compte est le
      // corps envoyé (le DOM, lui, garde la valeur écrite de force). Le
      // `disabled` est vérifié plus bas.
      await editButton(wrapper, ME_TABLE.technical, edited.title).trigger('click')
      await wrapper.get('#admin-about-me-card-category').setValue('hobby')
      await formOf(wrapper, ME_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(meCardRepository.update).toHaveBeenLastCalledWith(
        edited.id,
        expect.objectContaining({ category: 'technical', translationGroup: ME_FIXTURES.technical.groups[0] }),
      )

      // Désactivé, pas caché : la catégorie reste lisible. L'aide est un texte
      // visible relié au champ, jamais un `title`.
      await editButton(wrapper, ME_TABLE.technical, edited.title).trigger('click')
      const category = wrapper.get('#admin-about-me-card-category')
      expect(category.attributes('disabled')).toBeDefined()
      const helpId = category.attributes('aria-describedby')
      expect(helpId).toBe('admin-about-me-card-category-help')
      expect(wrapper.get(`#${helpId}`).text()).toContain(fr.admin.about.meCard.categoryLockedHelp)
    })

    it('libère la catégorie et retire son aide hors de l’édition', async () => {
      const { wrapper } = await mountPage()

      await editButton(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[0]).trigger('click')
      await buttonLabelled(formOf(wrapper, ME_FORM), FORM_TEXTS.cancel).trigger('click')

      const category = wrapper.get('#admin-about-me-card-category')
      expect(category.attributes('disabled')).toBeUndefined()
      // Pas de référence pendante : l'aide n'existe qu'en édition.
      expect(category.attributes('aria-describedby')).toBeUndefined()
      expect(wrapper.find('#admin-about-me-card-category-help').exists()).toBe(false)
    })

    // Un groupe antérieur à la garde de #501 : « Musique » (FR) reste dans
    // « En dehors du travail », sa version EN « Music » a été rangée dans
    // « Techniquement ». L'application ne peut pas en produire, seule une
    // écriture SQL d'avant #501 le pourrait — d'où une limite acceptée plutôt
    // qu'un guidage dans l'interface (décision #573, `frontend-backoffice.md`).
    // Les deux membres sont couverts : aucune catégorie n'est sûrement la bonne,
    // la carte égarée peut être l'une ou l'autre.
    it.each([
      { member: 'FR « Musique », restée dans « En dehors du travail »', index: 0, category: 'hobby' as const },
      { member: 'EN « Music », rangée dans « Techniquement »', index: 1, category: 'technical' as const },
    ])("groupe réparti sur deux catégories (limite acceptée, #573), $member : le sélecteur paraît vide, « aucune » détache", async ({ index, category }) => {
      const spreadGroup = ME_FIXTURES.hobby.groups[0]
      const musicEn = ME_FIXTURES.hobby.cards[1]
      const spreadCards = ALL_ME_CARDS.map((card) => (card.id === musicEn.id ? { ...card, category: 'technical' as const } : card))
      const edited = spreadCards.find((card) => card.id === ME_FIXTURES.hobby.cards[index].id)
      if (!edited) throw new Error('Carte du groupe réparti introuvable.')
      const meCardRepository = createMeCardRepository({ list: vi.fn(async () => spreadCards) })
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)

      await editButton(wrapper, ME_TABLE[category], edited.title).trigger('click')

      // `startEdit` garde le groupe (`hasSibling` cherche dans toutes les
      // catégories), mais « Version de » ne liste que la catégorie du
      // formulaire, où la sœur n'est pas : la valeur est hors des options, et
      // le navigateur affiche un sélecteur vide.
      const select = wrapper.get('#admin-about-me-card-translation-group').element as HTMLSelectElement
      const optionValues = Array.from(select.options).map((option) => option.value)
      expect(optionValues).not.toContain(spreadGroup)
      expect(select.selectedIndex).toBe(-1)

      // Enregistrer sans toucher au sélecteur renvoie le propre groupe de la
      // carte : un non-geste côté serveur (#561), le texte reste éditable.
      await formOf(wrapper, ME_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(meCardRepository.update).toHaveBeenLastCalledWith(
        edited.id,
        expect.objectContaining({ translationGroup: spreadGroup }),
      )

      // Le chemin de correction : « — aucune — » envoie `null`, que le serveur
      // lit toutes catégories confondues et traite en vrai détachement (#561).
      await editButton(wrapper, ME_TABLE[category], edited.title).trigger('click')
      await wrapper.get('#admin-about-me-card-translation-group').setValue('')
      await formOf(wrapper, ME_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(meCardRepository.update).toHaveBeenLastCalledWith(
        edited.id,
        expect.objectContaining({ translationGroup: null }),
      )
    })

    it("n'envoie jamais de position dans le corps d'une écriture", async () => {
      const siteCardRepository = createSiteCardRepository()
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), siteCardRepository, meCardRepository)

      await buttonLabelled(rowsOf(wrapper, SITE)[1], CREATE_EN_LABEL).trigger('click')
      await wrapper.get('#admin-about-site-card-title').setValue('Technical stack')
      await wrapper.get('#admin-about-site-card-description').setValue('Stack description')
      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      const sitePayload = vi.mocked(siteCardRepository.create).mock.calls[0][0]
      expect(sitePayload).not.toHaveProperty('position')
      expect(sitePayload.translationGroup).toBe(S_GROUP_TWO)

      await buttonLabelled(rowsOf(wrapper, ME_TABLE.hobby)[1], CREATE_FR_LABEL).trigger('click')
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

      await buttonLabelled(rowsOf(wrapper, SITE)[1], CREATE_EN_LABEL).trigger('click')
      await wrapper.get('#admin-about-site-card-title').setValue('Technical stack')
      await wrapper.get('#admin-about-site-card-description').setValue('Stack description')
      await formOf(wrapper, SITE_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(formOf(wrapper, SITE_FORM).get('[role="alert"]').text()).toBe(
        fr.admin.about.errors['translation-already-exists'],
      )
    })

    it('affiche le refus d’une catégorie changée (409 de #578) sous son propre message', async () => {
      const meCardRepository = createMeCardRepository({
        update: vi.fn(async () => Promise.reject(new AdminAboutError('category-immutable', 'Catégorie immuable'))),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)

      await editButton(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[0]).trigger('click')
      await formOf(wrapper, ME_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(formOf(wrapper, ME_FORM).get('[role="alert"]').text()).toBe(
        fr.admin.about.errors['category-immutable'],
      )
    })

    it('supprime une entrée après confirmation', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const meCardRepository = createMeCardRepository()
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardRepository)

      await buttonLabelled(actionsForLine(wrapper, ME_TABLE.personal, 'Curious'), FORM_TEXTS.delete).trigger('click')
      await flushPromises()

      expect(meCardRepository.remove).toHaveBeenCalledWith(ME_FIXTURES.personal.cards[1].id)
    })
  })

  describe('assistant de traduction', () => {
    it('propose un bouton par formulaire : réglages, cartes « site », cartes « moi »', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.findAll('button').filter((b) => b.text().includes(PROPOSE_VERSION_PREFIX_FR))).toHaveLength(3)
    })

    it("carte « site » : envoie les seuls champs de prose, jamais la clé d'icône", async () => {
      const translation = createTranslationRepository()
      const { wrapper } = await mountPage(undefined, undefined, undefined, translation)

      await editButton(wrapper, SITE, 'Stack technique').trigger('click')
      await translateEntryButton(wrapper, SITE_FORM).trigger('click')
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

      await editButton(wrapper, SITE, 'Stack technique').trigger('click')
      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe(fr.admin.about.siteCard.edit)
      vi.mocked(siteCardRepository.list).mockClear()

      await translateEntryButton(wrapper, SITE_FORM).trigger('click')
      await flushPromises()

      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe(SITE_CREATE)
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
      expect(banner.text()).toContain(proposalNoticeFrom('fr'))

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
      await editButton(wrapper, ME_TABLE.technical, fixture.titles[2]).trigger('click')
      expect(valueOf(wrapper, '#admin-about-me-card-locale')).toBe('en')

      await translateEntryButton(wrapper, ME_FORM).trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('en', 'fr', {
        title: fixture.titles[2],
        description: 'Description',
      })
      expect(wrapper.findAll('h2')[ME_FORM].text()).toBe(ME_CREATE)
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

    // Les dépôts du formulaire en cours de test, recréés par chaque `mount` : `holdNextSave` les arme.
    let siteCardUnderLock = createSiteCardRepository()
    let meCardUnderLock = createMeCardRepository()

    // Les cas du verrou du formulaire, avec l'aide permanente (#582) : écrits une fois, pour tous les
    // formulaires par entrée, dans `tests/support/entryFormLock.ts` (#748, R91). Dans les cartes « moi », la proposition
    // verrouille aussi la catégorie (D-b, plus bas) ; « Annuler » libère les deux.
    describeEntryFormLock({
      name: 'carte « site »',
      createTranslation: () => createTranslationRepository(),
      mount: async (translation) => {
        siteCardUnderLock = createSiteCardRepository()
        return (await mountPage(undefined, siteCardUnderLock, undefined, translation)).wrapper
      },
      form: SITE_FORM,
      ids: {
        locale: 'admin-about-site-card-locale',
        group: 'admin-about-site-card-translation-group',
        title: 'admin-about-site-card-title',
        hint: 'admin-about-site-card-entry-lock-help',
      },
      proseFields: SITE_CARD_PROSE_FIELDS,
      heading: SITE_CREATE,
      permanentHelp: ABOUT_LOCALE_HELP_FR,
      startEdit: async (wrapper) => {
        await editButton(wrapper, SITE, 'Stack technique').trigger('click')
      },
      deleteEditedEntry: async (wrapper) => {
        await buttonLabelled(actionsForLine(wrapper, SITE, 'Stack technique'), FORM_TEXTS.delete).trigger('click')
      },
      startEditOther: async (wrapper) => {
        await editButton(wrapper, SITE, 'Architecture').trigger('click')
      },
      // Le membre de l'autre langue de l'entrée que `startEditOther` ouvre.
      deleteSiblingOfOther: async (wrapper) => {
        await buttonLabelled(actionsForLine(wrapper, SITE, 'Architecture (en)'), FORM_TEXTS.delete).trigger('click')
      },
      startCreateVersion: (wrapper) => buttonLabelled(rowsOf(wrapper, SITE)[1], CREATE_EN_LABEL).trigger('click'),
      orderTable: SITE,
      propose: async (wrapper) => {
        await translateEntryButton(wrapper, SITE_FORM).trigger('click')
        await flushPromises()
      },
      holdNextSave: () => {
        vi.mocked(siteCardUnderLock.update).mockReturnValueOnce(new Promise<never>(() => {}))
      },
    })

    describeEntryFormLock({
      name: 'carte « moi »',
      createTranslation: () => createTranslationRepository({ title: 'Veille continue', description: 'Description veille' }),
      mount: async (translation) => {
        meCardUnderLock = createMeCardRepository()
        return (await mountPage(createSettingsRepository(), createSiteCardRepository(), meCardUnderLock, translation)).wrapper
      },
      form: ME_FORM,
      ids: {
        locale: 'admin-about-me-card-locale',
        group: 'admin-about-me-card-translation-group',
        title: 'admin-about-me-card-title',
        hint: 'admin-about-me-card-entry-lock-help',
      },
      proseFields: ME_CARD_PROSE_FIELDS,
      heading: ME_CREATE,
      permanentHelp: ABOUT_LOCALE_HELP_FR,
      startEdit: async (wrapper) => {
        await editButton(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[2]).trigger('click')
      },
      deleteEditedEntry: async (wrapper) => {
        await buttonLabelled(
          actionsForLine(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[2]),
          FORM_TEXTS.delete,
        ).trigger('click')
      },
      startEditOther: async (wrapper) => {
        await editButton(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[0]).trigger('click')
      },
      // Le membre de l'autre langue de l'entrée que `startEditOther` ouvre.
      deleteSiblingOfOther: async (wrapper) => {
        await buttonLabelled(
          actionsForLine(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[1]),
          FORM_TEXTS.delete,
        ).trigger('click')
      },
      startCreateVersion: (wrapper) => buttonLabelled(rowsOf(wrapper, ME_TABLE.technical)[1], CREATE_FR_LABEL).trigger('click'),
      orderTable: ME_TABLE.technical,
      propose: async (wrapper) => {
        await translateEntryButton(wrapper, ME_FORM).trigger('click')
        await flushPromises()
      },
      holdNextSave: () => {
        vi.mocked(meCardUnderLock.update).mockReturnValueOnce(new Promise<never>(() => {}))
      },
    })

    // D-b de l'auteur (#748, 2026-10-30) : une version dans l'autre langue ne change pas de catégorie
    // (#501, #561, comme en édition #578) ; la changer vidait « Version de » (le watcher `flush: 'sync'`)
    // et l'enregistrement créait une carte détachée de sa source avec la prose générée.
    it("carte « moi » : après une proposition, la catégorie est verrouillée et expliquée, « Annuler » la libère", async () => {
      const translation = createTranslationRepository({ title: 'Veille continue', description: 'Description veille' })
      const { wrapper } = await mountPage(createSettingsRepository(), createSiteCardRepository(), undefined, translation)

      await editButton(wrapper, ME_TABLE.technical, ME_FIXTURES.technical.titles[2]).trigger('click')
      await translateEntryButton(wrapper, ME_FORM).trigger('click')
      await flushPromises()

      expect(wrapper.get('#admin-about-me-card-category').attributes('disabled')).toBeDefined()
      expect(valueOf(wrapper, '#admin-about-me-card-category')).toBe('technical')
      expect(describedTexts(wrapper, '#admin-about-me-card-category')).toEqual([ENTRY_LOCK_PROPOSAL_FR])

      await formCancelButton(wrapper, ME_FORM)?.trigger('click')
      await flushPromises()

      expect(wrapper.get('#admin-about-me-card-category').attributes('disabled')).toBeUndefined()
      expect(describedTexts(wrapper, '#admin-about-me-card-category')).toEqual([])
      expect(wrapper.find('#admin-about-me-card-category-help').exists()).toBe(false)
    })

    it("carte « moi » : « Créer la version FR » laisse la catégorie libre, sans explication de verrou", async () => {
      const { wrapper } = await mountPage()

      await buttonLabelled(rowsOf(wrapper, ME_TABLE.technical)[1], CREATE_FR_LABEL).trigger('click')

      expect(wrapper.get('#admin-about-me-card-category').attributes('disabled')).toBeUndefined()
      expect(describedTexts(wrapper, '#admin-about-me-card-category')).toEqual([])
      expect(describedTexts(wrapper, '#admin-about-me-card-locale')).toEqual([ABOUT_LOCALE_HELP_FR])
    })

    it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(async () => {
          throw new AdminTranslationError('rate-limited', 'Quota.')
        }),
      })
      const { wrapper } = await mountPage(undefined, undefined, undefined, translation)

      await editButton(wrapper, SITE, 'Stack technique').trigger('click')
      await translateEntryButton(wrapper, SITE_FORM).trigger('click')
      await flushPromises()

      expect(formOf(wrapper, SITE_FORM).get('[role="alert"]').text()).toContain(TRANSLATION_RATE_LIMITED_FR)
      expect(wrapper.findAll('h2')[SITE_FORM].text()).toBe(fr.admin.about.siteCard.edit)
      expect(valueOf(wrapper, '#admin-about-site-card-locale')).toBe('fr')
      expect(valueOf(wrapper, '#admin-about-site-card-title')).toBe(SITE_FR_TWO.title)
    })
  })

  describe('réglages (singleton par locale)', () => {
    it('charge et pré-remplit les réglages de la langue de page', async () => {
      const settingsRepository = createSettingsRepository()
      const { wrapper } = await mountPage(settingsRepository)

      expect(settingsRepository.get).toHaveBeenCalledWith('fr')
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe('À propos de ce site')
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

    // Décision 2 de la onzième relecture (R125) : changer la langue pendant la traduction la périme ; l'appel déjà parti
    // continue, et une nouvelle traduction émise avant sa réponse est refusée, le bouton disant pourquoi il attend.
    it("la langue changée pendant une traduction : une seule traduction en vol, l'attente dite (#748, R125, décision 2)", async () => {
      let answer!: (proposal: TranslationProposal) => void
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(() => new Promise<TranslationProposal>((resolve) => { answer = resolve })),
      })
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
      })
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
      const settingsForm = wrapper.findAllComponents(AdminAboutSettingsForm)[0]
      const button = () => translateEntryButton(wrapper, SETTINGS_FORM)

      await button().trigger('click')
      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()
      settingsForm.findComponent(TranslateEntryButton).vm.$emit('translate', 'fr')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledTimes(1)
      expect(textsDescribing(wrapper, button())).toEqual([EXPIRED_CALL_PENDING_FR])
      await expectNoAccessibilityViolation(wrapper)

      answer({ sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED_SETTINGS })
      await flushPromises()

      expect(textsDescribing(wrapper, button())).toEqual([])
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)
      expect(button().attributes('disabled')).toBeUndefined()
    })

    // D5, Sp15-1 = C15-7 (#748, R187) : un geste de la personne résolu au même point de contrôle que la réponse de la traduction
    // (`p.then` est accroché après l'appel : il court juste après la pose, avant tout rendu). La page y revient à la langue
    // montrée : la proposition posée une micro-tâche trop tard le reprendrait sur la langue cible.
    it(
      "réglages : un geste résolu au même point de contrôle que la réponse ne reçoit pas de proposition périmée (#748, R187)",
      async () => {
        let answer!: (proposal: TranslationProposal) => void
        const held = new Promise<TranslationProposal>((resolve) => { answer = resolve })
        const translation = createTranslationRepository(TRANSLATED_SETTINGS, { translate: vi.fn(() => held) })
        const settingsRepository = createSettingsRepository({
          get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        })
        const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
        const events: string[] = []

        await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
        void held.then(() => {
          events.push('retour à la langue montrée')
          void wrapper.get('#admin-about-locale').setValue('fr')
        })
        answer({ sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED_SETTINGS })
        await flushPromises()

        expect(events).toEqual(['retour à la langue montrée'])
        expect(translation.translate).toHaveBeenCalledOnce()
        expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)
        expect(formOf(wrapper, SETTINGS_FORM).find('[role="status"]').exists()).toBe(false)
      },
    )

    // R187b, décision de l'auteur du 2026-11-01 : la proposition parquée pour EN part dès que la personne demande une autre
    // langue, même si FR → EN → FR se joue avant le rendu : le formulaire ne voit alors jamais la langue changer, et la
    // proposition se serait posée plus tard, au choix d'EN.
    it(
      "réglages : un retour à FR au même point de contrôle que la réponse, puis EN plus tard, ne pose aucune proposition (#748, R187b)",
      async () => {
        let answer!: (proposal: TranslationProposal) => void
        const held = new Promise<TranslationProposal>((resolve) => { answer = resolve })
        const translation = createTranslationRepository(TRANSLATED_SETTINGS, { translate: vi.fn(() => held) })
        const serverEnglish: AdminAboutSettings = { ...SETTINGS_EN, siteEyebrow: 'Texte EN du serveur' }
        const settingsRepository = createSettingsRepository({
          get: vi.fn(async (locale: string) => ('en' === locale ? serverEnglish : SETTINGS)),
        })
        const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

        await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
        void held.then(() => { void wrapper.get('#admin-about-locale').setValue('fr') })
        answer({ sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED_SETTINGS })
        await flushPromises()

        expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)

        await wrapper.get('#admin-about-locale').setValue('en')
        await flushPromises()

        expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe('Texte EN du serveur')
        expect(formOf(wrapper, SETTINGS_FORM).find('[role="status"]').exists()).toBe(false)
      },
    )

    // R201 (St16-2 = C16-7) : le sélecteur n'émet qu'une `string` ; une valeur hors de `SUPPORTED_LOCALES` (un `<option>` ajouté,
    // un événement programmatique) n'est ni montrée, ni lue du serveur, ni cause du retrait d'une proposition parquée.
    const emitLocaleChoice = (wrapper: VueWrapper, value: string): void => {
      wrapper.getComponent(BaseSelect).vm.$emit('update:modelValue', value)
    }

    it("réglages : une langue non prise en charge n'est ni montrée ni lue (#748, R201)", async () => {
      const settingsRepository = createSettingsRepository()
      const { wrapper } = await mountPage(settingsRepository)
      vi.mocked(settingsRepository.get).mockClear()

      emitLocaleChoice(wrapper, 'de')
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
      expect(settingsRepository.get).not.toHaveBeenCalled()
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)
    })

    it("réglages : une langue non prise en charge ne retire pas la proposition parquée (#748, R201)", async () => {
      let readEnglish!: (settings: AdminAboutSettings) => void
      const settingsRepository = createSettingsRepository({
        get: vi.fn((locale: string) => ('en' === locale
          ? new Promise<AdminAboutSettings>((resolve) => { readEnglish = resolve })
          : Promise.resolve(SETTINGS))),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      // La traduction bascule la page en EN : la proposition est parquée, la lecture d'EN reste en vol.
      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')

      emitLocaleChoice(wrapper, 'de')
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(settingsRepository.get).not.toHaveBeenCalledWith('de')

      readEnglish({ ...SETTINGS_EN, siteEyebrow: 'Texte EN du serveur' })
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))
    })

    // Décision 5 et Q5 (A) de la onzième relecture (R127) : ce que le bouton « Proposer la version XX » refuse, la
    // traduction le refuse aussi. Un « translate » émis par programme passerait un `:disabled` seul.
    const SETTINGS_PROPOSAL_LOCKED_HINT = '#admin-about-settings-proposal-locked-help'
    const emitSettingsTranslate = (wrapper: VueWrapper, target: 'fr' | 'en'): void => {
      wrapper.findAllComponents(AdminAboutSettingsForm)[0].findComponent(TranslateEntryButton).vm.$emit('translate', target)
    }

    it("réglages, un enregistrement en vol : un « translate » émis ne lance rien (#748, R127)", async () => {
      const settingsRepository = createSettingsRepository({ update: vi.fn(() => new Promise<AdminAboutSettings>(() => {})) })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(settingsRepository.update).toHaveBeenCalledOnce()
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeDefined()

      emitSettingsTranslate(wrapper, 'en')
      await flushPromises()

      expect(translation.translate).not.toHaveBeenCalled()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
    })

    it("réglages, un ordre modifié ailleurs : un « translate » émis ne lance rien (#748, R127)", async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)

      await dragRow(wrapper, SITE, 0, 2)
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeDefined()

      emitSettingsTranslate(wrapper, 'en')
      await flushPromises()

      expect(translation.translate).not.toHaveBeenCalled()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
    })

    // R127b : le bouton est aussi désactivé sans aucune prose à traduire ; un « translate » émis ne lance rien non plus.
    it("réglages, sans aucune prose à traduire : un « translate » émis ne lance rien (#748, R127b)", async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)
      for (const id of SETTINGS_PROSE_IDS) {
        await wrapper.get(`#${id}`).setValue('')
      }
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeDefined()

      emitSettingsTranslate(wrapper, 'en')
      await flushPromises()

      expect(translation.translate).not.toHaveBeenCalled()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="alert"]').exists()).toBe(false)
    })

    it("réglages, une proposition affichée : bouton désactivé et décrit, un « translate » émis ne lance rien (#748, R127)", async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)
      expect(wrapper.find(SETTINGS_PROPOSAL_LOCKED_HINT).exists()).toBe(false)
      expect(textsDescribing(wrapper, translateEntryButton(wrapper, SETTINGS_FORM))).toEqual([])

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(translation.translate).toHaveBeenCalledOnce()
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))

      const button = translateEntryButton(wrapper, SETTINGS_FORM)
      expect(button.attributes('disabled')).toBeDefined()
      expect(textsDescribing(wrapper, button)).toEqual([SETTINGS_PROPOSAL_LOCKED_FR])
      await expectNoAccessibilityViolation(wrapper)

      emitSettingsTranslate(wrapper, 'fr')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledOnce()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))
    })

    // R127b : la proposition ne se montre pas sous l'autre langue, et le retour sur sa langue cible relit le formulaire du
    // serveur, par le sélecteur « Langue des textes de la page À propos ». Depuis R127c, le texte
    // du verrou nomme « Abandonner » et non plus ce détour, qui reste un autre chemin du même retrait.
    it("réglages, une proposition affichée : passer à l'autre langue puis revenir l'abandonne (#748, R127b)", async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(textsDescribing(wrapper, translateEntryButton(wrapper, SETTINGS_FORM))).toEqual([SETTINGS_PROPOSAL_LOCKED_FR])

      // Sous l'autre langue, la proposition ne se montre pas (aucun avis ni verrou), que des réglages l'aient retirée ou non.
      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      expect(wrapper.find(SETTINGS_PROPOSAL_LOCKED_HINT).exists()).toBe(false)
      // Sous le FR, le formulaire porte les réglages FR enregistrés, ni avis ni verrou de la proposition de l'EN.
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)

      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(wrapper.find(SETTINGS_PROPOSAL_LOCKED_HINT).exists()).toBe(false)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeUndefined()
      expect(textsDescribing(wrapper, translateEntryButton(wrapper, SETTINGS_FORM))).toEqual([])
    })

    // R127c, décision de l'auteur du 2026-10-31 : un bouton « Abandonner » retire la proposition affichée et relit le
    // formulaire de la langue montrée. Il n'existe que pendant la proposition, et le texte du verrou le nomme.
    // Le bouton est trouvé par sa structure (un `type="button"` du panneau qui n'est pas « Proposer »), jamais par son libellé :
    // les cas qui le nomment lisent ce libellé sur le bouton rendu.
    const abandonButton = (wrapper: VueWrapper): DOMWrapper<Element> | undefined =>
      panelOf(wrapper, SETTINGS_PANEL)
        .findAll('button')
        .find((button) => 'button' === button.attributes('type') && button.element !== translateEntryButton(wrapper, SETTINGS_FORM).element)
    const SAVED_EN: AdminAboutSettings = { ...SETTINGS_EN, siteEyebrow: 'Saved English eyebrow' }

    it("réglages, « Abandonner » : un bouton simple, seulement pendant une proposition, nommé par le verrou (#748, R127c)", async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)
      expect(abandonButton(wrapper)).toBeUndefined()

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()

      const abandon = abandonButton(wrapper)
      expect(abandon?.element.tagName).toBe('BUTTON')
      expect(abandon?.attributes('type')).toBe('button')
      expect(abandon?.attributes('disabled')).toBeUndefined()
      // Le texte qui décrit « Proposer » nomme le bouton tel qu'il est rendu : son libellé est lu sur lui.
      const described = textsDescribing(wrapper, translateEntryButton(wrapper, SETTINGS_FORM))
      expect(described).toHaveLength(1)
      expect(described[0]).toContain(`« ${abandon?.text()} »`)
      await expectNoAccessibilityViolation(wrapper)
    })

    it("réglages, « Abandonner » retire la proposition, relit le formulaire, lève le verrou, rend le focus (#748, R127c)", async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SAVED_EN : SETTINGS)),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation, { attach: true })
      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)
      const englishReads = (): number =>
        vi.mocked(settingsRepository.get).mock.calls.filter(([locale]) => 'en' === locale).length
      expect(englishReads()).toBe(1)

      await abandonButton(wrapper)?.trigger('click')
      await flushPromises()

      // Le formulaire de la langue montrée est relu du serveur : les valeurs enregistrées reviennent.
      expect(englishReads()).toBe(2)
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SAVED_EN.siteEyebrow)
      expect(valueOf(wrapper, '#admin-about-settings-me-eyebrow')).toBe(SAVED_EN.meEyebrow)
      // Le verrou, son texte, l'avis et le bouton d'abandon sont partis ; « Proposer » est libre.
      expect(wrapper.find(SETTINGS_PROPOSAL_LOCKED_HINT).exists()).toBe(false)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
      expect(abandonButton(wrapper)).toBeUndefined()
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeUndefined()
      expect(textsDescribing(wrapper, translateEntryButton(wrapper, SETTINGS_FORM))).toEqual([])
      // Le bouton a disparu sous le focus : il revient au premier champ du formulaire relu, jamais à `body`.
      expect(document.activeElement).toBe(wrapper.get('#admin-about-settings-site-eyebrow').element)
      expect(translation.translate).toHaveBeenCalledOnce()
      await expectNoAccessibilityViolation(wrapper)
    })

    // Décision 3 de la onzième relecture (R126) : pendant la traduction des réglages, les cinq champs de prose sont en
    // lecture seule, décrits par le texte d'aide ; la réponse remplace une prose que personne n'a pu changer.
    const SETTINGS_PROSE_IDS = ABOUT_SETTINGS_PROSE_FIELDS.map((field) => proseFieldId('admin-about-settings', field))

    it("pendant une traduction, la prose des réglages est en lecture seule et décrite par le texte d'aide (#748, R126)", async () => {
      let answer!: (proposal: TranslationProposal) => void
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(() => new Promise<TranslationProposal>((resolve) => { answer = resolve })),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()

      for (const id of SETTINGS_PROSE_IDS) {
        const field = wrapper.get(`#${id}`)
        expect(field.attributes('readonly'), `#${id} : readonly`).toBeDefined()
        expect(field.attributes('disabled'), `#${id} : disabled`).toBeUndefined()
        expect(describedTexts(wrapper, `#${id}`), `#${id} : aria-describedby`).toEqual([PROSE_READONLY_FR])
      }
      // Dans l'autre sens : aucun autre champ du formulaire n'est en lecture seule, ni ne manque à la liste de la page.
      const readonlyIds = panelOf(wrapper, SETTINGS_PANEL).findAll('[readonly]').map((field) => field.attributes('id'))
      expect([...readonlyIds].sort()).toEqual([...SETTINGS_PROSE_IDS].sort())
      await expectNoAccessibilityViolation(wrapper)

      answer({ sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED_SETTINGS })
      await flushPromises()

      for (const id of SETTINGS_PROSE_IDS) {
        expect(wrapper.get(`#${id}`).attributes('readonly'), `#${id} : readonly après la réponse`).toBeUndefined()
      }
      expect(wrapper.text()).not.toContain(PROSE_READONLY_FR)
    })

    // Un seul préfixe d'identifiants pour les réglages, celui de leurs textes d'aide (`admin-about-settings-…`) : les cartes
    // gardent `admin-about-site-card-…` et `admin-about-me-card-…`, avec lesquels un `admin-about-site-eyebrow` se confondrait.
    it("réglages : chaque identifiant du panneau porte le même préfixe, celui des textes d'aide (#748, R157)", async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(() => new Promise<TranslationProposal>(() => {})),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)
      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()

      const ids = panelOf(wrapper, SETTINGS_PANEL).findAll('[id]').map((element) => element.attributes('id') as string)

      expect(ids.length).toBeGreaterThan(SETTINGS_PROSE_IDS.length)
      expect(ids.filter((id) => !id.startsWith('admin-about-settings-'))).toEqual([])
    })

    it("une traduction des réglages en échec : prose modifiable et intacte, l'erreur survit à la frappe (#748, R126)", async () => {
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
      })
      const { wrapper } = await mountPage(createSettingsRepository(), undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()

      for (const id of SETTINGS_PROSE_IDS) {
        expect(wrapper.get(`#${id}`).attributes('readonly'), `#${id} : readonly après l'échec`).toBeUndefined()
      }
      expect(wrapper.text()).not.toContain(PROSE_READONLY_FR)
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)

      await wrapper.get('#admin-about-settings-site-eyebrow').setValue('Corrigé après l\'échec')
      await flushPromises()

      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="alert"]').text()).toBe(TRANSLATION_UNAVAILABLE_FR)
    })

    it('enregistre les réglages via son formulaire', async () => {
      const settingsRepository = createSettingsRepository()
      const { wrapper } = await mountPage(settingsRepository)

      await wrapper.get('#admin-about-settings-site-eyebrow').setValue('Nouveau titre')
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

    it("la réponse d'un enregistrement parti dans la langue quittée ne remplace pas les réglages affichés (#748, R11)", async () => {
      // Le scénario complet, à promesses maîtrisées : un enregistrement FR est en vol, la personne passe en EN,
      // la lecture EN aboutit, puis seulement la réponse FR arrive.
      let answerFrenchSave!: (settings: AdminAboutSettings) => void
      const frenchSave = new Promise<AdminAboutSettings>((resolve) => {
        answerFrenchSave = resolve
      })
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn().mockReturnValueOnce(frenchSave).mockResolvedValue(SETTINGS_EN),
      })
      const { wrapper } = await mountPage(settingsRepository)

      await wrapper.get('#admin-about-settings-site-eyebrow').setValue('Nouveau titre')
      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)

      answerFrenchSave({ ...SETTINGS, siteEyebrow: 'Nouveau titre' })
      await flushPromises()

      // Le formulaire reste celui de l'anglais : la réponse française n'a rien à y poser.
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)
      expect(valueOf(wrapper, '#admin-about-settings-me-eyebrow')).toBe(TRANSLATED_SETTINGS.meEyebrow)

      // Et un « Enregistrer » ensuite n'écrit que l'anglais, jamais le français sous l'en-tête anglais.
      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(settingsRepository.update).toHaveBeenCalledTimes(2)
      expect(settingsRepository.update).toHaveBeenLastCalledWith('en', TRANSLATED_SETTINGS)
    })

    describe('session finie pendant la lecture des réglages de la locale cible (#748, R4)', () => {
      // Une page laissée montée relirait sa lecture interrompue au retour suivant, et rouvrirait la session finie
      // pour le test d'après : on la démonte avant de rendre la session.
      let mountedPage: VueWrapper | undefined
      afterEach(async () => {
        mountedPage?.unmount()
        mountedPage = undefined
        await signInAgain()
      })

      /** Les champs et boutons du panneau des réglages, d'un coup d'œil. */
      function settingsPanelState(wrapper: VueWrapper): { values: string[]; saveButton: boolean; translateButton: boolean; text: string } {
        const panel = panelOf(wrapper, SETTINGS_PANEL)

        return {
          values: panel.findAll('input').map((input) => (input.element as HTMLInputElement).value),
          saveButton: panel.findAll('button').some((button) => FORM_TEXTS.save === button.text()),
          translateButton: panel.findAll('button').some((button) => button.text().includes(PROPOSE_VERSION_PREFIX_FR)),
          text: panel.text(),
        }
      }

      it("session finie au passage en EN : ni les champs FR ni « Enregistrer », la mention « non chargé »", async () => {
        const session = { ended: false }
        const settingsRepository = createLocaleAwareSettingsRepository(session)
        const { wrapper } = await mountPage(settingsRepository)
        mountedPage = wrapper
        expect(settingsPanelState(wrapper).values).toContain(SETTINGS.siteEyebrow)

        session.ended = true
        await wrapper.get('#admin-about-locale').setValue('en')
        await flushPromises()

        const state = settingsPanelState(wrapper)
        // Jamais le français sous l'en-tête anglais : un « Enregistrer » ici écraserait les réglages EN.
        expect(state.values).toEqual([])
        expect(wrapper.find('#admin-about-settings-site-eyebrow').exists()).toBe(false)
        expect(state.saveButton).toBe(false)
        expect(state.translateButton).toBe(false)
        expect(state.text).toContain(fr.admin.notLoaded)
        expect(settingsRepository.update).not.toHaveBeenCalled()
      })

      it('au retour de la session, les réglages EN se lisent et remplissent le formulaire', async () => {
        await signInAgain() // Le compte affiché quand la session finit : sans lui, son retour n'est pas « le même compte » (R26, CR3-5).
        const session = { ended: false }
        const settingsRepository = createLocaleAwareSettingsRepository(session)
        const { wrapper } = await mountPage(settingsRepository)
        mountedPage = wrapper
        session.ended = true
        await wrapper.get('#admin-about-locale').setValue('en')
        await flushPromises()
        expect(settingsPanelState(wrapper).saveButton).toBe(false)

        session.ended = false
        await signInAgain()

        expect(settingsRepository.get).toHaveBeenLastCalledWith('en')
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)
        expect(settingsPanelState(wrapper).text).not.toContain(fr.admin.notLoaded)

        // « Enregistrer » ne touche que l'EN, avec ses propres valeurs.
        await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
        await flushPromises()
        expect(settingsRepository.update).toHaveBeenCalledOnce()
        expect(settingsRepository.update).toHaveBeenCalledWith('en', TRANSLATED_SETTINGS)
      })

      it('une proposition en attente ne se pose pas sur un formulaire non chargé, elle se pose au retour', async () => {
        await signInAgain() // Le compte affiché quand la session finit : sans lui, son retour n'est pas « le même compte » (R26, CR3-5).
        const session = { ended: false }
        const settingsRepository = createLocaleAwareSettingsRepository(session)
        const translation = createTranslationRepository({
          siteEyebrow: 'Proposed site',
          meEyebrow: 'Proposed me',
          technicalSubtitle: 'Proposed technical',
          personalSubtitle: 'Proposed personal',
          hobbiesSubtitle: 'Proposed hobbies',
        })
        const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
        mountedPage = wrapper
        session.ended = true

        await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
        await flushPromises()

        // La page est passée en EN, la lecture EN est en session finie : aucun formulaire, donc aucune proposition posée.
        expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
        let state = settingsPanelState(wrapper)
        expect(state.values).toEqual([])
        expect(state.text).toContain(fr.admin.notLoaded)
        expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)

        session.ended = false
        await signInAgain()

        // Au retour : la lecture EN aboutit, et la proposition se pose par-dessus, comme au premier passage.
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe('Proposed site')
        expect(valueOf(wrapper, '#admin-about-settings-hobbies-subtitle')).toBe('Proposed hobbies')
        expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))
        state = settingsPanelState(wrapper)
        expect(state.saveButton).toBe(true)
        expect(settingsRepository.update).not.toHaveBeenCalled()
      })
    })

    it('assistant : bascule la page en EN et applique la proposition APRÈS le rechargement des réglages', async () => {
      const settingsRepository = createSettingsRepository()
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
      vi.mocked(settingsRepository.get).mockClear()

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
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
      // pas écraser la proposition : celle-ci est appliquée une fois la locale chargée.
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe('About this site')
      expect(valueOf(wrapper, '#admin-about-settings-hobbies-subtitle')).toBe('Outside work')
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))
      expect(settingsRepository.update).not.toHaveBeenCalled()

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('en', TRANSLATED_SETTINGS)
    })

    it('un enregistrement refusé garde la mention de la proposition de traduction (#748, R20)', async () => {
      const settingsRepository = createSettingsRepository({
        update: vi.fn(async () => Promise.reject(new AdminAboutError('validation', 'Invalide'))),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      // Refusé : le formulaire porte toujours la proposition, la mention qui le dit reste. Réussi, elle part.
      expect(settingsRepository.update).toHaveBeenCalledOnce()
      expect(panelOf(wrapper, SETTINGS_PANEL).findAll('[role="alert"]').length).toBeGreaterThan(0)
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))
    })

    it("un enregistrement réussi retire la mention de la proposition de traduction", async () => {
      const settingsRepository = createSettingsRepository({ update: vi.fn(async () => SETTINGS_EN) })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(true)

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    // Changer la langue de la page relit le formulaire d'une autre langue : la traduction en vol n'a plus de formulaire
    // à qui parler, sa réponse ne ramène ni la langue ni la prose proposée (#748, R114b).
    it('changer la langue de la page pendant une traduction : la réponse qui arrive ne remplit rien (#748, R114b)', async () => {
      type Proposal = Awaited<ReturnType<AdminTranslationRepository['translate']>>
      let answer!: (proposal: Proposal) => void
      const translation = createTranslationRepository(TRANSLATED_SETTINGS, {
        translate: vi.fn(() => new Promise<Proposal>((resolve) => (answer = resolve))),
      })
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
      })
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()
      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      answer({ sourceLocale: 'fr', targetLocale: 'en', fields: TRANSLATED_SETTINGS })
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    // L'avis dit que le formulaire d'une langue porte une proposition : il se rapporte à la langue **cible** de la
    // proposition (celle que la page a prise pour la recevoir), pas à la langue source nommée dans son texte, et il ne
    // s'affiche que sous elle (R66, décision 4 de la sixième série : il ne reste pas sous l'autre langue).
    it("une proposition en EN, enregistrement réussi du FR : le FR n'affiche aucun avis (#748, R50, R66)", async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn(async () => SETTINGS),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(true)

      // La personne enregistre le français, un autre formulaire : la proposition EN n'a pas été enregistrée, mais
      // l'avis n'est pas celui du français.
      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('fr', expect.anything())
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    // Le retrait de la proposition par le `watch` de `settings` quand ils sont ceux de l'autre langue ne se voit pas dans le
    // DOM : l'avis n'est rendu que sous sa cible, et le retour sur l'EN relit l'EN, qui retire la proposition de toute
    // façon. Le cas dit ce que le DOM montre (les réglages du FR, aucun avis), jamais l'état du composant
    // (R141, St12-9).
    it("une proposition en EN, passage au FR puis enregistrement du FR : aucun avis sous le FR (#748, R141b)", async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn(async () => SETTINGS),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')

      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('fr', expect.anything())
      // Les réglages du FR sont arrivés sous le FR, qui n'est pas la cible : ils retirent la proposition de l'EN (R141b), ce
      // que le DOM ne dit pas. Il montre le formulaire du FR, qui porte les réglages rendus par le serveur, sans avis.
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    it('une proposition en EN, passage en FR : aucun avis sous le FR (#748, R66, décision 4)', async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(true)

      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-locale')).toBe('fr')
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    it("une proposition en EN, passage en FR puis retour en EN : réglages EN relus, avis disparu (#748, R66, décision 4)", async () => {
      // Le serveur rend à l'EN des valeurs qui diffèrent de la proposition : la lecture du retour les pose, et la
      // proposition, non enregistrée, est perdue avec son avis.
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? { ...SETTINGS_EN, siteEyebrow: 'Server EN' } : SETTINGS)),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(TRANSLATED_SETTINGS.siteEyebrow)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(true)

      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()

      expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe('Server EN')
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    // Une proposition en attente (la page est passée à la cible, dont les réglages se lisent) est retirée quand la page va à
    // une autre langue que sa cible : la personne n'attend plus cette traduction, et au chargement suivant de la cible rien
    // n'est posé. Une lecture de la cible qui échoue (une erreur ordinaire, un refus 403) ne la retire pas (R157b) : la page
    // n'a pas de « Réessayer », le changement de langue est le seul chemin vers un nouveau chargement, et c'est lui qui la retire.
    // Une lecture interrompue par une fin de session la garde : voir « une proposition en attente ne se pose pas sur un
    // formulaire non chargé, elle se pose au retour », ci-dessous.
    const SERVER_EN = { ...SETTINGS_EN, siteEyebrow: 'Server EN' }

    it(
      "réglages : une proposition en attente, un retour à la langue source avant la cible, puis la cible : rien n'est posé (#748, R157)",
      async () => {
        // La première lecture EN reste en vol le temps du retour au FR ; les suivantes sont servies.
        let releaseFirstEnglish!: () => void
        const firstEnglishRead = new Promise<void>((resolve) => { releaseFirstEnglish = resolve })
        let englishReads = 0
        const settingsRepository = createSettingsRepository({
          get: vi.fn(async (locale: string) => {
            if ('en' !== locale) return SETTINGS
            englishReads += 1
            if (1 === englishReads) await firstEnglishRead

            return SERVER_EN
          }),
        })
        const translation = createTranslationRepository(TRANSLATED_SETTINGS)
        const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

        await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
        await flushPromises()
        expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')

        await wrapper.get('#admin-about-locale').setValue('fr')
        await flushPromises()
        releaseFirstEnglish()
        await flushPromises()
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SETTINGS.siteEyebrow)

        await wrapper.get('#admin-about-locale').setValue('en')
        await flushPromises()

        expect(englishReads).toBe(2)
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SERVER_EN.siteEyebrow)
        expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
      },
    )

    it.each([
      ['une erreur ordinaire', () => new Error('unavailable')],
      ['un refus 403', () => new AccessForbiddenError()],
    ])(
      "réglages : une proposition en attente dont la lecture de la cible échoue (%s) n'est pas posée au chargement suivant (#748, R157)",
      async (_label, failure) => {
        let englishReads = 0
        const settingsRepository = createSettingsRepository({
          get: vi.fn(async (locale: string) => {
            if ('en' !== locale) return SETTINGS
            englishReads += 1
            if (1 === englishReads) throw failure()

            return SERVER_EN
          }),
        })
        const translation = createTranslationRepository(TRANSLATED_SETTINGS)
        const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

        await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
        await flushPromises()
        // La lecture de la cible a échoué : pas de formulaire, donc rien de posé.
        expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
        expect(panelOf(wrapper, SETTINGS_PANEL).find('form').exists()).toBe(false)

        await wrapper.get('#admin-about-locale').setValue('fr')
        await flushPromises()
        await wrapper.get('#admin-about-locale').setValue('en')
        await flushPromises()

        expect(englishReads).toBe(2)
        expect(valueOf(wrapper, '#admin-about-settings-site-eyebrow')).toBe(SERVER_EN.siteEyebrow)
        expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
      },
    )

    // La relecture de la cible refusée (403) ou interrompue laisse les réglages nuls : le formulaire n'est pas rendu, et
    // avec lui ni l'avis de la proposition, ni son verrou, ni « Abandonner » : tout vit dans le formulaire.
    it("une proposition en EN, retour à l'EN refusé : aucun avis sous un formulaire non chargé (#748, R113)", async () => {
      let englishReads = 0
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => {
          if ('en' !== locale) return SETTINGS
          englishReads += 1
          if (englishReads > 1) throw new AccessForbiddenError()

          return SETTINGS_EN
        }),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(true)

      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()

      expect(englishReads).toBe(2)
      const settingsPanel = panelOf(wrapper, SETTINGS_PANEL)
      expect(settingsPanel.find('form').exists()).toBe(false)
      expect(settingsPanel.text()).toContain(fr.admin.notLoaded)
      expect(settingsPanel.find('[role="status"]').exists()).toBe(false)
      expect(settingsPanel.findAll('button').map((button) => button.text())).not.toContain(fr.admin.about.settings.proposalDiscard)
    })

    it("une proposition en EN, enregistrement EN refusé : l'avis reste, rien n'est relu (#748, R66)", async () => {
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn(async () => Promise.reject(new AdminAboutError('validation', 'Invalide'))),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('en', TRANSLATED_SETTINGS)
      expect(panelOf(wrapper, SETTINGS_PANEL).get('[role="status"]').text()).toContain(proposalNoticeFrom('fr'))
    })

    it("enregistrement réussi de l'EN : l'avis disparaît (#748, R50, étape 2b)", async () => {
      const settingsRepository = createSettingsRepository({ update: vi.fn(async () => SETTINGS_EN) })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await translateEntryButton(wrapper, SETTINGS_FORM).trigger('click')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(true)

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('en', TRANSLATED_SETTINGS)
      expect(panelOf(wrapper, SETTINGS_PANEL).find('[role="status"]').exists()).toBe(false)
    })

    it("FR en vol, passage en EN : « Enregistrer » actif ; retour en FR : désactivé jusqu'à la réponse (#748, R50)", async () => {
      let answerFrenchSave!: (settings: AdminAboutSettings) => void
      const frenchSave = new Promise<AdminAboutSettings>((resolve) => {
        answerFrenchSave = resolve
      })
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn().mockReturnValueOnce(frenchSave).mockResolvedValue(SETTINGS_EN),
      })
      const { wrapper } = await mountPage(settingsRepository)
      const saveButton = () => buttonLabelled(panelOf(wrapper, SETTINGS_PANEL), FORM_TEXTS.save)

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(saveButton().attributes('disabled')).toBeDefined()

      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()
      expect(saveButton().attributes('disabled')).toBeUndefined()

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(settingsRepository.update).toHaveBeenCalledTimes(2)
      expect(settingsRepository.update).toHaveBeenLastCalledWith('en', TRANSLATED_SETTINGS)

      await wrapper.get('#admin-about-locale').setValue('fr')
      await flushPromises()
      expect(saveButton().attributes('disabled')).toBeDefined()
      // Une soumission au clavier ne passe pas par le bouton : le composable refuse lui-même un second FR.
      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(settingsRepository.update).toHaveBeenCalledTimes(2)

      answerFrenchSave(SETTINGS)
      await flushPromises()
      expect(saveButton().attributes('disabled')).toBeUndefined()
    })

    // Décision 3 de la sixième série (CR6-2) : la réponse d'un enregistrement de la langue **cible** est écrite dans les
    // réglages et recopiée par-dessus la proposition que la traduction vient d'y poser. « Proposer la traduction » attend
    // donc cet enregistrement ; celui de la langue montrée la retient déjà (`isSaving`), et un EN n'est pas tenu par un FR.
    it(
      "enregistrement EN en vol, page FR : « Proposer la version EN » désactivé jusqu'à la réponse, « Enregistrer » FR actif (#748, R67)",
      async () => {
        let answerEnglishSave!: (settings: AdminAboutSettings) => void
        const englishSave = new Promise<AdminAboutSettings>((resolve) => {
          answerEnglishSave = resolve
        })
        const settingsRepository = createSettingsRepository({
          get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
          update: vi.fn().mockReturnValueOnce(englishSave).mockResolvedValue(SETTINGS),
        })
        const translation = createTranslationRepository(TRANSLATED_SETTINGS)
        const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
        const saveButton = () => buttonLabelled(panelOf(wrapper, SETTINGS_PANEL), FORM_TEXTS.save)

        await wrapper.get('#admin-about-locale').setValue('en')
        await flushPromises()
        await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
        await flushPromises()
        expect(settingsRepository.update).toHaveBeenCalledWith('en', expect.anything())

        await wrapper.get('#admin-about-locale').setValue('fr')
        await flushPromises()

        expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeDefined()
        expect(saveButton().attributes('disabled')).toBeUndefined()

        answerEnglishSave(SETTINGS_EN)
        await flushPromises()

        expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeUndefined()
      },
    )

    it("un enregistrement FR en vol, la page en EN : « Proposer la version FR » désactivé, aucune requête (#748, R67)", async () => {
      let answerFrenchSave!: (settings: AdminAboutSettings) => void
      const frenchSave = new Promise<AdminAboutSettings>((resolve) => {
        answerFrenchSave = resolve
      })
      const settingsRepository = createSettingsRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn().mockReturnValueOnce(frenchSave).mockResolvedValue(SETTINGS_EN),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(settingsRepository.update).toHaveBeenCalledWith('fr', expect.anything())

      await wrapper.get('#admin-about-locale').setValue('en')
      await flushPromises()
      expect(valueOf(wrapper, '#admin-about-locale')).toBe('en')
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeDefined()

      answerFrenchSave(SETTINGS)
      await flushPromises()
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeUndefined()
    })

    // La langue montrée tient aussi « Proposer la traduction » (R86, CR7-10) : le bouton lit `isAnySaving`, l'enregistrement
    // de la langue montrée comme celui de l'autre.
    it("un enregistrement FR en vol, la page en FR : « Proposer la version EN » désactivé jusqu'à la réponse (#748, R86)", async () => {
      let answerFrenchSave!: (settings: AdminAboutSettings) => void
      const frenchSave = new Promise<AdminAboutSettings>((resolve) => {
        answerFrenchSave = resolve
      })
      const settingsRepository = createSettingsRepository({
        update: vi.fn().mockReturnValueOnce(frenchSave),
      })
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const { wrapper } = await mountPage(settingsRepository, undefined, undefined, translation)
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeUndefined()

      await formOf(wrapper, SETTINGS_FORM).trigger('submit.prevent')
      await flushPromises()
      expect(settingsRepository.update).toHaveBeenCalledWith('fr', expect.anything())
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeDefined()

      answerFrenchSave(SETTINGS)
      await flushPromises()
      expect(translateEntryButton(wrapper, SETTINGS_FORM).attributes('disabled')).toBeUndefined()
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

    // Issue #582 : chaque sélecteur de langue est relié à son texte d'aide,
    // rendu en permanence — une référence statique, jamais pendante.
    it('relie chaque sélecteur de langue à son aide par aria-describedby', async () => {
      const { wrapper } = await mountPage()

      for (const [selectId, helpText] of [
        ['admin-about-locale', 'un enregistrement par langue'],
        ['admin-about-site-card-locale', 'Langue de cette entrée'],
        ['admin-about-me-card-locale', 'Langue de cette entrée'],
      ] as const) {
        const helpId = wrapper.get(`#${selectId}`).attributes('aria-describedby')
        expect(helpId, selectId).toBe(`${selectId}-help`)
        expect(wrapper.get(`#${helpId}`).text()).toContain(helpText)
      }
    })

    it("ne présente aucune violation d'accessibilité, les quatre tableaux groupés rendus", async () => {
      const { wrapper } = await mountPage()

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
