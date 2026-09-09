import { describe, expect, it } from 'vitest'
import fr from '../../../src/infrastructure/i18n/locales/fr.json'
import en from '../../../src/infrastructure/i18n/locales/en.json'

type Messages = { [key: string]: string | Messages }

/**
 * Aplatit l'arborescence en chemins pointés : « stack.status.eol » plutôt
 * qu'une comparaison objet par objet, qui dirait qu'il manque quelque chose
 * sans dire quoi.
 */
function flatten(messages: Messages, prefix = ''): string[] {
  return Object.entries(messages).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key

    return typeof value === 'string' ? [path] : flatten(value, path)
  })
}

/**
 * Le site est bilingue de bout en bout, et vue-i18n ne signale une clé absente
 * qu'à l'exécution — par un avertissement en console que personne ne lit, et
 * une clé brute affichée à la place du texte. Une traduction ajoutée d'un seul
 * côté passerait donc les tests, le lint et le build sans qu'on s'en aperçoive,
 * jusqu'à ce qu'un visiteur anglophone tombe sur « stack.vulnerabilities.title ».
 *
 * Ce test ne vérifie pas la qualité des traductions, seulement qu'aucune ne
 * manque. C'est peu, mais c'est exactement ce que l'outillage ne fait pas.
 */
describe('fichiers de traduction', () => {
  const frenchKeys = flatten(fr as Messages)
  const englishKeys = flatten(en as Messages)

  it('déclarent exactement les mêmes clés dans les deux langues', () => {
    expect([...englishKeys].sort()).toEqual([...frenchKeys].sort())
  })

  it('ne déclarent aucune clé en double', () => {
    expect(new Set(frenchKeys).size).toBe(frenchKeys.length)
  })

  /**
   * Une valeur vide se voit encore moins qu'une clé absente : vue-i18n rend
   * une chaîne vide sans le moindre avertissement, et l'élément disparaît
   * silencieusement de la page.
   */
  it('ne laissent aucun message vide', () => {
    const empty = (messages: Messages, prefix = ''): string[] =>
      Object.entries(messages).flatMap(([key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key

        if (typeof value !== 'string') return empty(value, path)

        return '' === value.trim() ? [path] : []
      })

    expect(empty(fr as Messages)).toEqual([])
    expect(empty(en as Messages)).toEqual([])
  })
})
