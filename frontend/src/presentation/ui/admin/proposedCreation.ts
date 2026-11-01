import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { EntryFormState } from './entryFormState'

/**
 * Le `switchForm` que les formulaires par entrée passent à `proposeFrom` : ce que la page seule pose quand la proposition
 * arrive, avant que sa prose remplace celle du formulaire. Écrit une fois (spec 0004, D9), sur l'état d'un formulaire
 * (`useEntryFormState`) et sur les deux champs de `form` qui disent sa langue et son rattachement :
 *
 * - `editingId` est vidé : la proposition est une **création**, jamais la mise à jour de l'entrée dont elle vient ;
 * - `form.locale` devient la langue cible ;
 * - `form.translationGroup` devient le groupe de l'entrée **source** (`sourceGroup`, et non la valeur choisie dans le sélecteur
 *   « Version de »), `''` sur une page blanche : l'enregistrement rattache la version proposée à sa source sans autre geste,
 *   y compris quand ce groupe n'avait aucune traduction et que le sélecteur affichait « aucune » ;
 * - `proposalSourceLocale` est la langue traduite, celle que dit l'avis « proposition générée par IA » et qui verrouille le
 *   formulaire (`useEntryFormLock`).
 */
export function switchToProposedCreation(
  state: EntryFormState,
  form: { locale: Locale; translationGroup: string },
  targetLocale: Locale,
): (sourceLocale: Locale) => void {
  return (sourceLocale) => {
    state.editingId.value = null
    form.locale = targetLocale
    form.translationGroup = state.sourceGroup.value ?? ''
    state.proposalSourceLocale.value = sourceLocale
  }
}
