import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import {
  ADMIN_TRANSLATION_REPOSITORY,
  useAdminTranslation,
} from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'

const FIELDS = { title: 'Panne', impact: 'Formulaire en 500.' }
const PROPOSAL: TranslationProposal = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage', impact: 'Form down.' } }

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
    let refused = true
    const composable = mountWithComposable({ translate }, { isRefused: () => refused })

    expect(await composable.translate('fr', 'en', FIELDS)).toBeNull()
    expect(translate).not.toHaveBeenCalled()
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()

    refused = false
    expect(await composable.translate('fr', 'en', FIELDS)).toEqual(PROPOSAL)
    expect(translate).toHaveBeenCalledTimes(1)
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

  it("discard() : l'échec d'une traduction périmée ne pose aucune raison, et la suivante part (#748, R114)", async () => {
    let reject!: (error: unknown) => void
    const translate = vi.fn<AdminTranslationRepository['translate']>()
      .mockImplementationOnce(() => new Promise<TranslationProposal>((_, r) => { reject = r }))
      .mockResolvedValueOnce(PROPOSAL)
    const composable = mountWithComposable({ translate })

    const stale = composable.translate('fr', 'en', FIELDS)
    composable.discard()
    const next = composable.translate('fr', 'en', FIELDS)
    reject(new AdminTranslationError('unavailable', 'Indisponible.'))

    expect(await stale).toBeNull()
    expect(await next).toEqual(PROPOSAL)
    expect(composable.errorReason.value).toBeNull()
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
