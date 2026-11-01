import { describe, expect, it } from 'vitest'
import { useEntryFormState } from '../../../../src/presentation/ui/admin/entryFormState'

describe('useEntryFormState', () => {
  it("l'état d'un formulaire neuf : aucune entrée, rien en cours, aucun groupe ni langue source (#748, R204)", () => {
    const state = useEntryFormState()

    expect(state.editingId.value).toBeNull()
    expect(state.isSubmitting.value).toBe(false)
    expect(state.sourceGroup.value).toBeNull()
    expect(state.proposalSourceLocale.value).toBeNull()
  })

  it("deux appels rendent deux états indépendants : l'écriture de l'un ne se lit pas dans l'autre (#748, R204)", () => {
    const first = useEntryFormState()
    const second = useEntryFormState()

    first.editingId.value = 'entree-1'
    first.isSubmitting.value = true
    first.sourceGroup.value = 'groupe-source'
    first.proposalSourceLocale.value = 'fr'

    expect(second.editingId.value).toBeNull()
    expect(second.isSubmitting.value).toBe(false)
    expect(second.sourceGroup.value).toBeNull()
    expect(second.proposalSourceLocale.value).toBeNull()
  })
})
