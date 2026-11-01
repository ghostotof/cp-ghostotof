import { describe, expect, it } from 'vitest'
import { SRC_SCRIPTS } from '../../../support/srcSources'
import { deferredCallsOf, destructuredAliasesOf } from '../../../support/sourceText'

/**
 * Aucune page, section ni composant du backoffice ne lance son chargement après son montage (#748, R121, R144, décision 1 de
 * la onzième relecture). Une page lance sa lecture dans son `setup` (ou un watcher `immediate`, synchrone) :
 * `AccessRefusedNotice` avance l'époque du refus dans son `onMounted`, rendu avant la page, et une lecture envoyée depuis
 * un hook qui s'exécute après le montage partirait sous la nouvelle époque (`.claude/rules/frontend-session-refus.md`,
 * « A real refusal »).
 *
 * Lu : `presentation/pages/admin/` et `presentation/ui/admin/`. **Le critère est la première exécution du rappel** : refusé, ce
 * qui ne court jamais dans le `setup`. Dans ce que `deferredCallsOf` rend (la fonction, ou le nom passé par référence, de chaque
 * hook différé de `DEFERRED_HOOKS`, dans `tests/support/sourceText.ts`, avec ses deux listes voisines ; les hooks de `vue` se
 * lisent sous l'alias de leur import ; un `watch` n'en est un que **sans `immediate: true`**, un `watchEffect` qu'en `flush: 'post'`),
 * un appel dont le dernier segment est `reload`, commence par `load`, finit par `Load`, est `list`, `get`, `fetch` ou `translate`, ou
 * porte un nom local lié à la clé `load` ou `reload` d'une déstructuration (`const { load: refresh } = useX()`). Un `watch`
 * `immediate` n'est pas lu, posté ou non : sa **première** exécution est synchrone dans le `setup` (`job(true)`, `@vue/reactivity`) ;
 * ses suivantes répondent à un changement de sa source, ce que la garde admet.
 *
 * Non vu, tenu par la relecture : ce que ni le lecteur ni le filtre ne reconnaissent. La liste en est écrite une fois, dans le
 * dernier paragraphe du docblock de `deferredCallsOf` (`tests/support/sourceText.ts`), filtre de cette garde compris.
 */
const GUARDED_DIRS = ['presentation/pages/admin/', 'presentation/ui/admin/']
const REPOSITORY_CALLS = new Set(['list', 'get', 'fetch', 'translate'])
/**
 * Les fichiers que la garde lit, par dossier (#748, R203, Q12 (A)), les `.ts` comme les `.vue` (`proseFields.ts`…) : quinze dans
 * `presentation/pages/admin/` (treize `.vue`, deux `.ts`), douze dans `presentation/ui/admin/` (dont `entryFormState.ts`).
 * Une égalité, non un plancher : un fichier qui sort d'un dossier (déplacé, renommé) sort de la garde, un fichier qui entre y
 * entre, et dans les deux cas la garde rougit au lieu de changer de périmètre en silence ; le message dit la constante à changer.
 */
const PAGES_FILES = 15
const UI_FILES = 12

function isGuarded(path: string): boolean {
  return GUARDED_DIRS.some((dir) => path.startsWith(dir))
}

/** Le dernier segment d'un callee (`this.repository?.list` donne `list`). */
function lastSegmentOf(callee: string): string {
  return callee.split(/\??\./).pop() ?? callee
}

function isLoadingCall(callee: string, aliases: ReadonlySet<string>): boolean {
  const name = lastSegmentOf(callee)

  return (
    'reload' === name ||
    name.startsWith('load') ||
    name.endsWith('Load') ||
    REPOSITORY_CALLS.has(name) ||
    aliases.has(callee)
  )
}

/** Les chargements qu'une source lance après son montage. */
function loadsIn(source: string): string[] {
  const aliases = destructuredAliasesOf(source, ['load', 'reload'])

  return deferredCallsOf(source).filter((callee) => isLoadingCall(callee, aliases))
}

/** Un chargement par ligne, `chemin : appel`, pour chaque fichier lu de `files` (les chemins de `src/`). */
function offendingIn(files: Readonly<Record<string, string>>): string[] {
  return Object.entries(files)
    .filter(([path]) => isGuarded(path))
    .flatMap(([path, source]) => loadsIn(source).map((callee) => `${path} : ${callee}`))
}

