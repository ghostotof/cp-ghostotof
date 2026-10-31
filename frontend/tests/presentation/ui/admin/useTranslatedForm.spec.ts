import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, reactive, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { ADMIN_TRANSLATION_REPOSITORY } from '../../../../src/application/admin/translation/useAdminTranslation'
import type { AdminTranslationRepository } from '../../../../src/domain/admin/translation/repositories/AdminTranslationRepository'
import type { TranslationProposal } from '../../../../src/domain/admin/translation/entities/TranslationProposal'
import { AdminTranslationError } from '../../../../src/domain/admin/translation/errors/AdminTranslationError'
import type { Locale } from '../../../../src/domain/portfolio/entities/Locale'
import { createAppI18n } from '../../../../src/presentation/i18n'
import {
  useTranslatedForm,
  type UseTranslatedFormOptions,
  type UseTranslatedFormResult,
} from '../../../../src/presentation/ui/admin/useTranslatedForm'
import { TRANSLATION_UNAVAILABLE_FR } from '../../../support/adminTranslationTexts'

const PREFIX = 'admin-incident'
const HINT = `${PREFIX}-prose-readonly-hint`
const PROPOSAL: TranslationProposal = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage' } }

// Les champs de prose du formulaire de ces specs ; `note` est un champ du formulaire hors de la liste. Le titre est rempli au départ :
// sans prose, le composable refuse la traduction.
type Field = 'title' | 'impact'
const PROSE_FIELDS: readonly Field[] = ['title', 'impact']

/** Ce que rend `mountTranslatedForm` : le composable, son formulaire, son dépôt et la réponse que le test libère. */
interface MountedTranslatedForm {
  readonly form: UseTranslatedFormResult
  readonly fields: { title: string; impact: string; note: string }
  readonly repository: AdminTranslationRepository
  readonly release: (proposal: TranslationProposal) => void
}

/** Monte le composable dans un hôte, avec un dépôt dont la réponse attend `release()`. */
function mountTranslatedForm(
  options: Partial<UseTranslatedFormOptions<Field>> = {},
  failing?: AdminTranslationRepository,
): MountedTranslatedForm {
  let release!: (proposal: TranslationProposal) => void
  const repository: AdminTranslationRepository = failing ?? {
    translate: vi.fn(() => new Promise<TranslationProposal>((resolve) => { release = resolve })),
  }
  const fields = reactive({ title: 'Panne', impact: '', note: '' })
  let captured: ReturnType<typeof useTranslatedForm<Field>> | undefined
  const Host = defineComponent({
    setup() {
      captured = useTranslatedForm<Field>({
        hintIdPrefix: PREFIX,
        form: fields,
        proseFields: PROSE_FIELDS,
        identity: () => ({ entry: null, locale: 'fr', group: null }),
        isRefused: () => false,
        ...options,
      })
      return () => h('div')
    },
  })
  mount(Host, { global: { plugins: [createAppI18n()], provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: repository } } })
  if (!captured) throw new Error("Le composable n'a pas été capturé.")

  return { form: captured, fields, repository, release: (proposal: TranslationProposal) => release(proposal) }
}

