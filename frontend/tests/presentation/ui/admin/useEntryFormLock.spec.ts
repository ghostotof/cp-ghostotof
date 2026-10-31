import { ref } from 'vue'
import { describe, expect, it } from 'vitest'
import type { Locale } from '../../../../src/domain/portfolio/entities/Locale'
import { describedBy, useEntryFormLock } from '../../../../src/presentation/ui/admin/useEntryFormLock'

/** Le verrou d'un formulaire par entrée (#748, R92) : l'état, les verrous dérivés, les identifiants ARIA. */
describe('useEntryFormLock', () => {
  // Les refs du formulaire, comme le font les pages : `isEditing` et la locale de la source de la proposition.
  function lockOf(options: { localeHelpId?: string } = {}) {
    const isEditing = ref(false)
    const proposalSourceLocale = ref<Locale | null>(null)
    const lock = useEntryFormLock({ hintId: 'hint', isEditing, proposalSourceLocale: proposalSourceLocale, ...options })

    return { lock, isEditing, proposalSourceLocale }
  }

  it("l'état vaut 'none' sans édition ni proposition", () => {
    const { lock } = lockOf()

    expect(lock.state).toBe('none')
    expect(lock.isEntryLocked).toBe(false)
  })

  it("l'état vaut 'editing' en édition, 'proposal' pendant une proposition, et l'édition l'emporte", () => {
    const { lock, isEditing, proposalSourceLocale } = lockOf()

    proposalSourceLocale.value = 'fr'
    expect(lock.state).toBe('proposal')
    expect(lock.isEntryLocked).toBe(true)

    isEditing.value = true
    expect(lock.state).toBe('editing')
    expect(lock.isEntryLocked).toBe(true)

    proposalSourceLocale.value = null
    expect(lock.state).toBe('editing')
  })

  it("accepte aussi des getters, lus à chaque évaluation", () => {
    const isEditing = ref(false)
    const proposalSourceLocale = ref<Locale | null>(null)
    const lock = useEntryFormLock({
      hintId: 'hint',
      isEditing: () => isEditing.value,
      proposalSourceLocale: () => proposalSourceLocale.value,
    })
    expect(lock.state).toBe('none')

    proposalSourceLocale.value = 'en'
    expect(lock.state).toBe('proposal')

    isEditing.value = true
    expect(lock.state).toBe('editing')
  })

  it("isProposalLocked et proposalDescribedBy ne valent que pendant une proposition, pas en édition", () => {
    const { lock, isEditing, proposalSourceLocale } = lockOf()
    expect(lock.isProposalLocked).toBe(false)
    expect(lock.proposalDescribedBy).toBeUndefined()

    proposalSourceLocale.value = 'fr'
    expect(lock.isProposalLocked).toBe(true)
    expect(lock.proposalDescribedBy).toBe('hint')

    isEditing.value = true
    expect(lock.isProposalLocked).toBe(false)
    expect(lock.proposalDescribedBy).toBeUndefined()
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
    withoutHelp.proposalSourceLocale.value = 'fr'
    expect(withoutHelp.lock.localeDescribedBy).toBe('hint')
  })

  it('describedBy joint les identifiants non vides par une espace, undefined s\'il n\'y en a aucun', () => {
    expect(describedBy('a', undefined, '', 'b')).toBe('a b')
    expect(describedBy()).toBeUndefined()
    expect(describedBy(undefined, '')).toBeUndefined()
  })
})
