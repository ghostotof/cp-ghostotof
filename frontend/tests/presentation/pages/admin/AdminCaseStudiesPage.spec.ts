import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import { RouterView } from 'vue-router'
import AdminCaseStudiesPage from '../../../../src/presentation/pages/admin/AdminCaseStudiesPage.vue'
import { ADMIN_CASE_STUDY_REPOSITORY } from '../../../../src/application/admin/caseStudies/useAdminCaseStudies'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminCaseStudyRepository } from '../../../../src/domain/admin/caseStudies/repositories/AdminCaseStudyRepository'
import type { AdminCaseStudy } from '../../../../src/domain/admin/caseStudies/entities/AdminCaseStudy'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { markSessionExpired } from '../../../../src/application/auth/useAuth'
import { signInAgain } from '../../../support/signInAgain'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import { describeEntryFormLock, textsDescribing, translateEntryButton } from '../../../support/entryFormLock'
import { ENTRY_LOCK_PROPOSAL_FR } from '../../../support/entryFormLockTexts'
import { CASE_STUDY_PROSE_FIELDS } from '../../../../src/presentation/pages/admin/proseFields'
import { buttonLabelled } from '../../../support/buttonLabelled'
import { createVersionLabel, ENTRY_FORM_TEXTS, ENTRY_TITLES, ORDER_TEXTS, orderMovedLabel } from '../../../support/adminButtonTexts'
import { proposalNoticeFrom, TRANSLATION_RATE_LIMITED_FR } from '../../../support/adminTranslationTexts'
import fr from '../../../../src/infrastructure/i18n/locales/fr.json'
import { HttpAdminCaseStudyRepository } from '../../../../src/infrastructure/admin/caseStudies/HttpAdminCaseStudyRepository'
import { BACKOFFICE_TEST_API_URL, stubBackofficeJson } from '../../../support/backofficeFetch'

const GROUP_ONE = '019968b0-0000-7000-8000-000000000011'
const GROUP_TWO = '019968b0-0000-7000-8000-000000000012'
const GROUP_THREE = '019968b0-0000-7000-8000-000000000013'

/**
 * Trois groupes, dont un (GROUP_TWO) sans version EN : c'est lui qui porte
 * « Traduction manquante » et « Créer la version EN », et c'est sur lui que
 * l'assistant de traduction est exercé — un groupe déjà complet ne peut pas
 * recevoir une seconde entrée de la même langue. Contenu du palier de base
 * (ADR 0003 D5) : aucun nom de client dans ces fixtures.
 */
const FR_ONE: AdminCaseStudy = {
  id: '019968a0-0000-7000-8000-000000000101', locale: 'fr', translationGroup: GROUP_ONE,
  title: 'Un moteur de tarification asynchrone', problem: 'Calculs bloquants.', solution: 'Messenger.',
  tradeoffs: 'Cohérence différée.', measuredResult: 'Temps de réponse divisé par dix.', position: 0,
}
const EN_ONE: AdminCaseStudy = {
  ...FR_ONE, id: '019968a0-0000-7000-8000-000000000102', locale: 'en', title: 'An asynchronous pricing engine',
}
const FR_TWO: AdminCaseStudy = {
  id: '019968a0-0000-7000-8000-000000000103', locale: 'fr', translationGroup: GROUP_TWO,
  title: 'PHPStan progressif sur un legacy', problem: 'Aucune analyse statique.', solution: 'Baseline puis niveaux.',
  tradeoffs: 'Dette rendue visible.', measuredResult: 'Niveau max atteint en six mois.', position: 1,
}
const FR_THREE: AdminCaseStudy = {
  id: '019968a0-0000-7000-8000-000000000104', locale: 'fr', translationGroup: GROUP_THREE,
  title: 'Une API pour un parc de machines connectées', problem: 'Protocole propriétaire.', solution: 'Passerelle HTTP.',
  tradeoffs: 'Latence acceptée.', measuredResult: 'Mille machines suivies.', position: 2,
}
const EN_THREE: AdminCaseStudy = {
  ...FR_THREE, id: '019968a0-0000-7000-8000-000000000105', locale: 'en', title: 'An API for a fleet of connected machines',
}

