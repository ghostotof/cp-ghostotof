import { isFnExpressionBrowser, isMemberExpressionBrowser, NodeTypes, type ExpressionNode } from '@vue/compiler-dom'
import ts from 'typescript'
import { parse, type SFCTemplateBlock } from 'vue/compiler-sfc'

/**
 * Le lecteur de source des gardes de `tests/` (#748).
 *
 * Ce que le module fait : il lit le texte de `src/` comme le compilateur le lit. Il s'appuie sur l'arbre syntaxique de
 * TypeScript (`typescript`, déjà en `devDependencies`) : ce que le compilateur lit comme une chaîne, un gabarit, une
 * regex ou un commentaire l'est ici aussi, un échappement est lu (`'om\x69t'` est `omit`), et une regex littérale
 * (`` /`([^`]+)`/ ``, `/filename="?([^";]+)"?/`) n'ouvre ni gabarit, ni chaîne, ni commentaire. Un `.vue` passe d'abord
 * par `scriptOf`, qui n'en garde que les blocs `<script>` (le `<template>` n'est pas du JavaScript : son apostrophe ou
 * son `//` ne doivent rien ouvrir) ; les expressions de ce gabarit (directives, gestionnaires en ligne, interpolations)
 * et ses valeurs d'attributs statiques se lisent à part, par `templateFragmentsOf`, qui rend chacune comme le fragment
 * JavaScript que Vue en compile : le type d'un nœud (`NodeTypes`) et le jugement d'un `v-on` (`isMemberExpressionBrowser`,
 * `isFnExpressionBrowser`) viennent de `@vue/compiler-dom`, déclaré en `devDependencies` au même intervalle que `vue`.
 * Les fins de ligne (`\r` compris) d'un commentaire ou d'un bloc écarté sont gardées : une position ou un numéro de
 * ligne reste celui de la source.
 *
 * Qui l'appelle : `srcSources.ts` lit une fois les fichiers de `src/` pour `noOmittedCredentials.spec.ts` et
 * `sessionStateEncapsulation.spec.ts`.
 * `noLoadInOnMounted.spec.ts` lit, par `deferredCallsOf` et `destructuredAliasesOf`, ce que les pages et composants du backoffice
 * appellent après leur montage.
 *
 * Ce que ça coûte : les gardes dépendent de l'API du compilateur (`createSourceFile`, `getLeadingCommentRanges`…), de
 * celle de `vue/compiler-sfc` (`parse`) et de celle de `@vue/compiler-dom` (`NodeTypes`, `isMemberExpressionBrowser`…).
 * Un passage à TypeScript 7 (le portage natif) change la première : les gardes rougiront alors bruyamment (une importation
 * qui échoue), jamais en silence.
 *
 * Une chaîne non terminée est lue comme TypeScript la lit ; `vue-tsc` refuse ce code (`make front-build`), il n'est
 * jamais livré.
 */

/**
 * Le texte de `source` sans ce qui n'est pas du code : commentaires de bloc et de ligne retirés au profit d'un seul espace, les fins de
 * ligne d'un commentaire gardées pour que les numéros de ligne restent ceux de la source ; chaînes, gabarits, regex et
 * expressions de gabarit rendus tels quels.
 */
export function withoutComments(source: string): string {
  const file = syntaxTreeOf(source)
  // Les commentaires, par position de début (un même commentaire est vu comme « de tête » d'un jeton et « de queue » du
  // précédent : la table le compte une fois).
  const comments = new Map<number, number>()

  const visit = (node: ts.Node): void => {
    // Les nœuds JSDoc sont dans l'enfance d'un nœud (`getChildren`), mais ils sont déjà dans le commentaire qu'on retire :
    // lire un `//` écrit à l'intérieur donnerait une plage qui déborde du commentaire.
    if (node.kind >= ts.SyntaxKind.FirstJSDocNode && node.kind <= ts.SyntaxKind.LastJSDocNode) {
      return
    }
    for (const range of ts.getLeadingCommentRanges(source, node.getFullStart()) ?? []) {
      comments.set(range.pos, range.end)
    }
    for (const range of ts.getTrailingCommentRanges(source, node.getEnd()) ?? []) {
      comments.set(range.pos, range.end)
    }
    for (const child of node.getChildren(file)) {
      visit(child)
    }
  }
  visit(file)

  let text = ''
  let from = 0
  for (const start of [...comments.keys()].sort((a, b) => a - b)) {
    const end = comments.get(start) ?? start
    if (start < from) {
      continue
    }
    text += source.slice(from, start) + ' ' + source.slice(start, end).replace(/[^\r\n]/g, '')
    from = end
  }

  return text + source.slice(from)
}

