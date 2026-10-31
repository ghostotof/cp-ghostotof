import { describe, expect, it } from 'vitest'
import { scriptOf, stringLiterals, syntaxErrorsOf, templateFragmentsOf } from '../../support/sourceText'
import { SRC_SCRIPTS, SRC_TEMPLATE_FRAGMENTS } from '../../support/srcSources'

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
 * La garde refuse toute chaîne `'omit'` (guillemets simples, doubles ou gabarit) une fois les commentaires retirés, donc
 * aussi `const mode: RequestCredentials = 'omit'` puis `fetch(url, { credentials: mode })`. Limites écrites : une valeur
 * composée (`'om' + 'it'`), venue d'une variable ou d'un fichier d'ailleurs, ou un fichier hors de `src/`, lui échappe ;
 * la lecture de la règle (`.claude/rules/frontend.md`) et la relecture restent le filet de ces cas.
 *
 * Elle lit deux choses d'un fichier : ses littéraux de script (un `.vue` n'y a que ses blocs `<script>`) **et** les
 * expressions de son `<template>` (directives, gestionnaires en ligne, interpolations, `v-for`, `v-slot` et valeurs
 * d'attributs statiques compris), et les parties fixes d'un gabarit comptent comme des littéraux (`` `${''}omit` `` contient
 * `omit`). Un gabarit `${x}omit` est donc signalé sans être exactement `'omit'` : faux positif bruyant, aucun dans
 * `src/`. Chaque expression est lue comme le fragment JavaScript que Vue compile, et un test vérifie que tout fragment
 * de `src/` est de la syntaxe valide.
 *
 * Les fichiers sont lus une fois pour les gardes de `src/` par `tests/support/srcSources.ts` (R97).
 */

/** Les littéraux d'un fichier : ceux de son script et ceux des expressions de son gabarit (`[]` hors d'un `.vue`). */
function literalsOf(script: string, templateExpressions: readonly string[]): string[] {
  return [...stringLiterals(script), ...templateExpressions.flatMap((expression) => stringLiterals(expression))]
}

/** Vrai si les littéraux d'un fichier contiennent la chaîne `'omit'`. */
function writesOmit(literals: readonly string[]): boolean {
  return literals.includes('omit')
}

/** Les littéraux lus de chaque fichier de `src/`, par chemin relatif à `src/`. */
const literalsByFile = Object.fromEntries(
  Object.entries(SRC_SCRIPTS).map(([path, script]) => [path, literalsOf(script, SRC_TEMPLATE_FRAGMENTS[path] ?? [])]),
) as Record<string, string[]>

/**
 * Vrai si la source écrit une chaîne `'omit'` (simples, doubles ou gabarit) hors d'un commentaire. Lue par
 * `tests/support/sourceText.ts`, sur l'arbre syntaxique de TypeScript : un `'/*'`, un `'//'`, une URL entre guillemets
 * ou une regex littérale n'ouvre ni commentaire ni chaîne. Locale à ce spec, non exportée : une garde n'est pas une API.
 */
function offendsWithOmit(source: string, path = 'x.ts'): boolean {
  return writesOmit(literalsOf(scriptOf(path, source), templateFragmentsOf(path, source)))
}

describe("aucun credentials: 'omit' dans src/", () => {
  it('lit bien les sources (la glob ne doit pas être vide)', () => {
    expect(Object.keys(literalsByFile).length).toBeGreaterThan(100)
  })

  it("le détecteur voit une valeur typée hors de l'objet d'options", () => {
    expect(offendsWithOmit("const mode: RequestCredentials = 'omit'")).toBe(true)
    expect(offendsWithOmit('credentials: `omit`')).toBe(true)
    expect(offendsWithOmit('credentials: "omit"')).toBe(true)
    expect(offendsWithOmit("// Jamais 'omit'")).toBe(false)
    expect(offendsWithOmit("/* 'omit' */")).toBe(false)
    expect(offendsWithOmit("fetch('https://x/omit')")).toBe(false)
  })

  it("le détecteur voit un gabarit à expression et un gestionnaire en ligne du <template>", () => {
    expect(offendsWithOmit("const m = `${''}omit`")).toBe(true)
    const inline = '<template><button @click="go({ credentials: \'omit\' })">x</button></template>\n<script setup lang="ts">\n</script>\n'
    expect(offendsWithOmit(inline, 'X.vue')).toBe(true)
    expect(offendsWithOmit('<template><p>{{ \'omit\' }}</p></template>', 'X.vue')).toBe(true)
    // Du texte de gabarit n'est pas une expression : « omit » affiché ne signale rien.
    expect(offendsWithOmit('<template><p>omit</p></template>', 'X.vue')).toBe(false)
  })

  it("le détecteur ne laisse pas un '/*' ou un '//' dans une chaîne masquer la suite", () => {
    // Une chaîne `'/*'` n'ouvre pas de commentaire : le `'omit'` qui suit est bien écrit.
    expect(offendsWithOmit("const a = '/*'\nconst mode = 'omit' // */")).toBe(true)
    // Une URL n'est pas un commentaire, mais ce qu'elle contient n'est pas un `'omit'` non plus.
    expect(offendsWithOmit("const u = 'http://x/omit'")).toBe(false)
    // Un `'//'` dans une chaîne ne coupe pas la ligne : l'`'omit'` qui suit sur la même ligne reste vu.
    expect(offendsWithOmit("const a = ['//', 'omit']")).toBe(true)
  })

  it("aucun fichier n'écrit la chaîne 'omit' hors d'un commentaire (elle retire aussi la Basic Auth de la préprod, C1)", () => {
    const offenders = Object.entries(literalsByFile)
      .filter(([, literals]) => writesOmit(literals))
      .map(([path]) => path)
      .sort()

    expect(offenders).toEqual([])
  })

  it('chaque expression de gabarit de src/ se lit sans erreur de syntaxe : le détecteur ne lit pas une chaîne par chance', () => {
    const broken = Object.entries(SRC_TEMPLATE_FRAGMENTS).flatMap(([path, fragments]) =>
      fragments.flatMap((fragment) => syntaxErrorsOf(fragment).map((error) => `${path} : ${error} : ${fragment}`)),
    )

    expect(Object.values(SRC_TEMPLATE_FRAGMENTS).flat().length).toBeGreaterThan(100)
    expect(broken).toEqual([])
  })

  it('le détecteur voit un v-for, un v-slot déstructuré et un littéral objet lié du <template>', () => {
    const vue = (template: string): string => `<template>${template}</template>\n<script setup lang="ts">\n</script>\n`

    expect(offendsWithOmit(vue('<ul><li v-for="m in [\'omit\']">x</li></ul>'), 'X.vue')).toBe(true)
    expect(offendsWithOmit(vue('<C v-slot="{ mode = \'omit\' }">x</C>'), 'X.vue')).toBe(true)
    expect(offendsWithOmit(vue('<C :opts="{ credentials: \'omit\' }" />'), 'X.vue')).toBe(true)
  })
})
