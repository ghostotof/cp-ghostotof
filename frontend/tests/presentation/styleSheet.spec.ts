/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Gardes de source sur la feuille de style globale (#748, constats 1 et 17) : jsdom ne calcule pas le
 * contraste, la surcharge est donc épinglée dans le texte de `style.css`.
 */

/**
 * Lue par le système de fichiers et non par `import '…css?raw'` : Vitest vide tout fichier CSS qu'il ne
 * traite pas (`test.css`), `?raw` compris — l'import rend une chaîne de longueur 0, et la garde passerait
 * ou échouerait pour de mauvaises raisons. `tsconfig.app.json` ne charge que les types de Vite, d'où la
 * référence explicite aux types de Node.
 */
const styleSheet = readFileSync(resolve(import.meta.dirname, '../../src/style.css'), 'utf8')

/** Corps du premier bloc `<selecteur> { … }` de la feuille, ou null s'il n'existe pas. */
function blockOf(selector: string): string | null {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return new RegExp(`(?:^|\\n)${escaped}\\s*\\{([^}]*)\\}`).exec(styleSheet)?.[1] ?? null
}

describe('style.css', () => {
  it('btn-outline-danger : texte et bordure en danger-text-emphasis', () => {
    const block = blockOf('.btn-outline-danger')

    expect(block).not.toBeNull()
    expect(block).toContain('--bs-btn-color: var(--bs-danger-text-emphasis)')
    expect(block).toContain('--bs-btn-border-color: var(--bs-danger-text-emphasis)')
  })

  it('.link-muted prend la couleur secondaire du thème', () => {
    const block = blockOf('.link-muted')

    expect(block).not.toBeNull()
    expect(block).toContain('var(--bs-secondary-color)')
  })
})
