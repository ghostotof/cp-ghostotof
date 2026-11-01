import { describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import {
  ADMIN_TRANSLATION_REPOSITORY,
  useAdminTranslation,
} from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import type { FormIdentity } from '../../../../src/application/admin/translation/FormIdentity'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import { capturedProposal } from '../../../support/capturedProposal'

const FIELDS = { title: 'Panne', impact: 'Formulaire en 500.' }
const PROPOSAL: TranslationProposal = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage', impact: 'Form down.' } }

/** Une identité de formulaire typée, que seule l'entrée distingue. */
function identityOf(entry: string): FormIdentity {
  return { entry, locale: 'fr', group: null, generation: 0 }
}

/** Monte le composable dans un hôte qui fournit le dépôt : le composable et le montage (que `unmount()` arrête). */
function mountHost(repository: AdminTranslationRepository, options?: Parameters<typeof useAdminTranslation>[0]) {
  let captured: ReturnType<typeof useAdminTranslation> | undefined
  const Host = defineComponent({
    setup() {
      captured = useAdminTranslation(options)
      return () => h('div')
    },
  })
  const wrapper = mount(Host, { global: { provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")
  return { composable: captured, wrapper }
}

function mountWithComposable(repository: AdminTranslationRepository, options?: Parameters<typeof useAdminTranslation>[0]) {
  return mountHost(repository, options).composable
}

/** La proposition que `translate` remet à `accept` : celle-ci, ou `null` quand `accept` n'a pas été appelée. */
function proposalOf(composable: ReturnType<typeof useAdminTranslation>): Promise<TranslationProposal | null> {
  return capturedProposal((accept) => composable.translate('fr', 'en', FIELDS, accept))
}

describe('useAdminTranslation', () => {
  it('rend la proposition et passe par isTranslating pendant l\'appel', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const repository: AdminTranslationRepository = {
      translate: vi.fn(() => new Promise<TranslationProposal>((r) => { resolve = r })),
    }
    const composable = mountWithComposable(repository)

    const pending = proposalOf(composable)
    expect(composable.isTranslating.value).toBe(true)

    resolve(PROPOSAL)
    const proposal = await pending

    expect(proposal).toEqual(PROPOSAL)
    expect(repository.translate).toHaveBeenCalledWith('fr', 'en', FIELDS)
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  // R172 (C14-8) : `accept` est appelée dans le point de contrôle qui juge la réponse courante, pas une micro-tâche plus tard.
  describe('accept (#748, R172)', () => {
    it("est appelée avec la proposition avant tout rappel enregistré sur la réponse après l'appel, et translate rend vrai", async () => {
      const answered = Promise.resolve(PROPOSAL)
      const composable = mountWithComposable({ translate: vi.fn(() => answered) })
      const events: string[] = []

      const pending = composable.translate('fr', 'en', FIELDS, () => { events.push('accept') })
      void answered.then(() => { events.push('geste de la page') })

      expect(await pending).toBe(true)
      expect(events).toEqual(['accept', 'geste de la page'])
    })

    it("n'est pas appelée sur un refus, et translate rend faux sans appeler le dépôt (#748, R172)", async () => {
      const accept = vi.fn()
      const translate = vi.fn<AdminTranslationRepository['translate']>()
      const composable = mountWithComposable({ translate }, { isRefused: () => true })

      expect(await composable.translate('fr', 'en', FIELDS, accept)).toBe(false)

      expect(translate).not.toHaveBeenCalled()
      expect(accept).not.toHaveBeenCalled()
    })

    it("n'est pas appelée sur un échec, et translate rend faux avec la raison exposée (#748, R172)", async () => {
      const accept = vi.fn()
      const translate = vi.fn<AdminTranslationRepository['translate']>()
        .mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
      const composable = mountWithComposable({ translate })

      expect(await composable.translate('fr', 'en', FIELDS, accept)).toBe(false)

      expect(composable.errorReason.value).toBe('unavailable')
      expect(accept).not.toHaveBeenCalled()
    })

    it("n'est pas appelée sur une réponse périmée, et translate rend faux (#748, R172)", async () => {
      const accept = vi.fn()
      let resolve!: (proposal: TranslationProposal) => void
      const translate = vi.fn<AdminTranslationRepository['translate']>()
        .mockImplementationOnce(() => new Promise<TranslationProposal>((r) => { resolve = r }))
      const composable = mountWithComposable({ translate })

      const stale = composable.translate('fr', 'en', FIELDS, accept)
      composable.expire()
      resolve(PROPOSAL)

      expect(await stale).toBe(false)
      expect(accept).not.toHaveBeenCalled()
    })

    it("une exception d'accept remonte à l'appelant : aucune raison d'échec posée, plus rien en vol (#748, R172)", async () => {
      const composable = mountWithComposable({ translate: vi.fn(async () => PROPOSAL) })

      await expect(
        composable.translate('fr', 'en', FIELDS, () => { throw new Error('pose refusée') }),
      ).rejects.toThrow('pose refusée')

      expect(composable.errorReason.value).toBeNull()
      expect(composable.isTranslating.value).toBe(false)
      expect(composable.isExpiredCallPending.value).toBe(false)
    })
  })

  it("un échec du repository : translate rend faux sans lever, accept n'est pas appelée et la raison est exposée", async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota atteint.') }),
    }
    const composable = mountWithComposable(repository)

    const proposal = await proposalOf(composable)

    expect(proposal).toBeNull()
    expect(composable.errorReason.value).toBe('rate-limited')
    expect(composable.isTranslating.value).toBe(false)
  })

  it('classe une erreur inattendue en « unknown »', async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new TypeError('Failed to fetch') }),
    }
    const composable = mountWithComposable(repository)

    await proposalOf(composable)

    expect(composable.errorReason.value).toBe('unknown')
  })

  it('efface la raison de l\'erreur précédente au début de l\'appel suivant', async () => {
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    await proposalOf(composable)
    expect(composable.errorReason.value).toBe('unavailable')

    await proposalOf(composable)
    expect(composable.errorReason.value).toBeNull()
  })

  it('une traduction en vol refuse la suivante sans requête (#748, R114)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const composable = mountWithComposable({ translate })

    const first = proposalOf(composable)
    const second = await proposalOf(composable)

    expect(second).toBeNull()
    expect(translate).toHaveBeenCalledTimes(1)
    expect(composable.isTranslating.value).toBe(true)

    resolve(PROPOSAL)
    expect(await first).toEqual(PROPOSAL)
    expect(composable.isTranslating.value).toBe(false)
  })

  // Le refus suit le prédicat de l'appelant dans les deux sens : la valeur exposée et `translate()` lisent la même source (#748,
  // R114, R137). Le prédicat est lu par un calculé, que seule une source réactive (une `ref`) fait relire.
  // Une ligne par sens du changement, les valeurs écrites en toutes lettres : ni boucle ni condition dans le corps du cas.
  it.each([
    { sens: 'vrai puis faux', before: true, after: false, proposalBefore: null, proposalAfter: PROPOSAL, callsBefore: 0, callsAfter: 1 },
    { sens: 'faux puis vrai', before: false, after: true, proposalBefore: PROPOSAL, proposalAfter: null, callsBefore: 1, callsAfter: 1 },
  ])(
    'isRefused $sens : la valeur exposée et translate() suivent la source de la page (#748, R114, R137)',
    async ({ before, after, proposalBefore, proposalAfter, callsBefore, callsAfter }) => {
      const translate = vi.fn<AdminTranslationRepository['translate']>().mockResolvedValue(PROPOSAL)
      const refused = ref(before)
      const composable = mountWithComposable({ translate }, { isRefused: () => refused.value })

      expect(composable.isRefused.value).toBe(before)
      expect(await proposalOf(composable)).toEqual(proposalBefore)
      expect(translate).toHaveBeenCalledTimes(callsBefore)

      refused.value = after

      expect(composable.isRefused.value).toBe(after)
      expect(await proposalOf(composable)).toEqual(proposalAfter)
      expect(translate).toHaveBeenCalledTimes(callsAfter)
      expect(composable.isTranslating.value).toBe(false)
      expect(composable.errorReason.value).toBeNull()
    },
  )

  it("isRefused est faux quand la page n'en dit rien (#748, R137)", () => {
    const composable = mountWithComposable({ translate: vi.fn() })

    expect(composable.isRefused.value).toBe(false)
  })

  it("expire() périme la traduction en vol : sa réponse n'appelle pas accept (#748, R114)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const composable = mountWithComposable({ translate })

    const pending = proposalOf(composable)
    composable.expire()
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it("expire() : l'échec d'une traduction périmée ne pose aucune raison, et la suivante part ensuite (#748, R114, R125)", async () => {
    let reject!: (error: unknown) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockImplementationOnce(() => new Promise<TranslationProposal>((_, r) => { reject = r }))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    const stale = proposalOf(composable)
    composable.expire()
    reject(new AdminTranslationError('unavailable', 'Indisponible.'))

    expect(await stale).toBeNull()
    expect(await proposalOf(composable)).toEqual(PROPOSAL)
    expect(composable.errorReason.value).toBeNull()
    expect(composable.isTranslating.value).toBe(false)
  })

  it('expire() puis translate() avant la réponse périmée : aucune requête, aucune proposition posée (#748, R125, décision 2)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const composable = mountWithComposable({ translate })

    const stale = proposalOf(composable)
    composable.expire()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.isExpiredCallPending.value).toBe(true)

    expect(await proposalOf(composable)).toBeNull()
    expect(translate).toHaveBeenCalledTimes(1)

    resolve(PROPOSAL)
    expect(await stale).toBeNull()
  })

  it('la réponse périmée arrivée, isExpiredCallPending retombe et une traduction repart (#748, R125)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockImplementationOnce(() => new Promise<TranslationProposal>((r) => { resolve = r }))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    const stale = proposalOf(composable)
    composable.expire()
    resolve(PROPOSAL)
    expect(await stale).toBeNull()

    expect(composable.isExpiredCallPending.value).toBe(false)
    expect(await proposalOf(composable)).toEqual(PROPOSAL)
    expect(translate).toHaveBeenCalledTimes(2)
  })

  it('une réponse périmée en échec : isExpiredCallPending retombe, aucune raison posée (#748, R125)', async () => {
    let reject!: (error: unknown) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((_, r) => { reject = r }))
    const composable = mountWithComposable({ translate })

    const stale = proposalOf(composable)
    composable.expire()
    expect(composable.isExpiredCallPending.value).toBe(true)
    reject(new AdminTranslationError('unavailable', 'Indisponible.'))
    await stale

    expect(composable.isExpiredCallPending.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it("une identité changée : isExpiredCallPending jusqu'à la réponse, jamais deux appels en vol (#748, R125)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const stale = proposalOf(composable)
    identity.value = identityOf('b')
    expect(composable.isExpiredCallPending.value).toBe(true)
    expect(await proposalOf(composable)).toBeNull()
    expect(translate).toHaveBeenCalledTimes(1)

    resolve(PROPOSAL)
    await stale
    expect(composable.isExpiredCallPending.value).toBe(false)
  })

  it("une identité changée pendant la traduction : isTranslating retombe, la réponse n'appelle pas accept (#748, R124)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = proposalOf(composable)
    expect(composable.isTranslating.value).toBe(true)

    identity.value = identityOf('b')
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it("une identité changée : l'échec de la traduction périmée ne pose aucune raison (#748, R124)", async () => {
    let reject!: (error: unknown) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((_, r) => { reject = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = proposalOf(composable)
    identity.value = identityOf('b')
    reject(new AdminTranslationError('unavailable', 'Indisponible.'))

    expect(await pending).toBeNull()
    expect(composable.errorReason.value).toBeNull()
  })

  it("une identité changée puis rétablie : la traduction partie du premier formulaire reste périmée (#748, R124)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = proposalOf(composable)
    identity.value = identityOf('b')
    identity.value = identityOf('a')
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it("une identité qui ne diffère que par le groupe source : la traduction en vol est périmée (#748, R137)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    // Une création vierge, puis « Créer la version XX » d'une entrée : ni l'entrée (aucune) ni la langue ne changent.
    const identity = ref<FormIdentity>({ entry: null, locale: 'en', group: null, generation: 0 })
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = proposalOf(composable)
    identity.value = { ...identity.value, group: 'groupe-source' }
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it("une identité changée efface l'échec, un retour à elle ne le rend pas (#748, R139)", async () => {
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    await proposalOf(composable)
    expect(composable.errorReason.value).toBe('unavailable')

    identity.value = identityOf('b')
    expect(composable.errorReason.value).toBeNull()

    identity.value = identityOf('a')
    expect(composable.errorReason.value).toBeNull()
  })

  it("une génération avancée efface l'échec, et périme la traduction en vol (#748, R139)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
      .mockImplementationOnce(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref<FormIdentity>({ entry: null, locale: 'fr', group: null, generation: 0 })
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    await proposalOf(composable)
    expect(composable.errorReason.value).toBe('unavailable')
    identity.value = { ...identity.value, generation: 1 }
    expect(composable.errorReason.value).toBeNull()

    const pending = proposalOf(composable)
    expect(composable.isTranslating.value).toBe(true)
    identity.value = { ...identity.value, generation: 2 }
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it('une identité inchangée : la proposition est rendue (#748, R124)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = proposalOf(composable)
    // Une réaffectation à l'identique ne remplace rien : l'identité est comparée par son contenu.
    identity.value = identityOf('a')
    expect(composable.isTranslating.value).toBe(true)

    resolve(PROPOSAL)
    expect(await pending).toEqual(PROPOSAL)
    expect(composable.isTranslating.value).toBe(false)
  })

  // Le guetteur d'identité naît à la première traduction, dans la portée du composant s'il y en a une (R139) : trois situations.
  it("hors de toute portée, le guetteur d'identité s'installe : un changement d'identité périme (#748, R139)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    // Hors d'un `setup` : `inject` lit le contexte de l'application, `getCurrentScope()` ne rend aucune portée.
    const app = createApp({})
    app.provide(ADMIN_TRANSLATION_REPOSITORY as symbol, { translate })
    const composable = app.runWithContext(() => useAdminTranslation({ formIdentity: () => identity.value }))

    const pending = proposalOf(composable)
    expect(composable.isTranslating.value).toBe(true)
    identity.value = identityOf('b')
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it("dans une portée arrêtée (composant démonté), le guetteur n'installe rien, sans avertir (#748, R139)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const { composable: captured, wrapper } = mountHost({ translate }, { formIdentity: () => identity.value })
    wrapper.unmount()

    // Lancer un effet dans une portée arrêtée fait avertir Vue (« cannot run an inactive effect scope ») : la garde l'évite.
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    try {
      const pending = proposalOf(captured)
      identity.value = identityOf('b')
      // Aucun guetteur : la portée du composant est arrêtée, rien ne regarde l'identité.
      expect(captured.isTranslating.value).toBe(true)
      expect(warn).not.toHaveBeenCalled()

      resolve(PROPOSAL)
      expect(await pending).toEqual(PROPOSAL)
    } finally {
      warn.mockRestore()
    }
  })

  it('exige que le repository soit fourni', () => {
    const Host = defineComponent({
      setup() {
        useAdminTranslation()
        return () => h('div')
      },
    })

    expect(() => mount(Host)).toThrow(/AdminTranslationRepository/)
  })
})
