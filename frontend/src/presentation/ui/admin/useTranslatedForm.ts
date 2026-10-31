import { computed, type Ref } from 'vue'
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
   * génération, que le composable pose). Lu par `useAdminTranslation`, qui périme la traduction en vol quand elle change.
   */
  readonly identity: () => Omit<FormIdentity, 'generation'>
  /** Vrai quand la page ne veut aucune traduction (`useAdminTranslation`, `isRefused`) : ne dépend que de sources réactives. */
  readonly isRefused: () => boolean
}

export interface UseTranslatedFormResult extends UseAdminTranslationResult {
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
 * champs de prose (`proseFields.ts`), la proposition qu'elle pose, la suppression d'une entrée (`discard`).
 */
export function useTranslatedForm(options: UseTranslatedFormOptions): UseTranslatedFormResult {
  const translation = useAdminTranslation({
    isRefused: options.isRefused,
    // La génération du formulaire n'existe pas encore : toute identité en porte la même.
    formIdentity: () => ({ ...options.identity(), generation: 0 }),
  })

  const proseReadonlyHintId = `${options.hintIdPrefix}-prose-readonly-hint`
  const translationHintId = `${options.hintIdPrefix}-translation-hint`
  // La référence n'existe que pendant la traduction, avec le texte : jamais pendante.
  const proseDescribedBy = computed(() => (translation.isTranslating.value ? proseReadonlyHintId : undefined))
  const proseFieldAttrs = (helpId?: string) => ({
    readonly: translation.isTranslating.value,
    'aria-describedby': describedBy(helpId, proseDescribedBy.value),
  })

  return { ...translation, proseReadonlyHintId, translationHintId, proseDescribedBy, proseFieldAttrs }
}