describe('useTranslatedForm', () => {
  it('nomme les deux textes du formulaire d’après son préfixe (#748, R138)', () => {
    const { form } = mountTranslatedForm({ hintIdPrefix: 'admin-about-settings' })

    expect(form.proseReadonlyHintId).toBe('admin-about-settings-prose-readonly-hint')
    expect(form.translationHintId).toBe('admin-about-settings-translation-hint')
  })

  it('proseFieldAttrs : hors traduction, ni lecture seule ni référence (#748, R138)', () => {
    const { form } = mountTranslatedForm()

    expect(form.proseFieldAttrs()).toEqual({ readonly: false, 'aria-describedby': undefined })
  })

  it('proseFieldAttrs : pendant la traduction, lecture seule et texte d’aide, retirés à la réponse (#748, R138)', async () => {
    const { form, release } = mountTranslatedForm()

    const pending = form.translate('fr', 'en', { title: 'Panne' })

    expect(form.proseFieldAttrs()).toEqual({ readonly: true, 'aria-describedby': HINT })

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

  // Aucun cas d'intégration ne l'isole : chaque geste de page qui change le groupe avance aussi la génération.
  it("une identité qui ne change que par son groupe périme la traduction (#748, R152)", async () => {
    const group = ref<string | null>('groupe-source')
    const { form, release } = mountTranslatedForm({ identity: () => ({ entry: null, locale: 'fr', group: group.value }) })

    const pending = form.translate('fr', 'en', { title: 'Panne' })
    expect(form.isTranslating.value).toBe(true)

    group.value = 'autre-groupe'
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
  describe('expireIfDeleted', () => {
    const GROUP = 'groupe-source'
    const OTHER_GROUP = 'autre-groupe'

    /** Un formulaire dont l'identité est celle donnée, une traduction partie, puis la suppression de `deleted`. */
    async function deleteDuringTranslation(
      identity: { entry: string | null; group: string | null },
      deleted: { id: string; translationGroup: string | null },
    ): Promise<{ form: UseTranslatedFormResult; answer: TranslationProposal | null }> {
      const { form, release } = mountTranslatedForm({ identity: () => ({ ...identity, locale: 'fr' }) })
      const pending = form.translate('fr', 'en', { title: 'Panne' })

      form.expireIfDeleted(deleted)

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

  describe('hasProse', () => {
    it("est faux pour une prose de blancs, vrai dès un champ de la liste rempli, insensible à un champ hors liste (#748, R153)", () => {
      const { form, fields } = mountTranslatedForm()
      expect(form.hasProse.value).toBe(true)

      fields.title = '   '
      expect(form.hasProse.value).toBe(false)

      fields.note = 'hors de la liste des champs de prose'
      expect(form.hasProse.value).toBe(false)

      fields.impact = 'Impact'
      expect(form.hasProse.value).toBe(true)
    })
  })

  describe('errorText', () => {
    it("vaut le texte de la raison de l'échec de la traduction, null sinon (#748, R153)", async () => {
      const repository: AdminTranslationRepository = {
        translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
      }
      const { form } = mountTranslatedForm({}, repository)
      expect(form.errorText.value).toBeNull()

      await form.translate('fr', 'en', { title: 'Panne' })
      expect(form.errorText.value).toBe(TRANSLATION_UNAVAILABLE_FR)

      form.renewForm()
      expect(form.errorText.value).toBeNull()
    })
  })

  describe('proposeFrom', () => {
    it("envoie la prose collectée dans la langue de l'identité, sans les champs blancs ni hors liste (#748, R153)", async () => {
      const { form, fields, repository, release } = mountTranslatedForm()
      fields.note = 'hors liste'

      const pending = form.proposeFrom('en', vi.fn())
      release(PROPOSAL)
      await pending

      expect(repository.translate).toHaveBeenCalledOnce()
      expect(repository.translate).toHaveBeenCalledWith('fr', 'en', { title: 'Panne' })
    })

    it("appelle switchForm une fois avec la langue source, avant que la prose soit remplacée (#748, R153)", async () => {
      const { form, fields, release } = mountTranslatedForm()
      fields.title = 'Panne'
      fields.note = 'gardée'
      const proseSeenBySwitch: string[] = []
      const switchForm = vi.fn(() => { proseSeenBySwitch.push(fields.title) })

      const pending = form.proposeFrom('en', switchForm)
      release(PROPOSAL)
      await pending

      expect(switchForm).toHaveBeenCalledOnce()
      expect(switchForm).toHaveBeenCalledWith('fr')
      expect(proseSeenBySwitch).toEqual(['Panne'])
      expect(fields.title).toBe('Outage')
      expect(fields.note).toBe('gardée')
    })

    it("ne fait rien sur un échec : ni switchForm, ni prose remplacée (#748, R153)", async () => {
      const repository: AdminTranslationRepository = {
        translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
      }
      const { form, fields } = mountTranslatedForm({}, repository)
      fields.title = 'Panne'
      const switchForm = vi.fn()

      await form.proposeFrom('en', switchForm)

      expect(switchForm).not.toHaveBeenCalled()
      expect(fields.title).toBe('Panne')
    })

    it("ne fait rien quand la page refuse la traduction (#748, R153)", async () => {
      const { form, fields, repository } = mountTranslatedForm({ isRefused: () => true })
      fields.title = 'Panne'
      const switchForm = vi.fn()

      await form.proposeFrom('en', switchForm)

      expect(repository.translate).not.toHaveBeenCalled()
      expect(switchForm).not.toHaveBeenCalled()
    })
  })

  // Une suppression refusée périme quand même la traduction (limite acceptée, D7) : la périmer après le succès laisserait une
  // réponse arrivée pendant le `DELETE` se poser sur une entrée supprimée.
  describe('removeEntry', () => {
    const EDITED = { id: 'a', translationGroup: 'groupe-source' }

    it("périme la traduction de l'entrée éditée puis appelle remove une fois (#748, R153)", async () => {
      const { form, release } = mountTranslatedForm({ identity: () => ({ entry: 'a', locale: 'fr', group: 'groupe-source' }) })
      const pending = form.translate('fr', 'en', { title: 'Panne' })
      const remove = vi.fn(async () => true)

      await form.removeEntry(EDITED, remove)

      expect(form.isTranslating.value).toBe(false)
      expect(remove).toHaveBeenCalledOnce()
      release(PROPOSAL)
      expect(await pending).toBeNull()
    })

    it("périme la traduction même quand remove rejette, et laisse l'erreur remonter (#748, R153)", async () => {
      const { form, release } = mountTranslatedForm({ identity: () => ({ entry: 'a', locale: 'fr', group: 'groupe-source' }) })
      const pending = form.translate('fr', 'en', { title: 'Panne' })
      const remove = vi.fn(async () => { throw new Error('suppression refusée') })

      await expect(form.removeEntry(EDITED, remove)).rejects.toThrow('suppression refusée')

      expect(form.isTranslating.value).toBe(false)
      release(PROPOSAL)
      expect(await pending).toBeNull()
    })

    it("ne périme pas la traduction d'une autre entrée, mais supprime (#748, R153)", async () => {
      const { form, release } = mountTranslatedForm({ identity: () => ({ entry: 'b', locale: 'fr', group: 'autre-groupe' }) })
      const pending = form.translate('fr', 'en', { title: 'Panne' })
      const remove = vi.fn(async () => true)

      await form.removeEntry(EDITED, remove)

      expect(remove).toHaveBeenCalledOnce()
      release(PROPOSAL)
      expect(await pending).toEqual(PROPOSAL)
    })
  })
})
