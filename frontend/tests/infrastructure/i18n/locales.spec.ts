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

  /**
   * Chaque formulaire du backoffice nomme le lien vers les autres versions
   * linguistiques d'un contenu (le groupe de traduction, CONTEXT.md). Un même
   * concept doit porter un même libellé d'une page à l'autre : l'anglais disait
   * « Version of » sur deux pages et « Translation of » sur quatre (#602).
   */
  it('nomment le lien entre versions linguistiques d’un seul libellé par langue', () => {
    for (const messages of [fr, en] as Messages[]) {
      const labels = new Set(
        values(messages)
          .filter(([path]) => path.endsWith('.translationOfLabel'))
          .map(([, value]) => value),
      )

      expect([...labels]).toHaveLength(1)
    }
  })

  /**
   * Les termes que le glossaire du domaine (CONTEXT.md, #602) range sous
   * « À éviter » ne doivent pas reparaître dans l'interface : c'est là que le
   * vocabulaire dérive le plus vite, une clé à la fois.
   */
  it('n’emploient aucun terme écarté par le glossaire du domaine', () => {
    const avoided: RegExp[] = [
      // « CV sans identité » : publiable, mais pas anonyme.
      /\banonymous CV\b/i,
      /\bCV anonym/i,
    ]

    const offending = [...values(fr as Messages), ...values(en as Messages)]
      .filter(([, value]) => avoided.some((term) => term.test(value)))
      .map(([path, value]) => `${path} : ${value}`)

    expect(offending).toEqual([])
  })
})

/** Chaque message avec son chemin pointé, pour nommer la clé fautive. */
function values(messages: Messages, prefix = ''): [string, string][] {
  return Object.entries(messages).flatMap(([key, value]): [string, string][] => {
    const path = prefix ? `${prefix}.${key}` : key

    return typeof value === 'string' ? [[path, value]] : values(value, path)
  })
}
