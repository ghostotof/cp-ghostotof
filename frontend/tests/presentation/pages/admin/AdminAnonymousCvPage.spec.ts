import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import { RouterView } from 'vue-router'
import AdminAnonymousCvPage from '../../../../src/presentation/pages/admin/AdminAnonymousCvPage.vue'
import { ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY } from '../../../../src/application/admin/anonymousCv/useAdminAnonymousCvSections'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminAnonymousCvSectionRepository } from '../../../../src/domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import type { AdminAnonymousCvSection } from '../../../../src/domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
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
 * (ADR 0003 D5) : ni nom, ni employeur, ni client dans ces fixtures.
 */
const FR_ONE: AdminAnonymousCvSection = {
  id: '019968a0-0000-7000-8000-000000000101', locale: 'fr', translationGroup: GROUP_ONE,
  title: 'Backend PHP / Symfony', skills: 'Symfony 7, Doctrine', yearsOfExperience: 12,
  achievements: 'API multi-tenant.', position: 0,
}
const EN_ONE: AdminAnonymousCvSection = {
  ...FR_ONE, id: '019968a0-0000-7000-8000-000000000102', locale: 'en', title: 'PHP / Symfony backend',
}
const FR_TWO: AdminAnonymousCvSection = {
  id: '019968a0-0000-7000-8000-000000000103', locale: 'fr', translationGroup: GROUP_TWO,
  title: 'Frontend Vue 3', skills: 'Vue 3, TypeScript', yearsOfExperience: 4,
  achievements: 'SPA découplée.', position: 1,
}
const FR_THREE: AdminAnonymousCvSection = {
  id: '019968a0-0000-7000-8000-000000000104', locale: 'fr', translationGroup: GROUP_THREE,
  title: 'Infrastructure Docker', skills: 'Docker, Kubernetes', yearsOfExperience: 6,
  achievements: 'Stack déployée en préprod et prod.', position: 2,
}
const EN_THREE: AdminAnonymousCvSection = {
  ...FR_THREE, id: '019968a0-0000-7000-8000-000000000105', locale: 'en', title: 'Docker infrastructure',
}

const ALL_SECTIONS = [FR_ONE, EN_ONE, FR_TWO, FR_THREE, EN_THREE]

const TRANSLATED = {
  title: 'Vue 3 frontend',
  skills: 'Vue 3, TypeScript',
  achievements: 'Decoupled SPA.',
}

