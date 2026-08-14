import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_ABOUT_SETTINGS_REPOSITORY, useAdminAboutSettings } from '../../../../src/application/admin/about/useAdminAboutSettings'
import type { AdminAboutSettingsRepository } from '../../../../src/domain/admin/about/repositories/AdminAboutSettingsRepository'
import type { AdminAboutSettings } from '../../../../src/domain/admin/about/entities/AdminAboutSettings'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'

const SETTINGS: AdminAboutSettings = {
  locale: 'fr',
  siteEyebrow: 'À propos de ce site',
  meEyebrow: 'À propos de moi',
  technicalSubtitle: 'Techniquement',
  personalSubtitle: 'Humainement',
  hobbiesSubtitle: 'En dehors du travail',
}

function createStubRepository(overrides: Partial<AdminAboutSettingsRepository> = {}): AdminAboutSettingsRepository {
  return {
    get: vi.fn(async () => SETTINGS),
    update: vi.fn(async () => SETTINGS),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminAboutSettingsRepository) {
  let captured: ReturnType<typeof useAdminAboutSettings> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminAboutSettings()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_ABOUT_SETTINGS_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminAboutSettings() did not run during mount')
  }

  return captured
}

describe('useAdminAboutSettings', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminAboutSettings()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminAboutSettingsRepository/)
  })

  it('load(locale) charge les réglages', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    expect(composable.settings.value).toBeNull()
    await composable.load('fr')

    expect(repository.get).toHaveBeenCalledWith('fr')
    expect(composable.settings.value).toEqual(SETTINGS)
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ get: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)

    await composable.load('fr')

    expect(composable.hasError.value).toBe(true)
  })

  it('save() appelle update() et met à jour settings', async () => {
    const updated = { ...SETTINGS, siteEyebrow: 'Nouveau titre' }
    const repository = createStubRepository({ update: vi.fn(async () => updated) })
    const composable = mountWithComposable(repository)

    await composable.save('fr', {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    })

    expect(repository.update).toHaveBeenCalledWith('fr', {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    })
    expect(composable.settings.value).toEqual(updated)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('save() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({ update: vi.fn(async () => Promise.reject(new AdminAboutError('validation', 'Invalide'))) })
    const composable = mountWithComposable(repository)

    await composable.save('fr', {
      siteEyebrow: '',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    })

    expect(composable.errorMessage.value?.reason).toBe('validation')
  })
})
