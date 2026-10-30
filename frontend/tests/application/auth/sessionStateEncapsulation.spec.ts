import { describe, expect, it } from 'vitest'
import { withoutComments } from '../../support/sourceText'
import { SRC_SCRIPTS } from '../../support/srcSources'

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
 * Depuis R69 (C6-5) elle voit aussi le suffixe d'un chemin (`./sessionState.js`, `.mjs`), compte comme important
 * **toutes** les écritures un `import()` dont le chemin n'est pas un littéral (gabarit, variable) et tout
 * `import.meta.glob`, et un contrôle **par identité** porte sur les valeurs : aucun module de `src/` autre que
 * `sessionState.ts` n'exporte une valeur `===` à une écriture, sous aucun nom (`export const x = raiseSessionEnded`
 * échappe à toute lecture du texte d'un import).
 *
 * Limites écrites : le relevé lit les `import … from '…/sessionState'` et les `export … from` des sources de `src/` ; un
 * import d'espace de noms (`* as`, `export *`) ou dynamique est compté comme important **toutes** les écritures. Le
 * contrôle par identité charge les modules `.ts` de `src/` (`main.ts` exclu : il monte l'application) et ne voit ni une
 * écriture rangée dans un objet exporté (`export const api = { raise: raiseSessionEnded }`), ni un `.vue` (un
 * `<script>` non `setup` qui réexporterait une écriture). `markBaseAccessGranted` et `markBaseAccessExpired`
 * restent hors de la table : c'est l'API publique de la façade (`useBaseAccess` et les composables du palier de base les
 * appellent), elles jugent elles-mêmes la génération.
 */
const sources = SRC_SCRIPTS

/**
 * Les écritures de `sessionState.ts` et les seuls fichiers de `src/` (chemins relatifs à `src/`) autorisés à les importer.
 * - `raiseSessionEnded`, `lowerSessionEnded` : `sessionLifecycle.ts` seul — `markSessionExpired` lève la fin après avoir
 *   jugé la génération, `restoreSession` la baisse après `canRestore`. (La troisième descente, `markBaseAccessGranted`,
 *   vit dans `sessionState.ts` même : elle n'importe rien.)
 * - `markFirstCheckDone`, `forgetLastAccount` : `useAuth.ts` seul — `checkAuth` abaisse `isChecking` à la fin de la
 *   première vérification (R85 : il naît vrai, plus aucune écriture ne le lève), `logout` oublie le compte.
 * - `applySession` : `sessionLifecycle.ts` (`restoreSession`, `applyLiveSession`, `markSessionExpired`) et `useAuth.ts`
 *   (`login`, `logout`, `applyReadSession`).
 */
const WRITERS: Record<string, readonly string[]> = {
  raiseSessionEnded: ['application/auth/sessionLifecycle.ts'],
  lowerSessionEnded: ['application/auth/sessionLifecycle.ts'],
  markFirstCheckDone: ['application/auth/useAuth.ts'],
  forgetLastAccount: ['application/auth/useAuth.ts'],
  applySession: ['application/auth/sessionLifecycle.ts', 'application/auth/useAuth.ts'],
}

/** Joker : un import d'espace de noms, un `export *` ou un `import()` dynamique atteint toutes les écritures. */
const ALL = '*'

/** Un chemin vers `sessionState`, quel que soit son suffixe (`.ts`, `.js`, `.mjs`, `.cjs`…) : `…/sessionState` ou `…/sessionState.js`. */
const SESSION_STATE_PATH = '[^\'"]*\\/sessionState(?:\\.[cm]?[jt]s)?'

/**
 * Les noms qu'une source importe (ou réexporte) de `…/sessionState`, commentaires retirés. Un nom importé sous un autre
 * (`a as b`) est relevé sous son nom d'origine ; `type` est ignoré.
 */
function namesImportedFromSessionState(source: string): string[] {
  const code = withoutComments(source)
  const names: string[] = []

  for (const match of code.matchAll(new RegExp(`\\b(?:import|export)\\s+(?:type\\s+)?([^;'"]*?)\\s*\\bfrom\\s*['"]${SESSION_STATE_PATH}['"]`, 'g'))) {
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
  // Un `import()` du module (avec ou sans second argument), un `import()` dont le chemin n'est pas un littéral (gabarit,
  // variable : on ne sait pas où il mène) et tout `import.meta.glob` (`glob`, `globEager`…) atteignent toutes les écritures.
  const dynamicOfSessionState = new RegExp(`\\bimport\\s*\\(\\s*['"]${SESSION_STATE_PATH}['"]\\s*[,)]`)
  const dynamicOfUnknownPath = /\bimport\s*\(\s*(?!['"])/
  const globbed = /\bimport\.meta\.glob/
  if (dynamicOfSessionState.test(code) || dynamicOfUnknownPath.test(code) || globbed.test(code)) {
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

/**
 * Les modules de `src/` (hors `main.ts`, qui monte l'application et ne s'importe pas), chargés : le contrôle par identité
 * compare leurs valeurs exportées aux écritures. Aucun module de `src/*.ts` n'a été exclu pour ne pas se charger sous jsdom.
 */
const loadedModules = import.meta.glob(['../../../src/**/*.ts', '!../../../src/main.ts'], { eager: true }) as Record<string, Record<string, unknown>>

/** Le module qui définit les écritures : lui seul peut les exporter. */
const SESSION_STATE_MODULE = 'application/auth/sessionState.ts'

/**
 * Les exports (module, nom) qui valent `===` à une écriture de `writers`, sous quelque nom que ce soit. Un
 * `export const x = raiseSessionEnded` ou `export { raiseSessionEnded as x }` échappe à toute lecture du texte des
 * imports ; seule l'identité de la valeur le voit. `modules` est indexé par le chemin relatif à `src/`.
 */
function reexportedWriters(modules: Record<string, Record<string, unknown>>, writers: Record<string, unknown>): string[] {
  const offenders: string[] = []

  for (const [file, module] of Object.entries(modules)) {
    if (SESSION_STATE_MODULE === file) {
      continue
    }
    for (const [exportName, value] of Object.entries(module)) {
      for (const [writerName, writer] of Object.entries(writers)) {
        if (value === writer) {
          offenders.push(`${file} exporte ${writerName} sous le nom ${exportName}`)
        }
      }
    }
  }

  return offenders
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

  it("le relevé voit le suffixe d'un chemin (.js, .mjs, .ts), un import() non littéral et import.meta.glob", () => {
    expect(namesImportedFromSessionState("import { raiseSessionEnded } from './sessionState.js'")).toEqual(['raiseSessionEnded'])
    expect(namesImportedFromSessionState("import { raiseSessionEnded } from './sessionState.mjs'")).toEqual(['raiseSessionEnded'])
    expect(namesImportedFromSessionState("const m = await import('./sessionState.js')")).toEqual([ALL])
    expect(namesImportedFromSessionState("const m = await import('./sessionState.ts', { with: {} })")).toEqual([ALL])
    // Un chemin construit : le relevé ne sait pas où il mène, il compte toutes les écritures.
    expect(namesImportedFromSessionState('const m = await import(`./session${\'State\'}`)')).toEqual([ALL])
    expect(namesImportedFromSessionState('const m = await import(path)')).toEqual([ALL])
    expect(namesImportedFromSessionState("const m = import.meta.glob('./*.ts')")).toEqual([ALL])
    expect(namesImportedFromSessionState("const m = import.meta.glob(['./*.ts'], { eager: true })")).toEqual([ALL])
    // Ce que le relevé laisse passer : un import() littéral d'un autre module, et le code commenté.
    expect(namesImportedFromSessionState("const page = () => import('./pages/HomePage.vue')")).toEqual([])
    expect(namesImportedFromSessionState('// const m = import(path)')).toEqual([])
    expect(namesImportedFromSessionState("import { x } from './sessionState.json'")).toEqual([])
  })

  it("les fonctions qui écrivent l'état ne sont importées que par leurs modules", () => {
    const offenders: string[] = []

    for (const [path, source] of Object.entries(sources)) {
      const file = path
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
        const source = sources[file]
        const imported = source === undefined ? [] : namesImportedFromSessionState(source)
        if (!imported.includes(name)) {
          dead.push(`${file} n'importe pas ${name}`)
        }
      }
    }

    expect(dead).toEqual([])
  })

  it("aucun module de src/ autre que sessionState n'exporte une écriture, sous aucun nom", async () => {
    const sessionState = (await import('../../../src/application/auth/sessionState')) as Record<string, unknown>
    const writers = Object.fromEntries(Object.keys(WRITERS).map((name) => [name, sessionState[name]]))
    const modules = Object.fromEntries(Object.entries(loadedModules).map(([path, module]) => [path.replace('../../../src/', ''), module]))

    // Les modules sont bien chargés (une glob vide ne prouverait rien), et chaque écriture est bien une fonction.
    expect(Object.keys(modules).length).toBeGreaterThan(100)
    expect(modules).toHaveProperty([SESSION_STATE_MODULE])
    for (const writer of Object.values(writers)) {
      expect(typeof writer).toBe('function')
    }

    expect(reexportedWriters(modules, writers).sort()).toEqual([])
  })

  it('le contrôle par identité voit une écriture exportée sous un autre nom, et laisse sessionState', () => {
    const raise = (): void => {}
    const writers = { raiseSessionEnded: raise }

    expect(
      reexportedWriters(
        {
          'application/auth/sessionState.ts': { raiseSessionEnded: raise },
          'application/auth/sessionLifecycle.ts': { raise, other: (): void => {} },
          'application/auth/useAuth.ts': { useAuth: (): void => {} },
        },
        writers,
      ),
    ).toEqual(['application/auth/sessionLifecycle.ts exporte raiseSessionEnded sous le nom raise'])
  })

  it('le relevé des blocs export voit une réexportation, renommée ou non', () => {
    expect(namesInExportBlocks('export {\n  ROLE_SUPER,\n  applySession as apply,\n}')).toEqual(['ROLE_SUPER', 'applySession', 'apply'])
    expect(namesInExportBlocks("export { lowerSessionEnded } from './sessionState'")).toEqual(['lowerSessionEnded'])
    expect(namesInExportBlocks('// export { applySession }')).toEqual([])
  })

  it('useAuth ne réexporte aucune écriture', async () => {
    const source = sources['application/auth/useAuth.ts'] ?? ''
    const reexported = namesInExportBlocks(source)
    const exported = Object.keys(await import('../../../src/application/auth/useAuth'))

    expect(source).not.toBe('')
    for (const name of Object.keys(WRITERS)) {
      expect(reexported).not.toContain(name)
      expect(exported).not.toContain(name)
    }
  })
})
