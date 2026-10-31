import { computed, ref, type Ref } from 'vue'
import {
  useAdminTranslation,
  type UseAdminTranslationResult,
} from '../../../application/admin/translation/useAdminTranslation'
import type { FormIdentity } from '../../../domain/admin/translation/entities/FormIdentity'
import { describedBy } from './describedBy'

/**
 * L'identifiant d'un champ de prose : `<préfixe du formulaire>-<champ en kebab>` (`admin-incident` + `rootCause` donne
 * `admin-incident-root-cause`). La règle que suivent tous les champs de prose du backoffice, écrite une fois : un spec
 * dérive de la liste des champs d'une page (`proseFields.ts`) l'identifiant que le formulaire doit rendre.
 */
export function proseFieldId(prefix: string, field: string): string {
  return `${prefix}-${field.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`
}

export interface UseTranslatedFormOptions {
  /**
   * Préfixe des identifiants des deux textes que le formulaire rend (`<préfixe>-prose-readonly-hint`,
   * `<préfixe>-translation-hint`) : unique dans la page, qui porte plusieurs formulaires.
   */
  readonly hintIdPrefix: string
  /**
   * Ce qui fait l'identité du formulaire : l'entrée éditée, la langue, le groupe source (`FormIdentity` moins sa
   * génération, que le composable pose : `renewForm()`). Lu par `useAdminTranslation`, qui périme la traduction en vol et
   * efface l'échec quand elle change.
   */
  readonly identity: () => Omit<FormIdentity, 'generation'>
  /** Vrai quand la page ne veut aucune traduction (`useAdminTranslation`, `isRefused`) : ne dépend que de sources réactives. */
  readonly isRefused: () => boolean
}

export interface UseTranslatedFormResult extends UseAdminTranslationResult {
  /**
   * Fait avancer la génération du formulaire : à appeler en premier par **chaque** remise à zéro du formulaire
   * (`resetForm()`, `resetPrincipleForm()`, `resetTraitForm()`…). Une remise à zéro ne change ni l'entrée, ni la langue, ni le
   * groupe d'un formulaire déjà vierge, et la réponse d'une traduction partie avant elle poserait sa proposition sur le
   * formulaire vidé : la génération, elle, change, et périme la traduction en vol comme elle efface l'échec (ce
   * qu'aucune identité ne voit, la suppression d'une entrée, passe par `discardIfDeleted`).
   */
  readonly renewForm: () => void
  /**
   * À appeler par la page quand elle supprime une entrée, avant la suppression : périme la traduction en vol si elle porte sur
   * cette entrée. Une suppression ne change rien au formulaire, aucune identité ne la voit (R140). Deux cas, lus dans
   * l'identité du formulaire : l'entrée supprimée est celle qu'on édite, ou le formulaire est une création rattachée à un
   * groupe (`group` posé, aucune entrée éditée : « Créer la version XX », ou une proposition) et l'entrée supprimée est de ce
   * groupe — sa source. Supprimer un autre membre du groupe d'une entrée éditée, ou une entrée d'un autre groupe, ne change
   * rien : la traduction garde son entrée.
   */
  readonly discardIfDeleted: (entry: { readonly id: string; readonly translationGroup: string | null }) => void
  /** Identifiant du texte d'aide de la prose (`TranslationReadonlyHint`), rendu seulement pendant la traduction. */
  readonly proseReadonlyHintId: string
  /** Identifiant du texte de l'attente après « Annuler » (`TranslateEntryButton`, `hintId`). */
  readonly translationHintId: string
  /** `aria-describedby` d'un champ de prose : le texte d'aide pendant la traduction seulement, `undefined` sinon. */
  readonly proseDescribedBy: Readonly<Ref<string | undefined>>
  /**
   * Ce que chaque champ de prose reçoit, lié par `v-bind` : en lecture seule (`readonly`, pas `disabled` : lisible et
   * focusable) et décrit par le texte d'aide, pendant la traduction. `helpId` est l'aide propre du champ, gardée devant.
   */
  readonly proseFieldAttrs: (helpId?: string) => { readonly: boolean; 'aria-describedby': string | undefined }
}

/**
 * Ce qu'un formulaire à traduction assistée tient à part du formulaire lui-même (R138, ADR 0004 D4) : l'identité qui
 * périme la traduction, le refus de la page, et ce que la prose en tire — lecture seule pendant la traduction, décrite
 * par un texte d'aide, jamais d'identifiant pendant. Écrit une fois pour les neuf formulaires : sans lui, chacun
 * recopiait l'identifiant du texte, la référence et le prédicat des champs.
 *
 * Il appelle `useAdminTranslation` et rend tout ce qu'il rend. La page garde ce qui lui est propre : le formulaire, ses
 * champs de prose (`proseFields.ts`), la proposition qu'elle pose, la suppression d'une entrée (qu'elle dit à `discardIfDeleted`).
 */
export function useTranslatedForm(options: UseTranslatedFormOptions): UseTranslatedFormResult {
  // Le numéro du formulaire : une remise à zéro le fait avancer, et rien d'autre (voir `renewForm`).
  const generation = ref(0)
  const translation = useAdminTranslation({
    isRefused: options.isRefused,
    formIdentity: () => ({ ...options.identity(), generation: generation.value }),
  })
  const renewForm = (): void => {
    generation.value += 1
  }
  const discardIfDeleted = (entry: { readonly id: string; readonly translationGroup: string | null }): void => {
    const { entry: editingId, group } = options.identity()
    const isEdited = entry.id === editingId
    // Une création rattachée : aucune entrée éditée, un groupe source. L'entrée de ce groupe qu'on supprime en est la source.
    const isSourceOfAttachedCreation = null === editingId && null !== group && entry.translationGroup === group

    if (isEdited || isSourceOfAttachedCreation) {
      translation.discard()
    }
  }

  const proseReadonlyHintId = `${options.hintIdPrefix}-prose-readonly-hint`
  const translationHintId = `${options.hintIdPrefix}-translation-hint`
  // La référence n'existe que pendant la traduction, avec le texte : jamais pendante.
  const proseDescribedBy = computed(() => (translation.isTranslating.value ? proseReadonlyHintId : undefined))
  const proseFieldAttrs = (helpId?: string) => ({
    readonly: translation.isTranslating.value,
    'aria-describedby': describedBy(helpId, proseDescribedBy.value),
  })

  return { ...translation, renewForm, discardIfDeleted, proseReadonlyHintId, translationHintId, proseDescribedBy, proseFieldAttrs }
}