function createStubRepository(
  overrides: Partial<AdminAnonymousCvSectionRepository> = {},
): AdminAnonymousCvSectionRepository {
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
  sections: AdminAnonymousCvSectionRepository = createStubRepository(),
  translation: AdminTranslationRepository = createTranslationRepository(),
  options: { attach?: boolean } = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/anonymous-cv', component: AdminAnonymousCvPage },
      { path: '/admin/ailleurs', component: ELSEWHERE },
    ],
  })
  await router.push('/admin/anonymous-cv')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, {
    attachTo: options.attach ? document.body : undefined,
    global: {
      plugins: [createAppI18n(), router],
      provide: {
        [ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY as symbol]: sections,
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

describe('AdminAnonymousCvPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche une ligne par groupe, un badge par langue, et la traduction manquante', async () => {
    const { wrapper } = await mountPage()

    expect(rows(wrapper)).toHaveLength(3)
    expect(wrapper.text()).toContain('Backend PHP / Symfony')
    expect(wrapper.text()).toContain('PHP / Symfony backend')

    const badges = rows(wrapper)[0].findAll('.badge').map((badge) => badge.text())
    expect(badges).toEqual(['FR', 'EN'])

    const missing = rows(wrapper)[1]
    expect(missing.text()).toContain('Traduction manquante')
    expect(buttonLabelled(missing, 'Créer la version EN').exists()).toBe(true)
  })

  it("n'affiche plus aucune colonne ni champ de position", async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('#admin-anonymous-cv-position').exists()).toBe(false)
    expect(wrapper.find('thead').text()).not.toContain('Position')
  })

  it('rappelle la règle éditoriale (ni nom, ni employeur) au-dessus du formulaire', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.text()).toContain('Ni nom, ni employeur, ni client')
  })

  it("glisse la première ligne sur la troisième puis enregistre l'ordre des groupes", async () => {
    const sections = createStubRepository()
    const { wrapper } = await mountPage(sections)

    await dragRow(wrapper, 0, 2)
    expect(wrapper.text()).toContain('Ordre · modifié, non enregistré')

    vi.mocked(sections.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(sections.reorder).toHaveBeenCalledWith([GROUP_TWO, GROUP_THREE, GROUP_ONE])
    expect(sections.list).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
    const { wrapper } = await mountPage(createStubRepository(), createTranslationRepository(), { attach: true })

    const handle = rows(wrapper)[0].get('button')
    handle.element.focus()
    await handle.trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()

    expect(rows(wrapper)[1].text()).toContain('Backend PHP / Symfony')
    expect(rows(wrapper)[1].get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
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

    const edit = buttonLabelled(actionsForLine(wrapper, 'Backend PHP / Symfony'), 'Modifier')
    expect(edit.attributes('disabled')).toBeDefined()
    expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
    expect(
      buttonLabelled(actionsForLine(wrapper, 'Backend PHP / Symfony'), 'Supprimer').attributes('disabled'),
    ).toBeDefined()
    expect(buttonLabelled(wrapper, 'Créer la version EN').attributes('disabled')).toBeDefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeDefined()
    // Un appel au modèle produirait un brouillon que le formulaire verrouillé ne
    // pourrait pas enregistrer : du quota dépensé pour rien (ADR 0004).
    expect(translateButton(wrapper).attributes('disabled')).toBeDefined()

    await buttonLabelled(wrapper, 'Annuler').trigger('click')

    expect(
      buttonLabelled(actionsForLine(wrapper, 'Backend PHP / Symfony'), 'Modifier').attributes('disabled'),
    ).toBeUndefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
    const sections = createStubRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const { wrapper } = await mountPage(sections)

    await dragRow(wrapper, 0, 2)
    vi.mocked(sections.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(sections.list).toHaveBeenCalledOnce()
    const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
    expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('« Créer la version EN » ouvre une création rattachée, années recopiées, prose vide', async () => {
    const { wrapper } = await mountPage()

    await buttonLabelled(wrapper, 'Créer la version EN').trigger('click')

    expect(wrapper.get('h2').text()).toBe('Ajouter une section')
    expect(valueOf(wrapper, '#admin-anonymous-cv-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-anonymous-cv-translation-group')).toBe(GROUP_TWO)
    expect(valueOf(wrapper, '#admin-anonymous-cv-years')).toBe('4')
    expect(valueOf(wrapper, '#admin-anonymous-cv-title')).toBe('')
    expect(valueOf(wrapper, '#admin-anonymous-cv-skills')).toBe('')
    expect(valueOf(wrapper, '#admin-anonymous-cv-achievements')).toBe('')
  })

  it("renvoie toujours le groupe lu lors d'une modification, pour ne pas détacher la traduction", async () => {
    const sections = createStubRepository()
    const { wrapper } = await mountPage(sections)

    await buttonLabelled(actionsForLine(wrapper, 'Backend PHP / Symfony'), 'Modifier').trigger('click')
    expect(valueOf(wrapper, '#admin-anonymous-cv-translation-group')).toBe(GROUP_ONE)

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(sections.update).toHaveBeenCalledWith(FR_ONE.id, expect.objectContaining({ translationGroup: GROUP_ONE }))
  })

  it('envoie « aucune » pour une entrée solitaire — un non-geste côté serveur', async () => {
    const sections = createStubRepository()
    const { wrapper } = await mountPage(sections)

    // FR_TWO est seule dans son groupe : le sélecteur affiche « aucune » et le
    // formulaire envoie donc `translationGroup: null`. Ce n'est pas un
    // détachement — `ContentPlacement::reattach` traite le `null` en non-geste
    // quand l'entrée n'a pas de sœur (`count($members) === 1`), ce que pince
    // `ContentPlacementTest::testDetachingAnEntryWithoutTranslationsDoesNothing`.
    // Ces deux tests forment le contrat entre les deux moitiés : les casser
    // séparément doit être impossible sans que l'un des deux vire au rouge.
    await buttonLabelled(actionsForLine(wrapper, 'Frontend Vue 3'), 'Modifier').trigger('click')
    expect(valueOf(wrapper, '#admin-anonymous-cv-translation-group')).toBe('')

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(sections.update).toHaveBeenCalledWith(FR_TWO.id, expect.objectContaining({ translationGroup: null }))
  })

  it("n'envoie jamais de position dans le corps d'une écriture", async () => {
    const sections = createStubRepository()
    const { wrapper } = await mountPage(sections)

    await buttonLabelled(wrapper, 'Créer la version EN').trigger('click')
    await wrapper.get('#admin-anonymous-cv-title').setValue('Vue 3 frontend')
    await wrapper.get('#admin-anonymous-cv-skills').setValue('Vue 3, TypeScript')
    await wrapper.get('#admin-anonymous-cv-achievements').setValue('Decoupled SPA.')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    const payload = vi.mocked(sections.create).mock.calls[0][0]
    expect(payload).not.toHaveProperty('position')
    expect(payload.translationGroup).toBe(GROUP_TWO)
    expect(payload.yearsOfExperience).toBe(4)
  })

  it("envoie les seuls champs de prose de l'entrée chargée, jamais les années, vers l'autre locale", async () => {
    const translation = createTranslationRepository()
    const { wrapper } = await mountPage(createStubRepository(), translation)

    await buttonLabelled(actionsForLine(wrapper, 'Frontend Vue 3'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
      title: FR_TWO.title,
      skills: FR_TWO.skills,
      achievements: FR_TWO.achievements,
    })
  })

  it('bascule en création rattachée au groupe de la source, prose remplacée, années conservées, bannière affichée', async () => {
    const sections = createStubRepository()
    const { wrapper } = await mountPage(sections)

    await buttonLabelled(actionsForLine(wrapper, 'Frontend Vue 3'), 'Modifier').trigger('click')
    expect(wrapper.get('h2').text()).toBe('Modifier la section')

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('h2').text()).toBe('Ajouter une section')
    expect(valueOf(wrapper, '#admin-anonymous-cv-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-anonymous-cv-translation-group')).toBe(GROUP_TWO)
    expect(valueOf(wrapper, '#admin-anonymous-cv-title')).toBe(TRANSLATED.title)
    expect(valueOf(wrapper, '#admin-anonymous-cv-years')).toBe('4')
    expect(sections.create).not.toHaveBeenCalled()
    expect(sections.update).not.toHaveBeenCalled()

    const banner = wrapper.get('form [role="status"]')
    expect(banner.text()).toContain('Brouillon généré par IA')
    expect(banner.text()).toContain('FR')
  })

  it('enregistre le brouillon comme une nouvelle entrée EN rattachée au même groupe', async () => {
    const sections = createStubRepository()
    const { wrapper } = await mountPage(sections)

    await buttonLabelled(actionsForLine(wrapper, 'Frontend Vue 3'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(sections.update).not.toHaveBeenCalled()
    expect(sections.create).toHaveBeenCalledWith({
      locale: 'en',
      translationGroup: GROUP_TWO,
      title: TRANSLATED.title,
      skills: TRANSLATED.skills,
      yearsOfExperience: 4,
      achievements: TRANSLATED.achievements,
    })
  })

  it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
    const translation = createTranslationRepository({
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota.') }),
    })
    const { wrapper } = await mountPage(createStubRepository(), translation)

    await buttonLabelled(actionsForLine(wrapper, 'Frontend Vue 3'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('form [role="alert"]').text()).toContain('Quota horaire')
    expect(wrapper.get('h2').text()).toBe('Modifier la section')
    expect(valueOf(wrapper, '#admin-anonymous-cv-locale')).toBe('fr')
    expect(valueOf(wrapper, '#admin-anonymous-cv-title')).toBe(FR_TWO.title)
  })

  it('demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, router } = await mountPage()

    await dragRow(wrapper, 0, 2)
    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/admin/anonymous-cv')
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
