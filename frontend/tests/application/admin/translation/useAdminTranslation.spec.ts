import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import {
  ADMIN_TRANSLATION_REPOSITORY,
  useAdminTranslation,
} from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import type { FormIdentity } from '../../../../src/domain/admin/translation/entities/FormIdentity'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'

const FIELDS = { title: 'Panne', impact: 'Formulaire en 500.' }
const PROPOSAL: TranslationProposal = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage', impact: 'Form down.' } }

/** Une identité de formulaire typée, que seule l'entrée distingue. */
function identityOf(entry: string): FormIdentity {
  return { entry, locale: 'fr', group: null, generation: 0 }
}

function mountWithComposable(repository: AdminTranslationRepository, options?: Parameters<typeof useAdminTranslation>[0]) {
  let captured: ReturnType<typeof useAdminTranslation> | undefined
  const Host = defineComponent({
    setup() {
      captured = useAdminTranslation(options)
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")
  return captured
}

describe('useAdminTranslation', () => {
  it('rend la proposition et passe par isTranslating pendant l\'appel', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const repository: AdminTranslationRepository = {
      translate: vi.fn(() => new Promise<TranslationProposal>((r) => { resolve = r })),
    }
    const composable = mountWithComposable(repository)

    const pending = composable.translate('fr', 'en', FIELDS)
    expect(composable.isTranslating.value).toBe(true)

    resolve(PROPOSAL)
    const proposal = await pending

    expect(proposal).toEqual(PROPOSAL)
    expect(repository.translate).toHaveBeenCalledWith('fr', 'en', FIELDS)
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it('rend null et expose la raison quand le repository échoue, sans lever', async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota atteint.') }),
    }
    const composable = mountWithComposable(repository)

    const proposal = await composable.translate('fr', 'en', FIELDS)

    expect(proposal).toBeNull()
    expect(composable.errorReason.value).toBe('rate-limited')
    expect(composable.isTranslating.value).toBe(false)
  })

  it('classe une erreur inattendue en « unknown »', async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new TypeError('Failed to fetch') }),
    }
    const composable = mountWithComposable(repository)

    await composable.translate('fr', 'en', FIELDS)

    expect(composable.errorReason.value).toBe('unknown')
  })

  it('efface la raison de l\'erreur précédente au début de l\'appel suivant', async () => {
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockRejectedValueOnce(new AdminTranslationError('unavailable', 'Indisponible.'))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    await composable.translate('fr', 'en', FIELDS)
    expect(composable.errorReason.value).toBe('unavailable')

    await composable.translate('fr', 'en', FIELDS)
    expect(composable.errorReason.value).toBeNull()
  })

  it('une traduction en vol refuse la suivante sans requête (#748, R114)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const composable = mountWithComposable({ translate })

    const first = composable.translate('fr', 'en', FIELDS)
    const second = await composable.translate('fr', 'en', FIELDS)

    expect(second).toBeNull()
    expect(translate).toHaveBeenCalledTimes(1)
    expect(composable.isTranslating.value).toBe(true)

    resolve(PROPOSAL)
    expect(await first).toEqual(PROPOSAL)
    expect(composable.isTranslating.value).toBe(false)
  })

  it('isRefused vrai : aucune requête, null (#748, R114)', async () => {
    const translate = vi.fn<AdminTranslationRepository['translate']>(async () => PROPOSAL)
    // Une `ref` : le refus est un calculé (R137), qui ne se relit que si une source réactive change.
    const refused = ref(true)
    const composable = mountWithComposable({ translate }, { isRefused: () => refused.value })

    expect(await composable.translate('fr', 'en', FIELDS)).toBeNull()
    expect(translate).not.toHaveBeenCalled()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()

    refused.value = false
    expect(await composable.translate('fr', 'en', FIELDS)).toEqual(PROPOSAL)
    expect(translate).toHaveBeenCalledTimes(1)
  })

  it('isRefused suit le prédicat de la page : la valeur exposée et translate() lisent la même source (#748, R137)', async () => {
    const translate = vi.fn<AdminTranslationRepository['translate']>().mockResolvedValue(PROPOSAL)
    const refused = ref(true)
    const composable = mountWithComposable({ translate }, { isRefused: () => refused.value })

    expect(composable.isRefused.value).toBe(true)
    expect(await composable.translate('fr', 'en', FIELDS)).toBeNull()
    expect(translate).not.toHaveBeenCalled()

    refused.value = false
    expect(composable.isRefused.value).toBe(false)
    expect(await composable.translate('fr', 'en', FIELDS)).toEqual(PROPOSAL)
    expect(translate).toHaveBeenCalledTimes(1)
  })

  it("isRefused est faux quand la page n'en dit rien (#748, R137)", () => {
    const composable = mountWithComposable({ translate: vi.fn() })

    expect(composable.isRefused.value).toBe(false)
  })

  it('discard() périme la traduction en vol : sa réponse rend null (#748, R114)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const composable = mountWithComposable({ translate })

    const pending = composable.translate('fr', 'en', FIELDS)
    composable.discard()
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it("discard() : l'échec d'une traduction périmée ne pose aucune raison, et la suivante part ensuite (#748, R114, R125)", async () => {
    let reject!: (error: unknown) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockImplementationOnce(() => new Promise<TranslationProposal>((_, r) => { reject = r }))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    const stale = composable.translate('fr', 'en', FIELDS)
    composable.discard()
    reject(new AdminTranslationError('unavailable', 'Indisponible.'))

    expect(await stale).toBeNull()
    expect(await composable.translate('fr', 'en', FIELDS)).toEqual(PROPOSAL)
    expect(composable.errorReason.value).toBeNull()
    expect(composable.isTranslating.value).toBe(false)
  })

  it('discard() puis translate() avant la réponse périmée : aucune requête, null (#748, R125, décision 2)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const composable = mountWithComposable({ translate })

    const stale = composable.translate('fr', 'en', FIELDS)
    composable.discard()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.isDiscardPending.value).toBe(true)

    expect(await composable.translate('fr', 'en', FIELDS)).toBeNull()
    expect(translate).toHaveBeenCalledTimes(1)

    resolve(PROPOSAL)
    expect(await stale).toBeNull()
  })

  it('la réponse périmée arrivée, isDiscardPending retombe et une traduction repart (#748, R125)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockImplementationOnce(() => new Promise<TranslationProposal>((r) => { resolve = r }))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    const stale = composable.translate('fr', 'en', FIELDS)
    composable.discard()
    resolve(PROPOSAL)
    expect(await stale).toBeNull()

    expect(composable.isDiscardPending.value).toBe(false)
    expect(await composable.translate('fr', 'en', FIELDS)).toEqual(PROPOSAL)
    expect(translate).toHaveBeenCalledTimes(2)
  })

  it('une réponse périmée en échec : isDiscardPending retombe, aucune raison posée (#748, R125)', async () => {
    let reject!: (error: unknown) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((_, r) => { reject = r }))
    const composable = mountWithComposable({ translate })

    const stale = composable.translate('fr', 'en', FIELDS)
    composable.discard()
    expect(composable.isDiscardPending.value).toBe(true)
    reject(new AdminTranslationError('unavailable', 'Indisponible.'))
    await stale

    expect(composable.isDiscardPending.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it("une identité changée : isDiscardPending jusqu'à la réponse, jamais deux appels en vol (#748, R125)", async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const stale = composable.translate('fr', 'en', FIELDS)
    identity.value = identityOf('b')
    expect(composable.isDiscardPending.value).toBe(true)
    expect(await composable.translate('fr', 'en', FIELDS)).toBeNull()
    expect(translate).toHaveBeenCalledTimes(1)

    resolve(PROPOSAL)
    await stale
    expect(composable.isDiscardPending.value).toBe(false)
  })

  it('une identité de formulaire changée pendant la traduction : isTranslating retombe, la réponse rend null (#748, R124)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = composable.translate('fr', 'en', FIELDS)
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

    const pending = composable.translate('fr', 'en', FIELDS)
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

    const pending = composable.translate('fr', 'en', FIELDS)
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

    const pending = composable.translate('fr', 'en', FIELDS)
    identity.value = { ...identity.value, group: 'groupe-source' }
    expect(composable.isTranslating.value).toBe(false)

    resolve(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it('une identité inchangée : la proposition est rendue (#748, R124)', async () => {
    let resolve!: (proposal: TranslationProposal) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>(() => new Promise<TranslationProposal>((r) => { resolve = r }))
    const identity = ref(identityOf('a'))
    const composable = mountWithComposable({ translate }, { formIdentity: () => identity.value })

    const pending = composable.translate('fr', 'en', FIELDS)
    // Une réaffectation à l'identique ne remplace rien : l'identité est comparée par son contenu.
    identity.value = identityOf('a')
    expect(composable.isTranslating.value).toBe(true)

    resolve(PROPOSAL)
    expect(await pending).toEqual(PROPOSAL)
    expect(composable.isTranslating.value).toBe(false)
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