describe('aucun chargement après le montage dans le backoffice', () => {
  const guarded = Object.entries(SRC_SCRIPTS).filter(([path]) => isGuarded(path))

  it("aucun fichier des deux dossiers du backoffice n'appelle un chargement après son montage (#748, R121, R144, décision 1)", () => {
    expect(offendingIn(SRC_SCRIPTS)).toEqual([])
  })

  it('la garde lit les pages et les composants du backoffice et trouve au moins un hook différé (#748, R121, R144, décision 1)', () => {
    // Sans ces bornes, une garde qui ne lit plus rien resterait verte.
    const read = (dir: string): string[] => guarded.map(([path]) => path).filter((path) => path.startsWith(dir))
    const pages = read('presentation/pages/admin/')
    const ui = read('presentation/ui/admin/')
    expect(pages.length, `fichiers de pages/admin lus : ${pages.join(', ')}. Changer PAGES_FILES, en tête de ce fichier`).toBe(PAGES_FILES)
    expect(ui.length, `fichiers de ui/admin lus : ${ui.join(', ')}. Changer UI_FILES, en tête de ce fichier`).toBe(UI_FILES)
    // Un plancher, à dessein : « un hook différé au moins » dit que le lecteur lit encore ; le nombre exact de rappels bouge avec
    // chaque `onMounted` qu'une page ajoute, et n'est pas un périmètre.
    expect(guarded.flatMap(([, source]) => deferredCallsOf(source)).length).toBeGreaterThanOrEqual(1)
  })

  it('le détecteur nomme un chargement direct, par un nom local lié à load, ou par un dépôt (#748, R121, décision 1)', () => {
    const source = 'const { load: refresh } = useX()\nonMounted(() => { void load(); refresh(); repository.list(); other() })'

    expect(loadsIn(source)).toEqual(['load', 'refresh', 'repository.list'])
  })

  describe('chaque forme que la garde voit rougit sur un fichier factice', () => {
    const PAGE = 'presentation/pages/admin/AdminFakePage.vue'

    it('un chargement passé par référence à onMounted (#748, R144)', () => {
      expect(offendingIn({ [PAGE]: 'onMounted(load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'const { load: refresh } = useX()\nonMounted(refresh)' })).toEqual([`${PAGE} : refresh`])
    })

    it("un onMounted importé de vue sous un alias (#748, R144)", () => {
      const source = "import { onMounted as whenMounted } from 'vue'\nwhenMounted(() => { void load() })"

      expect(offendingIn({ [PAGE]: source })).toEqual([`${PAGE} : load`])
    })

    it('un chargement différé par nextTick, setTimeout, queueMicrotask ou requestAnimationFrame (#748, R144)', () => {
      expect(offendingIn({ [PAGE]: 'nextTick(load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'nextTick(() => void load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'setTimeout(() => void load(), 0)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'window.setTimeout(load, 0)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'queueMicrotask(load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'requestAnimationFrame(() => repository.list())' })).toEqual([`${PAGE} : repository.list`])
    })

    it("un watch sans immediate charge à un changement de sa source, donc après le montage (#748, R159)", () => {
      expect(offendingIn({ [PAGE]: 'watch(route, () => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'watch(() => props.locale, load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: "watch(id, () => { void load() }, { flush: 'post' })" })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: "watch(id, () => { void load() }, { flush: 'sync' })" })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'watch(id, () => repository.list(), opts)' })).toEqual([`${PAGE} : repository.list`])
    })

    it("un watch immediate lit dans le setup, avec ou sans flush: 'post', et ne rougit pas (#748, R144, R159)", () => {
      expect(offendingIn({ [PAGE]: 'watch(id, () => { void load() }, { immediate: true })' })).toEqual([])
      expect(offendingIn({ [PAGE]: "watch(id, () => { void load() }, { immediate: true, flush: 'post' })" })).toEqual([])
      expect(offendingIn({ [PAGE]: "watch(id, () => { void load() }, { flush: 'sync', immediate: true })" })).toEqual([])
    })

    it("un watchEffect posté charge, le même sans flush: 'post' lit dans le setup (#748, R144)", () => {
      expect(offendingIn({ [PAGE]: `watchEffect(() => { void load() }, { flush: 'post' })` })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'watchEffect(() => { void load() })' })).toEqual([])
    })

    it('un chargement dans onUpdated, onBeforeUpdate ou onActivated (#748, R159)', () => {
      expect(offendingIn({ [PAGE]: 'onUpdated(() => { void load() })' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'onBeforeUpdate(load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'onActivated(() => repository.list())' })).toEqual([`${PAGE} : repository.list`])
    })

    it('un chargement dans un hook de démontage, de désactivation ou de navigation (#748, R178)', () => {
      expect(offendingIn({ [PAGE]: 'onBeforeUnmount(() => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'onUnmounted(load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'onDeactivated(() => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'onBeforeRouteUpdate(() => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'onBeforeRouteLeave(() => repository.list())' })).toEqual([`${PAGE} : repository.list`])
    })

    it('un chargement lancé par un minuteur répété (#748, R178)', () => {
      expect(offendingIn({ [PAGE]: 'setInterval(load, 1)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'window.setInterval(() => void load(), 1)' })).toEqual([`${PAGE} : load`])
    })

    it('un chargement lancé par le rappel d\'une promesse, then, catch ou finally, ou par une chaîne (#748, R178)', () => {
      expect(offendingIn({ [PAGE]: 'promise.then(load)' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'promise.catch(() => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'promise.finally(() => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'promise.then(ok, () => load())' })).toEqual([`${PAGE} : load`])
      expect(offendingIn({ [PAGE]: 'prepare().then(() => load()).then(done)' })).toEqual([`${PAGE} : load`])
    })

    it('un chargement en tête du setup, ou un then qui ne charge pas, passe (#748, R178)', () => {
      expect(offendingIn({ [PAGE]: 'void load()\nconst { value } = useX()' })).toEqual([])
      expect(offendingIn({ [PAGE]: 'prepare().then(() => focusTitle())' })).toEqual([])
    })

    it('un composant de presentation/ui/admin (#748, R144)', () => {
      const component = 'presentation/ui/admin/FakeNotice.vue'

      expect(offendingIn({ [component]: 'onMounted(() => { void repository.list() })' })).toEqual([`${component} : repository.list`])
    })

    it('un fichier hors des deux dossiers du backoffice n\'est pas lu (#748, R144)', () => {
      expect(offendingIn({ 'presentation/ui/Other.vue': 'onMounted(load)', 'application/useX.ts': 'onMounted(load)' })).toEqual([])
    })

    it("un onMounted qui ne charge pas passe, qu'il soit écrit en fonction ou en référence (#748, R144)", () => {
      expect(offendingIn({ [PAGE]: 'onMounted(() => { focusTitle() })\nnextTick(focusTitle)' })).toEqual([])
    })
  })
})
