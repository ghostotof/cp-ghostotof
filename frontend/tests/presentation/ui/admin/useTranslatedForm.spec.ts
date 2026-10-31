import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
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
function mountTranslatedForm(options: Partial<UseTranslatedFormOptions> = {}, failing?: AdminTranslationRepository) {
  let release!: (proposal: TranslationProposal) => void
  const repository: AdminTranslationRepository = failing ?? {
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

  it("renewForm() périme la traduction en vol, même si l'entrée, la langue et le groupe sont inchangés (#748, R139)", async () => {
    const { form, release } = mountTranslatedForm()

    const pending = form.translate('fr', 'en', { title: 'Panne' })
    expect(form.isTranslating.value).toBe(true)

    form.renewForm()
    expect(form.isTranslating.value).toBe(false)

    release(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it("renewForm() efface l'échec d'une traduction (#748, R139)", async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
    }
    const { form } = mountTranslatedForm({}, repository)

    await form.translate('fr', 'en', { title: 'Panne' })
    expect(form.errorReason.value).toBe('unavailable')

    form.renewForm()
    expect(form.errorReason.value).toBeNull()
  })

  // Supprimer une entrée ne change rien au formulaire : la page le dit au composable, qui juge si la traduction en vol y
  // était rattachée — l'entrée éditée, ou la source d'une création rattachée à son groupe (#748, R140).
  describe('discardIfDeleted', () => {
    const GROUP = 'groupe-source'
    const OTHER_GROUP = 'autre-groupe'

    /** Un formulaire dont l'identité est celle donnée, une traduction partie, puis la suppression de `deleted`. */
    async function deleteDuringTranslation(
      identity: { entry: string | null; group: string | null },
      deleted: { id: string; translationGroup: string | null },
    ) {
      const { form, release } = mountTranslatedForm({ identity: () => ({ ...identity, locale: 'fr' }) })
      const pending = form.translate('fr', 'en', { title: 'Panne' })

      form.discardIfDeleted(deleted)

      release(PROPOSAL)
      return { form, answer: await pending }
    }

    it("périme la traduction de l'entrée éditée qu'on supprime (#748, R140)", async () => {
      const { form, answer } = await deleteDuringTranslation(
        { entry: 'a', group: GROUP },
        { id: 'a', translationGroup: GROUP },
      )

      expect(answer).toBeNull()
      expect(form.isTranslating.value).toBe(false)
    })

    it("périme la traduction d'une création rattachée quand on supprime l'entrée source de son groupe (#748, R140)", async () => {
      const { answer } = await deleteDuringTranslation(
        { entry: null, group: GROUP },
        { id: 'source', translationGroup: GROUP },
      )

      expect(answer).toBeNull()
    })

    it("ne périme rien quand l'entrée supprimée est une autre que l'entrée éditée, même du même groupe (#748, R140)", async () => {
      const { answer } = await deleteDuringTranslation(
        { entry: 'en', group: GROUP },
        { id: 'fr', translationGroup: GROUP },
      )

      expect(answer).toEqual(PROPOSAL)
    })

    it("ne périme rien quand l'entrée supprimée n'est pas de la source d'une création rattachée (#748, R140)", async () => {
      const { answer } = await deleteDuringTranslation(
        { entry: null, group: GROUP },
        { id: 'ailleurs', translationGroup: OTHER_GROUP },
      )

      expect(answer).toEqual(PROPOSAL)
    })

    it("ne périme pas la traduction d'un formulaire vierge quand une entrée sans groupe est supprimée (#748, R140)", async () => {
      const { answer } = await deleteDuringTranslation(
        { entry: null, group: null },
        { id: 'seule', translationGroup: null },
      )

      expect(answer).toEqual(PROPOSAL)
    })
  })
})
