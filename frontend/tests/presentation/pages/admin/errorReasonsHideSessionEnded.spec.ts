import { describe, expect, it } from 'vitest'

/**
 * Garde de source (#748, constat 7) : une page ou un composant d'administration qui compose la clé d'un
 * message d'erreur à partir d'une raison (`admin.<contexte>.errors.${reason}`) doit d'abord passer cette
 * raison par `visibleErrorReason()`, qui tait « session-ended » : le bandeau de session finie est alors
 * la seule annonce. Sans cela, la raison commune s'afficherait en clé brute, sous le bandeau.
 *
 * Un test de rendu par page ne verrait pas la page qu'on oublie d'écrire ; la garde porte sur la source
 * de tout ce qui compose une telle clé.
 */
const sources = import.meta.glob(
  ['../../../../src/presentation/pages/admin/*.vue', '../../../../src/presentation/ui/admin/*.vue'],
  { query: '?raw', import: 'default', eager: true },
) as Record<string, string>

describe('raisons d\'erreur des pages d\'administration', () => {
  it('le glob voit les pages et les composants d\'administration', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(10)
  })

  it('tout fichier qui compose une clé « errors.${…} » passe la raison par visibleErrorReason()', () => {
    const offenders = Object.entries(sources)
      .filter(([, source]) => source.includes('errors.${') && !source.includes('visibleErrorReason('))
      .map(([path]) => path.replace('../../../../src/presentation/', ''))
      .sort()

    expect(offenders).toEqual([])
  })
})