/**
 * Le contenu de chaque littéral de la source, hors commentaires : chaînes `'…'` et `"…"`, gabarits sans expression, et
 * chaque partie fixe d'un gabarit à expression (`a${x}b` donne `a` et `b`), puis les chaînes de ses expressions. Les
 * échappements sont décodés (`'om\x69t'` donne `omit`).
 */
export function stringLiterals(source: string): string[] {
  const literals: string[] = []

  const visit = (node: ts.Node): void => {
    // Une partie fixe de gabarit à expression (`TemplateHead`, `TemplateMiddle`, `TemplateTail`) est un littéral aussi :
    // dans `` `${''}omit` ``, `omit` est un `TemplateTail`.
    if (ts.isStringLiteralLike(node) || ts.isTemplateHead(node) || ts.isTemplateMiddle(node) || ts.isTemplateTail(node)) {
      literals.push(node.text)
    }
    ts.forEachChild(node, visit)
  }
  visit(syntaxTreeOf(source))

  return literals
}

/**
 * La partie JavaScript de `source` : pour un chemin en `.vue`, le contenu de ses blocs `<script>` et `<script setup>`,
 * **à leur place** — tout le reste (le `<template>`, le `<style>`, les balises) est remplacé par des espaces, les fins de
 * ligne gardées, de sorte qu'une position ou un numéro de ligne reste celui de la source. Tout autre chemin : la source
 * telle quelle. Un `.vue` que le compilateur de composants ne sait pas lire est refusé (une garde qui sauterait ce
 * fichier le laisserait sans garde).
 */
export function scriptOf(path: string, source: string): string {
  if (!path.endsWith('.vue')) {
    return source
  }

  const { descriptor, errors } = parse(source, { filename: path })
  if (errors.length > 0) {
    throw new Error(`${path} : ${errors.map((error) => error.message).join(' ; ')}`)
  }

  let code = source.replace(/[^\r\n]/g, ' ')
  for (const block of [descriptor.script, descriptor.scriptSetup]) {
    if (null === block) {
      continue
    }
    const at = block.loc.start.offset
    code = code.slice(0, at) + block.content + code.slice(at + block.content.length)
  }

  return code
}

/** La racine du gabarit, telle que `vue/compiler-sfc` la type. */
export type TemplateRoot = NonNullable<SFCTemplateBlock['ast']>
type TemplateChild = TemplateRoot['children'][number]
type TemplateElement = Extract<TemplateChild, { props: unknown[] }>
type TemplateProp = TemplateElement['props'][number]
type TemplateDirective = Extract<TemplateProp, { exp: unknown }>
type TemplateExpression = NonNullable<TemplateDirective['exp']>

/**
 * Les fragments JavaScript du `<template>` d'un `.vue`, dans l'ordre du document, chacun écrit comme Vue le compile et lu
 * par TypeScript de la même façon (voir `fragmentsOfTemplateAst`). `[]` pour un chemin qui n'est pas un `.vue` ou sans
 * gabarit ; un `.vue` illisible est refusé comme par `scriptOf`, et un `<template>` sans arbre (`lang="pug"`, `src`) aussi :
 * le lire `[]` le laisserait sans garde. Chaque fragment se lit ensuite avec `stringLiterals`.
 */
export function templateFragmentsOf(path: string, source: string): string[] {
  if (!path.endsWith('.vue')) {
    return []
  }

  const { descriptor, errors } = parse(source, { filename: path })
  if (errors.length > 0) {
    throw new Error(`${path} : ${errors.map((error) => error.message).join(' ; ')}`)
  }
  if (null === descriptor.template) {
    return []
  }
  // `src` n'a pas d'arbre ; un `lang` autre que HTML (`pug`) en a un, mais d'une seule ligne de texte : ni l'un ni l'autre n'est lu.
  if (undefined === descriptor.template.ast || (undefined !== descriptor.template.lang && 'html' !== descriptor.template.lang)) {
    throw new Error(`${path} : <template> sans arbre (lang ou src), non lu`)
  }

  return fragmentsOfTemplateAst(descriptor.template.ast)
}

