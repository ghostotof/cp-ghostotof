import { describe, expect, it } from 'vitest'

/**
 * Garde de source (#748, R37, R51) : aucun fichier de `src/` n'écrit une chaîne `'omit'` hors d'un commentaire.
 *
 * Pourquoi : la spécification Fetch range sous « credentials » les cookies ET l'authentification HTTP. `'omit'` retire
 * donc aussi l'en-tête `Authorization: Basic` que le navigateur rejoue sur l'origine de la préprod (Basic Auth sur
 * l'Ingress principal) : chaque appel concerné y prend le 401 de l'ingress. Ni le dev, ni la CI, ni les `curl` de
 * `smoke-test-preprod` (qui passent la Basic Auth par `-K`) ne le voient : seule cette garde l'attrape avant la préprod.
 *
 * Ce n'est pas la garde retirée (`credentialsAreExplicit.spec.ts`, R23) : celle-ci n'impose aucune valeur, elle en
 * interdit une. Le défaut `same-origin` convient aux dépôts publics, parce que le serveur sert une route publique comme
 * à l'anonyme quel que soit le cookie reçu (R35).
 *
 * Depuis R51 la garde ne cherche plus le seul littéral `credentials: 'omit'` : elle refuse toute chaîne `'omit'`
 * (guillemets simples, doubles ou gabarit) une fois les commentaires retirés, donc aussi `const mode:
 * RequestCredentials = 'omit'` puis `fetch(url, { credentials: mode })`. Limites écrites : une valeur composée
 * (`'om' + 'it'`), venue d'une variable ou d'un fichier d'ailleurs, ou un fichier hors de `src/`, lui échappe ; la
 * lecture de la règle (`.claude/rules/frontend.md`) et la relecture restent le filet de ces cas.
 */
const sources = import.meta.glob(['../../../src/**/*.ts', '../../../src/**/*.vue'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/**
 * Vrai si la source écrit une chaîne `'omit'` (simples, doubles ou gabarit) hors d'un commentaire. Les commentaires de
 * bloc sont retirés d'abord, puis ceux de ligne : un `//` précédé de `:` (le `https://` d'une URL) n'en ouvre pas un.
 */
export function offendsWithOmit(source: string): boolean {
  const withoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(?<!:)\/\/.*$/gm, '')

  return /(['"`])omit\1/.test(withoutComments)
}

describe("aucun credentials: 'omit' dans src/", () => {
  it('lit bien les sources (la glob ne doit pas être vide)', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(100)
  })

  it("le détecteur voit une valeur typée hors de l'objet d'options", () => {
    expect(offendsWithOmit("const mode: RequestCredentials = 'omit'")).toBe(true)
    expect(offendsWithOmit('credentials: `omit`')).toBe(true)
    expect(offendsWithOmit('credentials: "omit"')).toBe(true)
    expect(offendsWithOmit("// Jamais 'omit'")).toBe(false)
    expect(offendsWithOmit("/* 'omit' */")).toBe(false)
    expect(offendsWithOmit("fetch('https://x/omit')")).toBe(false)
  })

  it("aucun fichier n'écrit la chaîne 'omit' hors d'un commentaire (elle retire aussi la Basic Auth de la préprod, C1)", () => {
    const offenders = Object.entries(sources)
      .filter(([, source]) => offendsWithOmit(source))
      .map(([path]) => path.replace('../../../src/', ''))
      .sort()

    expect(offenders).toEqual([])
  })
})
