import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import AdminQualityPage from '../../../../src/presentation/pages/admin/AdminQualityPage.vue'
import { ADMIN_QUALITY_PRINCIPLE_REPOSITORY } from '../../../../src/application/admin/quality/useAdminQualityPrinciples'
import { ADMIN_QUALITY_TRAIT_REPOSITORY } from '../../../../src/application/admin/quality/useAdminQualityTraits'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminQualityPrincipleRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import type { AdminQualityTraitRepository } from '../../../../src/domain/admin/quality/repositories/AdminQualityTraitRepository'
import type { AdminQualityPrinciple } from '../../../../src/domain/admin/quality/entities/AdminQualityPrinciple'
import type { AdminQualityTrait } from '../../../../src/domain/admin/quality/entities/AdminQualityTrait'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const P_GROUP_ONE = '019968b0-0000-7000-8000-0000000000e1'
const P_GROUP_TWO = '019968b0-0000-7000-8000-0000000000e2'
const P_GROUP_THREE = '019968b0-0000-7000-8000-0000000000e3'
const T_GROUP_ONE = '019968b0-0000-7000-8000-0000000000f1'
const T_GROUP_TWO = '019968b0-0000-7000-8000-0000000000f2'
const T_GROUP_THREE = '019968b0-0000-7000-8000-0000000000f3'

/**
 * Trois groupes par tableau, dont un sans version EN (P_GROUP_TWO,
 * T_GROUP_TWO) : ce sont eux qui portent « Traduction manquante » et « Créer
 * la version EN », et c'est sur eux que l'assistant est exercé — un groupe
 * déjà complet ne peut pas recevoir une seconde entrée de la même langue.
 */
const PRINCIPLE_FR_ONE: AdminQualityPrinciple = {
  id: '019968a0-0000-7000-8000-0000000000a1', locale: 'fr', translationGroup: P_GROUP_ONE,
  title: 'DDD', description: 'Description DDD', iconKey: 'boxes', position: 0,
}
const PRINCIPLE_EN_ONE: AdminQualityPrinciple = {
  ...PRINCIPLE_FR_ONE, id: '019968a0-0000-7000-8000-0000000000a2', locale: 'en',
  title: 'DDD in English', description: 'DDD description',
}
const PRINCIPLE_FR_TWO: AdminQualityPrinciple = {
  id: '019968a0-0000-7000-8000-0000000000a3', locale: 'fr', translationGroup: P_GROUP_TWO,
  title: "Tests d'abord", description: 'On écrit le test avant.', iconKey: 'flask-conical', position: 1,
}
const PRINCIPLE_FR_THREE: AdminQualityPrinciple = {
  id: '019968a0-0000-7000-8000-0000000000a4', locale: 'fr', translationGroup: P_GROUP_THREE,
  title: 'Revue systématique', description: 'Tout passe en revue.', iconKey: 'eye', position: 2,
}
const PRINCIPLE_EN_THREE: AdminQualityPrinciple = {
  ...PRINCIPLE_FR_THREE, id: '019968a0-0000-7000-8000-0000000000a5', locale: 'en', title: 'Systematic review',
}

const ALL_PRINCIPLES = [PRINCIPLE_FR_ONE, PRINCIPLE_EN_ONE, PRINCIPLE_FR_TWO, PRINCIPLE_FR_THREE, PRINCIPLE_EN_THREE]

