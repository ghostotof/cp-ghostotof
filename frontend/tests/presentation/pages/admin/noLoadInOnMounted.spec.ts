import { describe, expect, it } from 'vitest'
import { SRC_SCRIPTS } from '../../../support/srcSources'
import { callsInsideHook, withoutComments } from '../../../support/sourceText'

/**
 * Aucune page ni section du backoffice ne lance son chargement dans un `onMounted` (#748, R121, décision 1 de la
 * onzième relecture ; CR11-1 ≈ C11-9). Une page lance sa lecture dans son `setup` (ou un watcher `immediate`) :
 * `AccessRefusedNotice` avance l'époque du refus dans son `onMounted`, rendu avant la page, et une lecture envoyée depuis
 * celui de la page partirait sous la nouvelle époque (`.claude/rules/frontend-session-refus.md`, « A real refusal »).
 *
 * Refusé dans une fonction passée à `onMounted` (imbriquées comprises) : un appel dont le dernier segment est `load`,
 * `reload`, commence par `load`, finit par `Load`, est `list`, `get`, `fetch` ou `translate`, ou porte un nom local lié à
 * la clé `load` d'une déstructuration (`const { load: refresh } = useX()`).
 *
 * Non vu, tenu par la relecture : une lecture envoyée après un `await`, ou par un sous-composant rendu plus tard par un `v-if`.
 */
const ADMIN_PAGES = 'presentation/pages/admin/'
const REPOSITORY_CALLS = new Set(['list', 'get', 'fetch', 'translate'])

/** Le dernier segment d'un callee (`this.repository?.list` donne `list`). */
function lastSegmentOf(callee: string): string {
  return callee.split(/\??\./).pop() ?? callee
}

/** Les noms locaux liés à la clé `load` ou `reload` d'une déstructuration (`load: refresh` donne `refresh`). */
function loadAliasesOf(source: string): Set<string> {
  const aliases = new Set<string>()
  for (const match of withoutComments(source).matchAll(/\b(?:re)?load\s*:\s*([A-Za-z_$][\w$]*)/g)) {
    aliases.add(match[1] ?? '')
  }

  return aliases
}

function isLoadingCall(callee: string, aliases: ReadonlySet<string>): boolean {
  const name = lastSegmentOf(callee)

  return (
    'load' === name ||
    'reload' === name ||
    name.startsWith('load') ||
    name.endsWith('Load') ||
    REPOSITORY_CALLS.has(name) ||
    aliases.has(callee)
  )
}

describe('aucun chargement dans un onMounted du backoffice', () => {
  const adminFiles = Object.entries(SRC_SCRIPTS).filter(([path]) => path.startsWith(ADMIN_PAGES))

  it("aucun fichier de presentation/pages/admin n'appelle un chargement dans un onMounted (#748, R121, décision 1)", () => {
    const offending = adminFiles.flatMap(([path, source]) => {
      const aliases = loadAliasesOf(source)

      return callsInsideHook(source, 'onMounted')
        .filter((callee) => isLoadingCall(callee, aliases))
        .map((callee) => `${path} : ${callee}`)
    })

    expect(offending).toEqual([])
  })

  it('la garde lit les pages du backoffice et trouve au moins un onMounted (#748, R121, décision 1)', () => {
    // Sans ces bornes, une garde qui ne lit plus rien resterait verte.
    expect(adminFiles.length).toBeGreaterThanOrEqual(12)
    expect(adminFiles.flatMap(([, source]) => callsInsideHook(source, 'onMounted')).length).toBeGreaterThanOrEqual(1)
  })

  it('le détecteur nomme un chargement direct, par un nom local lié à load, ou par un dépôt (#748, R121, décision 1)', () => {
    const source = 'const { load: refresh } = useX()\nonMounted(() => { void load(); refresh(); repository.list(); other() })'
    const aliases = loadAliasesOf(source)
    const loads = callsInsideHook(source, 'onMounted').filter((callee) => isLoadingCall(callee, aliases))

    expect(loads).toEqual(['load', 'refresh', 'repository.list'])
  })
})
