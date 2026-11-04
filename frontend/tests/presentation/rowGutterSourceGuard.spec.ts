import { describe, expect, it } from 'vitest'
import { stringLiterals } from '../support/sourceText'
import { SRC_SCRIPTS, SRC_TEMPLATE_FRAGMENTS } from '../support/srcSources'

/**
 * Garde de source sur la gouttière horizontale d'une `.row` (#748, R244 de la vingtième relecture ; WCAG 1.4.10 « Reflow »).
 *
 * Une `.row` Bootstrap prend une marge négative de la moitié de sa gouttière (`--bs-gutter-x` / 2) de chaque côté. Le
 * conteneur qui la porte n'a que 0,75 rem de padding : au-delà de 1,5 rem de gouttière (`g-4`), la ligne dépasse le conteneur
 * et la page défile horizontalement à 320 px. `g-5` (3 rem) a fait 12 px de défilement sur la page d'accueil, mesurés au
 * navigateur (`scrollWidth` 317 pour `clientWidth` 305).
 *
 * jsdom ne calcule aucune mise en page : la mesure de largeur n'y existe pas, la garde porte donc sur la source. Elle refuse,
 * dans un gabarit ou une chaîne de `src/`, une `.row` dont la classe porte une gouttière horizontale de 3 rem **sans préfixe de
 * point de rupture** (`g-5`, `gx-5`) ; `g-4 g-lg-5` passe (1,5 rem sous lg, 3 rem dès 992 px où le conteneur est assez large).
 * Un préfixe seul (`g-lg-5`) laisse la gouttière par défaut de 1,5 rem en dessous : il passe aussi.
 *
 * Ne sont pas vus, tenus par la relecture et par la mesure au navigateur : une gouttière posée par `--bs-gutter-x` dans une
 * feuille de style ou un `style=""`, et une `.row` placée hors d'un conteneur à padding suffisant.
 */
const OVERLARGE_GUTTER = /^g[x]?-5$/

/** Vrai si une valeur de `class` (ou une chaîne) est celle d'une `.row` à gouttière horizontale de 3 rem sans point de rupture. */
function hasOverlargeRowGutter(classes: string): boolean {
  const tokens = classes.split(/\s+/).filter((token) => '' !== token)

  return tokens.includes('row') && tokens.some((token) => OVERLARGE_GUTTER.test(token))
}

/** Les fichiers de `src/` dont une chaîne ou un attribut de gabarit est une `.row` à gouttière trop large, triés. */
function filesWithOverlargeRowGutter(): string[] {
  return Object.keys(SRC_SCRIPTS)
    .filter((path) => {
      const strings = [...stringLiterals(SRC_SCRIPTS[path] ?? ''), ...(SRC_TEMPLATE_FRAGMENTS[path] ?? []).flatMap(stringLiterals)]

      return strings.some(hasOverlargeRowGutter)
    })
    .sort()
}

describe("gouttière horizontale d'une .row dans src/", () => {
  it('lit bien les sources (la glob ne doit pas être vide)', () => {
    expect(Object.keys(SRC_TEMPLATE_FRAGMENTS).length).toBeGreaterThan(100)
  })

  it('le détecteur voit g-5 et gx-5 sur une .row, pas une gouttière responsive', () => {
    expect(hasOverlargeRowGutter('row align-items-center g-5')).toBe(true)
    expect(hasOverlargeRowGutter('row gx-5')).toBe(true)
    expect(hasOverlargeRowGutter('g-5 row')).toBe(true)
    expect(hasOverlargeRowGutter('row  g-4 g-5')).toBe(true)
    // 1,5 rem au plus : la marge négative (0,75 rem) tient dans le padding du conteneur.
    expect(hasOverlargeRowGutter('row g-4')).toBe(false)
    expect(hasOverlargeRowGutter('row g-3')).toBe(false)
    expect(hasOverlargeRowGutter('row gy-5')).toBe(false)
    // Un préfixe de point de rupture : la gouttière large ne vaut qu'au-dessus.
    expect(hasOverlargeRowGutter('row g-4 g-lg-5')).toBe(false)
    expect(hasOverlargeRowGutter('row g-lg-5')).toBe(false)
    expect(hasOverlargeRowGutter('row gx-md-5')).toBe(false)
    // Hors d'une .row, la classe n'est pas une gouttière (`row-cols-*` n'est pas `row`).
    expect(hasOverlargeRowGutter('d-flex g-5')).toBe(false)
    expect(hasOverlargeRowGutter('row-cols-2 g-5')).toBe(false)
  })

  it('aucune .row en g-5 ou gx-5 sans point de rupture : 3 rem dépassent le padding du conteneur (WCAG 1.4.10)', () => {
    expect(filesWithOverlargeRowGutter()).toEqual([])
  })
})