const ALL_SECTIONS = [FR_ONE, EN_ONE, FR_TWO, FR_THREE, EN_THREE]

const TRANSLATED = {
  title: 'Progressive PHPStan on a legacy codebase',
  problem: 'No static analysis.',
  solution: 'Baseline, then levels.',
  tradeoffs: 'Debt made visible.',
  measuredResult: 'Max level reached in six months.',
}

function createStubRepository(
  overrides: Partial<AdminCaseStudyRepository> = {},
): AdminCaseStudyRepository {
  return {
    list: vi.fn(async () => ALL_SECTIONS),
    create: vi.fn(async () => FR_TWO),
    update: vi.fn(async () => FR_TWO),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createTranslationRepository(overrides: Partial<AdminTranslationRepository> = {}): AdminTranslationRepository {
  return {
    translate: vi.fn(async () => ({ sourceLocale: 'fr' as const, targetLocale: 'en' as const, fields: TRANSLATED })),
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
  caseStudies: AdminCaseStudyRepository = createStubRepository(),
  translation: AdminTranslationRepository = createTranslationRepository(),
  options: { attach?: boolean } = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/case-studies', component: AdminCaseStudiesPage },
      { path: '/admin/ailleurs', component: ELSEWHERE },
    ],
  })
  await router.push('/admin/case-studies')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, {
    attachTo: options.attach ? document.body : undefined,
    global: {
      plugins: [createAppI18n(), router],
      provide: {
        [ADMIN_CASE_STUDY_REPOSITORY as symbol]: caseStudies,
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translation,
      },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

function valueOf(wrapper: VueWrapper, id: string): string {
  return (wrapper.get(id).element as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value
}

function rows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr')
}

/** Les lignes internes du tableau : une par langue de chaque groupe. */
function localeLines(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr td:nth-child(2) > div')
}

/**
 * Les boutons d'une langue vivent dans la dernière cellule (colonne « Actions »),
 * à la même position que sa ligne dans la cellule de contenu : on retrouve la
 * ligne par son texte, puis les actions par son index.
 */
function actionsForLine(wrapper: VueWrapper, text: string): DOMWrapper<Element> {
  const index = localeLines(wrapper).findIndex((candidate) => candidate.text().includes(text))
  if (index < 0) throw new Error(`Ligne introuvable pour « ${text} ».`)
  return wrapper.findAll('tbody tr td:last-child > div')[index]
}

// Les libellés des boutons viennent du catalogue par `tests/support/adminButtonTexts.ts`, écrits une fois ; le « Modifier »
// de la ligne, propre à la section, se lit ici : retoucher un libellé ne fait pas rougir le fichier.
const FORM_TEXTS = ENTRY_FORM_TEXTS.caseStudies
const TITLES = ENTRY_TITLES.caseStudies
const CREATE_EN_LABEL: string = createVersionLabel('en')
const EDIT_FR: string = fr.admin.caseStudies.editAction

/** Glisse la première ligne sur la troisième, comme le ferait la souris. */
async function dragRow(wrapper: VueWrapper, from: number, to: number): Promise<void> {
  const tableRows = rows(wrapper)
  await tableRows[from].trigger('dragstart')
  await tableRows[to].trigger('dragover')
  await tableRows[to].trigger('drop')
}

describe('AdminCaseStudiesPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  // Le dépôt HTTP réel juge la langue de la réponse à la frontière : la page montre son alerte de chargement et l'entrée
  // dans une langue que le site ne porte pas n'atteint ni le tableau ni un formulaire (#748, R229, Sp18-3).
  it("une liste répondue avec une entrée en langue 'de' montre l'alerte de chargement, sans l'entrée (#748, R229)", async () => {
    const unsupportedEntry = { ...FR_ONE, locale: 'de' }
    stubBackofficeJson([unsupportedEntry])

    const { wrapper } = await mountPage(new HttpAdminCaseStudyRepository(BACKOFFICE_TEST_API_URL))

    expect(wrapper.get('[role="alert"]').text()).toBe(fr.admin.caseStudies.loadError)
    expect(wrapper.text()).not.toContain(unsupportedEntry.title)
  })

  it('affiche une ligne par groupe, un badge par langue, et la traduction manquante', async () => {
    const { wrapper } = await mountPage()

    expect(rows(wrapper)).toHaveLength(3)
    expect(wrapper.text()).toContain('Un moteur de tarification asynchrone')
    expect(wrapper.text()).toContain('An asynchronous pricing engine')

    const badges = rows(wrapper)[0].findAll('.badge').map((badge) => badge.text())
    expect(badges).toEqual(['FR', 'EN'])

    const missing = rows(wrapper)[1]
    expect(missing.text()).toContain(ORDER_TEXTS.missingTranslation)
    expect(buttonLabelled(missing, CREATE_EN_LABEL).exists()).toBe(true)
  })

  it("n'affiche plus aucune colonne ni champ de position", async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('#admin-case-study-position').exists()).toBe(false)
    expect(wrapper.find('thead').text()).not.toContain('Position')
  })

  it('rappelle la règle éditoriale (jamais de nom de client) au-dessus du formulaire', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.text()).toContain(fr.admin.caseStudies.identityHelp)
  })

  it("glisse la première ligne sur la troisième puis enregistre l'ordre des groupes", async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await dragRow(wrapper, 0, 2)
    expect(wrapper.text()).toContain(ORDER_TEXTS.statusDirty)

    vi.mocked(caseStudies.list).mockClear()
    await buttonLabelled(wrapper, ORDER_TEXTS.save).trigger('click')
    await flushPromises()

    expect(caseStudies.reorder).toHaveBeenCalledWith([GROUP_TWO, GROUP_THREE, GROUP_ONE])
    expect(caseStudies.list).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain(ORDER_TEXTS.statusClean)
  })

  it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
    const { wrapper } = await mountPage(createStubRepository(), createTranslationRepository(), { attach: true })

    const handle = rows(wrapper)[0].get('button')
    handle.element.focus()
    await handle.trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()

    expect(rows(wrapper)[1].text()).toContain('Un moteur de tarification asynchrone')
    // Issue #170 F3 : l'annonce vit dans la barre du tableau, pas dans la ligne déplacée.
    expect(rows(wrapper)[1].find('[role="status"]').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toBe(orderMovedLabel(2, 3))
    expect(document.activeElement).toBe(rows(wrapper)[1].get('button').element)

    wrapper.unmount()
  })

  it("verrouille toutes les mutations tant que l'ordre est modifié, et les libère sur Annuler", async () => {
    const { wrapper } = await mountPage()

    await dragRow(wrapper, 0, 2)

    // L'aide est rendue visible, jamais portée par un `title` : Bootstrap pose
    // `pointer-events: none` sur `.btn:disabled`, donc l'infobulle d'un bouton
    // désactivé ne s'affiche jamais. Les boutons la désignent par aria-describedby.
    const hint = wrapper.get('#admin-order-locked-hint')
    expect(hint.text()).toBe(ORDER_TEXTS.lockedHint)

    const edit = buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), EDIT_FR)
    expect(edit.attributes('disabled')).toBeDefined()
    expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
    expect(
      buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), FORM_TEXTS.delete).attributes('disabled'),
    ).toBeDefined()
    expect(buttonLabelled(wrapper, CREATE_EN_LABEL).attributes('disabled')).toBeDefined()
    expect(buttonLabelled(wrapper, FORM_TEXTS.save).attributes('disabled')).toBeDefined()
    // Un appel au modèle produirait une proposition que le formulaire verrouillé ne
    // pourrait pas enregistrer : du quota dépensé pour rien (ADR 0004).
    expect(translateEntryButton(wrapper, 0).attributes('disabled')).toBeDefined()

    await buttonLabelled(wrapper, ORDER_TEXTS.cancel).trigger('click')

    expect(
      buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), EDIT_FR).attributes('disabled'),
    ).toBeUndefined()
    expect(buttonLabelled(wrapper, FORM_TEXTS.save).attributes('disabled')).toBeUndefined()
    expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
    expect(wrapper.text()).toContain(ORDER_TEXTS.statusClean)
  })

  // (#748, R93) : le bouton de traduction porte deux verrous à la fois et les décrit tous les deux, celui de l'ordre d'abord.
  it("un ordre modifié et une proposition affichée : le bouton de traduction est décrit par les deux textes", async () => {
    const { wrapper } = await mountPage()

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    await translateEntryButton(wrapper, 0).trigger('click')
    await flushPromises()
    await dragRow(wrapper, 0, 2)

    expect(textsDescribing(wrapper, translateEntryButton(wrapper, 0))).toEqual([
      ORDER_TEXTS.lockedHint,
      ENTRY_LOCK_PROPOSAL_FR,
    ])
  })

  it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
    const caseStudies = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const { wrapper } = await mountPage(caseStudies)

    await dragRow(wrapper, 0, 2)
    vi.mocked(caseStudies.list).mockClear()
    await buttonLabelled(wrapper, ORDER_TEXTS.save).trigger('click')
    await flushPromises()

    expect(caseStudies.list).toHaveBeenCalledOnce()
    const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
    expect(alerts.some((text) => text.includes(ORDER_TEXTS.staleOrder))).toBe(true)
    expect(wrapper.text()).toContain(ORDER_TEXTS.statusClean)
  })

  it('« Créer la version EN » ouvre une création rattachée, tout vide — une étude de cas est prose de bout en bout', async () => {
    const { wrapper } = await mountPage()

    await buttonLabelled(wrapper, CREATE_EN_LABEL).trigger('click')

    expect(wrapper.get('h2').text()).toBe(TITLES.create)
    expect(valueOf(wrapper, '#admin-case-study-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe(GROUP_TWO)
    for (const field of ['title', 'problem', 'solution', 'tradeoffs', 'measured-result']) {
      expect(valueOf(wrapper, `#admin-case-study-${field}`)).toBe('')
    }
  })

  describe('liste non chargée (#748, R4)', () => {
    // Une page laissée montée relirait sa lecture interrompue au retour suivant : démontée avant de rendre la session.
    let mountedPage: VueWrapper | undefined
    afterEach(async () => {
      mountedPage?.unmount()
      mountedPage = undefined
      await signInAgain()
    })

    /** Le dépôt de la page, dont la lecture tombe en session finie (la réaction du client marque la session) tant que `ended`. */
    function repositoryEndingSession(session: { ended: boolean }): AdminCaseStudyRepository {
      return createStubRepository({
        list: vi.fn(async () => {
          if (session.ended) {
            markSessionExpired()
            throw new SessionEndedError()
          }

          return ALL_SECTIONS
        }),
      })
    }

    it('première lecture en session finie : ni « aucune étude de cas » ni tableau, la mention « non chargé »', async () => {
      const { wrapper } = await mountPage(repositoryEndingSession({ ended: true }))
      mountedPage = wrapper

      expect(wrapper.text()).toContain(fr.admin.notLoaded)
      expect(wrapper.text()).not.toContain(fr.admin.caseStudies.empty)
      expect(wrapper.find('table').exists()).toBe(false)
      // Le bandeau de session finie est la seule alerte : la mention n'en est pas une.
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    })

    it("au retour de la session, la liste se lit et s'affiche", async () => {
      await signInAgain() // Le compte affiché quand la session finit : sans lui, son retour n'est pas « le même compte » (R26, CR3-5).
      const session = { ended: true }
      const caseStudies = repositoryEndingSession(session)
      const { wrapper } = await mountPage(caseStudies)
      mountedPage = wrapper
      expect(rows(wrapper)).toHaveLength(0)

      session.ended = false
      await signInAgain()

      expect(caseStudies.list).toHaveBeenCalledTimes(2)
      expect(wrapper.text()).not.toContain(fr.admin.notLoaded)
      expect(rows(wrapper)).toHaveLength(3)
    })

    it('une liste réellement vide dit toujours « aucune étude de cas »', async () => {
      const { wrapper } = await mountPage(createStubRepository({ list: vi.fn(async () => []) }))

      expect(wrapper.text()).toContain(fr.admin.caseStudies.empty)
      expect(wrapper.text()).not.toContain(fr.admin.notLoaded)
    })
  })

  it("une erreur « session finie » à l'enregistrement n'affiche aucune alerte de la page", async () => {
    // Le bandeau de session finie (AdminLayout) est alors la seule annonce : la page ne double ni
    // d'une alerte, ni d'une clé de traduction brute (#748, constat 7).
    const caseStudies = createStubRepository({
      create: vi.fn(async () => Promise.reject(new SessionEndedError())),
    })
    const { wrapper } = await mountPage(caseStudies)

    await wrapper.get('#admin-case-study-title').setValue(TRANSLATED.title)
    await wrapper.get('#admin-case-study-problem').setValue(TRANSLATED.problem)
    await wrapper.get('#admin-case-study-solution').setValue(TRANSLATED.solution)
    await wrapper.get('#admin-case-study-tradeoffs').setValue(TRANSLATED.tradeoffs)
    await wrapper.get('#admin-case-study-measured-result').setValue(TRANSLATED.measuredResult)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(caseStudies.create).toHaveBeenCalledOnce()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('admin.caseStudies.errors.session-ended')
    // La saisie reste : l'échec a bien été vu comme tel, le formulaire n'est pas remis à zéro.
    expect(valueOf(wrapper, '#admin-case-study-title')).toBe(TRANSLATED.title)
  })

  it("renvoie toujours le groupe lu lors d'une modification, pour ne pas détacher la traduction", async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), EDIT_FR).trigger('click')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe(GROUP_ONE)

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(caseStudies.update).toHaveBeenCalledWith(FR_ONE.id, expect.objectContaining({ translationGroup: GROUP_ONE }))
  })

  it('envoie « aucune » pour une entrée solitaire — un non-geste côté serveur', async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    // FR_TWO est seule dans son groupe : le sélecteur affiche « aucune » et le
    // formulaire envoie donc `translationGroup: null`. Ce n'est pas un
    // détachement — `ContentPlacement::detach` traite une entrée seule en non-geste
    // quand l'entrée n'a pas de sœur (`count($members) === 1`), ce que pince
    // `ContentPlacementTest::testDetachingAnEntryWithoutTranslationsDoesNothing`.
    // Ces deux tests forment le contrat entre les deux moitiés : les casser
    // séparément doit être impossible sans que l'un des deux vire au rouge.
    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe('')

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(caseStudies.update).toHaveBeenCalledWith(FR_TWO.id, expect.objectContaining({ translationGroup: null }))
  })

  it("n'envoie jamais de position dans le corps d'une écriture", async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(wrapper, CREATE_EN_LABEL).trigger('click')
    await wrapper.get('#admin-case-study-title').setValue(TRANSLATED.title)
    await wrapper.get('#admin-case-study-problem').setValue(TRANSLATED.problem)
    await wrapper.get('#admin-case-study-solution').setValue(TRANSLATED.solution)
    await wrapper.get('#admin-case-study-tradeoffs').setValue(TRANSLATED.tradeoffs)
    await wrapper.get('#admin-case-study-measured-result').setValue(TRANSLATED.measuredResult)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    const payload = vi.mocked(caseStudies.create).mock.calls[0][0]
    expect(payload).not.toHaveProperty('position')
    expect(payload.translationGroup).toBe(GROUP_TWO)
  })

  it("envoie les cinq champs de l'entrée chargée vers l'autre locale — tout est prose", async () => {
    const translation = createTranslationRepository()
    const { wrapper } = await mountPage(createStubRepository(), translation)

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    await translateEntryButton(wrapper, 0).trigger('click')
    await flushPromises()

    expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
      title: FR_TWO.title,
      problem: FR_TWO.problem,
      solution: FR_TWO.solution,
      tradeoffs: FR_TWO.tradeoffs,
      measuredResult: FR_TWO.measuredResult,
    })
  })

  it('bascule en création rattachée au groupe de la source, prose remplacée, bannière affichée', async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    expect(wrapper.get('h2').text()).toBe(TITLES.edit)

    await translateEntryButton(wrapper, 0).trigger('click')
    await flushPromises()

    expect(wrapper.get('h2').text()).toBe(TITLES.create)
    expect(valueOf(wrapper, '#admin-case-study-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe(GROUP_TWO)
    expect(valueOf(wrapper, '#admin-case-study-title')).toBe(TRANSLATED.title)
    expect(valueOf(wrapper, '#admin-case-study-measured-result')).toBe(TRANSLATED.measuredResult)
    expect(caseStudies.create).not.toHaveBeenCalled()
    expect(caseStudies.update).not.toHaveBeenCalled()

    const banner = wrapper.get('form [role="status"]')
    expect(banner.text()).toContain(proposalNoticeFrom('fr'))
  })

  // Le dépôt du formulaire en cours de test, recréé par chaque `mount` : `holdNextSave` l'arme.
  let repositoryUnderLock = createStubRepository()

  // Les cas du verrou (langue désactivée, « Annuler », texte du verrou…) : écrits une fois, pour tous les
  // formulaires par entrée, dans `tests/support/entryFormLock.ts` (#748, R91).
  describeEntryFormLock({
    name: '',
    createTranslation: () => createTranslationRepository(),
    mount: async (translation) => {
      repositoryUnderLock = createStubRepository()
      return (await mountPage(repositoryUnderLock, translation)).wrapper
    },
    form: 0,
    ids: {
      locale: 'admin-case-study-locale',
      group: 'admin-case-study-translation-group',
      title: 'admin-case-study-title',
      hint: 'admin-case-study-entry-lock-help',
    },
    proseFields: CASE_STUDY_PROSE_FIELDS,
    heading: TITLES.create,
    startEdit: async (wrapper) => {
      await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    },
    deleteEditedEntry: async (wrapper) => {
      await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), FORM_TEXTS.delete).trigger('click')
    },
    startEditOther: async (wrapper) => {
      await buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), EDIT_FR).trigger('click')
    },
    // Le membre de l'autre langue de l'entrée que `startEditOther` ouvre.
    deleteSiblingOfOther: async (wrapper) => {
      await buttonLabelled(actionsForLine(wrapper, 'An asynchronous pricing engine'), FORM_TEXTS.delete).trigger('click')
    },
    startCreateVersion: (wrapper) => buttonLabelled(wrapper, CREATE_EN_LABEL).trigger('click'),
    propose: async (wrapper) => {
      await translateEntryButton(wrapper, 0).trigger('click')
      await flushPromises()
    },
    holdNextSave: () => {
      vi.mocked(repositoryUnderLock.update).mockReturnValueOnce(new Promise<never>(() => {}))
    },
  })

  it('enregistre la proposition comme une nouvelle entrée EN rattachée au même groupe', async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    await translateEntryButton(wrapper, 0).trigger('click')
    await flushPromises()

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(caseStudies.update).not.toHaveBeenCalled()
    expect(caseStudies.create).toHaveBeenCalledWith({
      locale: 'en',
      translationGroup: GROUP_TWO,
      ...TRANSLATED,
    })
  })

  it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
    const translation = createTranslationRepository({
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota.') }),
    })
    const { wrapper } = await mountPage(createStubRepository(), translation)

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), EDIT_FR).trigger('click')
    await translateEntryButton(wrapper, 0).trigger('click')
    await flushPromises()

    expect(wrapper.get('form [role="alert"]').text()).toContain(TRANSLATION_RATE_LIMITED_FR)
    expect(wrapper.get('h2').text()).toBe(TITLES.edit)
    expect(valueOf(wrapper, '#admin-case-study-locale')).toBe('fr')
    expect(valueOf(wrapper, '#admin-case-study-title')).toBe(FR_TWO.title)
  })

  it('demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, router } = await mountPage()

    await dragRow(wrapper, 0, 2)
    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/admin/case-studies')
  })

  it("quitte la route sans rien demander quand l'ordre est à jour", async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router } = await mountPage()

    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/admin/ailleurs')
  })

  it("ne présente aucune violation d'accessibilité, tableau groupé, poignée et barre d'ordre rendus", async () => {
    const { wrapper } = await mountPage()

    await expectNoAccessibilityViolation(wrapper)
  })
})
