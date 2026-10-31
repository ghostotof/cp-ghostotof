import { describe, expect, it } from 'vitest'
import { describedBy } from '../../../../src/presentation/ui/admin/describedBy'

/** La forme d'un `aria-describedby` : écrite une fois, lue par le verrou, la prose traduite, le bouton et les pages. */
describe('describedBy', () => {
  it("n'a aucun identifiant : undefined, l'attribut n'est pas rendu", () => {
    expect(describedBy()).toBeUndefined()
  })

  it('un seul identifiant : cet identifiant', () => {
    expect(describedBy('a')).toBe('a')
  })

  it('joint les identifiants par une espace, dans leur ordre, et ignore un identifiant absent', () => {
    expect(describedBy('a', undefined, 'b')).toBe('a b')
  })

  it("ignore un identifiant vide, et rend undefined quand il n'en reste aucun", () => {
    expect(describedBy('a', '', 'b')).toBe('a b')
    expect(describedBy(undefined, '')).toBeUndefined()
  })
})
