import { describe, expect, it } from 'vitest'

/**
 * Garde d'encapsulation (#748, R42, St4-5, St4-6) : l'état réactif de la session n'est écrit que par
 * `sessionState.ts`, qui n'expose que des fonctions nommées et `authState` en lecture seule. Un export `state`
 * laisserait n'importe quel module de `application/auth/` (ou de `src/`) écrire `tier`, `user` ou `sessionEnded`
 * sans passer par `applySession` ni par la génération.
 */
describe("L'état de session n'est pas exporté en écriture", () => {
  it("sessionState n'exporte pas l'état en écriture", async () => {
    const exported = Object.keys(await import('../../../src/application/auth/sessionState'))

    expect(exported).not.toContain('state')
    // La lecture seule reste exposée, et les trois écritures sont des fonctions nommées.
    expect(exported).toEqual(expect.arrayContaining(['authState', 'beginCheck', 'raiseSessionEnded', 'lowerSessionEnded']))
  })

  it("sessionLifecycle n'exporte pas isSameAccount", async () => {
    const exported = Object.keys(await import('../../../src/application/auth/sessionLifecycle'))

    expect(exported).not.toContain('isSameAccount')
  })
})
