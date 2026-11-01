import ts from 'typescript'
import { describe, expect, it } from 'vitest'
import { SRC_SCRIPTS } from '../../../support/srcSources'

/**
 * Ce que lisent `isRefused` et `identity` est déclaré **avant** l'appel de `useTranslatedForm` (#748, R189b, décision de l'auteur
 * du 2026-11-01). `useTranslatedForm` passe ces deux fonctions à `useAdminTranslation`, qui en lit le résultat dès sa
 * construction (le refus exposé est un `computed`, l'identité une surveillance) : une `const` lue par l'une d'elles et déclarée
 * après l'appel est alors dans sa zone morte (`ReferenceError` à l'exécution du `setup`), et le compilateur ne le voit pas, la
 * lecture étant dans une fermeture. `form`, lui, est passé tel quel : `vue-tsc` refuse sa déclaration tardive (TS2448, R189), donc
 * la garde ne le relit pas. L'ordre n'est donc pas une convention écrite dans la règle : c'est un contrôle.
 *
 * Lu : chaque fichier de `presentation/pages/admin/` dont le `<script>` appelle `useTranslatedForm` (huit fichiers : la section
 * générique de Qualité, R190, est lue comme les autres, par son dossier et son appel, sans liste de noms). Pour chaque appel, les
 * options `isRefused` et `identity` ; les identifiants qu'elles lisent, puis, de proche en proche, ceux que lisent les initialiseurs
 * des constantes du `setup` ainsi atteintes (`isAnyOrderDirty` lit `categoryOrders`, `lock` lit `isEditing`…) : chacun déclaré au
 * niveau du `setup` doit l'être, **fin de sa déclaration comprise**, avant le début de l'appel (la déclaration qui contient
 * l'appel, `const { isRefused } = useTranslatedForm(…)`, est donc refusée si une option la lit). Le message dit le fichier,
 * l'identifiant, l'option qui le lit et les deux lignes.
 *
 * Non lu, par choix : une déclaration de fonction (`function f() {}`, remontée : lisible à tout endroit) ; l'initialiseur d'une
 * constante qui est elle-même une fonction (`const f = () => …`) : son corps ne court qu'à son appel, que la garde ne suit pas ; un
 * nom lié localement dans la lecture (paramètre, variable d'une fermeture : il masque une déclaration du `setup`) ; un nom de
 * propriété (`x.later`) ; un type. Non vu, tenu par la relecture : un `setup` qui appelle `useTranslatedForm` hors du premier
 * argument en littéral objet (`useTranslatedForm(options)`) ; une lecture par un alias posé par une affectation à une variable
 * déclarée avant mais remplie après ; un `<script>` qui déclare l'appel dans une fonction.
 */
const GUARDED_DIR = 'presentation/pages/admin/'
const COMPOSABLE = 'useTranslatedForm'
const CHECKED_OPTIONS: ReadonlySet<string> = new Set(['isRefused', 'identity'])

/** Une déclaration du niveau du `setup` : où elle finit et ce qu'elle initialise. */
interface Declaration {
  readonly end: number
  readonly hoisted: boolean
  readonly initializer: ts.Expression | undefined
}

interface LateRead {
  readonly name: string
  readonly option: string
  readonly declaredLine: number
  readonly callLine: number
}

interface Inspection {
  /** Les appels de `useTranslatedForm` vus. */
  readonly calls: number
  /** Les déclarations du `setup` atteintes depuis les options lues (une fois chacune par appel). */
  readonly reads: number
  readonly late: readonly LateRead[]
}

/** Les noms que lie un identifiant ou un motif de déstructuration. */
function bindingNames(name: ts.BindingName): string[] {
  if (ts.isIdentifier(name)) {
    return [name.text]
  }

  return name.elements.flatMap((element) => (ts.isOmittedExpression(element) ? [] : bindingNames(element.name)))
}

/** Les déclarations du niveau du `setup`, par nom : variables (chaque nom d'un motif) et fonctions. */
function declarationsOf(file: ts.SourceFile): Map<string, Declaration> {
  const declarations = new Map<string, Declaration>()

  for (const statement of file.statements) {
    if (ts.isFunctionDeclaration(statement) && undefined !== statement.name) {
      declarations.set(statement.name.text, { end: statement.getEnd(), hoisted: true, initializer: undefined })
    } else if (ts.isVariableStatement(statement)) {
      for (const declaration of statement.declarationList.declarations) {
        for (const name of bindingNames(declaration.name)) {
          declarations.set(name, { end: declaration.getEnd(), hoisted: false, initializer: declaration.initializer })
        }
      }
    }
  }

  return declarations
}

