import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, reactive, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
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
import TranslateEntryButton from '../../../../src/presentation/ui/admin/TranslateEntryButton.vue'
import { TRANSLATION_UNAVAILABLE_FR } from '../../../support/adminTranslationTexts'
import { capturedProposal } from '../../../support/capturedProposal'

const PREFIX = 'admin-incident'
const HINT = `${PREFIX}-prose-readonly-hint`
const PROPOSAL: TranslationProposal = { sourceLocale: 'fr', targetLocale: 'en', fields: { title: 'Outage' } }

// Les champs de prose du formulaire de ces specs ; `note` est un champ du formulaire hors de la liste. Le titre est rempli au départ :
// sans prose, le composable refuse la traduction.
type Field = 'title' | 'impact'
const PROSE_FIELDS: readonly Field[] = ['title', 'impact']

/**
 * Ce que rend `mountTranslatedForm`, dans l'ordre des options du composable : le formulaire (`form`), ce que le composable en
 * rend (`translated`), le dépôt de traduction et la réponse que le test libère.
 */
interface MountedTranslatedForm {
  readonly form: { title: string; impact: string; note: string }
  readonly translated: UseTranslatedFormResult
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
  const form = reactive({ title: 'Panne', impact: '', note: '' })
  let captured: ReturnType<typeof useTranslatedForm<Field>> | undefined
  const Host = defineComponent({
    setup() {
      captured = useTranslatedForm<Field>({
        hintIdPrefix: PREFIX,
        form,
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

  return { form, translated: captured, repository, release: (proposal: TranslationProposal) => release(proposal) }
}

/** La proposition que la page reçoit de `requestProposal` vers `en` : celle-ci, ou `null` si `accept` n'a pas été appelée. */
function proposalOf(translated: UseTranslatedFormResult): Promise<TranslationProposal | null> {
  return capturedProposal((accept) => translated.requestProposal('en', accept))
}

describe('useTranslatedForm', () => {
  it('nomme les deux textes du formulaire d’après son préfixe (#748, R138)', () => {
    const { translated } = mountTranslatedForm({ hintIdPrefix: 'admin-about-settings' })

    expect(translated.proseReadonlyHintId).toBe('admin-about-settings-prose-readonly-hint')
    expect(translated.translationHintId).toBe('admin-about-settings-translation-hint')
  })

  it('proseFieldAttrs : hors traduction, ni lecture seule ni référence (#748, R138)', () => {
    const { translated } = mountTranslatedForm()

    expect(translated.proseFieldAttrs()).toEqual({ readonly: false, 'aria-describedby': undefined })
  })

  it('proseFieldAttrs : pendant la traduction, lecture seule et texte d’aide, retirés à la réponse (#748, R138)', async () => {
    const { translated, release } = mountTranslatedForm()

    const pending = proposalOf(translated)

    expect(translated.proseFieldAttrs()).toEqual({ readonly: true, 'aria-describedby': HINT })

    release(PROPOSAL)
    await pending

    expect(translated.proseFieldAttrs()).toEqual({ readonly: false, 'aria-describedby': undefined })
  })

  it("proseFieldAttrs('aide') garde l'aide propre du champ, devant le texte de la traduction (#748, R138)", async () => {
    const { translated, release } = mountTranslatedForm()

    expect(translated.proseFieldAttrs('aide')['aria-describedby']).toBe('aide')

    const pending = proposalOf(translated)
    expect(translated.proseFieldAttrs('aide')['aria-describedby']).toBe(`aide ${HINT}`)

    release(PROPOSAL)
    await pending
  })

  it("rend le refus de la page tel que useAdminTranslation l'expose : une seule source (#748, R138)", async () => {
    const refused = ref(true)
    const { translated, repository } = mountTranslatedForm({ isRefused: () => refused.value })

    expect(translated.isRefused.value).toBe(true)
    expect(await proposalOf(translated)).toBeNull()
    expect(repository.translate).not.toHaveBeenCalled()
    expect(translated.isTranslating.value).toBe(false)

    refused.value = false
    expect(translated.isRefused.value).toBe(false)
  })

  it("périme la traduction en vol quand l'identité du formulaire change (#748, R138)", async () => {
    const locale = ref<Locale>('fr')
    const { translated, release } = mountTranslatedForm({ identity: () => ({ entry: null, locale: locale.value, group: null }) })

    const pending = proposalOf(translated)
    expect(translated.isTranslating.value).toBe(true)

    locale.value = 'en'
    expect(translated.isTranslating.value).toBe(false)

    release(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it("renewForm() périme la traduction en vol, même si l'entrée, la langue et le groupe sont inchangés (#748, R139)", async () => {
    const { translated, release } = mountTranslatedForm()

    const pending = proposalOf(translated)
    expect(translated.isTranslating.value).toBe(true)

    translated.renewForm()
    expect(translated.isTranslating.value).toBe(false)

    release(PROPOSAL)
    expect(await pending).toBeNull()
  })

  // Aucun cas d'intégration ne l'isole : chaque geste de page qui change le groupe avance aussi la génération.
  it("une identité qui ne change que par son groupe périme la traduction (#748, R152)", async () => {
    const group = ref<string | null>('groupe-source')
    const { translated, release } = mountTranslatedForm({ identity: () => ({ entry: null, locale: 'fr', group: group.value }) })

    const pending = proposalOf(translated)
    expect(translated.isTranslating.value).toBe(true)

    group.value = 'autre-groupe'
    expect(translated.isTranslating.value).toBe(false)

    release(PROPOSAL)
    expect(await pending).toBeNull()
  })

  it("renewForm() efface l'échec d'une traduction (#748, R139)", async () => {
    const repository: AdminTranslationRepository = {
      translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
    }
    const { translated } = mountTranslatedForm({}, repository)

    await proposalOf(translated)
    expect(translated.errorReason.value).toBe('unavailable')

    translated.renewForm()
    expect(translated.errorReason.value).toBeNull()
  })

  // Supprimer une entrée ne change rien au formulaire : la page le dit au composable (`removeEntry`), qui juge si la traduction
  // en vol y était rattachée — l'entrée éditée, ou la source d'une création rattachée à son groupe (#748, R140).
  describe("la traduction rattachée à l'entrée supprimée", () => {
    const GROUP = 'groupe-source'
    const OTHER_GROUP = 'autre-groupe'

    /** Un formulaire dont l'identité est celle donnée, une traduction partie, puis la suppression de `deleted`. */
    async function deleteDuringTranslation(
      identity: { entry: string | null; group: string | null },
      deleted: { id: string; translationGroup: string | null },
    ): Promise<{ translated: UseTranslatedFormResult; answer: TranslationProposal | null }> {
      const { translated, release } = mountTranslatedForm({ identity: () => ({ ...identity, locale: 'fr' }) })
      const pending = proposalOf(translated)

      await translated.removeEntry(deleted, async () => true)

      release(PROPOSAL)
      return { translated, answer: await pending }
    }

    it("périme la traduction de l'entrée éditée qu'on supprime (#748, R140)", async () => {
      const { translated, answer } = await deleteDuringTranslation(
        { entry: 'a', group: GROUP },
        { id: 'a', translationGroup: GROUP },
      )

      expect(answer).toBeNull()
      expect(translated.isTranslating.value).toBe(false)
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
      const { form, translated } = mountTranslatedForm()
      expect(translated.hasProse.value).toBe(true)

      form.title = '   '
      expect(translated.hasProse.value).toBe(false)

      form.note = 'hors de la liste des champs de prose'
      expect(translated.hasProse.value).toBe(false)

      form.impact = 'Impact'
      expect(translated.hasProse.value).toBe(true)
    })
  })

  describe('translationErrorText', () => {
    it("vaut le texte de la raison de l'échec de la traduction, null sinon (#748, R153)", async () => {
      const repository: AdminTranslationRepository = {
        translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
      }
      const { translated } = mountTranslatedForm({}, repository)
      expect(translated.translationErrorText.value).toBeNull()

      await proposalOf(translated)
      expect(translated.translationErrorText.value).toBe(TRANSLATION_UNAVAILABLE_FR)

      translated.renewForm()
      expect(translated.translationErrorText.value).toBeNull()
    })
  })

  describe('requestProposal', () => {
    it("la prose collectée part, accept reçoit la proposition et la langue source de l'identité (#748, R172)", async () => {
      const { form, translated, repository, release } = mountTranslatedForm()
      form.note = 'hors liste'
      const accept = vi.fn()

      const pending = translated.requestProposal('en', accept)
      release(PROPOSAL)

      expect(await pending).toBe(true)
      expect(repository.translate).toHaveBeenCalledWith('fr', 'en', { title: 'Panne' })
      expect(accept).toHaveBeenCalledOnce()
      expect(accept).toHaveBeenCalledWith(PROPOSAL, 'fr')
    })

    it("rend faux sans appeler accept sur un refus, et sur une réponse périmée (#748, R172)", async () => {
      const refused = mountTranslatedForm({ isRefused: () => true })
      const accept = vi.fn()
      expect(await refused.translated.requestProposal('en', accept)).toBe(false)

      const { translated, release } = mountTranslatedForm()
      const stale = translated.requestProposal('en', accept)
      translated.renewForm()
      release(PROPOSAL)

      expect(await stale).toBe(false)
      expect(accept).not.toHaveBeenCalled()
    })
  })

  describe('proposeFrom', () => {
    it("envoie la prose collectée dans la langue de l'identité, sans les champs blancs ni hors liste (#748, R153)", async () => {
      const { form, translated, repository, release } = mountTranslatedForm()
      form.note = 'hors liste'

      const pending = translated.proposeFrom('en', vi.fn())
      release(PROPOSAL)
      await pending

      expect(repository.translate).toHaveBeenCalledOnce()
      expect(repository.translate).toHaveBeenCalledWith('fr', 'en', { title: 'Panne' })
    })

    it("appelle switchForm une fois avec la langue source, avant que la prose soit remplacée (#748, R153)", async () => {
      const { form, translated, release } = mountTranslatedForm()
      form.title = 'Panne'
      form.note = 'gardée'
      const proseSeenBySwitch: string[] = []
      const switchForm = vi.fn(() => { proseSeenBySwitch.push(form.title) })

      const pending = translated.proposeFrom('en', switchForm)
      release(PROPOSAL)
      await pending

      expect(switchForm).toHaveBeenCalledOnce()
      expect(switchForm).toHaveBeenCalledWith('fr')
      expect(proseSeenBySwitch).toEqual(['Panne'])
      expect(form.title).toBe('Outage')
      expect(form.note).toBe('gardée')
    })

    it("ne fait rien sur un échec : ni switchForm, ni prose remplacée (#748, R153)", async () => {
      const repository: AdminTranslationRepository = {
        translate: vi.fn(async () => { throw new AdminTranslationError('unavailable', 'Indisponible.') }),
      }
      const { form, translated } = mountTranslatedForm({}, repository)
      form.title = 'Panne'
      const switchForm = vi.fn()

      await translated.proposeFrom('en', switchForm)

      expect(switchForm).not.toHaveBeenCalled()
      expect(form.title).toBe('Panne')
    })

    it("ne fait rien quand la page refuse la traduction (#748, R153)", async () => {
      const { form, translated, repository } = mountTranslatedForm({ isRefused: () => true })
      form.title = 'Panne'
      const switchForm = vi.fn()

      await translated.proposeFrom('en', switchForm)

      expect(repository.translate).not.toHaveBeenCalled()
      expect(switchForm).not.toHaveBeenCalled()
    })

    // R172 (C14-8) : un geste de la page résolu au même point de contrôle de micro-tâches que la réponse du dépôt passe entre le
    // jugement « la réponse est courante » et la pose ; la pose est faite sous le jugement, jamais une micro-tâche plus tard.
    describe('un geste résolu au même point de contrôle que la réponse (#748, R172)', () => {
      /** Un dépôt dont la réponse est une promesse que le test tient : il y accroche un rappel après l'appel. */
      function mountWithHeldAnswer(identity?: UseTranslatedFormOptions<Field>['identity']): {
        translated: UseTranslatedFormResult
        answered: Promise<TranslationProposal>
        answer: () => void
      } {
        let resolve!: (proposal: TranslationProposal) => void
        const answered = new Promise<TranslationProposal>((r) => { resolve = r })
        const { translated } = mountTranslatedForm(identity ? { identity } : {}, { translate: vi.fn(() => answered) })

        return { translated, answered, answer: () => resolve(PROPOSAL) }
      }

      it("une remise à zéro : switchForm n'est pas appelée après elle (#748, R172)", async () => {
        const { translated, answered, answer } = mountWithHeldAnswer()
        const events: string[] = []

        const pending = translated.proposeFrom('en', () => { events.push('switch') })
        void answered.then(() => { events.push('renew'); translated.renewForm() })
        answer()
        await pending
        await flushPromises()

        expect(events).toContain('renew')
        expect(events.slice(events.indexOf('renew'))).not.toContain('switch')
      })

      it("la suppression de l'entrée éditée : switchForm n'est pas appelée après elle (#748, R172)", async () => {
        const { translated, answered, answer } = mountWithHeldAnswer(() => ({ entry: 'a', locale: 'fr', group: 'groupe-source' }))
        const events: string[] = []

        const pending = translated.proposeFrom('en', () => { events.push('switch') })
        void answered.then(() => {
          events.push('remove')
          void translated.removeEntry({ id: 'a', translationGroup: 'groupe-source' }, async () => true)
        })
        answer()
        await pending
        await flushPromises()

        expect(events).toContain('remove')
        expect(events.slice(events.indexOf('remove'))).not.toContain('switch')
      })
    })
  })

  // Une exception du rappel de la page (le `switchForm` d'une page qui lève : une faute de programmation) : la page l'écrit
  // dans un gestionnaire `@translate` asynchrone, que Vue appelle par `callWithAsyncErrorHandling` — il attache un `catch` à la
  // promesse rendue et passe l'erreur à `app.config.errorHandler`. Mesuré (#748, R187, CR15-5, Q7 (A)) : aucun rejet non géré,
  // la traduction ne reste pas en cours ; la bascule faite à moitié est une limite écrite (`frontend-backoffice.md`).
  describe("une exception du rappel de la page : l'erreur va au gestionnaire de Vue (#748, R187)", () => {
    const unhandled: unknown[] = []
    const recordUnhandled = (reason: unknown): void => { unhandled.push(reason) }

    afterEach(() => {
      process.off('unhandledRejection', recordUnhandled)
      unhandled.length = 0
    })

    it("une bascule qui lève : l'erreur va au gestionnaire de Vue, la traduction ne reste pas en cours (#748, R187)", async () => {
      process.on('unhandledRejection', recordUnhandled)
      const failure = new Error('bascule')
      const errorHandler = vi.fn()
      const repository: AdminTranslationRepository = { translate: vi.fn(async () => PROPOSAL) }
      const form = reactive({ title: 'Panne', impact: '', note: '' })
      let captured: UseTranslatedFormResult | undefined
      // Comme les pages des formulaires : un gestionnaire `async` qui attend `proposeFrom`, lié par `@translate` au vrai bouton.
      const Host = defineComponent({
        setup() {
          const translated = useTranslatedForm<Field>({
            hintIdPrefix: PREFIX,
            form,
            proseFields: PROSE_FIELDS,
            identity: () => ({ entry: null, locale: 'fr', group: null }),
            isRefused: () => false,
          })
          captured = translated
          const handleTranslate = async (target: Locale): Promise<void> => {
            await translated.proposeFrom(target, () => { throw failure })
          }

          return () => h(TranslateEntryButton, { formLocale: 'fr', isTranslating: false, hintId: HINT, onTranslate: handleTranslate })
        },
      })
      const wrapper = mount(Host, {
        global: {
          plugins: [createAppI18n()],
          provide: { [ADMIN_TRANSLATION_REPOSITORY as symbol]: repository },
          config: { errorHandler },
        },
      })

      await wrapper.get('button').trigger('click')
      await flushPromises()
      // Un rejet non géré n'est annoncé qu'au tour de boucle suivant.
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(errorHandler).toHaveBeenCalledOnce()
      expect(errorHandler.mock.calls[0]?.[0]).toBe(failure)
      expect(unhandled).toEqual([])
      expect(captured?.isTranslating.value).toBe(false)
      expect(captured?.isExpiredCallPending.value).toBe(false)
    })
  })

  // Une suppression refusée périme quand même la traduction (limite acceptée, D7) : la périmer après le succès laisserait une
  // réponse arrivée pendant le `DELETE` se poser sur une entrée supprimée.
  describe('removeEntry', () => {
    const EDITED = { id: 'a', translationGroup: 'groupe-source' }

    it("périme la traduction de l'entrée éditée puis appelle remove une fois (#748, R153)", async () => {
      const { translated, release } = mountTranslatedForm({ identity: () => ({ entry: 'a', locale: 'fr', group: 'groupe-source' }) })
      const pending = proposalOf(translated)
      const remove = vi.fn(async () => true)

      await translated.removeEntry(EDITED, remove)

      expect(translated.isTranslating.value).toBe(false)
      expect(remove).toHaveBeenCalledOnce()
      release(PROPOSAL)
      expect(await pending).toBeNull()
    })

    // D7 : la péremption précède `remove`. Le faux `remove` lit l'état de la traduction à son appel : périmée pendant la suppression,
    // pas après, ce qu'une péremption placée après `await remove()` (même dans un `finally`) ne fait pas.
    it("périme la traduction avant d'appeler remove (#748, R174)", async () => {
      const { translated, release } = mountTranslatedForm({ identity: () => ({ entry: 'a', locale: 'fr', group: 'groupe-source' }) })
      const pending = proposalOf(translated)
      const seenByRemove: { isTranslating: boolean | null; isExpiredCallPending: boolean | null } = {
        isTranslating: null,
        isExpiredCallPending: null,
      }
      const remove = vi.fn(async () => {
        seenByRemove.isTranslating = translated.isTranslating.value
        seenByRemove.isExpiredCallPending = translated.isExpiredCallPending.value

        return true
      })

      await translated.removeEntry(EDITED, remove)

      expect(seenByRemove).toEqual({ isTranslating: false, isExpiredCallPending: true })
      release(PROPOSAL)
      await pending
    })

    it("périme la traduction même quand remove rejette, et laisse l'erreur remonter (#748, R153)", async () => {
      const { translated, release } = mountTranslatedForm({ identity: () => ({ entry: 'a', locale: 'fr', group: 'groupe-source' }) })
      const pending = proposalOf(translated)
      const remove = vi.fn(async () => { throw new Error('suppression refusée') })

      await expect(translated.removeEntry(EDITED, remove)).rejects.toThrow('suppression refusée')

      expect(translated.isTranslating.value).toBe(false)
      release(PROPOSAL)
      expect(await pending).toBeNull()
    })

    it("ne périme pas la traduction d'une autre entrée, mais supprime (#748, R153)", async () => {
      const { translated, release } = mountTranslatedForm({ identity: () => ({ entry: 'b', locale: 'fr', group: 'autre-groupe' }) })
      const pending = proposalOf(translated)
      const remove = vi.fn(async () => true)

      await translated.removeEntry(EDITED, remove)

      expect(remove).toHaveBeenCalledOnce()
      release(PROPOSAL)
      expect(await pending).toEqual(PROPOSAL)
    })
  })
})
