import { describe, expect, it } from 'vitest'

/**
 * Garde de source (#748, R37) : aucun fichier de `src/` n'écrit `credentials: 'omit'`.
 *
 * Pourquoi : la spécification Fetch range sous « credentials » les cookies ET l'authentification HTTP. `'omit'` retire
 * donc aussi l'en-tête `Authorization: Basic` que le navigateur rejoue sur l'origine de la préprod (Basic Auth sur
 * l'Ingress principal) : chaque appel concerné y prend le 401 de l'ingress. Ni le dev, ni la CI, ni les `curl` de
 * `smoke-test-preprod` (qui passent la Basic Auth par `-K`) ne le voient : seule cette garde l'attrape avant la préprod.
 *
 * Ce n'est pas la garde retirée (`credentialsAreExplicit.spec.ts`, R23) : celle-ci n'impose aucune valeur, elle en
 * interdit une. Le défaut `same-origin` convient aux dépôts publics, parce que le serveur sert une route publique comme
 * à l'anonyme quel que soit le cookie reçu (R35).
 */
const sources = import.meta.glob(['../../../src/**/*.ts', '../../../src/**/*.vue'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

describe("aucun credentials: 'omit' dans src/", () => {
  it('lit bien les sources (la glob ne doit pas être vide)', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(100)
  })

  it("aucun fichier n'écrit credentials: 'omit' (il retire aussi la Basic Auth de la préprod, C1)", () => {
    const offenders = Object.entries(sources)
      .filter(([, source]) => /credentials:\s*['"]omit['"]/.test(source))
      .map(([path]) => path.replace('../../../src/', ''))
      .sort()

    expect(offenders).toEqual([])
  })
})