/** Vrai si l'identifiant est une lecture : ni un nom de propriété, ni un site de déclaration. */
function isRead(node: ts.Identifier): boolean {
  const parent = node.parent

  return !(
    (ts.isPropertyAccessExpression(parent) && parent.name === node) ||
    (ts.isPropertyAssignment(parent) && parent.name === node) ||
    (ts.isBindingElement(parent) && (parent.name === node || parent.propertyName === node)) ||
    (ts.isParameter(parent) && parent.name === node) ||
    (ts.isVariableDeclaration(parent) && parent.name === node)
  )
}

/** Les identifiants lus par `node`, moins ceux que `node` lie lui-même (paramètres et variables de ses fermetures). */
function readsOf(node: ts.Node): Set<string> {
  const reads = new Set<string>()
  const locals = new Set<string>()

  const visit = (current: ts.Node): void => {
    // Un type ne lit rien : `defineProps<{ locale: Locale }>()` ne lit ni `locale` ni `Locale`.
    if (ts.isTypeNode(current)) {
      return
    }
    if (ts.isParameter(current) || ts.isVariableDeclaration(current)) {
      bindingNames(current.name).forEach((name) => locals.add(name))
    }
    if (ts.isIdentifier(current) && isRead(current)) {
      reads.add(current.text)
    }
    ts.forEachChild(current, visit)
  }
  visit(node)

  return new Set([...reads].filter((name) => !locals.has(name)))
}

const isFunctionLike = (node: ts.Expression | undefined): boolean =>
  undefined !== node && (ts.isArrowFunction(node) || ts.isFunctionExpression(node))

/** Les options `isRefused` et `identity` d'un appel, avec ce que chacune lit : `[option, noms lus]`. */
function checkedOptionsOf(call: ts.CallExpression): [string, Set<string>][] {
  const options = call.arguments[0]
  if (undefined === options || !ts.isObjectLiteralExpression(options)) {
    return []
  }

  return options.properties.flatMap((property): [string, Set<string>][] => {
    if (ts.isPropertyAssignment(property) && ts.isIdentifier(property.name) && CHECKED_OPTIONS.has(property.name.text)) {
      return [[property.name.text, readsOf(property.initializer)]]
    }
    if (ts.isShorthandPropertyAssignment(property) && CHECKED_OPTIONS.has(property.name.text)) {
      return [[property.name.text, new Set([property.name.text])]]
    }

    return []
  })
}

