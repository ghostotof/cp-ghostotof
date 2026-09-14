import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminAboutPage from '../../../../src/presentation/pages/admin/AdminAboutPage.vue'
import { ADMIN_ABOUT_SETTINGS_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutSettings'
import { ADMIN_ABOUT_SITE_CARD_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutSiteCards'
import { ADMIN_ABOUT_ME_CARD_REPOSITORY } from '../../../../src/application/admin/about/useAdminAboutMeCards'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminAboutSettingsRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutSettingsRepository'
import type { AdminAboutSiteCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutSiteCardRepository'
import type { AdminAboutMeCardRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutMeCardRepository'
import type { AdminAboutSettings } from '../../../../src/domain/admin/about/entities/AdminAboutSettings'
import type { AdminAboutSiteCard } from '../../../../src/domain/admin/about/entities/AdminAboutSiteCard'
import type { AdminAboutMeCard } from '../../../../src/domain/admin/about/entities/AdminAboutMeCard'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
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

const SITE_CARD: AdminAboutSiteCard = { id: 1, locale: 'fr', title: 'Architecture', description: 'Description architecture', iconKey: 'layers', position: 0 }

const ME_CARD: AdminAboutMeCard = { id: 1, locale: 'fr', category: 'technical', title: 'Dev senior', description: 'Description dev', iconKey: 'code', position: 0 }

function createStubSettingsRepository(overrides: Partial<AdminAboutSettingsRepository> = {}): AdminAboutSettingsRepository {
  return {
    get: vi.fn(async () => SETTINGS),
    update: vi.fn(async () => SETTINGS),
    ...overrides,
  }
}

function createStubSiteCardRepository(overrides: Partial<AdminAboutSiteCardRepository> = {}): AdminAboutSiteCardRepository {
  return {
    list: vi.fn(async () => [SITE_CARD]),
    create: vi.fn(async () => SITE_CARD),
    update: vi.fn(async () => SITE_CARD),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createStubMeCardRepository(overrides: Partial<AdminAboutMeCardRepository> = {}): AdminAboutMeCardRepository {
  return {
    list: vi.fn(async () => [ME_CARD]),
    create: vi.fn(async () => ME_CARD),
    update: vi.fn(async () => ME_CARD),
    remove: vi.fn(async () => undefined),
    ...overrides,
  }
}

const TRANSLATED_SETTINGS = {
  siteEyebrow: 'About this site',
  meEyebrow: 'About me',
  technicalSubtitle: 'Technically',
  personalSubtitle: 'Personally',
  hobbiesSubtitle: 'Outside work',
}

function createTranslationRepository(fields: Record<string, string>, overrides: Partial<AdminTranslationRepository> = {}): AdminTranslationRepository {
  return {
    translate: vi.fn(async () => ({ sourceLocale: 'fr' as const, targetLocale: 'en' as const, fields })),
    ...overrides,
  }
}

async function mountPage(
  settingsRepository: AdminAboutSettingsRepository = createStubSettingsRepository(),
  siteCardRepository: AdminAboutSiteCardRepository = createStubSiteCardRepository(),
  meCardRepository: AdminAboutMeCardRepository = createStubMeCardRepository(),
  translationRepository: AdminTranslationRepository = createTranslationRepository(TRANSLATED_SETTINGS),
) {
  const wrapper = mount(AdminAboutPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_ABOUT_SETTINGS_REPOSITORY as symbol]: settingsRepository,
        [ADMIN_ABOUT_SITE_CARD_REPOSITORY as symbol]: siteCardRepository,
        [ADMIN_ABOUT_ME_CARD_REPOSITORY as symbol]: meCardRepository,
        [ADMIN_TRANSLATION_REPOSITORY as symbol]: translationRepository,
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('AdminAboutPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('charge et pré-remplit les réglages, les cartes du site et les cartes à propos de moi (locale fr par défaut)', async () => {
    const settingsRepository = createStubSettingsRepository()
    const siteCardRepository = createStubSiteCardRepository()
    const meCardRepository = createStubMeCardRepository()
    const wrapper = await mountPage(settingsRepository, siteCardRepository, meCardRepository)

    expect(settingsRepository.get).toHaveBeenCalledWith('fr')
    expect(siteCardRepository.list).toHaveBeenCalledWith('fr')
    expect(meCardRepository.list).toHaveBeenCalledWith('fr')

    expect((wrapper.get('#admin-about-site-eyebrow').element as HTMLInputElement).value).toBe('À propos de ce site')
    expect(wrapper.text()).toContain('Architecture')
    expect(wrapper.text()).toContain('Dev senior')
  })

  it('affiche un message si le chargement des réglages échoue', async () => {
    const settingsRepository = createStubSettingsRepository({ get: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(settingsRepository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('recharge réglages/cartes filtrés quand la locale sélectionnée change', async () => {
    const settingsRepository = createStubSettingsRepository()
    const siteCardRepository = createStubSiteCardRepository()
    const meCardRepository = createStubMeCardRepository()
    const wrapper = await mountPage(settingsRepository, siteCardRepository, meCardRepository)
    vi.mocked(settingsRepository.get).mockClear()
    vi.mocked(siteCardRepository.list).mockClear()
    vi.mocked(meCardRepository.list).mockClear()

    await wrapper.get('#admin-about-locale').setValue('en')
    await flushPromises()

    expect(settingsRepository.get).toHaveBeenCalledWith('en')
    expect(siteCardRepository.list).toHaveBeenCalledWith('en')
    expect(meCardRepository.list).toHaveBeenCalledWith('en')
  })

  it('enregistre les réglages via son formulaire', async () => {
    const settingsRepository = createStubSettingsRepository()
    const wrapper = await mountPage(settingsRepository)

    await wrapper.get('#admin-about-site-eyebrow').setValue('Nouveau titre')
    await wrapper.findAll('form')[0]?.trigger('submit.prevent')
    await flushPromises()

    expect(settingsRepository.update).toHaveBeenCalledWith('fr', {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: 'À propos de moi',
      technicalSubtitle: 'Techniquement',
      personalSubtitle: 'Humainement',
      hobbiesSubtitle: 'En dehors du travail',
    })
  })

  it('crée une carte de site via son formulaire puis réinitialise les champs', async () => {
    const siteCardRepository = createStubSiteCardRepository()
    const wrapper = await mountPage(undefined, siteCardRepository)

    await wrapper.get('#admin-about-site-card-title').setValue('Stack technique')
    await wrapper.get('#admin-about-site-card-description').setValue('Description stack')
    await wrapper.get('#admin-about-site-card-icon-key').setValue('server')
    await wrapper.findAll('form')[1]?.trigger('submit.prevent')
    await flushPromises()

    expect(siteCardRepository.create).toHaveBeenCalledWith({
      locale: 'fr',
      title: 'Stack technique',
      description: 'Description stack',
      iconKey: 'server',
      position: 0,
    })
    expect((wrapper.get('#admin-about-site-card-title').element as HTMLInputElement).value).toBe('')
  })

  it("préremplit le formulaire de carte de site à l'édition puis appelle update()", async () => {
    const siteCardRepository = createStubSiteCardRepository()
    const wrapper = await mountPage(undefined, siteCardRepository)

    // Par libellé : le bouton de traduction partage `btn-outline-light` et précède « Modifier ».
    const editButtons = wrapper.findAll('button').filter((button) => 'Modifier' === button.text())
    await editButtons[0]?.trigger('click')

    expect((wrapper.get('#admin-about-site-card-title').element as HTMLInputElement).value).toBe('Architecture')

    await wrapper.get('#admin-about-site-card-title').setValue('Architecture (mise à jour)')
    await wrapper.findAll('form')[1]?.trigger('submit.prevent')
    await flushPromises()

    expect(siteCardRepository.update).toHaveBeenCalledWith(1, {
      locale: 'fr',
      title: 'Architecture (mise à jour)',
      description: 'Description architecture',
      iconKey: 'layers',
      position: 0,
    })
  })

  it('crée une carte "à propos de moi" via son formulaire (avec catégorie) puis réinitialise les champs', async () => {
    const meCardRepository = createStubMeCardRepository()
    const wrapper = await mountPage(undefined, undefined, meCardRepository)

    await wrapper.get('#admin-about-me-card-category').setValue('hobby')
    await wrapper.get('#admin-about-me-card-title').setValue('Musique')
    await wrapper.get('#admin-about-me-card-description').setValue('Description musique')
    await wrapper.findAll('form')[2]?.trigger('submit.prevent')
    await flushPromises()

    expect(meCardRepository.create).toHaveBeenCalledWith({
      locale: 'fr',
      category: 'hobby',
      title: 'Musique',
      description: 'Description musique',
      iconKey: null,
      position: 0,
    })
    expect((wrapper.get('#admin-about-me-card-title').element as HTMLInputElement).value).toBe('')
  })

  it('supprime une carte de site après confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const siteCardRepository = createStubSiteCardRepository()
    const wrapper = await mountPage(undefined, siteCardRepository)

    const deleteButtons = wrapper.findAll('button.btn-outline-danger')
    await deleteButtons[0]?.trigger('click')
    await flushPromises()

    expect(siteCardRepository.remove).toHaveBeenCalledWith(1)
  })

  it("affiche un message traduit si une mutation de carte échoue", async () => {
    const siteCardRepository = createStubSiteCardRepository({
      create: vi.fn(async () => Promise.reject(new AdminAboutError('validation', 'Invalide'))),
    })
    const wrapper = await mountPage(undefined, siteCardRepository)

    await wrapper.get('#admin-about-site-card-title').setValue('')
    await wrapper.get('#admin-about-site-card-description').setValue('x')
    await wrapper.findAll('form')[1]?.trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Le formulaire contient des erreurs. Vérifiez les champs.')
  })

  describe('assistant de traduction', () => {
    type Wrapper = Awaited<ReturnType<typeof mountPage>>

    function translateButtons(wrapper: Wrapper) {
      return wrapper.findAll('button').filter((candidate) => candidate.text().includes('Proposer la version'))
    }

    function localeValue(wrapper: Wrapper): string {
      return (wrapper.get('#admin-about-locale').element as HTMLSelectElement).value
    }

    function inputValue(wrapper: Wrapper, id: string): string {
      return (wrapper.get(id).element as HTMLInputElement | HTMLTextAreaElement).value
    }

    it('propose un bouton par formulaire : réglages, cartes du site, cartes à propos de moi', async () => {
      const wrapper = await mountPage()

      expect(translateButtons(wrapper)).toHaveLength(3)
    })

    it('réglages : envoie les cinq libellés, bascule la page en EN et applique le brouillon APRÈS le rechargement des réglages', async () => {
      const settingsRepository = createStubSettingsRepository()
      const translation = createTranslationRepository(TRANSLATED_SETTINGS)
      const wrapper = await mountPage(settingsRepository, undefined, undefined, translation)
      vi.mocked(settingsRepository.get).mockClear()

      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', {
        siteEyebrow: 'À propos de ce site',
        meEyebrow: 'À propos de moi',
        technicalSubtitle: 'Techniquement',
        personalSubtitle: 'Humainement',
        hobbiesSubtitle: 'En dehors du travail',
      })
      expect(localeValue(wrapper)).toBe('en')
      expect(settingsRepository.get).toHaveBeenCalledWith('en')
      // Le rechargement des réglages EN (le stub rend les valeurs FR) ne doit
      // pas écraser le brouillon : celui-ci est appliqué une fois la locale chargée.
      expect(inputValue(wrapper, '#admin-about-site-eyebrow')).toBe('About this site')
      expect(inputValue(wrapper, '#admin-about-hobbies-subtitle')).toBe('Outside work')
      expect(wrapper.get('[role="status"]').text()).toContain('Brouillon généré par IA')
      expect(settingsRepository.update).not.toHaveBeenCalled()
    })

    it('réglages : Enregistrer sauvegarde alors le brouillon dans la locale cible', async () => {
      const settingsRepository = createStubSettingsRepository()
      const wrapper = await mountPage(settingsRepository)
      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      await wrapper.findAll('form')[0]?.trigger('submit.prevent')
      await flushPromises()

      expect(settingsRepository.update).toHaveBeenCalledWith('en', TRANSLATED_SETTINGS)
    })

    it('carte du site : envoie titre et description, bascule en création EN, icône et position conservées', async () => {
      const siteCardRepository = createStubSiteCardRepository()
      const translation = createTranslationRepository({ title: 'Architecture', description: 'Architecture description' })
      const wrapper = await mountPage(undefined, siteCardRepository, undefined, translation)
      const editButtons = wrapper.findAll('button').filter((button) => 'Modifier' === button.text())
      await editButtons[0]?.trigger('click')
      vi.mocked(siteCardRepository.list).mockClear()

      await translateButtons(wrapper)[1]?.trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', { title: 'Architecture', description: 'Description architecture' })
      expect(localeValue(wrapper)).toBe('en')
      expect(siteCardRepository.list).toHaveBeenCalledWith('en')
      expect(inputValue(wrapper, '#admin-about-site-card-description')).toBe('Architecture description')
      expect(inputValue(wrapper, '#admin-about-site-card-icon-key')).toBe('layers')
      expect(siteCardRepository.create).not.toHaveBeenCalled()
      expect(siteCardRepository.update).not.toHaveBeenCalled()

      await wrapper.findAll('form')[1]?.trigger('submit.prevent')
      await flushPromises()

      expect(siteCardRepository.create).toHaveBeenCalledWith({ locale: 'en', title: 'Architecture', description: 'Architecture description', iconKey: 'layers', position: 0 })
    })

    it('carte à propos de moi : envoie titre et description, la catégorie est conservée', async () => {
      const meCardRepository = createStubMeCardRepository()
      const translation = createTranslationRepository({ title: 'Senior developer', description: 'Developer description' })
      const wrapper = await mountPage(undefined, undefined, meCardRepository, translation)
      const editButtons = wrapper.findAll('button').filter((button) => 'Modifier' === button.text())
      await editButtons[1]?.trigger('click')

      await translateButtons(wrapper)[2]?.trigger('click')
      await flushPromises()

      expect(translation.translate).toHaveBeenCalledWith('fr', 'en', { title: 'Dev senior', description: 'Description dev' })
      expect(localeValue(wrapper)).toBe('en')
      expect(inputValue(wrapper, '#admin-about-me-card-title')).toBe('Senior developer')
      expect((wrapper.get('#admin-about-me-card-category').element as HTMLSelectElement).value).toBe('technical')

      await wrapper.findAll('form')[2]?.trigger('submit.prevent')
      await flushPromises()

      expect(meCardRepository.create).toHaveBeenCalledWith({ locale: 'en', category: 'technical', title: 'Senior developer', description: 'Developer description', iconKey: 'code', position: 0 })
    })

    it('en échec, la page reste en FR et les réglages affichés sont inchangés', async () => {
      const translation = createTranslationRepository({}, {
        translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota.') }),
      })
      const wrapper = await mountPage(undefined, undefined, undefined, translation)

      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      expect(wrapper.get('[role="alert"]').text()).toContain('Quota horaire')
      expect(localeValue(wrapper)).toBe('fr')
      expect(inputValue(wrapper, '#admin-about-site-eyebrow')).toBe('À propos de ce site')
    })

    it("ne présente aucune violation d'accessibilité avec un brouillon rendu", async () => {
      const wrapper = await mountPage()
      await translateButtons(wrapper)[0]?.trigger('click')
      await flushPromises()

      await expectNoAccessibilityViolation(wrapper)
    })
  })
})
