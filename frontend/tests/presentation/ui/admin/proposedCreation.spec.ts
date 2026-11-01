import { describe, expect, it } from 'vitest'
import { reactive, ref } from 'vue'
import type { Locale } from '../../../../src/domain/portfolio/entities/Locale'
import { switchToProposedCreation } from '../../../../src/presentation/ui/admin/proposedCreation'

/** L'état d'une page par entrée au moment où la proposition arrive : une entrée éditée en français, rattachée à un groupe. */
function editedEntryState(sourceGroup: string | null) {
  return {
    editingId: ref<string | null>('entree-1'),
    form: reactive<{ locale: Locale; translationGroup: string; title: string }>({
      locale: 'fr',
      translationGroup: '',
      title: 'Panne',
    }),
    sourceGroup: ref<string | null>(sourceGroup),
    proposalSourceLocale: ref<Locale | null>(null),
  }
}

describe('switchToProposedCreation', () => {
  it(
    "la bascule vide l'entrée éditée, pose la langue cible, le groupe de la source et la langue source (#748, R189)",
    () => {
      const state = editedEntryState('groupe-source')

      switchToProposedCreation(state, 'en')('fr')

      expect(state.editingId.value).toBeNull()
      expect(state.form.locale).toBe('en')
      expect(state.form.translationGroup).toBe('groupe-source')
      expect(state.proposalSourceLocale.value).toBe('fr')
    },
  )

  it("rattache la proposition à une chaîne vide quand la source n'a aucun groupe : la page blanche (#748, R189)", () => {
    const state = editedEntryState(null)
    state.form.translationGroup = 'choisi-dans-le-selecteur'

    switchToProposedCreation(state, 'en')('fr')

    expect(state.form.translationGroup).toBe('')
  })

  it('ne pose rien avant que le rappel soit appelé, et laisse les champs hors bascule intacts (#748, R189)', () => {
    const state = editedEntryState('groupe-source')

    const switchForm = switchToProposedCreation(state, 'en')

    expect(state.editingId.value).toBe('entree-1')
    expect(state.form.locale).toBe('fr')
    expect(state.proposalSourceLocale.value).toBeNull()

    switchForm('fr')

    expect(state.form.title).toBe('Panne')
  })
})