const TRAIT_FR_ONE: AdminQualityTrait = {
  id: '019968a0-0000-7000-8000-0000000000b1', locale: 'fr', translationGroup: T_GROUP_ONE, label: 'Testé', position: 0,
}
const TRAIT_EN_ONE: AdminQualityTrait = {
  ...TRAIT_FR_ONE, id: '019968a0-0000-7000-8000-0000000000b2', locale: 'en', label: 'Tested',
}
const TRAIT_FR_TWO: AdminQualityTrait = {
  id: '019968a0-0000-7000-8000-0000000000b3', locale: 'fr', translationGroup: T_GROUP_TWO, label: 'Documenté', position: 1,
}
const TRAIT_FR_THREE: AdminQualityTrait = {
  id: '019968a0-0000-7000-8000-0000000000b4', locale: 'fr', translationGroup: T_GROUP_THREE, label: 'Observable', position: 2,
}
const TRAIT_EN_THREE: AdminQualityTrait = {
  ...TRAIT_FR_THREE, id: '019968a0-0000-7000-8000-0000000000b5', locale: 'en', label: 'Observable (en)',
}

const ALL_TRAITS = [TRAIT_FR_ONE, TRAIT_EN_ONE, TRAIT_FR_TWO, TRAIT_FR_THREE, TRAIT_EN_THREE]

const TRANSLATED_PRINCIPLE = { title: 'Tests first', description: 'The test comes first.' }
const TRANSLATED_TRAIT = { label: 'Documented' }

