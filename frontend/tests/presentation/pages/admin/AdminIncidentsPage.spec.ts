import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AdminIncidentsPage from '../../../../src/presentation/pages/admin/AdminIncidentsPage.vue'
import { ADMIN_INCIDENT_REPOSITORY } from '../../../../src/application/admin/incidents/useAdminIncidents'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminIncidentRepository } from '../../../../src/domain/admin/incidents/repositories/AdminIncidentRepository'
import type { AdminIncident } from '../../../../src/domain/admin/incidents/entities/AdminIncident'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const INCIDENT_ID = '019968a0-0000-7000-8000-000000000001'
const INCIDENT: AdminIncident = {
  id: INCIDENT_ID, locale: 'fr', title: 'Panne du broker RabbitMQ', version: 'v0.5.0', occurredAt: '2026-09-03',
  impact: 'Formulaire en 500 pendant 15 minutes.', rootCause: 'Cookie Erlang.', resolution: 'Rollback.', invariant: 'Tester sur un volume déjà initialisé.', position: 0,
}

const TRANSLATED = {
  title: 'RabbitMQ broker outage',
  impact: 'Form answered 500 for 15 minutes.',
  rootCause: 'Erlang cookie.',
  resolution: 'Rollback.',
  invariant: 'Test on an already-initialised volume.',
}

function createIncidentRepository(overrides: Partial<AdminIncidentRepository> = {}): AdminIncidentRepository {
  return {
    list: vi.fn(async () => [INCIDENT]),
    create: vi.fn(async () => INCIDENT),
    update: vi.fn(async () => INCIDENT),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createTranslationRepository(overrides: Partial<AdminTranslationRepository> = {}): AdminTranslationRepository {
  return {
    translate: vi.fn(async () => ({ sourceLocale: 'fr' as const, targetLocale: 'en' as const, fields: TRANSLATED })),
    ...overrides,
  }
}

async function mountPage(
  incidents: AdminIncidentRepository = createIncidentRepository(),
  translation: AdminTranslationRepository = createTranslationRepository(),
) {
  const wrapper = mount(AdminIncidentsPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_INCIDENT_REPOSITORY as symbol]: incidents,
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translation,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>

function valueOf(wrapper: Wrapper, id: string): string {
  return (wrapper.get(id).element as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value
}

function translateButton(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Proposer la version'))
  if (!button) throw new Error('Bouton de traduction introuvable.')
  return button
}

async function startEditing(wrapper: Wrapper): Promise<void> {
  const editButton = wrapper.findAll('button').find((button) => 'Modifier' === button.text())
  await editButton?.trigger('click')
}

describe('AdminIncidentsPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche la liste des incidents chargés', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Panne du broker RabbitMQ')
    expect(wrapper.text()).toContain('v0.5.0')
  })

  it('désactive le bouton de traduction tant qu\'aucun champ de prose n\'est rempli', async () => {
    const wrapper = await mountPage()

    expect(translateButton(wrapper).attributes('disabled')).toBeDefined()

    await wrapper.get('#admin-incident-title').setValue('Un titre')

    expect(translateButton(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('envoie les seuls champs de prose de l\'entrée chargée, vers l\'autre locale', async () => {
    const translation = createTranslationRepository()
    const wrapper = await mountPage(createIncidentRepository(), translation)
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
      title: INCIDENT.title,
      impact: INCIDENT.impact,
      rootCause: INCIDENT.rootCause,
      resolution: INCIDENT.resolution,
      invariant: INCIDENT.invariant,
    })
  })

  it('bascule le formulaire en création dans la locale cible, prose remplacée, reste conservé, bannière affichée', async () => {
    const wrapper = await mountPage()
    await startEditing(wrapper)
    expect(wrapper.get('h2').text()).toBe("Modifier l'incident")

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('h2').text()).toBe('Ajouter un incident')
    expect(valueOf(wrapper, '#admin-incident-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-incident-title')).toBe(TRANSLATED.title)
    expect(valueOf(wrapper, '#admin-incident-impact')).toBe(TRANSLATED.impact)
    expect(valueOf(wrapper, '#admin-incident-root-cause')).toBe(TRANSLATED.rootCause)
    expect(valueOf(wrapper, '#admin-incident-resolution')).toBe(TRANSLATED.resolution)
    expect(valueOf(wrapper, '#admin-incident-invariant')).toBe(TRANSLATED.invariant)
    expect(valueOf(wrapper, '#admin-incident-version')).toBe('v0.5.0')
    expect(valueOf(wrapper, '#admin-incident-occurred-at')).toBe('2026-09-03')
    expect(valueOf(wrapper, '#admin-incident-position')).toBe('0')

    const banner = wrapper.get('[role="status"]')
    expect(banner.text()).toContain('Brouillon généré par IA')
    expect(banner.text()).toContain('FR')
  })

  it('n\'enregistre rien par lui-même : ni create() ni update() ne sont appelés par l\'assistant', async () => {
    const incidents = createIncidentRepository()
    const wrapper = await mountPage(incidents)
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(incidents.create).not.toHaveBeenCalled()
    expect(incidents.update).not.toHaveBeenCalled()
  })

  it('enregistre le brouillon comme une nouvelle entrée EN quand l\'humain clique sur Enregistrer', async () => {
    const incidents = createIncidentRepository()
    const wrapper = await mountPage(incidents)
    await startEditing(wrapper)
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(incidents.update).not.toHaveBeenCalled()
    expect(incidents.create).toHaveBeenCalledWith({
      locale: 'en',
      title: TRANSLATED.title,
      version: 'v0.5.0',
      occurredAt: '2026-09-03',
      impact: TRANSLATED.impact,
      rootCause: TRANSLATED.rootCause,
      resolution: TRANSLATED.resolution,
      invariant: TRANSLATED.invariant,
      position: 0,
    })
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('affiche le message du quota en cas de 429 et laisse le formulaire intact', async () => {
    const translation = createTranslationRepository({
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota.') }),
    })
    const wrapper = await mountPage(createIncidentRepository(), translation)
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Quota horaire')
    expect(wrapper.get('h2').text()).toBe("Modifier l'incident")
    expect(valueOf(wrapper, '#admin-incident-locale')).toBe('fr')
    expect(valueOf(wrapper, '#admin-incident-title')).toBe(INCIDENT.title)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('affiche le message d\'indisponibilité en cas de 503', async () => {
    const translation = createTranslationRepository({
      translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
    })
    const wrapper = await mountPage(createIncidentRepository(), translation)
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('indisponible')
  })

  it('ne présente aucune violation d\'accessibilité, bannière et bouton rendus', async () => {
    const wrapper = await mountPage()
    await startEditing(wrapper)
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})
