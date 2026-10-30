import { describe, expect, it } from 'vitest'
import { withoutComments } from '../../support/sourceText'

/**
 * Garde d'encapsulation (#748, R42, St4-5, St4-6, R52, St5-3) : l'état réactif de la session n'est écrit que par
 * `sessionState.ts`, qui n'expose que des fonctions nommées et `authState` en lecture seule. Un export `state`
 * laisserait n'importe quel module de `application/auth/` (ou de `src/`) écrire `tier`, `user` ou `sessionEnded`
 * sans passer par `applySession` ni par la génération.
 *
 * Depuis R52 la garde porte aussi sur **qui importe** chaque écriture : `raiseSessionEnded` et `lowerSessionEnded`
 * posent `sessionEnded` sans juger la génération ni le jeton d'écriture (c'est `markSessionExpired` / `restoreSession`
 * qui le font), et elles étaient exportées à tout `src/`. Chaque fonction de `WRITERS` n'est importée que par les
 * modules de sa liste, avec la raison de chacun.
 *
 * Limites écrites : la garde lit les `import … from '…/sessionState'` et les `export … from` des sources de `src/` ; un
 * chemin d'import construit (`import(chemin)`) lui échappe, et un import d'espace de noms (`* as`, `export *`) ou
 * dynamique est compté comme important **toutes** les écritures. `markBaseAccessGranted` et `markBaseAccessExpired`
 * restent hors de la table : c'est l'API publique de la façade (`useBaseAccess` et les composables du palier de base les
 * appellent), elles jugent elles-mêmes la génération.
 */
