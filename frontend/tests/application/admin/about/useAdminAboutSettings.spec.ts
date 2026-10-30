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
    // Une écriture ne pose sa réponse que sous la clé demandée (R20) : la page a toujours lu avant d'enregistrer.
    await composable.load('fr')

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
    await composable.load('fr')

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

  describe('la clé courante vient de useAdminLoad (#748, R20)', () => {
    const INPUT = {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    }
    const SAVED_FR: AdminAboutSettings = { ...SETTINGS, siteEyebrow: 'Nouveau titre' }

    it("FR enregistré lentement, EN, puis FR : la lecture FR servie avant la réponse du PUT ne repose pas l'ancien FR", async () => {
      let answerSave!: (settings: AdminAboutSettings) => void
      let answerRead!: (settings: AdminAboutSettings) => void
      let frenchReads = 0
      const repository = createStubRepository({
        get: vi.fn((locale: string) => {
          if ('en' === locale) return Promise.resolve({ ...SETTINGS, locale: 'en' as const })
          frenchReads += 1

          // La première lecture FR est celle du chargement ; la seconde, celle du retour en FR, reste en vol.
          return 1 === frenchReads ? Promise.resolve(SETTINGS) : new Promise<AdminAboutSettings>((resolve) => (answerRead = resolve))
        }),
        update: vi.fn(() => new Promise<AdminAboutSettings>((resolve) => (answerSave = resolve))),
      })
      const composable = mountWithComposable(repository)
      await composable.load('fr')

      const saving = composable.save('fr', INPUT)
      await composable.load('en')
      const reading = composable.load('fr')

      // Le PUT répond d'abord avec les valeurs neuves ; le GET parti avant son COMMIT répond ensuite avec les anciennes.
      answerSave(SAVED_FR)
      expect(await saving).toBe(true)
      answerRead(SETTINGS)
      await reading

      expect(composable.settings.value).toEqual(SAVED_FR)
      expect(composable.isLoaded.value).toBe(true)
      expect(composable.isLoading.value).toBe(false)
    })

    it("un échec FR arrivé après le passage en EN n'est pas montré sous l'EN, il l'est au retour en FR", async () => {
      let failSave!: (reason: unknown) => void
      const repository = createStubRepository({
        update: vi.fn(() => new Promise<AdminAboutSettings>((_, reject) => (failSave = reject))),
      })
      const composable = mountWithComposable(repository)
      await composable.load('fr')

      const saving = composable.save('fr', INPUT)
      await composable.load('en')
      failSave(new AdminAboutError('validation', 'Invalide'))

      expect(await saving).toBe(false)
      expect(composable.errorMessage.value).toBeNull()

      await composable.load('fr')
      expect(composable.errorMessage.value?.reason).toBe('validation')
    })

    it("l'erreur d'un enregistrement est effacée au prochain enregistrement", async () => {
      const update = vi
        .fn<AdminAboutSettingsRepository['update']>()
        .mockRejectedValueOnce(new AdminAboutError('validation', 'Invalide'))
        .mockResolvedValue(SAVED_FR)
      const composable = mountWithComposable(createStubRepository({ update }))
      await composable.load('fr')

      await composable.save('fr', INPUT)
      expect(composable.errorMessage.value?.reason).toBe('validation')

      expect(await composable.save('fr', INPUT)).toBe(true)
      expect(composable.errorMessage.value).toBeNull()
    })
  })

  describe('une erreur par langue, jusqu\'au prochain enregistrement de cette langue (#748, R31)', () => {
    const INPUT = {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    }

    it("un échec FR, puis un enregistrement EN réussi : l'erreur FR est encore là au retour en FR", async () => {
      const update = vi
        .fn<AdminAboutSettingsRepository['update']>()
        .mockRejectedValueOnce(new AdminAboutError('validation', 'Invalide'))
        .mockResolvedValueOnce({ ...SETTINGS, locale: 'en' })
      const composable = mountWithComposable(createStubRepository({ update }))
      await composable.load('fr')
      await composable.save('fr', INPUT)
      expect(composable.errorMessage.value?.reason).toBe('validation')

      await composable.load('en')
      expect(await composable.save('en', INPUT)).toBe(true)
      expect(composable.errorMessage.value).toBeNull()

      // L'enregistrement EN n'a rien réglé du FR : son erreur revient avec sa langue.
      await composable.load('fr')
      expect(composable.errorMessage.value?.reason).toBe('validation')
    })

    it('un échec FR puis un échec EN : chacun reste sous sa langue', async () => {
      const update = vi
        .fn<AdminAboutSettingsRepository['update']>()
        .mockRejectedValueOnce(new AdminAboutError('validation', 'Invalide'))
        .mockRejectedValueOnce(new AdminAboutError('unknown', 'Inconnue'))
      const composable = mountWithComposable(createStubRepository({ update }))
      await composable.load('fr')
      await composable.save('fr', INPUT)

      await composable.load('en')
      await composable.save('en', INPUT)
      expect(composable.errorMessage.value?.reason).toBe('unknown')

      await composable.load('fr')
      expect(composable.errorMessage.value?.reason).toBe('validation')
    })

    it("un enregistrement FR réussi efface l'erreur FR seulement", async () => {
      const update = vi
        .fn<AdminAboutSettingsRepository['update']>()
        .mockRejectedValueOnce(new AdminAboutError('validation', 'Invalide'))
        .mockRejectedValueOnce(new AdminAboutError('unknown', 'Inconnue'))
        .mockResolvedValueOnce(SETTINGS)
      const composable = mountWithComposable(createStubRepository({ update }))
      await composable.load('fr')
      await composable.save('fr', INPUT)
      await composable.load('en')
      await composable.save('en', INPUT)

      await composable.load('fr')
      expect(await composable.save('fr', INPUT)).toBe(true)
      expect(composable.errorMessage.value).toBeNull()

      // L'erreur EN, elle, attend toujours le prochain enregistrement EN.
      await composable.load('en')
      expect(composable.errorMessage.value?.reason).toBe('unknown')
    })
  })

  describe('un seul enregistrement en vol par langue (#748, R50, décision 3)', () => {
    const INPUT = {
      siteEyebrow: 'Nouveau titre',
      meEyebrow: SETTINGS.meEyebrow,
      technicalSubtitle: SETTINGS.technicalSubtitle,
      personalSubtitle: SETTINGS.personalSubtitle,
      hobbiesSubtitle: SETTINGS.hobbiesSubtitle,
    }

    /** Un `update` dont chaque appel reste en vol : `answer(n, …)` / `fail(n, …)` le règle, dans l'ordre voulu. */
    function pendingUpdates() {
      const settlers: Array<{ resolve: (settings: AdminAboutSettings) => void; reject: (reason: unknown) => void }> = []
      const update = vi.fn<AdminAboutSettingsRepository['update']>(
        () => new Promise<AdminAboutSettings>((resolve, reject) => settlers.push({ resolve, reject })),
      )

      return {
        update,
        answer: (index: number, settings: AdminAboutSettings) => settlers[index]?.resolve(settings),
        fail: (index: number, reason: unknown) => settlers[index]?.reject(reason),
      }
    }

    it("un second enregistrement FR pendant le premier : aucune requête, rend false, l'erreur FR intacte", async () => {
      const pending = pendingUpdates()
      const composable = mountWithComposable(createStubRepository({ update: pending.update }))
      await composable.load('fr')

      // Une erreur FR laissée par un enregistrement antérieur : le refus du second ne l'efface pas (aucune requête).
      const failed = composable.save('fr', INPUT)
      pending.fail(0, new AdminAboutError('validation', 'Invalide'))
      await failed
      expect(composable.errorMessage.value?.reason).toBe('validation')

      const first = composable.save('fr', INPUT)
      expect(composable.errorMessage.value).toBeNull()
      const second = await composable.save('fr', INPUT)

      expect(second).toBe(false)
      expect(pending.update).toHaveBeenCalledTimes(2)
      expect(composable.errorMessage.value).toBeNull()

      pending.answer(1, SETTINGS)
      expect(await first).toBe(true)
    })

    it('un enregistrement EN part pendant un FR en vol', async () => {
      const pending = pendingUpdates()
      const composable = mountWithComposable(createStubRepository({ update: pending.update }))
      await composable.load('fr')

      const french = composable.save('fr', INPUT)
      const english = composable.save('en', INPUT)

      expect(pending.update).toHaveBeenCalledTimes(2)
      expect(pending.update).toHaveBeenNthCalledWith(1, 'fr', INPUT)
      expect(pending.update).toHaveBeenNthCalledWith(2, 'en', INPUT)

      pending.answer(0, SETTINGS)
      pending.answer(1, { ...SETTINGS, locale: 'en' })
      expect(await french).toBe(true)
      expect(await english).toBe(true)
    })

    it('isSaving suit la langue montrée : vrai en FR pendant le FR, faux en EN', async () => {
      const pending = pendingUpdates()
      const composable = mountWithComposable(
        createStubRepository({ update: pending.update, get: vi.fn(async (locale) => ({ ...SETTINGS, locale })) }),
      )
      await composable.load('fr')
      expect(composable.isSaving.value).toBe(false)

      const french = composable.save('fr', INPUT)
      expect(composable.isSaving.value).toBe(true)

      await composable.load('en')
      expect(composable.isSaving.value).toBe(false)

      await composable.load('fr')
      expect(composable.isSaving.value).toBe(true)

      pending.answer(0, SETTINGS)
      await french
      expect(composable.isSaving.value).toBe(false)
    })

    it.each([
      ['réussie', 'answer'],
      ['en échec', 'fail'],
    ] as const)('après la réponse du FR, %s, un nouvel enregistrement FR part', async (_label, outcome) => {
      const pending = pendingUpdates()
      const composable = mountWithComposable(createStubRepository({ update: pending.update }))
      await composable.load('fr')

      const first = composable.save('fr', INPUT)
      if ('answer' === outcome) pending.answer(0, SETTINGS)
      else pending.fail(0, new AdminAboutError('validation', 'Invalide'))
      await first

      const second = composable.save('fr', INPUT)
      expect(pending.update).toHaveBeenCalledTimes(2)
      pending.answer(1, SETTINGS)
      expect(await second).toBe(true)
    })
  })
})
