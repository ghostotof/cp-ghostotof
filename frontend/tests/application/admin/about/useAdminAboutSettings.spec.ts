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
  describe('une réponse d\'enregistrement ne remplace que les réglages de la langue demandée en dernier (#748, R11)', () => {
    const INPUT = {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    }
    const SETTINGS_EN: AdminAboutSettings = { ...SETTINGS, locale: 'en', siteEyebrow: 'About this site' }

    it("la réponse d'un enregistrement FR arrivée après la lecture EN laisse les réglages EN, et rend vrai", async () => {
      let answerSave!: (settings: AdminAboutSettings) => void
      const repository = createStubRepository({
        get: vi.fn(async (locale: string) => ('en' === locale ? SETTINGS_EN : SETTINGS)),
        update: vi.fn(() => new Promise<AdminAboutSettings>((resolve) => (answerSave = resolve))),
      })
      const composable = mountWithComposable(repository)
      await composable.load('fr')

      const saving = composable.save('fr', INPUT)
      await composable.load('en')
      answerSave({ ...SETTINGS, siteEyebrow: 'Nouveau titre' })

      // Vrai : le serveur a enregistré le français. Les réglages affichés restent ceux de l'anglais.
      expect(await saving).toBe(true)
      expect(composable.settings.value).toEqual(SETTINGS_EN)
    })

    it("la réponse d'un enregistrement FR arrivée pendant la lecture EN ne remplit pas les réglages vidés", async () => {
      let answerSave!: (settings: AdminAboutSettings) => void
      let answerRead!: (settings: AdminAboutSettings) => void
      const repository = createStubRepository({
        get: vi.fn((locale: string) =>
          'en' === locale ? new Promise<AdminAboutSettings>((resolve) => (answerRead = resolve)) : Promise.resolve(SETTINGS),
        ),
        update: vi.fn(() => new Promise<AdminAboutSettings>((resolve) => (answerSave = resolve))),
      })
      const composable = mountWithComposable(repository)
      await composable.load('fr')

      const saving = composable.save('fr', INPUT)
      const reading = composable.load('en')
      answerSave({ ...SETTINGS, siteEyebrow: 'Nouveau titre' })
      await saving

      // Ni le français sous l'en-tête anglais, ni « chargé » : la lecture EN n'a pas répondu.
      expect(composable.settings.value).toBeNull()
      expect(composable.isLoaded.value).toBe(false)

      answerRead(SETTINGS_EN)
      await reading
      expect(composable.settings.value).toEqual(SETTINGS_EN)
    })
  })
})
