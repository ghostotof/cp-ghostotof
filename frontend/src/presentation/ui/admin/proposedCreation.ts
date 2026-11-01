import type { Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

/**
 * Ce que la bascule en création lit et écrit dans une page par entrée : l'entrée éditée, les deux champs du formulaire qui
 * disent sa langue et son rattachement, le groupe de l'entrée source et la langue dont la proposition est la traduction.
 */
export interface ProposedCreationState {
  readonly editingId: Ref<string | null>
  readonly form: { locale: Locale; translationGroup: string }
  readonly sourceGroup: Readonly<Ref<string | null>>
  readonly proposalSourceLocale: Ref<Locale | null>
}

/**
 * Le `switchForm` que les huit formulaires par entrée passent à `proposeFrom` : ce que la page seule pose quand la proposition
 * arrive, avant que sa prose remplace celle du formulaire. Écrit une fois pour les huit (spec 0004, D9) :
 *
 * - `editingId` est vidé : la proposition est une **création**, jamais la mise à jour de l'entrée dont elle vient ;
 * - `form.locale` devient la langue cible ;
 * - `form.translationGroup` devient le groupe de l'entrée **source** (`sourceGroup`, et non la valeur choisie dans le sélecteur
 *   « Version de »), `''` sur une page blanche : l'enregistrement rattache la version proposée à sa source sans autre geste,
 *   y compris quand ce groupe n'avait aucune traduction et que le sélecteur affichait « aucune » ;
 * - `proposalSourceLocale` est la langue traduite, celle que dit l'avis « proposition générée par IA » et qui verrouille le
 *   formulaire (`useEntryFormLock`).
 *
 * Les réglages « À propos » ne l'utilisent pas : leur proposition est différée, posée sur le formulaire de la langue cible une
 * fois lu (`AdminAboutSettingsForm`).
 */
export function switchToProposedCreation(state: ProposedCreationState, targetLocale: Locale): (sourceLocale: Locale) => void {
  return (sourceLocale) => {
    state.editingId.value = null
    state.form.locale = targetLocale
    state.form.translationGroup = state.sourceGroup.value ?? ''
    state.proposalSourceLocale.value = sourceLocale
  }
}
