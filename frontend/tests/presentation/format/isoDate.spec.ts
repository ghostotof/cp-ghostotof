import { describe, expect, it } from 'vitest'
import { parseIsoDate } from '../../../src/presentation/format/isoDate'

describe('parseIsoDate', () => {
  /**
   * Le test qui compte : c'est cette assertion qui casse si quelqu'un
   * « simplifie » l'implémentation en `new Date(isoDate)`. La chaîne réduite à
   * une date serait alors lue en UTC, et la journée reculerait d'un cran pour
   * tout visiteur à l'ouest de Greenwich.
   */
  it('lit la date en heure locale, et non en UTC', () => {
    const parsed = parseIsoDate('2027-12-31')

    expect(parsed).not.toBeNull()
    expect(parsed?.getFullYear()).toBe(2027)
    // getMonth() est indexé à zéro : 11 vaut décembre.
    expect(parsed?.getMonth()).toBe(11)
    expect(parsed?.getDate()).toBe(31)
  })

  it.each([
    ['chaîne vide', ''],
    ['texte quelconque', 'bientôt'],
    ['mois inexistant', '2027-13-01'],
    ['date déjà horodatée', '2027-12-31T10:00:00Z'],
  ])('renvoie null sur une valeur illisible (%s)', (_cas, valeur) => {
    expect(parseIsoDate(valeur)).toBeNull()
  })
})