function createPrincipleRepository(
  overrides: Partial<AdminQualityPrincipleRepository> = {},
): AdminQualityPrincipleRepository {
  return {
    list: vi.fn(async () => ALL_PRINCIPLES),
    create: vi.fn(async () => PRINCIPLE_FR_TWO),
    update: vi.fn(async () => PRINCIPLE_FR_TWO),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createTraitRepository(overrides: Partial<AdminQualityTraitRepository> = {}): AdminQualityTraitRepository {
  return {
    list: vi.fn(async () => ALL_TRAITS),
    create: vi.fn(async () => TRAIT_FR_TWO),
    update: vi.fn(async () => TRAIT_FR_TWO),
    remove: vi.fn(async () => undefined),
    reorder: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createTranslationRepository(
  fields: Record<string, string> = TRANSLATED_PRINCIPLE,
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
  principleRepository: AdminQualityPrincipleRepository = createPrincipleRepository(),
  traitRepository: AdminQualityTraitRepository = createTraitRepository(),
  translationRepository: AdminTranslationRepository = createTranslationRepository(),
  options: { attach?: boolean } = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/quality', component: AdminQualityPage },
      { path: '/admin/ailleurs', component: ELSEWHERE },
    ],
  })
  await router.push('/admin/quality')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, {
    attachTo: options.attach ? document.body : undefined,
    global: {
      plugins: [createAppI18n(), router],
      provide: {
        [ADMIN_QUALITY_PRINCIPLE_REPOSITORY as symbol]: principleRepository,
        [ADMIN_QUALITY_TRAIT_REPOSITORY as symbol]: traitRepository,
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translationRepository,
      },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

/** Index des deux panneaux et des deux tableaux : principes d'abord, traits ensuite. */
const PRINCIPLES = 0
const TRAITS = 1

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

/** Le panneau (formulaire + tableau) d'un des deux contenus : principes puis traits. */
function panelOf(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  return wrapper.findAll('.surface-panel')[which]
}

/** L'identifiant du sélecteur de langue d'un formulaire : chacun a le sien. */
const LOCALE_SELECT = ['#admin-quality-principle-locale', '#admin-quality-trait-locale'] as const

function formOf(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  return wrapper.findAll('form')[which]
}

function translateButton(wrapper: VueWrapper, which: number): DOMWrapper<Element> {
  const button = panelOf(wrapper, which)
    .findAll('button')
    .find((candidate) => candidate.text().includes('Proposer la version'))
  if (!button) throw new Error('Bouton de traduction introuvable.')
  return button
}

/** Glisse une ligne sur une autre, comme le ferait la souris. */
async function dragRow(wrapper: VueWrapper, which: number, from: number, to: number): Promise<void> {
  const rows = rowsOf(wrapper, which)
  await rows[from].trigger('dragstart')
  await rows[to].trigger('dragover')
  await rows[to].trigger('drop')
}

describe('AdminQualityPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('tableaux toutes langues', () => {
    it('charge les deux listes sans filtre de locale (spec 0004, D8)', async () => {
      const principleRepository = createPrincipleRepository()
      const traitRepository = createTraitRepository()
      await mountPage(principleRepository, traitRepository)

      expect(principleRepository.list).toHaveBeenCalledWith()
      expect(traitRepository.list).toHaveBeenCalledWith()
    })

    it('ne recharge rien quand la langue sélectionnée change : elle ne pilote que les formulaires', async () => {
      const principleRepository = createPrincipleRepository()
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(principleRepository, traitRepository)
      vi.mocked(principleRepository.list).mockClear()
      vi.mocked(traitRepository.list).mockClear()

      await wrapper.get(LOCALE_SELECT[PRINCIPLES]).setValue('en')
      await flushPromises()

      expect(principleRepository.list).not.toHaveBeenCalled()
      expect(traitRepository.list).not.toHaveBeenCalled()
    })

    it.each([
      [PRINCIPLES, 'DDD', 'DDD in English', "Tests d'abord"],
      [TRAITS, 'Testé', 'Tested', 'Documenté'],
    ])(
      'tableau %i : une ligne par groupe, un badge par langue, et la traduction manquante',
      async (which, frTitle, enTitle, lonelyTitle) => {
        const { wrapper } = await mountPage()

        expect(rowsOf(wrapper, which)).toHaveLength(3)
        expect(tableOf(wrapper, which).text()).toContain(frTitle)
        expect(tableOf(wrapper, which).text()).toContain(enTitle)

        const badges = rowsOf(wrapper, which)[0].findAll('.badge').map((badge) => badge.text())
        expect(badges).toEqual(['FR', 'EN'])

        const missing = rowsOf(wrapper, which)[1]
        expect(missing.text()).toContain(lonelyTitle)
        expect(missing.text()).toContain('Traduction manquante')
        expect(buttonLabelled(missing, 'Créer la version EN').exists()).toBe(true)
      },
    )

    it("n'affiche plus aucune colonne ni champ de position", async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.find('#admin-quality-principle-position').exists()).toBe(false)
      expect(wrapper.find('#admin-quality-trait-position').exists()).toBe(false)
      expect(tableOf(wrapper, PRINCIPLES).find('thead').text()).not.toContain('Position')
      expect(tableOf(wrapper, TRAITS).find('thead').text()).not.toContain('Position')
    })
  })

  describe('ordre des principes', () => {
    it("glisse la première ligne sur la troisième puis enregistre l'ordre des groupes", async () => {
      const principleRepository = createPrincipleRepository()
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(principleRepository, traitRepository)

      await dragRow(wrapper, PRINCIPLES, 0, 2)
      expect(panelOf(wrapper, PRINCIPLES).text()).toContain('Ordre · modifié, non enregistré')

      vi.mocked(principleRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, PRINCIPLES), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(principleRepository.reorder).toHaveBeenCalledWith([P_GROUP_TWO, P_GROUP_THREE, P_GROUP_ONE])
      expect(principleRepository.list).toHaveBeenCalledOnce()
      // Les deux brouillons sont indépendants : l'ordre des traits n'a pas bougé,
      // donc son endpoint n'est pas appelé.
      expect(traitRepository.reorder).not.toHaveBeenCalled()
      expect(panelOf(wrapper, PRINCIPLES).text()).toContain('Ordre · à jour')
    })

    it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
      const { wrapper } = await mountPage(createPrincipleRepository(), createTraitRepository(), createTranslationRepository(), {
        attach: true,
      })

      const handle = rowsOf(wrapper, PRINCIPLES)[0].get('button')
      handle.element.focus()
      await handle.trigger('keydown', { key: 'ArrowDown' })
      await flushPromises()

      expect(rowsOf(wrapper, PRINCIPLES)[1].text()).toContain('DDD')
      // Issue #170 F3 : l'annonce vit dans la barre du tableau, pas dans la ligne déplacée.
      expect(rowsOf(wrapper, PRINCIPLES)[1].find('[role="status"]').exists()).toBe(false)
      expect(panelOf(wrapper, PRINCIPLES).get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
      expect(document.activeElement).toBe(rowsOf(wrapper, PRINCIPLES)[1].get('button').element)

      wrapper.unmount()
    })

    it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
      const principleRepository = createPrincipleRepository({
        reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
      })
      const { wrapper } = await mountPage(principleRepository)

      await dragRow(wrapper, PRINCIPLES, 0, 2)
      vi.mocked(principleRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, PRINCIPLES), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(principleRepository.list).toHaveBeenCalledOnce()
      const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
      expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
      expect(panelOf(wrapper, PRINCIPLES).text()).toContain('Ordre · à jour')
    })
  })

  describe('ordre des traits', () => {
    it("glisse la première ligne sur la troisième puis enregistre l'ordre des groupes", async () => {
      const principleRepository = createPrincipleRepository()
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(principleRepository, traitRepository)

      await dragRow(wrapper, TRAITS, 0, 2)
      expect(panelOf(wrapper, TRAITS).text()).toContain('Ordre · modifié, non enregistré')

      vi.mocked(traitRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, TRAITS), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(traitRepository.reorder).toHaveBeenCalledWith([T_GROUP_TWO, T_GROUP_THREE, T_GROUP_ONE])
      expect(traitRepository.list).toHaveBeenCalledOnce()
      expect(principleRepository.reorder).not.toHaveBeenCalled()
      expect(panelOf(wrapper, TRAITS).text()).toContain('Ordre · à jour')
    })

    it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
      const { wrapper } = await mountPage(createPrincipleRepository(), createTraitRepository(), createTranslationRepository(), {
        attach: true,
      })

      const handle = rowsOf(wrapper, TRAITS)[0].get('button')
      handle.element.focus()
      await handle.trigger('keydown', { key: 'ArrowDown' })
      await flushPromises()

      expect(rowsOf(wrapper, TRAITS)[1].text()).toContain('Testé')
      // Issue #170 F3 : l'annonce vit dans la barre du tableau, pas dans la ligne déplacée.
      expect(rowsOf(wrapper, TRAITS)[1].find('[role="status"]').exists()).toBe(false)
      expect(panelOf(wrapper, TRAITS).get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
      expect(document.activeElement).toBe(rowsOf(wrapper, TRAITS)[1].get('button').element)

      wrapper.unmount()
    })

    it("recharge la liste et annonce un ordre obsolète quand le serveur refuse l'ensemble envoyé", async () => {
      const traitRepository = createTraitRepository({
        reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
      })
      const { wrapper } = await mountPage(createPrincipleRepository(), traitRepository)

      await dragRow(wrapper, TRAITS, 0, 2)
      vi.mocked(traitRepository.list).mockClear()
      await buttonLabelled(panelOf(wrapper, TRAITS), "Enregistrer l'ordre").trigger('click')
      await flushPromises()

      expect(traitRepository.list).toHaveBeenCalledOnce()
      const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
      expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
      expect(panelOf(wrapper, TRAITS).text()).toContain('Ordre · à jour')
    })
  })

  describe('verrouillage', () => {
    it("verrouille les deux panneaux tant qu'un ordre est modifié, et les libère sur Annuler", async () => {
      const { wrapper } = await mountPage()

      // Le verrou est **global à la page** : un brouillon d'ordre de principes
      // désactive aussi les mutations des traits. Un état, une aide, une règle.
      await dragRow(wrapper, PRINCIPLES, 0, 2)

      // L'aide est rendue visible, jamais portée par un `title` : Bootstrap pose
      // `pointer-events: none` sur `.btn:disabled`, donc l'infobulle d'un bouton
      // désactivé ne s'affiche jamais. Les boutons la désignent par aria-describedby.
      const hint = wrapper.get('#admin-order-locked-hint')
      expect(hint.text()).toBe("Enregistrez ou annulez l'ordre d'abord.")

      const edit = buttonLabelled(actionsForLine(wrapper, PRINCIPLES, 'DDD in English'), 'Modifier')
      expect(edit.attributes('disabled')).toBeDefined()
      expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
      expect(
        buttonLabelled(actionsForLine(wrapper, PRINCIPLES, "Tests d'abord"), 'Supprimer').attributes('disabled'),
      ).toBeDefined()
      // Par tableau et non par index de ligne : le glisser-déposer vient de
      // déplacer le groupe sans traduction.
      expect(
        buttonLabelled(tableOf(wrapper, PRINCIPLES), 'Créer la version EN').attributes('disabled'),
      ).toBeDefined()
      expect(buttonLabelled(formOf(wrapper, PRINCIPLES), 'Enregistrer').attributes('disabled')).toBeDefined()
      // Un appel au modèle produirait un brouillon que le formulaire verrouillé ne
      // pourrait pas enregistrer : du quota dépensé pour rien (ADR 0004).
      expect(translateButton(wrapper, PRINCIPLES).attributes('disabled')).toBeDefined()

      // L'autre panneau est verrouillé lui aussi.
      expect(buttonLabelled(actionsForLine(wrapper, TRAITS, 'Testé'), 'Modifier').attributes('disabled')).toBeDefined()
      expect(buttonLabelled(formOf(wrapper, TRAITS), 'Enregistrer').attributes('disabled')).toBeDefined()
      expect(translateButton(wrapper, TRAITS).attributes('disabled')).toBeDefined()

      await buttonLabelled(panelOf(wrapper, PRINCIPLES), 'Annuler').trigger('click')

      expect(
        buttonLabelled(actionsForLine(wrapper, PRINCIPLES, 'DDD in English'), 'Modifier').attributes('disabled'),
      ).toBeUndefined()
      expect(buttonLabelled(formOf(wrapper, TRAITS), 'Enregistrer').attributes('disabled')).toBeUndefined()
      expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
    })
  })

  describe('« Créer la version » et rattachement', () => {
    it("principe : ouvre une création rattachée, l'icône recopiée, la prose vide, la langue basculée", async () => {
      const { wrapper } = await mountPage()

      await buttonLabelled(rowsOf(wrapper, PRINCIPLES)[1], 'Créer la version EN').trigger('click')

      expect(wrapper.findAll('h2')[PRINCIPLES].text()).toBe('Ajouter un principe')
      expect(valueOf(wrapper, LOCALE_SELECT[PRINCIPLES])).toBe('en')
      expect(valueOf(wrapper, '#admin-quality-principle-translation-group')).toBe(P_GROUP_TWO)
      expect(valueOf(wrapper, '#admin-quality-principle-icon-key')).toBe('flask-conical')
      expect(valueOf(wrapper, '#admin-quality-principle-title')).toBe('')
      expect(valueOf(wrapper, '#admin-quality-principle-description')).toBe('')
    })

    it('trait : ouvre une création rattachée, le libellé vide (aucun champ non-prose à recopier)', async () => {
      const { wrapper } = await mountPage()

      await buttonLabelled(rowsOf(wrapper, TRAITS)[1], 'Créer la version EN').trigger('click')

      expect(wrapper.findAll('h2')[TRAITS].text()).toBe('Ajouter un trait')
      expect(valueOf(wrapper, LOCALE_SELECT[TRAITS])).toBe('en')
      expect(valueOf(wrapper, '#admin-quality-trait-translation-group')).toBe(T_GROUP_TWO)
      expect(valueOf(wrapper, '#admin-quality-trait-label')).toBe('')
    })

    it("renvoie toujours le groupe lu lors d'une modification, pour ne pas détacher la traduction", async () => {
      const principleRepository = createPrincipleRepository()
      const { wrapper } = await mountPage(principleRepository)

      // Modifier l'entrée EN bascule aussi la langue du formulaire : sans cela,
      // l'enregistrement réécrirait l'entrée anglaise en français.
      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, 'DDD in English'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, LOCALE_SELECT[PRINCIPLES])).toBe('en')
      expect(valueOf(wrapper, '#admin-quality-principle-translation-group')).toBe(P_GROUP_ONE)

      await formOf(wrapper, PRINCIPLES).trigger('submit.prevent')
      await flushPromises()

      expect(principleRepository.update).toHaveBeenCalledWith(
        PRINCIPLE_EN_ONE.id,
        expect.objectContaining({ locale: 'en', translationGroup: P_GROUP_ONE }),
      )
    })

    it("un geste dans un panneau ne change jamais la langue du formulaire de l'autre", async () => {
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(createPrincipleRepository(), traitRepository)

      // Régression : avec un sélecteur de langue unique et partagé par les deux
      // formulaires, éditer une entrée anglaise dans un panneau basculait la
      // langue de l'autre — et le trait français en cours d'édition se serait
      // enregistré en anglais, sans avertissement ni index unique pour l'arrêter
      // (une entrée solitaire n'a pas de sœur qui occupe déjà la locale). La
      // langue est désormais un champ de chaque formulaire.
      await buttonLabelled(actionsForLine(wrapper, TRAITS, 'Documenté'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, LOCALE_SELECT[TRAITS])).toBe('fr')

      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, 'DDD in English'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, LOCALE_SELECT[PRINCIPLES])).toBe('en')
      expect(valueOf(wrapper, LOCALE_SELECT[TRAITS])).toBe('fr')

      await formOf(wrapper, TRAITS).trigger('submit.prevent')
      await flushPromises()

      expect(traitRepository.update).toHaveBeenCalledWith(
        TRAIT_FR_TWO.id,
        expect.objectContaining({ locale: 'fr', label: TRAIT_FR_TWO.label }),
      )
    })

    it('envoie « aucune » pour une entrée solitaire — un non-geste côté serveur', async () => {
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(createPrincipleRepository(), traitRepository)

      // TRAIT_FR_TWO est seul dans son groupe : le sélecteur affiche « aucune » et
      // le formulaire envoie donc `translationGroup: null`. Ce n'est pas un
      // détachement — `ContentPlacement::detach` traite une entrée seule en non-geste
      // quand l'entrée n'a pas de sœur (`count($members) === 1`), ce que pince
      // `ContentPlacementTest::testDetachingAnEntryWithoutTranslationsDoesNothing`.
      // Ces deux tests forment le contrat entre les deux moitiés : les casser
      // séparément doit être impossible sans que l'un des deux vire au rouge.
      await buttonLabelled(actionsForLine(wrapper, TRAITS, 'Documenté'), 'Modifier').trigger('click')
      expect(valueOf(wrapper, '#admin-quality-trait-translation-group')).toBe('')

      await formOf(wrapper, TRAITS).trigger('submit.prevent')
      await flushPromises()

      expect(traitRepository.update).toHaveBeenCalledWith(
        TRAIT_FR_TWO.id,
        expect.objectContaining({ translationGroup: null }),
      )
    })

    it("n'envoie jamais de position dans le corps d'une écriture", async () => {
      const principleRepository = createPrincipleRepository()
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(principleRepository, traitRepository)

      await buttonLabelled(rowsOf(wrapper, PRINCIPLES)[1], 'Créer la version EN').trigger('click')
      await wrapper.get('#admin-quality-principle-title').setValue('Tests first')
      await wrapper.get('#admin-quality-principle-description').setValue('The test comes first.')
      await formOf(wrapper, PRINCIPLES).trigger('submit.prevent')
      await flushPromises()

      const principlePayload = vi.mocked(principleRepository.create).mock.calls[0][0]
      expect(principlePayload).not.toHaveProperty('position')
      expect(principlePayload.translationGroup).toBe(P_GROUP_TWO)

      await buttonLabelled(rowsOf(wrapper, TRAITS)[1], 'Créer la version EN').trigger('click')
      await wrapper.get('#admin-quality-trait-label').setValue('Documented')
      await formOf(wrapper, TRAITS).trigger('submit.prevent')
      await flushPromises()

      const traitPayload = vi.mocked(traitRepository.create).mock.calls[0][0]
      expect(traitPayload).not.toHaveProperty('position')
      expect(traitPayload.translationGroup).toBe(T_GROUP_TWO)
    })

    it('affiche un message traduit si une mutation échoue', async () => {
      const principleRepository = createPrincipleRepository({
        create: vi.fn(async () => Promise.reject(new AdminQualityError('translation-already-exists', 'Déjà traduit'))),
      })
      const { wrapper } = await mountPage(principleRepository)

      await buttonLabelled(rowsOf(wrapper, PRINCIPLES)[1], 'Créer la version EN').trigger('click')
      await wrapper.get('#admin-quality-principle-title').setValue('Tests first')
      await wrapper.get('#admin-quality-principle-description').setValue('The test comes first.')
      await formOf(wrapper, PRINCIPLES).trigger('submit.prevent')
      await flushPromises()

      expect(formOf(wrapper, PRINCIPLES).get('[role="alert"]').text()).toBe(
        'Ce contenu a déjà une version dans cette langue.',
      )
    })

    it('supprime une entrée après confirmation', async () => {
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const principleRepository = createPrincipleRepository()
      const { wrapper } = await mountPage(principleRepository)

      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, 'DDD in English'), 'Supprimer').trigger('click')
      await flushPromises()

      expect(principleRepository.remove).toHaveBeenCalledWith(PRINCIPLE_EN_ONE.id)
    })
  })

  describe('assistant de traduction', () => {
    it('propose un bouton par formulaire, principes et traits', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.findAll('button').filter((b) => b.text().includes('Proposer la version'))).toHaveLength(2)
    })

    it("principe : envoie les seuls champs de prose, jamais la clé d'icône", async () => {
      const translation = createTranslationRepository()
      const { wrapper } = await mountPage(createPrincipleRepository(), createTraitRepository(), translation)

      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, "Tests d'abord"), 'Modifier').trigger('click')
      await translateButton(wrapper, PRINCIPLES).trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
        title: PRINCIPLE_FR_TWO.title,
        description: PRINCIPLE_FR_TWO.description,
      })
    })

    it('principe : bascule le formulaire (et non la page) en création rattachée au groupe source', async () => {
      const principleRepository = createPrincipleRepository()
      const { wrapper } = await mountPage(principleRepository)

      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, "Tests d'abord"), 'Modifier').trigger('click')
      expect(wrapper.findAll('h2')[PRINCIPLES].text()).toBe('Modifier le principe')
      vi.mocked(principleRepository.list).mockClear()

      await translateButton(wrapper, PRINCIPLES).trigger('click')
      await flushPromises()

      expect(wrapper.findAll('h2')[PRINCIPLES].text()).toBe('Ajouter un principe')
      expect(valueOf(wrapper, LOCALE_SELECT[PRINCIPLES])).toBe('en')
      // Aucune liste rechargée : le tableau affiche déjà les deux langues.
      expect(principleRepository.list).not.toHaveBeenCalled()
      expect(valueOf(wrapper, '#admin-quality-principle-translation-group')).toBe(P_GROUP_TWO)
      expect(valueOf(wrapper, '#admin-quality-principle-title')).toBe(TRANSLATED_PRINCIPLE.title)
      expect(valueOf(wrapper, '#admin-quality-principle-icon-key')).toBe('flask-conical')
      expect(principleRepository.create).not.toHaveBeenCalled()
      expect(principleRepository.update).not.toHaveBeenCalled()

      const banner = formOf(wrapper, PRINCIPLES).get('[role="status"]')
      expect(banner.text()).toContain('Brouillon généré par IA')
      expect(banner.text()).toContain('FR')
    })

    it('principe : enregistrer le brouillon crée une entrée EN rattachée au même groupe', async () => {
      const principleRepository = createPrincipleRepository()
      const { wrapper } = await mountPage(principleRepository)

      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, "Tests d'abord"), 'Modifier').trigger('click')
      await translateButton(wrapper, PRINCIPLES).trigger('click')
      await flushPromises()

      await formOf(wrapper, PRINCIPLES).trigger('submit.prevent')
      await flushPromises()

      expect(principleRepository.update).not.toHaveBeenCalled()
      expect(principleRepository.create).toHaveBeenCalledWith({
        locale: 'en',
        translationGroup: P_GROUP_TWO,
        title: TRANSLATED_PRINCIPLE.title,
        description: TRANSLATED_PRINCIPLE.description,
        iconKey: 'flask-conical',
      })
    })

    it('trait : envoie le libellé, bascule le formulaire et enregistre la version rattachée', async () => {
      const translation = createTranslationRepository(TRANSLATED_TRAIT)
      const traitRepository = createTraitRepository()
      const { wrapper } = await mountPage(createPrincipleRepository(), traitRepository, translation)

      await buttonLabelled(actionsForLine(wrapper, TRAITS, 'Documenté'), 'Modifier').trigger('click')
      await translateButton(wrapper, TRAITS).trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', { label: TRAIT_FR_TWO.label })
      expect(valueOf(wrapper, LOCALE_SELECT[TRAITS])).toBe('en')
      expect(wrapper.findAll('h2')[TRAITS].text()).toBe('Ajouter un trait')
      expect(valueOf(wrapper, '#admin-quality-trait-label')).toBe(TRANSLATED_TRAIT.label)

      await formOf(wrapper, TRAITS).trigger('submit.prevent')
      await flushPromises()

      expect(traitRepository.create).toHaveBeenCalledWith({
        locale: 'en',
        translationGroup: T_GROUP_TWO,
        label: TRANSLATED_TRAIT.label,
      })
    })

    it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
      const translation = createTranslationRepository(TRANSLATED_PRINCIPLE, {
        translate: vi.fn(async () => {
          throw new AdminTranslationError('rate-limited', 'Quota.')
        }),
      })
      const { wrapper } = await mountPage(createPrincipleRepository(), createTraitRepository(), translation)

      await buttonLabelled(actionsForLine(wrapper, PRINCIPLES, "Tests d'abord"), 'Modifier').trigger('click')
      await translateButton(wrapper, PRINCIPLES).trigger('click')
      await flushPromises()

      expect(formOf(wrapper, PRINCIPLES).get('[role="alert"]').text()).toContain('Quota horaire')
      expect(wrapper.findAll('h2')[PRINCIPLES].text()).toBe('Modifier le principe')
      expect(valueOf(wrapper, LOCALE_SELECT[PRINCIPLES])).toBe('fr')
      expect(valueOf(wrapper, '#admin-quality-principle-title')).toBe(PRINCIPLE_FR_TWO.title)
    })
  })

  describe('garde de sortie et accessibilité', () => {
    it('demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const { wrapper, router } = await mountPage()

      await dragRow(wrapper, TRAITS, 0, 2)
      await router.push('/admin/ailleurs')
      await flushPromises()

      expect(confirmSpy).toHaveBeenCalledOnce()
      expect(router.currentRoute.value.path).toBe('/admin/quality')
    })

    it("quitte la route sans rien demander quand les deux ordres sont à jour", async () => {
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
      const { router } = await mountPage()

      await router.push('/admin/ailleurs')
      await flushPromises()

      expect(confirmSpy).not.toHaveBeenCalled()
      expect(router.currentRoute.value.path).toBe('/admin/ailleurs')
    })

    it("ne présente aucune violation d'accessibilité, les deux tableaux groupés rendus", async () => {
      const { wrapper } = await mountPage()

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
