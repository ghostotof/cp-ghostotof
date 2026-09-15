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
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

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

function buttonLabelled(scope: VueWrapper | DOMWrapper<Element>, label: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll('button').find((candidate) => candidate.text() === label)
  if (!button) throw new Error(`Bouton « ${label} » introuvable.`)
  return button as DOMWrapper<HTMLButtonElement>
}

function translateButton(wrapper: VueWrapper): DOMWrapper<Element> {
  const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Proposer la version'))
  if (!button) throw new Error('Bouton de traduction introuvable.')
  return button
}

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
  })

  it('affiche une ligne par groupe, un badge par langue, et la traduction manquante', async () => {
    const { wrapper } = await mountPage()

    expect(rows(wrapper)).toHaveLength(3)
    expect(wrapper.text()).toContain('Un moteur de tarification asynchrone')
    expect(wrapper.text()).toContain('An asynchronous pricing engine')

    const badges = rows(wrapper)[0].findAll('.badge').map((badge) => badge.text())
    expect(badges).toEqual(['FR', 'EN'])

    const missing = rows(wrapper)[1]
    expect(missing.text()).toContain('Traduction manquante')
    expect(buttonLabelled(missing, 'Créer la version EN').exists()).toBe(true)
  })

  it("n'affiche plus aucune colonne ni champ de position", async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('#admin-case-study-position').exists()).toBe(false)
    expect(wrapper.find('thead').text()).not.toContain('Position')
  })

  it('rappelle la règle éditoriale (jamais de nom de client) au-dessus du formulaire', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.text()).toContain('Ni nom de client')
  })

  it("glisse la première ligne sur la troisième puis enregistre l'ordre des groupes", async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await dragRow(wrapper, 0, 2)
    expect(wrapper.text()).toContain('Ordre · modifié, non enregistré')

    vi.mocked(caseStudies.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(caseStudies.reorder).toHaveBeenCalledWith([GROUP_TWO, GROUP_THREE, GROUP_ONE])
    expect(caseStudies.list).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('Ordre · à jour')
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
    expect(wrapper.get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
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
    expect(hint.text()).toBe("Enregistrez ou annulez l'ordre d'abord.")

    const edit = buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), 'Modifier')
    expect(edit.attributes('disabled')).toBeDefined()
    expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
    expect(
      buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), 'Supprimer').attributes('disabled'),
    ).toBeDefined()
    expect(buttonLabelled(wrapper, 'Créer la version EN').attributes('disabled')).toBeDefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeDefined()
    // Un appel au modèle produirait un brouillon que le formulaire verrouillé ne
    // pourrait pas enregistrer : du quota dépensé pour rien (ADR 0004).
    expect(translateButton(wrapper).attributes('disabled')).toBeDefined()

    await buttonLabelled(wrapper, 'Annuler').trigger('click')

    expect(
      buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), 'Modifier').attributes('disabled'),
    ).toBeUndefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
    const caseStudies = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const { wrapper } = await mountPage(caseStudies)

    await dragRow(wrapper, 0, 2)
    vi.mocked(caseStudies.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(caseStudies.list).toHaveBeenCalledOnce()
    const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
    expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('« Créer la version EN » ouvre une création rattachée, tout vide — une étude de cas est prose de bout en bout', async () => {
    const { wrapper } = await mountPage()

    await buttonLabelled(wrapper, 'Créer la version EN').trigger('click')

    expect(wrapper.get('h2').text()).toBe('Ajouter une étude de cas')
    expect(valueOf(wrapper, '#admin-case-study-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe(GROUP_TWO)
    for (const field of ['title', 'problem', 'solution', 'tradeoffs', 'measured-result']) {
      expect(valueOf(wrapper, `#admin-case-study-${field}`)).toBe('')
    }
  })

  it("renvoie toujours le groupe lu lors d'une modification, pour ne pas détacher la traduction", async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(actionsForLine(wrapper, 'Un moteur de tarification asynchrone'), 'Modifier').trigger('click')
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
    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), 'Modifier').trigger('click')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe('')

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(caseStudies.update).toHaveBeenCalledWith(FR_TWO.id, expect.objectContaining({ translationGroup: null }))
  })

  it("n'envoie jamais de position dans le corps d'une écriture", async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(wrapper, 'Créer la version EN').trigger('click')
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

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
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

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), 'Modifier').trigger('click')
    expect(wrapper.get('h2').text()).toBe("Modifier l'étude de cas")

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('h2').text()).toBe('Ajouter une étude de cas')
    expect(valueOf(wrapper, '#admin-case-study-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-case-study-translation-group')).toBe(GROUP_TWO)
    expect(valueOf(wrapper, '#admin-case-study-title')).toBe(TRANSLATED.title)
    expect(valueOf(wrapper, '#admin-case-study-measured-result')).toBe(TRANSLATED.measuredResult)
    expect(caseStudies.create).not.toHaveBeenCalled()
    expect(caseStudies.update).not.toHaveBeenCalled()

    const banner = wrapper.get('form [role="status"]')
    expect(banner.text()).toContain('Brouillon généré par IA')
    expect(banner.text()).toContain('FR')
  })

  it('enregistre le brouillon comme une nouvelle entrée EN rattachée au même groupe', async () => {
    const caseStudies = createStubRepository()
    const { wrapper } = await mountPage(caseStudies)

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
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

    await buttonLabelled(actionsForLine(wrapper, 'PHPStan progressif sur un legacy'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('form [role="alert"]').text()).toContain('Quota horaire')
    expect(wrapper.get('h2').text()).toBe("Modifier l'étude de cas")
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
