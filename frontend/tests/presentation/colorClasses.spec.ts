import { describe, expect, it } from 'vitest'

/**
 * Gardes de source sur les classes de couleur de texte (#748, constats 1, 2 et 17).
 *
 * jsdom ne calcule aucun contraste (`tests/support/axe.ts` désactive `color-contrast`) : un test de rendu
 * resterait vert avec un texte illisible. La garde porte donc sur la source — la classe qui produit le
 * mauvais contraste ne doit plus apparaître — et la mesure réelle se fait au navigateur.
 */
const sources = import.meta.glob('../../src/**/*.{vue,ts}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** Chemins (relatifs à `src/`) des fichiers dont la source correspond au motif. */
function filesMatching(pattern: RegExp): string[] {
  return Object.entries(sources)
    .filter(([, source]) => pattern.test(source))
    .map(([path]) => path.replace('../../src/', ''))
    .sort()
}

describe('classes de couleur de texte', () => {
  it('aucun texte en text-danger : #dc3545 fait 4,34:1 sur le fond, text-danger-emphasis le remplace', () => {
    // Le lookahead laisse passer `text-danger-emphasis` ; `text-bg-danger` ne correspond pas (autre classe).
    expect(filesMatching(/\btext-danger\b(?!-)/)).toEqual([])
  })

  it('ni text-secondary ni link-secondary : #6c757d fait 4,19:1 sur le fond', () => {
    expect(filesMatching(/\b(?:text|link)-secondary\b/)).toEqual([])
  })
})