/**
 * Comment Vue lit la valeur d'une directive, pour que TypeScript la lise de la même façon :
 *
 * - `v-on` sans argument (`v-on="{ click: a }"`) est une expression ; avec un argument, la règle de `vOn` de Vue 3.5 : une
 *   expression si Vue juge le texte un chemin de membre (`isMemberExpressionBrowser`) ou une fonction
 *   (`isFnExpressionBrowser`), sinon une instruction en ligne, que Vue enveloppe dans une fonction de `$event` : un corps de
 *   fonction si le texte contient un `;` (`a(); b()`), une expression entre parenthèses sinon (`{ credentials: 'omit' }`, `b()`) ;
 * - un paramètre de `v-slot` est un paramètre de fonction (`{ a = 1 }` y est un motif de déstructuration, non un bloc) ;
 * - toute autre directive est une expression (`{ credentials: 'omit' }` y est un littéral objet, non un bloc étiqueté).
 */
function wrapperOf(directive: TemplateDirective): (text: string, node: ExpressionNode) => string {
  switch (directive.name) {
    case 'on':
      return (text, node) => {
        if (undefined === directive.arg || isMemberExpressionBrowser(node) || isFnExpressionBrowser(node)) {
          return asExpression(text)
        }

        return text.includes(';') ? asBody(text) : asHandler(text)
      }
    case 'slot':
      return asParameter
    default:
      return asExpression
  }
}

const asExpression = (text: string): string => `(${text})`
const asParameter = (text: string): string => `(${text}) => 0`
const asHandler = (text: string): string => `($event) => (${text})`
const asBody = (text: string): string => `($event) => {\n${text}\n}`

/**
 * Les fragments JavaScript d'un gabarit déjà lu, dans l'ordre du document : la valeur de chaque directive (écrite par
 * `wrapperOf`), les alias d'un `v-for` (valeur, clé, index, des paramètres de fonction ; sa source, une expression),
 * l'argument d'une directive dynamique (`:[nom]`), la valeur d'un attribut statique (`mode="omit"`, rendue en chaîne
 * JavaScript) et le contenu de chaque interpolation `{{ … }}`.
 *
 * Un nœud sans contenu (`null` ou absent) n'est jamais visité.
 */
export function fragmentsOfTemplateAst(root: TemplateRoot): string[] {
  const fragments: string[] = []

  const read = (expression: TemplateExpression | undefined, wrap: (text: string, node: ExpressionNode) => string): void => {
    // Une expression composée (`CompoundExpressionNode`) naît de la transformation, jamais de l'analyse : seul le texte source compte.
    if (undefined !== expression && 'content' in expression && 'string' === typeof expression.content) {
      fragments.push(wrap(expression.content, expression))
    }
  }

  const readDirective = (directive: TemplateDirective): void => {
    if (undefined !== directive.forParseResult) {
      const { source, value, key, index } = directive.forParseResult
      read(source, asExpression)
      read(value, asParameter)
      read(key, asParameter)
      read(index, asParameter)
    } else {
      read(directive.exp, wrapperOf(directive))
    }
    // Un argument statique (`:title`, `@click`) est un nom, non une expression.
    if (undefined !== directive.arg && 'isStatic' in directive.arg && !directive.arg.isStatic) {
      read(directive.arg, asExpression)
    }
  }

  const visit = (node: TemplateRoot | TemplateChild | TemplateExpression): void => {
    if ('props' in node) {
      for (const prop of node.props) {
        if ('exp' in prop) {
          readDirective(prop)
        } else if (undefined !== prop.value) {
          // Un attribut statique (`mode="omit"`) est une chaîne que le composant reçoit telle quelle.
          fragments.push(JSON.stringify(prop.value.content))
        }
      }
    }
    // Une interpolation porte son expression dans `content` (un nœud) ; un nœud de texte, dans `content` (une chaîne).
    if ('type' in node && NodeTypes.INTERPOLATION === node.type && 'content' in node && null !== node.content) {
      read(node.content, asExpression)
    }
    if ('children' in node) {
      for (const child of node.children) {
        // Les enfants d'une expression composée comptent aussi des chaînes et des symboles, qui ne sont pas des nœuds.
        if ('object' === typeof child) {
          visit(child)
        }
      }
    }
  }
  visit(root)

  return fragments
}

/**
 * Les erreurs de syntaxe que TypeScript relève dans `fragment` (une liste de messages, vide si le fragment est valide) :
 * la garde qui vérifie que tout fragment lu de `src/` est du JavaScript valide, et non une chaîne lue par chance.
 */