/** Les appels de `useTranslatedForm` du fichier, à tout niveau. */
function callsOf(file: ts.SourceFile): ts.CallExpression[] {
  const calls: ts.CallExpression[] = []
  const visit = (node: ts.Node): void => {
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && COMPOSABLE === node.expression.text) {
      calls.push(node)
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  return calls
}

/** Ce que la garde voit dans un `<script>` : les appels, les déclarations atteintes, et celles qui sont déclarées trop tard. */
function inspect(source: string): Inspection {
  const file = ts.createSourceFile('script.ts', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
  const declarations = declarationsOf(file)
  const lineOf = (position: number): number => file.getLineAndCharacterOfPosition(position).line + 1
  const calls = callsOf(file)
  const late: LateRead[] = []
  let reads = 0

  for (const call of calls) {
    const callStart = call.getStart(file)
    for (const [option, direct] of checkedOptionsOf(call)) {
      const seen = new Set<string>()
      const queue = [...direct]
      for (let name = queue.shift(); undefined !== name; name = queue.shift()) {
        const declaration = declarations.get(name)
        if (seen.has(name) || undefined === declaration || declaration.hoisted) {
          continue
        }
        seen.add(name)
        reads++
        if (declaration.end > callStart) {
          late.push({ name, option, declaredLine: lineOf(declaration.end), callLine: lineOf(callStart) })
        } else if (undefined !== declaration.initializer && !isFunctionLike(declaration.initializer)) {
          queue.push(...readsOf(declaration.initializer))
        }
      }
    }
  }

  return { calls: calls.length, reads, late }
}

function isGuarded(path: string): boolean {
  return path.startsWith(GUARDED_DIR)
}

/** Une lecture trop tardive par ligne, `chemin : nom (…)`, pour chaque fichier lu de `files` (les chemins de `src/`). */
function offendingIn(files: Readonly<Record<string, string>>): string[] {
  return Object.entries(files)
    .filter(([path]) => isGuarded(path))
    .flatMap(([path, source]) =>
      inspect(source).late.map(
        (read) =>
          `${path} : ${read.name} (lu par ${read.option}, déclaré ligne ${read.declaredLine}, ` +
          `après l'appel de ${COMPOSABLE} ligne ${read.callLine})`,
      ),
    )
}

describe("ce que lit le refus de traduction est déclaré avant l'appel de useTranslatedForm", () => {
  const sites = Object.entries(SRC_SCRIPTS)
    .filter(([path]) => isGuarded(path))
    .map(([path, source]) => ({ path, inspection: inspect(source) }))
    .filter(({ inspection }) => inspection.calls > 0)

  it("aucun fichier de pages/admin ne lit, dans isRefused ou identity, une déclaration placée après l'appel (#748, R189b)", () => {
    expect(offendingIn(SRC_SCRIPTS)).toEqual([])
  })

  it("la garde lit les formulaires à traduction assistée et ce que leurs options lisent (#748, R189b)", () => {
    // Plancher : huit fichiers appellent le composable (sept pages ou sections par entrée, dont la section générique de Qualité,
    // montée deux fois, R190, et les réglages « À propos »). Sans cette borne, une garde qui ne lit plus rien resterait verte.
    expect(sites.length).toBeGreaterThanOrEqual(8)
    expect(sites.reduce((total, site) => total + site.inspection.calls, 0)).toBeGreaterThanOrEqual(8)
    // Chaque appel lit au moins `isSubmitting` ou `isAnySaving`, `props` ou `lock`… : un site qui n'atteint plus aucune déclaration
    // n'est plus lu.
    for (const { path, inspection } of sites) {
      expect({ path, enough: inspection.reads >= 2 }).toEqual({ path, enough: true })
    }
  })

  describe('chaque forme que la garde voit rougit sur un fichier factice, son voisin déclaré avant reste vert', () => {
    const PAGE = 'presentation/pages/admin/AdminFakePage.vue'
    const CALL = 'useTranslatedForm({ isRefused: () => isSubmitting.value, identity: () => ({ entry: editingId.value }) })'
    const lateLine = (name: string, option: string, declaredLine: number, callLine: number): string =>
      `${PAGE} : ${name} (lu par ${option}, déclaré ligne ${declaredLine}, après l'appel de ${COMPOSABLE} ligne ${callLine})`

    it('une constante lue par isRefused et déclarée après l\'appel rougit ; déclarée avant, elle passe (#748, R189b)', () => {
      const before = `const isSubmitting = ref(false)\nconst editingId = ref(null)\n${CALL}`
      const after = `const editingId = ref(null)\n${CALL}\nconst isSubmitting = ref(false)`

      expect(offendingIn({ [PAGE]: before })).toEqual([])
      expect(offendingIn({ [PAGE]: after })).toEqual([lateLine('isSubmitting', 'isRefused', 3, 2)])
    })

    it("une constante lue par identity et déclarée après l'appel rougit (#748, R189b)", () => {
      const source = `const isSubmitting = ref(false)\n${CALL}\nconst editingId = ref(null)`

      expect(offendingIn({ [PAGE]: source })).toEqual([lateLine('editingId', 'identity', 3, 2)])
    })

    it("un nom que déstructure une déclaration placée après l'appel rougit (#748, R189b)", () => {
      const source = `const editingId = ref(null)\n${CALL}\nconst { isDirty: isSubmitting } = useOrderDraft()`

      expect(offendingIn({ [PAGE]: source })).toEqual([lateLine('isSubmitting', 'isRefused', 3, 2)])
    })

    it("un dérivé déclaré avant l'appel qui lit une constante déclarée après rougit, sur la constante (#748, R189b)", () => {
      const source = [
        'const editingId = ref(null)',
        'const isSubmitting = computed(() => draft.value)',
        CALL,
        'const draft = ref(false)',
      ].join('\n')

      expect(offendingIn({ [PAGE]: source })).toEqual([lateLine('draft', 'isRefused', 4, 3)])
    })

    it("un dérivé lu par un nom, de proche en proche sur plusieurs niveaux, passe quand tout est avant l'appel (#748, R189b)", () => {
      const source = [
        'const orders = [1].map(create)',
        'const isSubmitting = computed(() => orders.some((order) => order.isDirty.value))',
        'const editingId = ref(null)',
        CALL,
      ].join('\n')

      expect(offendingIn({ [PAGE]: source })).toEqual([])
    })

    it("la déclaration qui contient l'appel, lue par une option, rougit (#748, R189b)", () => {
      const source = `const editingId = ref(null)\nconst { isRefused: isSubmitting } = ${CALL}`

      expect(offendingIn({ [PAGE]: source })).toEqual([lateLine('isSubmitting', 'isRefused', 2, 2)])
    })

    it("une option qui nomme une fonction (propriété abrégée ou référence) est lue comme les autres (#748, R189b)", () => {
      const shorthand = 'useTranslatedForm({ isRefused, identity: () => ({}) })\nconst isRefused = () => false'
      const reference = 'useTranslatedForm({ isRefused: refused, identity: () => ({}) })\nconst refused = () => false'

      expect(offendingIn({ [PAGE]: shorthand })).toEqual([lateLine('isRefused', 'isRefused', 2, 1)])
      expect(offendingIn({ [PAGE]: reference })).toEqual([lateLine('refused', 'isRefused', 2, 1)])
    })

    it('une déclaration de fonction placée après est remontée, une constante fonction ne court qu\'à son appel (#748, R189b)', () => {
      const hoisted = [
        'const editingId = ref(null)',
        'useTranslatedForm({ isRefused: () => isSubmitting(), identity: () => ({ entry: editingId.value }) })',
        'function isSubmitting() { return false }',
      ].join('\n')
      const lazy = [
        'const editingId = ref(null)',
        'const isSubmitting = computed(() => check())',
        CALL,
        'const check = () => later.value',
        'const later = ref(false)',
      ].join('\n')

      expect(offendingIn({ [PAGE]: hoisted })).toEqual([])
      expect(offendingIn({ [PAGE]: lazy })).toEqual([lateLine('check', 'isRefused', 4, 3)])
    })

    it('un nom lié par une fermeture, un nom de propriété et un type ne sont pas des lectures (#748, R189b)', () => {
      const source = [
        'const editingId = ref(null)',
        'const isSubmitting = computed(() => rows.some((later) => later.isDirty) || state.later || toKey<later>())',
        CALL,
        'const later = ref(false)',
        'const rows = ref([])',
      ].join('\n')

      // `rows` est lu par `isSubmitting` et déclaré après l'appel : le seul rouge. `later` (paramètre, propriété, type) n'en est pas un.
      expect(offendingIn({ [PAGE]: source })).toEqual([lateLine('rows', 'isRefused', 5, 3)])
    })

    it('un identifiant déclaré hors du setup (importé, global) n\'est pas lu ; deux appels sont lus chacun (#748, R189b)', () => {
      const imported = `import { isSubmitting } from './x'\nconst editingId = ref(null)\n${CALL}`
      const twice = `const editingId = ref(null)\n${CALL}\nconst isSubmitting = ref(false)\n${CALL}`

      expect(offendingIn({ [PAGE]: imported })).toEqual([])
      expect(inspect(twice).calls).toBe(2)
      expect(offendingIn({ [PAGE]: twice })).toEqual([lateLine('isSubmitting', 'isRefused', 3, 2)])
    })

    it("un fichier hors de pages/admin ou sans appel de useTranslatedForm n'est pas lu (#748, R189b)", () => {
      const late = `useTranslatedForm({ isRefused: () => isSubmitting.value, identity: () => ({}) })\nconst isSubmitting = ref(false)`

      expect(offendingIn({ 'presentation/ui/admin/Other.vue': late })).toEqual([])
      const other = 'const isSubmitting = ref(false)\nuseOther({ isRefused: () => later.value })\nconst later = 1'

      expect(offendingIn({ [PAGE]: other })).toEqual([])
    })
  })
})
