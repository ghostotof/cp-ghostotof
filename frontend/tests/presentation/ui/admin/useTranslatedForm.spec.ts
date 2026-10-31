import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import type { Locale } from '../../../../src/domain/portfolio/entities/Locale'
import {
  proseFieldId,
  useTranslatedForm,
  type UseTranslatedFormOptions,
} from '../../../../src/presentation/ui/admin/useTranslatedForm'

const PREFIX = 'admin-incident'
const HINT = `${PREFIX}-prose-readonly-hint`
const PROPOSAL: TranslationProposal = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage' } }

/** Monte le composable dans un hôte, avec un dépôt dont la réponse attend `release()`. */
function mountTranslatedForm(options: Partial<UseTranslatedFormOptions> = {}) {
  let release!: (proposal: TranslationProposal) => void
  const repository: AdminTranslationRepository = {
    translate: vi.fn(() => new Promise<TranslationProposal>((resolve) => { release = resolve })),
  }
  let captured: ReturnType<typeof useTranslatedForm> | undefined
  const Host = defineComponent({
    setup() {
      captured = useTranslatedForm({
        hintIdPrefix: PREFIX,
        identity: () => ({ entry: null, locale: 'fr', group: null }),
        isRefused: () => false,
        ...options,
      })
      return () => h('div')
    },
  })
  mount(Host, { global: { provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")

  return { form: captured, repository, release: (proposal: TranslationProposal) => release(proposal) }
}

describe('useTranslatedForm', () => {
  it("proseFieldId écrit l'identifiant d'un champ en kebab, derrière le préfixe de son formulaire (#748, R138)", () => {
    expect(proseFieldId('admin-incident', 'rootCause')).toBe('admin-incident-root-cause')
    expect(proseFieldId('admin-about', 'siteEyebrow')).toBe('admin-about-site-eyebrow')
    expect(proseFieldId('admin-case-study', 'title')).toBe('admin-case-study-title')
    expect(proseFieldId('admin-case-study', 'measuredResult')).toBe('admin-case-study-measured-result')
  })

  it('nomme les deux textes du formulaire d’après son préfixe (#748, R138)', () => {
    const { form } = mountTranslatedForm({ hintIdPrefix: 'admin-about-settings' })

    expect(form.proseReadonlyHintId).toBe('admin-about-settings-prose-readonly-hint')
    expect(form.translationHintId).toBe('admin-about-settings-translation-hint')
  })

  it('proseFieldAttrs : hors traduction, ni lecture seule ni référence (#748, R138)', () => {
    const { form } = mountTranslatedForm()

    expect(form.proseFieldAttrs()).toEqual({ readonly: false, 'aria-describedby': undefined })
    expect(form.proseDescribedBy.value).toBeUndefined()
  })

  it('proseFieldAttrs : pendant la traduction, lecture seule et texte d’aide, retirés à la réponse (#748, R138)', async () => {
    const { form, release } = mountTranslatedForm()

    const pending = form.translate('fr', 'en', { title: 'Panne' })

    expect(form.proseFieldAttrs()).toEqual({ readonly: true, 'aria-describedby': HINT })
    expect(form.proseDescribedBy.value).toBe(HINT)

    release(PROPOSAL)
    await pending

    expect(form.proseFieldAttrs()).toEqual({ readonly: false, 'aria-describedby': undefined })
  })

  it("proseFieldAttrs('aide') garde l'aide propre du champ, devant le texte de la traduction (#748, R138)", async () => {
    const { form, release } = mountTranslatedForm()

    expect(form.proseFieldAttrs('aide')['aria-describedby']).toBe('aide')

    const pending = form.translate('fr', 'en', { title: 'Panne' })
    expect(form.proseFieldAttrs('aide')['aria-describedby']).toBe(`aide ${HINT}`)

    release(PROPOSAL)
    await pending
  })

  it("rend le refus de la page tel que useAdminTranslation l'expose : une seule source (#748, R138)", async () => {
    const refused = ref(true)
    const { form, repository } = mountTranslatedForm({ isRefused: () => refused.value })

    expect(form.isRefused.value).toBe(true)
    expect(await form.translate('fr', 'en', { title: 'Panne' })).toBeNull()
    expect(repository.translate).not.toHaveBeenCalled()
    expect(form.isTranslating.value).toBe(false)

    refused.value = false
    expect(form.isRefused.value).toBe(false)
  })

  it("périme la traduction en vol quand l'identité du formulaire change (#748, R138)", async () => {
    const locale = ref<Locale>('fr')
    const { form, release } = mountTranslatedForm({ identity: () => ({ entry: null, locale: locale.value, group: null }) })

    const pending = form.translate('fr', 'en', { title: 'Panne' })
    expect(form.isTranslating.value).toBe(true)

    locale.value = 'en'
    expect(form.isTranslating.value).toBe(false)

    release(PROPOSAL)
    expect(await pending).toBeNull()
  })
})
