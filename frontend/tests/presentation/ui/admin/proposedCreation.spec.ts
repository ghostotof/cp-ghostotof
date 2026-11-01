import { describe, expect, it } from 'vitest'
import { reactive } from 'vue'
import type { Locale } from '../../../../src/domain/portfolio/entities/Locale'
import { useEntryFormState } from '../../../../src/presentation/ui/admin/entryFormState'
import { switchToProposedCreation } from '../../../../src/presentation/ui/admin/proposedCreation'

/** L'état d'une page par entrée au moment où la proposition arrive : une entrée éditée en français, rattachée à un groupe. */
function editedEntry(sourceGroup: string | null) {
  const state = useEntryFormState()
  state.editingId.value = 'entree-1'
  state.sourceGroup.value = sourceGroup
  const form = reactive<{ locale: Locale; translationGroup: string; title: string }>({
    locale: 'fr',
    translationGroup: '',
    title: 'Panne',
  })

  return { state, form }
}

describe('switchToProposedCreation', () => {
  it(
    "la bascule vide l'entrée éditée, pose la langue cible, le groupe de la source et la langue source (#748, R189)",
    () => {
      const { state, form } = editedEntry('groupe-source')

      switchToProposedCreation(state, form, 'en')('fr')

      expect(state.editingId.value).toBeNull()
      expect(form.locale).toBe('en')
      expect(form.translationGroup).toBe('groupe-source')
      expect(state.proposalSourceLocale.value).toBe('fr')
    },
  )

  it("rattache la proposition à une chaîne vide quand la source n'a aucun groupe : la page blanche (#748, R189)", () => {
    const { state, form } = editedEntry(null)
    form.translationGroup = 'choisi-dans-le-selecteur'

    switchToProposedCreation(state, form, 'en')('fr')

    expect(form.translationGroup).toBe('')
  })

  it('ne pose rien avant que le rappel soit appelé, et laisse les champs hors bascule intacts (#748, R189)', () => {
    const { state, form } = editedEntry('groupe-source')

    const switchForm = switchToProposedCreation(state, form, 'en')

    expect(state.editingId.value).toBe('entree-1')
    expect(form.locale).toBe('fr')
    expect(state.proposalSourceLocale.value).toBeNull()

    switchForm('fr')

    expect(form.title).toBe('Panne')
  })
})
