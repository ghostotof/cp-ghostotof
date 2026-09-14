import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import { RouterView } from 'vue-router'
import AdminIncidentsPage from '../../../../src/presentation/pages/admin/AdminIncidentsPage.vue'
import { ADMIN_INCIDENT_REPOSITORY } from '../../../../src/application/admin/incidents/useAdminIncidents'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminIncidentRepository } from '../../../../src/domain/admin/incidents/repositories/AdminIncidentRepository'
import type { AdminIncident } from '../../../../src/domain/admin/incidents/entities/AdminIncident'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { AdminOrderError } from '../../../../src/domain/admin/shared/errors/AdminOrderError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const GROUP_ONE = '019968b0-0000-7000-8000-000000000001'
const GROUP_TWO = '019968b0-0000-7000-8000-000000000002'
const GROUP_THREE = '019968b0-0000-7000-8000-000000000003'

/**
 * Trois groupes, dont un (GROUP_TWO) sans version EN : c'est lui qui porte
 * « Traduction manquante » et « Créer la version EN », et c'est sur lui que
 * l'assistant de traduction est exercé — un groupe déjà complet ne peut pas
 * recevoir une seconde entrée de la même langue.
 */
const FR_ONE: AdminIncident = {
  id: '019968a0-0000-7000-8000-000000000001', locale: 'fr', translationGroup: GROUP_ONE,
  title: 'Panne du broker RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Formulaire en 500 pendant 15 minutes.', rootCause: 'Cookie Erlang.', resolution: 'Rollback.',
  invariant: 'Tester sur un volume déjà initialisé.', position: 0,
}
const EN_ONE: AdminIncident = {
  ...FR_ONE, id: '019968a0-0000-7000-8000-000000000002', locale: 'en', title: 'RabbitMQ broker outage',
}
const FR_TWO: AdminIncident = {
  id: '019968a0-0000-7000-8000-000000000003', locale: 'fr', translationGroup: GROUP_TWO,
  title: 'Image obsolète servie', version: 'v0.7.0', occurredAt: '2026-09-05',
  impact: 'Le job de migration a rejoué le build précédent.', rootCause: 'Tag git réutilisé.',
  resolution: 'imagePullPolicy Always.', invariant: 'Ne jamais recouper un tag.', position: 1,
}
const FR_THREE: AdminIncident = {
  id: '019968a0-0000-7000-8000-000000000004', locale: 'fr', translationGroup: GROUP_THREE,
  title: 'ConfigMap jamais rechargée', version: 'v0.6.0', occurredAt: '2026-09-07',
  impact: 'nginx a gardé ses anciennes règles.', rootCause: 'Montage subPath.',
  resolution: 'configMapGenerator.', invariant: 'Hacher ce que kustomize possède entièrement.', position: 2,
}
const EN_THREE: AdminIncident = {
  ...FR_THREE, id: '019968a0-0000-7000-8000-000000000005', locale: 'en', title: 'ConfigMap never reloaded',
}

const ALL_INCIDENTS = [FR_ONE, EN_ONE, FR_TWO, FR_THREE, EN_THREE]

const TRANSLATED = {
  title: 'Stale image served',
  impact: 'The migration job replayed the previous build.',
  rootCause: 'Reused git tag.',
  resolution: 'imagePullPolicy Always.',
  invariant: 'Never re-cut a tag.',
}

