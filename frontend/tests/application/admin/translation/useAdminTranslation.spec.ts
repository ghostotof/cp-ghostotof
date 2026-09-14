import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import {
  ADMIN_TRANSLATION_REPOSITORY,
  useAdminTranslation,
} from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationDraft } from '../../../../src/domain/admin/translation/entities/TranslationDraft'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'

const FIELDS = { title: 'Panne', impact: 'Formulaire en 500.' }
const DRAFT: TranslationDraft = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage', impact: 'Form down.' } }

function mountWithComposable(repository: AdminTranslationRepository) {
  let captured: ReturnType<typeof useAdminTranslation> | undefined
  const Host = defineComponent({
    setup() {
      captured = useAdminTranslation()
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")
  return captured
}

describe('useAdminTranslation', () => {
  it('rend le brouillon et passe par isTranslating pendant l\'appel', async () => {
    let resolve!: (draft: TranslationDraft) => void
    const repository: AdminTranslationRepository = {
      translate: vi.fn(() => new Promise<TranslationDraft>((r) => { resolve = r })),
    }
    const composable = mountWithComposable(repository)

    const pending = composable.translate('fr', 'en', FIELDS)
    expect(composable.isTranslating.value).toBe(true)

    resolve(DRAFT)
    const draft = await pending

    expect(draft).toEqual(DRAFT)
    expect(repository.translate).toHaveBeenCalledWith('fr', 'en', FIELDS)
    expect(composable.isTranslating.value).toBe(false)
    expect(composable.errorReason.value).toBeNull()
  })

  it('rend null et expose la raison quand le repository échoue, sans lever', async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new AdminTranslationError('rate-limited', 'Quota atteint.') }),
    }
    const composable = mountWithComposable(repository)

    const draft = await composable.translate('fr', 'en', FIELDS)

    expect(draft).toBeNull()
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
      .mockResolvedValueOnce(DRAFT)
    const composable = mountWithComposable({ translate })

    await composable.translate('fr', 'en', FIELDS)
    expect(composable.errorReason.value).toBe('unavailable')

    await composable.translate('fr', 'en', FIELDS)
    expect(composable.errorReason.value).toBeNull()
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
