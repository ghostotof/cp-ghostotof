import { describe, expect, it } from 'vitest'
import { resolveIcon } from '../../../src/presentation/ui/icons'

describe('resolveIcon', () => {
  it('retourne undefined quand aucune clé n\'est fournie', () => {
    expect(resolveIcon(undefined)).toBeUndefined()
  })

  it('retourne undefined pour une clé inconnue', () => {
    expect(resolveIcon('clé-inexistante')).toBeUndefined()
  })

  it('retourne un composant pour une clé enregistrée', () => {
    expect(resolveIcon('check')).toBeDefined()
  })
})
