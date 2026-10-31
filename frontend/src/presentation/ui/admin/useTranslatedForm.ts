import { computed, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { applyTranslationProposal, collectProseFields } from '../../../application/admin/translation/proseFieldCopy'
import {
  useAdminTranslation,
  type UseAdminTranslationResult,
} from '../../../application/admin/translation/useAdminTranslation'
import type { FormIdentity } from '../../../domain/admin/translation/entities/FormIdentity'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { describedBy } from './describedBy'

/** Ce que `discardIfDeleted` et `removeEntry` lisent d'une entrée qu'on supprime : sa clé et son groupe de traduction. */
export type DeletableEntry = { readonly id: string; readonly translationGroup: string | null }

export interface UseTranslatedFormOptions<F extends string> {
  /**
   * Préfixe des identifiants des deux textes que le formulaire rend (`<préfixe>-prose-readonly-hint`,
   * `<préfixe>-translation-hint`) : unique dans la page, qui porte plusieurs formulaires.
   */
  readonly hintIdPrefix: string
  /**
   * L'objet réactif du formulaire : le composable en lit la prose (`hasProse`, la prose envoyée) et la remplace par la proposition
   * (`proposeFrom`). Il doit être déclaré avant l'appel du composable.
   */
  readonly form: Record<F, string>
  /** Les champs de prose du formulaire, ceux que la traduction envoie et remplace (`presentation/pages/admin/proseFields.ts`). */
  readonly proseFields: readonly F[]
  /**
   * Ce qui fait l'identité du formulaire : l'entrée éditée, la langue, le groupe source (`FormIdentity` moins sa
   * génération, que le composable pose : `renewForm()`). Lu par `useAdminTranslation`, qui périme la traduction en vol et
   * efface l'échec quand elle change ; `locale` est la langue source d'une demande (`proposeFrom`).
   */
  readonly identity: () => Omit<FormIdentity, 'generation'>
  /**
   * Vrai quand la page ne veut aucune traduction : ne dépend que de sources réactives. Le composable y ajoute l'absence de
   * prose (`hasProse`) : la page n'écrit que ses propres conditions.
   */
  readonly isRefused: () => boolean
}

export interface UseTranslatedFormResult extends Omit<UseAdminTranslationResult, 'discard'> {
  /**
   * Fait avancer la génération du formulaire : à appeler en premier par **chaque** geste qui remplit ou vide le formulaire —
   * remise à zéro (`resetForm()`, `resetPrincipleForm()`, `resetTraitForm()`…), « Modifier » (`startEdit`…), « Créer la version
   * XX » (`startCreateVersion`…). Un tel geste peut ne changer ni l'entrée, ni la langue, ni le groupe (un formulaire déjà
   * vierge, « Modifier » de l'entrée déjà éditée, « Créer la version XX » de la même ligne), et la réponse d'une traduction
   * partie avant lui poserait sa proposition sur le formulaire vidé ou rechargé : la génération, elle, change, et périme la
   * traduction en vol comme elle efface l'échec (ce qu'aucune identité ne voit, la suppression d'une entrée, passe par
   * `discardIfDeleted`).
   */
  readonly renewForm: () => void
  /**
   * Périme la traduction en vol si elle porte sur l'entrée qu'on supprime. Une suppression ne change rien au formulaire,
   * aucune identité ne la voit (R140). Deux cas, lus dans l'identité du formulaire : l'entrée supprimée est celle qu'on édite,
   * ou le formulaire est une création rattachée à un groupe (`group` posé, aucune entrée éditée : « Créer la version XX », ou
   * une proposition) et l'entrée supprimée est de ce groupe — sa source. Supprimer un autre membre du groupe d'une entrée
   * éditée, ou une entrée d'un autre groupe, ne change rien : la traduction garde son entrée. Une page supprime par
   * `removeEntry`, qui l'appelle.
   */
  readonly discardIfDeleted: (entry: DeletableEntry) => void
  /** Un champ de `proseFields` du formulaire n'est pas blanc : sans prose, rien à traduire (le refus exposé le contient). */
  readonly hasProse: Readonly<Ref<boolean>>
  /** Le texte, traduit, de l'échec de la dernière traduction (`admin.translation.errors.<raison>`), `null` sans échec. */
  readonly errorText: Readonly<Ref<string | null>>
  /**
   * Demande une proposition dans `targetLocale`, puis bascule le formulaire en **création** avec elle : la prose est remplacée,
   * le reste gardé. Rien n'est enregistré (ADR 0004, D4). La langue source est celle de l'identité du formulaire ; la prose
   * envoyée, celle de `proseFields` (les champs blancs omis). Sur un refus, un échec ou une réponse périmée, il ne se passe
   * rien : le formulaire reste intact et `errorText` dit l'échec. Sinon, `switchForm(sourceLocale)` est appelée **avant** que la
   * prose soit remplacée : la page y pose ce qu'elle seule sait (l'entrée éditée vidée, la langue cible, le groupe source,
   * l'avis de proposition).
   */
  readonly proposeFrom: (targetLocale: Locale, switchForm: (sourceLocale: Locale) => void) => Promise<void>
  /**
   * Supprime une entrée : périme d'abord la traduction qui lui est rattachée (`discardIfDeleted`), puis attend `remove`. La
   * page garde le `window.confirm`, dont le texte lui est propre. Limite acceptée (D7) : une suppression refusée périme quand
   * même la traduction — la périmer après le succès laisserait une réponse arrivée pendant le `DELETE` se poser sur une entrée
   * supprimée.
   */
  readonly removeEntry: (entry: DeletableEntry, remove: () => Promise<unknown>) => Promise<void>
  /** Identifiant du texte d'aide de la prose (`TranslationReadonlyHint`), rendu seulement pendant la traduction. */
  readonly proseReadonlyHintId: string
  /** Identifiant du texte de l'attente après « Annuler » (`TranslateEntryButton`, `hintId`). */
  readonly translationHintId: string
  /**
   * Ce que chaque champ de prose reçoit, lié par `v-bind` : en lecture seule (`readonly`, pas `disabled` : lisible et
   * focusable) et décrit par le texte d'aide, pendant la traduction. `helpId` est l'aide propre du champ, gardée devant.
   */
  readonly proseFieldAttrs: (helpId?: string) => { readonly: boolean; 'aria-describedby': string | undefined }
}

/**
 * Ce qu'un formulaire à traduction assistée tient à part du formulaire lui-même (ADR 0004 D4) : l'identité qui périme la
 * traduction, le refus de la page, la demande de proposition et la bascule du formulaire, la suppression d'une entrée, le texte
 * de l'échec, et ce que la prose en tire — lecture seule pendant la traduction, décrite par un texte d'aide, jamais
 * d'identifiant pendant. Écrit une fois pour les neuf formulaires.
 *
 * Il appelle `useAdminTranslation` et en rend ce qu'une page lit, sans `discard` : un geste de page périme par son identité
 * (`renewForm`) ou par `removeEntry`. La page garde ce qui lui est propre : le formulaire et ses gestes (`startEdit`,
 * `startCreateVersion`, l'enregistrement), ses champs de prose (`proseFields.ts`), le `window.confirm` de la suppression, et le
 * bloc de gabarit — `TranslateEntryButton`, `TranslationReadonlyHint`, l'alerte d'erreur —, que chaque page écrit : chacune de
 * ses liaisons porte une valeur de la page (la langue du formulaire, l'aide du verrou, son `describedBy`), et un composant qui
 * les recevrait toutes ne ferait que déplacer la recopie. Les réglages « À propos » (proposition différée) lisent `hasProse`
 * et `errorText` mais gardent leur `translate` direct, sans `proposeFrom`.
 */
export function useTranslatedForm<F extends string>(options: UseTranslatedFormOptions<F>): UseTranslatedFormResult {
  const { t } = useI18n()
  // Le numéro du formulaire : un geste qui le remplit ou le vide le fait avancer, et rien d'autre (voir `renewForm`).
  const generation = ref(0)
  const hasProse = computed(() => options.proseFields.some((field) => '' !== options.form[field].trim()))
  const { discard, ...translation } = useAdminTranslation({
    isRefused: () => !hasProse.value || options.isRefused(),
    formIdentity: () => ({ ...options.identity(), generation: generation.value }),
  })
  const renewForm = (): void => {
    generation.value += 1
  }
  const discardIfDeleted = (entry: DeletableEntry): void => {
    const { entry: editingId, group } = options.identity()
    const isEdited = entry.id === editingId
    // Une création rattachée : aucune entrée éditée, un groupe source. L'entrée de ce groupe qu'on supprime en est la source.
    const isSourceOfAttachedCreation = null === editingId && null !== group && entry.translationGroup === group

    if (isEdited || isSourceOfAttachedCreation) {
      discard()
    }
  }
  const removeEntry = async (entry: DeletableEntry, remove: () => Promise<unknown>): Promise<void> => {
    discardIfDeleted(entry)

    await remove()
  }
  const proposeFrom = async (targetLocale: Locale, switchForm: (sourceLocale: Locale) => void): Promise<void> => {
    const sourceLocale = options.identity().locale
    const proposal = await translation.translate(sourceLocale, targetLocale, collectProseFields(options.form, options.proseFields))
    if (null === proposal) {
      return
    }

    switchForm(sourceLocale)
    applyTranslationProposal(options.form, options.proseFields, proposal)
  }
  const errorText = computed(() => {
    const reason = translation.errorReason.value

    return reason ? t(`admin.translation.errors.${reason}`) : null
  })

  const proseReadonlyHintId = `${options.hintIdPrefix}-prose-readonly-hint`
  const translationHintId = `${options.hintIdPrefix}-translation-hint`
  const proseFieldAttrs = (helpId?: string) => ({
    readonly: translation.isTranslating.value,
    // La référence n'existe que pendant la traduction, avec le texte : jamais pendante.
    'aria-describedby': describedBy(helpId, translation.isTranslating.value ? proseReadonlyHintId : undefined),
  })

  return {
    ...translation,
    renewForm,
    discardIfDeleted,
    hasProse,
    errorText,
    proposeFrom,
    removeEntry,
    proseReadonlyHintId,
    translationHintId,
    proseFieldAttrs,
  }
}
