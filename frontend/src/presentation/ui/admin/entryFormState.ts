import { ref, type Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

/**
 * Les quatre états que partagent les formulaires par entrée du backoffice, hors les champs du formulaire lui-même :
 *
 * - `editingId` : l'entrée en cours d'édition, `null` pour une création ;
 * - `isSubmitting` : un enregistrement est parti et n'a pas répondu ;
 * - `sourceGroup` : le groupe de traduction de l'entrée dont le formulaire est issu (édition, « Créer la version XX »), `null`
 *   sur une page blanche. Distinct de `form.translationGroup`, qui est la valeur *choisie* dans le sélecteur « Version de » :
 *   la bascule d'une proposition y rattache la version proposée au groupe de la **source** (spec 0004, D9), y compris quand ce
 *   groupe n'avait aucune traduction et que le sélecteur affichait « aucune » ;
 * - `proposalSourceLocale` : la langue de l'entrée dont le formulaire est une proposition traduite, `null` sinon. Elle porte
 *   l'avis « proposition générée par IA » et verrouille la langue (`useEntryFormLock`) ; chaque geste qui remplit le formulaire
 *   (réinitialisation, « Modifier », « Créer la version XX ») la remet à `null`.
 */
export interface EntryFormState {
  readonly editingId: Ref<string | null>
  readonly isSubmitting: Ref<boolean>
  readonly sourceGroup: Ref<string | null>
  readonly proposalSourceLocale: Ref<Locale | null>
}

/**
 * Un état de formulaire neuf, écrit une fois pour les formulaires par entrée : chaque appel rend ses propres `Ref`,
 * jamais partagés entre deux formulaires (une page qui monte plusieurs formulaires appelle la fonction plusieurs fois).
 * Une page déstructure ce qu'elle lit, **avant** l'appel de `useTranslatedForm` dont les options le lisent
 * (`translatedFormDeclarationOrder.spec.ts`), et passe l'objet entier à `switchToProposedCreation`.
 */
export function useEntryFormState(): EntryFormState {
  return {
    editingId: ref<string | null>(null),
    isSubmitting: ref(false),
    sourceGroup: ref<string | null>(null),
    proposalSourceLocale: ref<Locale | null>(null),
  }
}
