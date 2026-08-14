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

async function mountPage(
  settingsRepository: AdminAboutSettingsRepository = createStubSettingsRepository(),
  siteCardRepository: AdminAboutSiteCardRepository = createStubSiteCardRepository(),
  meCardRepository: AdminAboutMeCardRepository = createStubMeCardRepository(),
) {
  const wrapper = mount(AdminAboutPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_ABOUT_SETTINGS_REPOSITORY as symbol]: settingsRepository,
        [ADMIN_ABOUT_SITE_CARD_REPOSITORY as symbol]: siteCardRepository,
        [ADMIN_ABOUT_ME_CARD_REPOSITORY as symbol]: meCardRepository,
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

    const editButtons = wrapper.findAll('button.btn-outline-light')
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
})