export function syntaxErrorsOf(fragment: string): string[] {
  const { diagnostics } = ts.transpileModule(fragment, { reportDiagnostics: true })

  return (diagnostics ?? []).map((diagnostic) => ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'))
}

/** L'arbre de `source`, lu comme du TypeScript (parents posés : `getChildren` en a besoin). */
function syntaxTreeOf(source: string): ts.SourceFile {
  return ts.createSourceFile('source.ts', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
}

/**
 * Où un hook différé range la fonction qu'il rappellera (`callback`, l'index de l'argument), où il range ses options
 * (`options`) et s'il ne s'exécute après le rendu que posté (`postedOnly`, `{ flush: 'post' }` : un `watch` ou un
 * `watchEffect` sans lui s'exécute dans le `setup`, ou de façon synchrone, jamais après le montage).
 */
interface DeferredHook {
  readonly callback: number
  readonly options?: number
  readonly postedOnly?: boolean
}

/** Les hooks de `vue` dont le rappel s'exécute après le montage, et les appels globaux qui différent une fonction. */
const DEFERRED_HOOKS: ReadonlyMap<string, DeferredHook> = new Map([
  ['onMounted', { callback: 0 }],
  ['nextTick', { callback: 0 }],
  ['watchPostEffect', { callback: 0 }],
  ['watch', { callback: 1, options: 2, postedOnly: true }],
  ['watchEffect', { callback: 0, options: 1, postedOnly: true }],
  ['setTimeout', { callback: 0 }],
  ['queueMicrotask', { callback: 0 }],
  ['requestAnimationFrame', { callback: 0 }],
])

/** Les appels globaux, qui s'écrivent aussi `window.setTimeout(…)`. */
const GLOBAL_DEFERRALS: ReadonlySet<string> = new Set(['setTimeout', 'queueMicrotask', 'requestAnimationFrame'])
const GLOBAL_OBJECTS: ReadonlySet<string> = new Set(['window', 'globalThis', 'self'])

/** Le nom importé de chaque nom local d'une importation nommée de `vue` (`onMounted as whenMounted` : `whenMounted` donne `onMounted`). */
function vueImportsOf(file: ts.SourceFile): ReadonlyMap<string, string> {
  const imported = new Map<string, string>()
  for (const statement of file.statements) {
    if (!ts.isImportDeclaration(statement) || !ts.isStringLiteral(statement.moduleSpecifier) || 'vue' !== statement.moduleSpecifier.text) {
      continue
    }
    const bindings = statement.importClause?.namedBindings
    if (undefined === bindings || !ts.isNamedImports(bindings)) {
      continue
    }
    for (const specifier of bindings.elements) {
      imported.set(specifier.name.text, (specifier.propertyName ?? specifier.name).text)
    }
  }

  return imported
}

/** Vrai si `options` est un littéral objet qui porte `flush: 'post'` (une variable d'options n'est pas lue). */
function isPostedOptions(options: ts.Expression | undefined): boolean {
  if (undefined === options || !ts.isObjectLiteralExpression(options)) {
    return false
  }

  return options.properties.some(
    (property) =>
      ts.isPropertyAssignment(property) &&
      (ts.isIdentifier(property.name) || ts.isStringLiteral(property.name)) &&
      'flush' === property.name.text &&
      ts.isStringLiteralLike(property.initializer) &&
      'post' === property.initializer.text,
  )
}

/**
 * Le texte du *callee* de chaque appel qui s'exécute après le rendu d'un composant, dans l'ordre du source :
 *
 * - les appels écrits dans une fonction passée à un hook différé, fonctions imbriquées comprises
 *   (`onMounted(() => { a(); x.y() })` donne `['a', 'x.y']`) ;
 * - le nom passé **par référence** à un tel hook (`onMounted(load)` donne `['load']`, `onMounted(page.load)` donne `['page.load']`),
 *   et de même pour un hook posé dans la fonction d'un autre (`onMounted(() => { nextTick(load) })` donne `['nextTick', 'load']`).
 *
 * Les hooks différés sont `onMounted`, `nextTick`, `watchPostEffect`, `watch` et `watchEffect` quand leurs options portent
 * `flush: 'post'`, puis `setTimeout`, `queueMicrotask` et `requestAnimationFrame` (nus ou sur `window`, `globalThis`,
 * `self`). Les hooks de `vue` se lisent sous le nom que leur importation leur donne (`import { onMounted as whenMounted }
 * from 'vue'`), lu dans l'`ImportDeclaration` ; un nom que `vue` ne lie pas est lu tel qu'il est écrit, pour qu'une source
 * sans importation (un fichier de test) se lise de même. Seul l'argument qui est le rappel est lu : le délai d'un
 * `setTimeout` et la source d'un `watch` ne sont pas des appels différés.
 *
 * Invisible, et la limite est celle de la garde qui s'en sert : un hook passé par une variable (`const hook = onMounted`), une
 * option d'un `watch` passée par une variable, un module importé en espace de noms (`Vue.onMounted(…)`), une fonction déclarée
 * dans le fichier et passée par référence (vue par son nom seul, non par ce qu'elle appelle).
 */
export function deferredCallsOf(source: string): string[] {
  const file = syntaxTreeOf(source)
  const imported = vueImportsOf(file)
  const callees: string[] = []

  /** Le hook différé que `call` pose, ou `null` : son nom est lu par l'importation, puis `window.` ou `globalThis.` des minuteurs. */
  const hookOf = (call: ts.CallExpression): DeferredHook | null => {
    const callee = call.expression
    let name: string | null = null
    if (ts.isIdentifier(callee)) {
      name = imported.get(callee.text) ?? callee.text
    } else if (
      ts.isPropertyAccessExpression(callee) &&
      ts.isIdentifier(callee.expression) &&
      GLOBAL_OBJECTS.has(callee.expression.text) &&
      GLOBAL_DEFERRALS.has(callee.name.text)
    ) {
      name = callee.name.text
    }
    const hook = null === name ? undefined : DEFERRED_HOOKS.get(name)
    if (undefined === hook || (true === hook.postedOnly && !isPostedOptions(call.arguments[hook.options ?? -1]))) {
      return null
    }

    return hook
  }

  /** Le rappel d'un hook, s'il est écrit comme une fonction ou comme une référence (`undefined` sinon : un appel n'est pas un rappel). */
  const callbackOf = (call: ts.CallExpression, hook: DeferredHook): ts.Expression | undefined => call.arguments[hook.callback]
  const isReference = (node: ts.Expression): boolean => ts.isIdentifier(node) || ts.isPropertyAccessExpression(node)
  const isFunction = (node: ts.Expression): boolean => ts.isArrowFunction(node) || ts.isFunctionExpression(node)

  // Tout appel écrit dans la fonction d'un hook, y compris ceux d'un hook qu'elle pose à son tour (dont la référence compte).
  const collect = (node: ts.Node): void => {
    if (ts.isCallExpression(node)) {
      callees.push(node.expression.getText(file))
      const hook = hookOf(node)
      const callback = null === hook ? undefined : callbackOf(node, hook)
      if (undefined !== callback && isReference(callback)) {
        callees.push(callback.getText(file))
      }
    }
    ts.forEachChild(node, collect)
  }

  const visit = (node: ts.Node): void => {
    if (ts.isCallExpression(node)) {
      const hook = hookOf(node)
      const callback = null === hook ? undefined : callbackOf(node, hook)
      if (undefined !== callback && isFunction(callback)) {
        collect((callback as ts.ArrowFunction | ts.FunctionExpression).body)

        return
      }
      if (undefined !== callback && isReference(callback)) {
        callees.push(callback.getText(file))

        return
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(file)

  return callees
}

/**
 * Les noms locaux liés à l'une des `keys` par une déstructuration d'objet (`const { load: refresh } = useX()` donne
 * `refresh` pour la clé `load`) : une liaison de l'arbre (`BindingElement` d'un `ObjectBindingPattern`, sa `propertyName`),
 * non un texte `load: x` — un type (`{ load: () => void }`), un littéral objet ou une chaîne n'en créent pas. Une clé gardée
 * sous son nom (`const { load } = …`) n'a pas d'alias. Un alias posé par une affectation (`const refresh = load`) n'est pas lu.
 */
export function destructuredAliasesOf(source: string, keys: readonly string[]): Set<string> {
  const aliases = new Set<string>()

  const visit = (node: ts.Node): void => {
    if (
      ts.isBindingElement(node) &&
      ts.isObjectBindingPattern(node.parent) &&
      undefined !== node.propertyName &&
      (ts.isIdentifier(node.propertyName) || ts.isStringLiteral(node.propertyName)) &&
      keys.includes(node.propertyName.text) &&
      ts.isIdentifier(node.name)
    ) {
      aliases.add(node.name.text)
    }
    ts.forEachChild(node, visit)
  }
  visit(syntaxTreeOf(source))

  return aliases
}