const sources = import.meta.glob(['../../../src/**/*.ts', '../../../src/**/*.vue'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/**
 * Les écritures de `sessionState.ts` et les seuls fichiers de `src/` (chemins relatifs à `src/`) autorisés à les importer.
 * - `raiseSessionEnded`, `lowerSessionEnded` : `sessionLifecycle.ts` seul — `markSessionExpired` lève la fin après avoir
 *   jugé la génération, `restoreSession` la baisse après `canRestore`. (La troisième descente, `markBaseAccessGranted`,
 *   vit dans `sessionState.ts` même : elle n'importe rien.)
 * - `beginCheck`, `markFirstCheckDone`, `forgetLastAccount` : `useAuth.ts` seul — `checkAuth` pose le drapeau de la
 *   première vérification, `logout` oublie le compte.
 * - `applySession` : `sessionLifecycle.ts` (`restoreSession`, `applyLiveSession`, `markSessionExpired`) et `useAuth.ts`
 *   (`login`, `logout`, `applyReadSession`).
 */
const WRITERS: Record<string, readonly string[]> = {
  raiseSessionEnded: ['application/auth/sessionLifecycle.ts'],
  lowerSessionEnded: ['application/auth/sessionLifecycle.ts'],
  beginCheck: ['application/auth/useAuth.ts'],
  markFirstCheckDone: ['application/auth/useAuth.ts'],
  forgetLastAccount: ['application/auth/useAuth.ts'],
  applySession: ['application/auth/sessionLifecycle.ts', 'application/auth/useAuth.ts'],
}

/** Joker : un import d'espace de noms, un `export *` ou un `import()` dynamique atteint toutes les écritures. */
const ALL = '*'

/**
 * Les noms qu'une source importe (ou réexporte) de `…/sessionState`, commentaires retirés. Un nom importé sous un autre
 * (`a as b`) est relevé sous son nom d'origine ; `type` est ignoré.
 */
function namesImportedFromSessionState(source: string): string[] {
  const code = withoutComments(source)
  const names: string[] = []

  for (const match of code.matchAll(/\b(?:import|export)\s+(?:type\s+)?([^;'"]*?)\s*\bfrom\s*['"][^'"]*\/sessionState(?:\.ts)?['"]/g)) {
    const clause = match[1] ?? ''
    if (clause.includes('*')) {
      names.push(ALL)
      continue
    }
    for (const part of clause.replace(/[{}]/g, '').split(',')) {
      const name = part
        .trim()
        .replace(/^type\s+/, '')
        .split(/\s+as\s+/)[0]
        ?.trim()
      if (undefined !== name && '' !== name) {
        names.push(name)
      }
    }
  }
  if (/\bimport\s*\(\s*['"][^'"]*\/sessionState(?:\.ts)?['"]\s*\)/.test(code)) {
    names.push(ALL)
  }

  return names
}

/**
 * Les noms que les blocs `export { … }` d'une source exposent, commentaires retirés (`export { a as b }` donne `a` et
 * `b`). La lecture à l'exécution ne suffit pas à elle seule : le transformateur de TypeScript retire un
 * `export { nom }` dont `nom` est importé sans autre usage (il ne peut pas savoir si c'est un type), de sorte que le
 * module chargé n'expose rien et que le test passerait avec une vraie réexportation.
 */
function namesInExportBlocks(source: string): string[] {
  const code = withoutComments(source)
  const names: string[] = []

  for (const match of code.matchAll(/\bexport\s+(?:type\s+)?\{([^}]*)\}/g)) {
    for (const part of (match[1] ?? '').split(',')) {
      names.push(
        ...part
          .trim()
          .replace(/^type\s+/, '')
          .split(/\s+as\s+/)
          .map((name) => name.trim())
          .filter((name) => '' !== name),
      )
    }
  }

  return names
}

describe("L'état de session n'est pas exporté en écriture", () => {
  it("sessionState n'exporte pas l'état en écriture, et ses exports sont exactement ceux attendus", async () => {
    const exported = Object.keys(await import('../../../src/application/auth/sessionState'))

    expect(exported).not.toContain('state')
    // La liste exacte : un export de plus (une écriture de plus) se décide ici, et se range dans `WRITERS` ou en est exclu.
    expect([...exported].sort()).toEqual(
      [
        'applySession',
        'authState',
        'beginCheck',
        'forgetLastAccount',
        'getLastAccount',
        'lowerSessionEnded',
        'markBaseAccessExpired',
        'markBaseAccessGranted',
        'markFirstCheckDone',
        'raiseSessionEnded',
        'resetSessionStateForTests',
        'waitForAuthCheck',
      ].sort(),
    )
  })

  it("sessionLifecycle n'exporte pas isSameAccount", async () => {
    const exported = Object.keys(await import('../../../src/application/auth/sessionLifecycle'))

    expect(exported).not.toContain('isSameAccount')
  })
})

describe("Les écritures de l'état de session ont chacune leurs importeurs", () => {
  it('lit bien les sources, et chaque écriture de WRITERS est un export de sessionState', async () => {
    expect(Object.keys(sources).length).toBeGreaterThan(100)
    const exported = Object.keys(await import('../../../src/application/auth/sessionState'))

    for (const name of Object.keys(WRITERS)) {
      expect(exported).toContain(name)
    }
  })

  it('le relevé voit les formes d\'import (nommé, multiligne, type, renommé, espace de noms, dynamique, réexport)', () => {
    expect(namesImportedFromSessionState("import { lowerSessionEnded } from './sessionState'")).toEqual(['lowerSessionEnded'])
    expect(namesImportedFromSessionState("import {\n  applySession,\n  raiseSessionEnded as raise,\n  type LastAccount,\n} from '../auth/sessionState'")).toEqual([
      'applySession',
      'raiseSessionEnded',
      'LastAccount',
    ])
    expect(namesImportedFromSessionState("import * as state from './sessionState'")).toEqual([ALL])
    expect(namesImportedFromSessionState("export * from './sessionState'")).toEqual([ALL])
    expect(namesImportedFromSessionState("export { lowerSessionEnded } from './sessionState'")).toEqual(['lowerSessionEnded'])
    expect(namesImportedFromSessionState("const m = await import('./sessionState')")).toEqual([ALL])
    expect(namesImportedFromSessionState("// import { lowerSessionEnded } from './sessionState'")).toEqual([])
    expect(namesImportedFromSessionState("import { x } from './sessionStateOther'")).toEqual([])
  })

  it("les fonctions qui écrivent l'état ne sont importées que par leurs modules", () => {
    const offenders: string[] = []

    for (const [path, source] of Object.entries(sources)) {
      const file = path.replace('../../../src/', '')
      const imported = namesImportedFromSessionState(source)

      for (const [name, allowed] of Object.entries(WRITERS)) {
        if ((imported.includes(name) || imported.includes(ALL)) && !allowed.includes(file)) {
          offenders.push(`${file} importe ${name} (autorisés : ${allowed.join(', ')})`)
        }
      }
    }

    expect(offenders.sort()).toEqual([])
  })

  it("chaque importeur autorisé importe bien l'écriture (la table ne garde pas de ligne morte)", () => {
    const dead: string[] = []

    for (const [name, allowed] of Object.entries(WRITERS)) {
      for (const file of allowed) {
        const source = sources[`../../../src/${file}`]
        const imported = source === undefined ? [] : namesImportedFromSessionState(source)
        if (!imported.includes(name)) {
          dead.push(`${file} n'importe pas ${name}`)
        }
      }
    }

    expect(dead).toEqual([])
  })

  it('le relevé des blocs export voit une réexportation, renommée ou non', () => {
    expect(namesInExportBlocks('export {\n  ROLE_SUPER,\n  applySession as apply,\n}')).toEqual(['ROLE_SUPER', 'applySession', 'apply'])
    expect(namesInExportBlocks("export { lowerSessionEnded } from './sessionState'")).toEqual(['lowerSessionEnded'])
    expect(namesInExportBlocks('// export { applySession }')).toEqual([])
  })

  it('useAuth ne réexporte aucune écriture', async () => {
    const source = sources['../../../src/application/auth/useAuth.ts'] ?? ''
    const reexported = namesInExportBlocks(source)
    const exported = Object.keys(await import('../../../src/application/auth/useAuth'))

    expect(source).not.toBe('')
    for (const name of Object.keys(WRITERS)) {
      expect(reexported).not.toContain(name)
      expect(exported).not.toContain(name)
    }
  })
})
