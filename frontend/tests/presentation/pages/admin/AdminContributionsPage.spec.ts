import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AdminContributionsPage from '../../../../src/presentation/pages/admin/AdminContributionsPage.vue'
import { ADMIN_CONTRIBUTION_REPOSITORY } from '../../../../src/application/admin/contributions/useAdminContributions'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminContributionRepository } from '../../../../src/domain/admin/contributions/repositories/AdminContributionRepository'
import type { AdminContribution } from '../../../../src/domain/admin/contributions/entities/AdminContribution'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const CONTRIBUTION: AdminContribution = {
  id: 1, locale: 'fr', title: 'Un lock npm dans le manifeste', project: 'symfony/ai', reference: 'PR #42',
  url: 'https://example.test/pr/42', summary: 'Résumé.', body: 'Le fichier `composer.lock` suffit.\n\nSecond paragraphe.', position: 0,
}

const TRANSLATED = {
  title: 'An npm lock in the manifest',
  summary: 'Summary.',
  body: 'The `composer.lock` file is enough.\n\nSecond paragraph.',
}

function createContributionRepository(overrides: Partial<AdminContributionRepository> = {}): AdminContributionRepository {
  return {
    list: vi.fn(async () => [CONTRIBUTION]),
    create: vi.fn(async () => CONTRIBUTION),
    update: vi.fn(async () => CONTRIBUTION),
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
  contributions: AdminContributionRepository = createContributionRepository(),
  translation: AdminTranslationRepository = createTranslationRepository(),
) {
  const wrapper = mount(AdminContributionsPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_CONTRIBUTION_REPOSITORY as symbol]: contributions,
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

describe('AdminContributionsPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche la liste des contributions chargées', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Un lock npm dans le manifeste')
  })

  it('désactive le bouton de traduction sans prose', async () => {
    const wrapper = await mountPage()

    expect(translateButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('envoie titre, résumé et corps — jamais le projet, la référence ni l\'URL', async () => {
    const translation = createTranslationRepository()
    const wrapper = await mountPage(createContributionRepository(), translation)
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
      title: CONTRIBUTION.title,
      summary: CONTRIBUTION.summary,
      body: CONTRIBUTION.body,
    })
  })

  it('bascule en création EN : prose remplacée backticks compris, projet / référence / URL / position conservés', async () => {
    const wrapper = await mountPage()
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('h2').text()).toBe('Ajouter une contribution')
    expect(valueOf(wrapper, '#admin-contribution-locale')).toBe('en')
    expect(valueOf(wrapper, '#admin-contribution-title')).toBe(TRANSLATED.title)
    expect(valueOf(wrapper, '#admin-contribution-summary')).toBe(TRANSLATED.summary)
    expect(valueOf(wrapper, '#admin-contribution-body')).toBe(TRANSLATED.body)
    expect(valueOf(wrapper, '#admin-contribution-project')).toBe('symfony/ai')
    expect(valueOf(wrapper, '#admin-contribution-reference')).toBe('PR #42')
    expect(valueOf(wrapper, '#admin-contribution-url')).toBe('https://example.test/pr/42')
    expect(valueOf(wrapper, '#admin-contribution-position')).toBe('0')
    expect(wrapper.get('[role="status"]').text()).toContain('Brouillon généré par IA')
  })

  it('n\'enregistre rien par lui-même, et Enregistrer crée l\'entrée EN', async () => {
    const contributions = createContributionRepository()
    const wrapper = await mountPage(contributions)
    await startEditing(wrapper)
    await translateButton(wrapper).trigger('click')
    await flushPromises()
    expect(contributions.create).not.toHaveBeenCalled()
    expect(contributions.update).not.toHaveBeenCalled()

    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(contributions.update).not.toHaveBeenCalled()
    expect(contributions.create).toHaveBeenCalledWith({
      locale: 'en',
      title: TRANSLATED.title,
      project: 'symfony/ai',
      reference: 'PR #42',
      url: 'https://example.test/pr/42',
      summary: TRANSLATED.summary,
      body: TRANSLATED.body,
      position: 0,
    })
  })

  it('affiche la raison en cas d\'échec et laisse le formulaire intact', async () => {
    const translation = createTranslationRepository({
      translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
    })
    const wrapper = await mountPage(createContributionRepository(), translation)
    await startEditing(wrapper)

    await translateButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('indisponible')
    expect(wrapper.get('h2').text()).toBe('Modifier la contribution')
    expect(valueOf(wrapper, '#admin-contribution-locale')).toBe('fr')
  })

  it('ne présente aucune violation d\'accessibilité avec le brouillon rendu', async () => {
    const wrapper = await mountPage()
    await startEditing(wrapper)
    await translateButton(wrapper).trigger('click')
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })
})