function createIncidentRepository(overrides: Partial<AdminIncidentRepository> = {}): AdminIncidentRepository {
  return {
    list: vi.fn(async () => ALL_INCIDENTS),
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
  incidents: AdminIncidentRepository = createIncidentRepository(),
  translation: AdminTranslationRepository = createTranslationRepository(),
  options: { attach?: boolean } = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/incidents', component: AdminIncidentsPage },
      { path: '/admin/ailleurs', component: ELSEWHERE },
    ],
  })
  await router.push('/admin/incidents')
  await router.isReady()

  const Host = defineComponent({ setup: () => () => h(RouterView) })
  const wrapper = mount(Host, {
    attachTo: options.attach ? document.body : undefined,
    global: {
      plugins: [createAppI18n(), router],
      provide: {
        [ADMIN_INCIDENT_REPOSITORY as symbol]: incidents,
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

describe('AdminIncidentsPage', () => {
  // Chaque page démontée après son test : sans cela, une page laissée montée
  // avec un ordre modifié garde son écouteur `beforeunload` et fait mentir le
  // test suivant sur cet événement global.
  enableAutoUnmount(afterEach)

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche une ligne par groupe, un badge par langue, et la traduction manquante', async () => {
    const { wrapper } = await mountPage()

    expect(rows(wrapper)).toHaveLength(3)
    expect(wrapper.text()).toContain('Panne du broker RabbitMQ')
    expect(wrapper.text()).toContain('RabbitMQ broker outage')

    const badges = rows(wrapper)[0].findAll('.badge').map((badge) => badge.text())
    expect(badges).toEqual(['FR', 'EN'])

    const missing = rows(wrapper)[1]
    expect(missing.text()).toContain('Traduction manquante')
    expect(buttonLabelled(missing, 'Créer la version EN').exists()).toBe(true)
  })

  it('n\'affiche plus aucune colonne ni champ de position', async () => {
    const { wrapper } = await mountPage()

    expect(wrapper.find('#admin-incident-position').exists()).toBe(false)
    expect(wrapper.find('thead').text()).not.toContain('Position')
  })

  it('glisse la première ligne sur la troisième puis enregistre l\'ordre des groupes', async () => {
    const incidents = createIncidentRepository()
    const { wrapper } = await mountPage(incidents)

    await dragRow(wrapper, 0, 2)
    expect(wrapper.text()).toContain('Ordre · modifié, non enregistré')

    vi.mocked(incidents.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(incidents.reorder).toHaveBeenCalledWith([GROUP_TWO, GROUP_THREE, GROUP_ONE])
    expect(incidents.list).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('déplace une ligne au clavier, garde le focus sur sa poignée et annonce la position', async () => {
    const { wrapper } = await mountPage(createIncidentRepository(), createTranslationRepository(), { attach: true })

    const handle = rows(wrapper)[0].get('button')
    handle.element.focus()
    await handle.trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()

    expect(rows(wrapper)[1].text()).toContain('Panne du broker RabbitMQ')
    expect(rows(wrapper)[1].get('[role="status"]').text()).toBe('Déplacé en position 2 sur 3')
    expect(document.activeElement).toBe(rows(wrapper)[1].get('button').element)
  })

  it('verrouille toutes les mutations tant que l\'ordre est modifié, et les libère sur Annuler', async () => {
    const { wrapper } = await mountPage()

    await dragRow(wrapper, 0, 2)

    // L'aide est rendue visible, jamais portée par un `title` : Bootstrap pose
    // `pointer-events: none` sur `.btn:disabled`, donc l'infobulle d'un bouton
    // désactivé ne s'affiche jamais. Les boutons la désignent par aria-describedby.
    const hint = wrapper.get('#admin-order-locked-hint')
    expect(hint.text()).toBe("Enregistrez ou annulez l'ordre d'abord.")

    const edit = buttonLabelled(actionsForLine(wrapper, 'Panne du broker RabbitMQ'), 'Modifier')
    expect(edit.attributes('disabled')).toBeDefined()
    expect(edit.attributes('aria-describedby')).toBe('admin-order-locked-hint')
    expect(buttonLabelled(actionsForLine(wrapper, 'Panne du broker RabbitMQ'), 'Supprimer').attributes('disabled')).toBeDefined()
    expect(buttonLabelled(wrapper, 'Créer la version EN').attributes('disabled')).toBeDefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeDefined()
    // Un appel au modèle produirait un brouillon que le formulaire verrouillé ne
    // pourrait pas enregistrer : du quota dépensé pour rien (ADR 0004).
    expect(translateButton(wrapper).attributes('disabled')).toBeDefined()

    await buttonLabelled(wrapper, 'Annuler').trigger('click')

    expect(buttonLabelled(actionsForLine(wrapper, 'Panne du broker RabbitMQ'), 'Modifier').attributes('disabled')).toBeUndefined()
    expect(buttonLabelled(wrapper, 'Enregistrer').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('#admin-order-locked-hint').exists()).toBe(false)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('recharge la liste et annonce un ordre obsolète quand le serveur refuse l\'ensemble envoyé', async () => {
    const incidents = createIncidentRepository({
      reorder: vi.fn(async () => Promise.reject(new AdminOrderError('stale-order', 'obsolète'))),
    })
    const { wrapper } = await mountPage(incidents)

    await dragRow(wrapper, 0, 2)
    vi.mocked(incidents.list).mockClear()
    await buttonLabelled(wrapper, "Enregistrer l'ordre").trigger('click')
    await flushPromises()

    expect(incidents.list).toHaveBeenCalledOnce()
    const alerts = wrapper.findAll('[role="alert"]').map((alert) => alert.text())
    expect(alerts.some((text) => text.includes('La liste a changé entre-temps'))).toBe(true)
    expect(wrapper.text()).toContain('Ordre · à jour')
  })

  it('« Créer la version EN » ouvre une création rattachée, version et date recopiées, prose vide', async () => {
    const { wrapper } = await mountPage()

    await buttonLabelled(wrapper, 'Créer la version EN').trigger('click')

    expect(wrapper.get('h2').text()).toBe('Ajouter un incident')
    expect(valueOf(wrapper, '#admin-incident-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-incident-translation-group')).toBe(GROUP_TWO)
    expect(valueOf(wrapper, '#admin-incident-version')).toBe('v0.7.0')
    expect(valueOf(wrapper, '#admin-incident-occurred-at')).toBe('2026-09-05')
    expect(valueOf(wrapper, '#admin-incident-title')).toBe('')
    expect(valueOf(wrapper, '#admin-incident-impact')).toBe('')
    expect(valueOf(wrapper, '#admin-incident-invariant')).toBe('')
  })

  it('renvoie toujours le groupe lu lors d\'une modification, pour ne pas détacher la traduction', async () => {
    const incidents = createIncidentRepository()
    const { wrapper } = await mountPage(incidents)

    await buttonLabelled(actionsForLine(wrapper, 'Panne du broker RabbitMQ'), 'Modifier').trigger('click')
    expect(valueOf(wrapper, '#admin-incident-translation-group')).toBe(GROUP_ONE)

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(incidents.update).toHaveBeenCalledWith(FR_ONE.id, expect.objectContaining({ translationGroup: GROUP_ONE }))
  })

  it('envoie « aucune » pour une entrée solitaire — un non-geste côté serveur', async () => {
    const incidents = createIncidentRepository()
    const { wrapper } = await mountPage(incidents)

    // FR_TWO est seule dans son groupe : le sélecteur affiche « aucune » et le
    // formulaire envoie donc `translationGroup: null`. Ce n'est pas un
    // détachement — `ContentPlacement::reattach` traite le `null` en non-geste
    // quand l'entrée n'a pas de sœur (`count($members) === 1`), ce que pince
    // `ContentPlacementTest::testDetachingAnEntryWithoutTranslationsDoesNothing`.
    // Ces deux tests forment le contrat entre les deux moitiés : les casser
    // séparément doit être impossible sans que l'un des deux vire au rouge.
    await buttonLabelled(actionsForLine(wrapper, 'Image obsolète servie'), 'Modifier').trigger('click')
    expect(valueOf(wrapper, '#admin-incident-translation-group')).toBe('')

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(incidents.update).toHaveBeenCalledWith(FR_TWO.id, expect.objectContaining({ translationGroup: null }))
  })

  it('n\'envoie jamais de position dans le corps d\'une écriture', async () => {
    const incidents = createIncidentRepository()
    const { wrapper } = await mountPage(incidents)

    await buttonLabelled(wrapper, 'Créer la version EN').trigger('click')
    await wrapper.get('#admin-incident-title').setValue('Stale image served')
    await wrapper.get('#admin-incident-impact').setValue('Impact.')
    await wrapper.get('#admin-incident-root-cause').setValue('Cause.')
    await wrapper.get('#admin-incident-resolution').setValue('Résolution.')
    await wrapper.get('#admin-incident-invariant').setValue('Règle.')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    const payload = vi.mocked(incidents.create).mock.calls[0][0]
    expect(payload).not.toHaveProperty('position')
    expect(payload.translationGroup).toBe(GROUP_TWO)
  })

  it('envoie les seuls champs de prose de l\'entrée chargée, vers l\'autre locale', async () => {
    const translation = createTranslationRepository()
    const { wrapper } = await mountPage(createIncidentRepository(), translation)

    await buttonLabelled(actionsForLine(wrapper, 'Image obsolète servie'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
      title: FR_TWO.title,
      impact: FR_TWO.impact,
      rootCause: FR_TWO.rootCause,
      resolution: FR_TWO.resolution,
      invariant: FR_TWO.invariant,
    })
  })

  it('bascule en création rattachée au groupe de la source, prose remplacée, bannière affichée', async () => {
    const incidents = createIncidentRepository()
    const { wrapper } = await mountPage(incidents)

    await buttonLabelled(actionsForLine(wrapper, 'Image obsolète servie'), 'Modifier').trigger('click')
    expect(wrapper.get('h2').text()).toBe("Modifier l'incident")

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('h2').text()).toBe('Ajouter un incident')
    expect(valueOf(wrapper, '#admin-incident-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-incident-translation-group')).toBe(GROUP_TWO)
    expect(valueOf(wrapper, '#admin-incident-title')).toBe(TRANSLATED.title)
    expect(valueOf(wrapper, '#admin-incident-version')).toBe('v0.7.0')
    expect(incidents.create).not.toHaveBeenCalled()
    expect(incidents.update).not.toHaveBeenCalled()

    const banner = wrapper.get('form [role="status"]')
    expect(banner.text()).toContain('Brouillon généré par IA')
    expect(banner.text()).toContain('FR')
  })

  it('enregistre le brouillon comme une nouvelle entrée EN rattachée au même groupe', async () => {
    const incidents = createIncidentRepository()
    const { wrapper } = await mountPage(incidents)

    await buttonLabelled(actionsForLine(wrapper, 'Image obsolète servie'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(incidents.update).not.toHaveBeenCalled()
    expect(incidents.create).toHaveBeenCalledWith({
      locale: 'en',
      translationGroup: GROUP_TWO,
      title: TRANSLATED.title,
      version: 'v0.7.0',
      occurredAt: '2026-09-05',
      impact: TRANSLATED.impact,
      rootCause: TRANSLATED.rootCause,
      resolution: TRANSLATED.resolution,
      invariant: TRANSLATED.invariant,
    })
  })

  it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
    const translation = createTranslationRepository({
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota.') }),
    })
    const { wrapper } = await mountPage(createIncidentRepository(), translation)

    await buttonLabelled(actionsForLine(wrapper, 'Image obsolète servie'), 'Modifier').trigger('click')
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('form [role="alert"]').text()).toContain('Quota horaire')
    expect(wrapper.get('h2').text()).toBe("Modifier l'incident")
    expect(valueOf(wrapper, '#admin-incident-locale')).toBe('fr')
    expect(valueOf(wrapper, '#admin-incident-title')).toBe(FR_TWO.title)
  })

  it('demande confirmation avant de quitter la route avec un ordre modifié, et reste sur place si on refuse', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, router } = await mountPage()

    await dragRow(wrapper, 0, 2)
    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/admin/incidents')
  })

  /**
   * La fermeture de l'onglet ne passe pas par le routeur : c'est `beforeunload`
   * qui porte l'avertissement (D6), et le navigateur affiche sa propre boîte
   * quand l'événement est annulé. Le gestionnaire est identique sur les six
   * pages ordonnées ; il est vérifié une fois, ici, sur la première d'entre
   * elles — y compris son retrait au démontage, sans quoi la page suivante
   * hériterait d'un avertissement fantôme.
   */
  it('annule beforeunload seulement tant que l\'ordre est modifié, et se détache au démontage', async () => {
    const { wrapper } = await mountPage()

    const clean = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(clean)
    expect(clean.defaultPrevented).toBe(false)

    await dragRow(wrapper, 0, 2)

    const dirty = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(dirty)
    expect(dirty.defaultPrevented).toBe(true)

    wrapper.unmount()

    const afterUnmount = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(afterUnmount)
    expect(afterUnmount.defaultPrevented).toBe(false)
  })

  it('quitte la route sans rien demander quand l\'ordre est à jour', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { router } = await mountPage()

    await router.push('/admin/ailleurs')
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/admin/ailleurs')
  })

  it('ne présente aucune violation d\'accessibilité, tableau groupé, poignée et barre d\'ordre rendus', async () => {
    const { wrapper } = await mountPage()

    await expectNoAccessibilityViolation(wrapper)
  })
})
