import { ref } from 'vue'
import { describe, expect, it } from 'vitest'
import { describedBy, useEntryFormLock } from '../../../../src/presentation/ui/admin/useEntryFormLock'

/** Le verrou d'un formulaire par entrée (#748, R92) : l'état, les verrous dérivés, les identifiants ARIA. */
describe('useEntryFormLock', () => {
  function lockOf(options: { localeHelpId?: string } = {}) {
    const isEditing = ref(false)
    const hasProposal = ref(false)
    const lock = useEntryFormLock({
      hintId: 'hint',
      isEditing: () => isEditing.value,
      hasProposal: () => hasProposal.value,
      ...options,
    })

    return { lock, isEditing, hasProposal }
  }

  it("l'état vaut 'none' sans édition ni proposition", () => {
    const { lock } = lockOf()

    expect(lock.state).toBe('none')
    expect(lock.isEntryLocked).toBe(false)
  })

  it("l'état vaut 'editing' en édition, 'proposal' pendant une proposition, et l'édition l'emporte", () => {
    const { lock, isEditing, hasProposal } = lockOf()

    hasProposal.value = true
    expect(lock.state).toBe('proposal')
    expect(lock.isEntryLocked).toBe(true)

    isEditing.value = true
    expect(lock.state).toBe('editing')
    expect(lock.isEntryLocked).toBe(true)

    hasProposal.value = false
    expect(lock.state).toBe('editing')
  })

  it("l'identifiant du texte se lit sans `.value`", () => {
    expect(lockOf().lock.hintId).toBe('hint')
  })

  it("localeDescribedBy vaut undefined sans verrou ni aide, et ne désigne que l'aide hors verrou", () => {
    expect(lockOf().lock.localeDescribedBy).toBeUndefined()
    expect(lockOf({ localeHelpId: 'help' }).lock.localeDescribedBy).toBe('help')
  })

  it("localeDescribedBy désigne l'aide puis le texte pendant le verrou, le texte seul sans aide", () => {
    const withHelp = lockOf({ localeHelpId: 'help' })
    withHelp.isEditing.value = true
    expect(withHelp.lock.localeDescribedBy).toBe('help hint')

    const withoutHelp = lockOf()
    withoutHelp.hasProposal.value = true
    expect(withoutHelp.lock.localeDescribedBy).toBe('hint')
  })

  it('describedBy joint les identifiants non vides par une espace, undefined s\'il n\'y en a aucun', () => {
    expect(describedBy('a', undefined, '', 'b')).toBe('a b')
    expect(describedBy()).toBeUndefined()
    expect(describedBy(undefined, '')).toBeUndefined()
  })
})
