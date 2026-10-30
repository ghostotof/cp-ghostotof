import { describe, expect, it } from 'vitest'
import { scriptOf, stringLiterals, templateExpressionsOf } from '../../support/sourceText'
import { SRC_SCRIPTS, SRC_TEMPLATE_EXPRESSIONS } from '../../support/srcSources'

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
 *
 * Depuis R96 (CR8-10) la garde lit deux choses d'un fichier : ses littéraux de script (un `.vue` n'y a que ses blocs
 * `<script>`) **et** les expressions de son `<template>` (directives, gestionnaires en ligne, interpolations), et les
 * parties fixes d'un gabarit comptent comme des littéraux (`` `${''}omit` `` contient `omit`). Un gabarit `${x}omit` est
 * donc signalé sans être exactement `'omit'` : faux positif bruyant, aucun dans `src/`.
 *
 * Les fichiers sont lus une fois pour les gardes de `src/` par `tests/support/srcSources.ts` (R97).
 */

/** Les littéraux lus de chaque fichier : ceux de son script et, pour un `.vue`, ceux des expressions de son gabarit (R96). */
const sources = Object.fromEntries(
  Object.entries(SRC_SCRIPTS).map(([path, script]) => [
    path,
    [...stringLiterals(script), ...(SRC_TEMPLATE_EXPRESSIONS[path] ?? []).flatMap((expression) => stringLiterals(expression))],
  ]),
) as Record<string, string[]>

/** Les littéraux d'une source de test, lus comme ceux d'un fichier de `src/` (`path` en `.vue` : script et gabarit). */
function literalsOf(source: string, path = 'x.ts'): string[] {
  return [...stringLiterals(scriptOf(path, source)), ...templateExpressionsOf(path, source).flatMap((expression) => stringLiterals(expression))]
}

/**
 * Vrai si la source écrit une chaîne `'omit'` (simples, doubles ou gabarit) hors d'un commentaire. Lue par
 * `tests/support/sourceText.ts`, sur l'arbre syntaxique de TypeScript : un `'/*'`, un `'//'`, une URL entre guillemets
 * ou une regex littérale n'ouvre ni commentaire ni chaîne (Sp6-11, R68, CR7-7, R80). Locale à ce spec, non exportée : une garde n'est pas une API.
 */
function offendsWithOmit(source: string, path = 'x.ts'): boolean {
  return literalsOf(source, path).includes('omit')
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

  it("le détecteur voit un gabarit à expression et un gestionnaire en ligne du <template> (CR8-10)", () => {
    expect(offendsWithOmit("const m = `${''}omit`")).toBe(true)
    const inline = '<template><button @click="go({ credentials: \'omit\' })">x</button></template>\n<script setup lang="ts">\n</script>\n'
    expect(offendsWithOmit(inline, 'X.vue')).toBe(true)
    expect(offendsWithOmit('<template><p>{{ \'omit\' }}</p></template>', 'X.vue')).toBe(true)
    // Du texte de gabarit n'est pas une expression : « omit » affiché ne signale rien.
    expect(offendsWithOmit('<template><p>omit</p></template>', 'X.vue')).toBe(false)
  })

  it("le détecteur ne laisse pas un '/*' ou un '//' dans une chaîne masquer la suite (Sp6-11)", () => {
    // Une chaîne `'/*'` n'ouvre pas de commentaire : le `'omit'` qui suit est bien écrit.
    expect(offendsWithOmit("const a = '/*'\nconst mode = 'omit' // */")).toBe(true)
    // Une URL n'est pas un commentaire, mais ce qu'elle contient n'est pas un `'omit'` non plus.
    expect(offendsWithOmit("const u = 'http://x/omit'")).toBe(false)
    // Un `'//'` dans une chaîne ne coupe pas la ligne : l'`'omit'` qui suit sur la même ligne reste vu.
    expect(offendsWithOmit("const a = ['//', 'omit']")).toBe(true)
  })

  it("aucun fichier n'écrit la chaîne 'omit' hors d'un commentaire (elle retire aussi la Basic Auth de la préprod, C1)", () => {
    const offenders = Object.entries(sources)
      .filter(([, literals]) => literals.includes('omit'))
      .map(([path]) => path)
      .sort()

    expect(offenders).toEqual([])
  })
})
