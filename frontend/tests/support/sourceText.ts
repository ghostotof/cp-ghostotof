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
 * se lisent à part, par `templateExpressionsOf`, qui rend chacune comme le fragment JavaScript que Vue en
 * compile. Les fins de ligne (`\r` compris) d'un commentaire ou d'un bloc écarté
 * sont gardées : une position ou un numéro de ligne reste celui de la source.
 *
 * Qui l'appelle : `srcSources.ts` lit une fois les fichiers de `src/` pour `noOmittedCredentials.spec.ts` et
 * `sessionStateEncapsulation.spec.ts`.
 *
 * Ce que ça coûte : les gardes dépendent de l'API du compilateur (`createSourceFile`, `getLeadingCommentRanges`…) et de
 * celle de `vue/compiler-sfc` (`parse`). Un passage à TypeScript 7 (le portage natif) la changera : les gardes
 * rougiront alors bruyamment (une importation qui échoue), jamais en silence.
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
  const file = treeOf(source)
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
  visit(treeOf(source))

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

/** La racine du gabarit, telle que `vue/compiler-sfc` la type (`@vue/compiler-core` n'est pas une dépendance du dépôt). */
export type TemplateRoot = NonNullable<SFCTemplateBlock['ast']>
type TemplateChild = TemplateRoot['children'][number]
type TemplateElement = Extract<TemplateChild, { props: unknown[] }>
type TemplateProp = TemplateElement['props'][number]
type TemplateDirective = Extract<TemplateProp, { exp: unknown }>
type TemplateExpression = NonNullable<TemplateDirective['exp']>

/**
 * Les expressions JavaScript du `<template>` d'un `.vue`, dans l'ordre du document, chacune rendue comme un fragment que
 * TypeScript lit comme Vue l'entend (voir `expressionsOfTemplate`). `[]` pour un chemin qui n'est pas un `.vue` ou sans
 * gabarit ; un `.vue` illisible est refusé comme par `scriptOf`. Chacune se lit ensuite avec `stringLiterals`.
 */
export function templateExpressionsOf(path: string, source: string): string[] {
  if (!path.endsWith('.vue')) {
    return []
  }

  const { descriptor, errors } = parse(source, { filename: path })
  if (errors.length > 0) {
    throw new Error(`${path} : ${errors.map((error) => error.message).join(' ; ')}`)
  }

  return null !== descriptor.template && undefined !== descriptor.template.ast ? expressionsOfTemplate(descriptor.template.ast) : []
}

/**
 * Les fragments JavaScript d'un gabarit, dans l'ordre du document : la valeur de chaque directive, l'argument d'une
 * directive dynamique (`:[nom]`) et le contenu de chaque interpolation `{{ … }}`. Chaque expression est écrite comme Vue
 * la compile, pour que TypeScript la lise de la même façon :
 *
 * - un gestionnaire `v-on` est un corps de fonction (`a(); b()` y est permis) : `() => {\n…\n}` ;
 * - un paramètre de `v-slot`, et chaque alias d'un `v-for` (valeur, clé, index), est un paramètre de fonction (`{ a = 1 }`
 *   y est un motif de déstructuration, non un bloc) : `(…) => 0` ;
 * - la source d'un `v-for`, toute autre expression de directive, un argument dynamique et une interpolation sont une
 *   expression : `(…)` (`{ credentials: 'omit' }` y est un littéral objet, non un bloc étiqueté).
 *
 * Un nœud sans contenu (`null` ou absent) n'est jamais visité.
 */
export function expressionsOfTemplate(root: TemplateRoot): string[] {
  const fragments: string[] = []

  const read = (expression: object | null | undefined, wrap: (text: string) => string): void => {
    // Une expression composée (`CompoundExpressionNode`) naît de la transformation, jamais de l'analyse : seul le texte source compte.
    if (undefined !== expression && null !== expression && 'content' in expression && 'string' === typeof expression.content) {
      fragments.push(wrap(expression.content))
    }
  }
  const asExpression = (text: string): string => `(${text})`
  const asParameter = (text: string): string => `(${text}) => 0`
  const asBody = (text: string): string => `() => {\n${text}\n}`

  const readDirective = (directive: TemplateDirective): void => {
    if (undefined !== directive.forParseResult) {
      const { source, value, key, index } = directive.forParseResult
      read(source, asExpression)
      read(value, asParameter)
      read(key, asParameter)
      read(index, asParameter)
    } else {
      read(directive.exp, 'on' === directive.name ? asBody : 'slot' === directive.name ? asParameter : asExpression)
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
        }
      }
    }
    // Une interpolation porte son expression dans `content` (un nœud) ; un nœud de texte, dans `content` (une chaîne).
    if ('content' in node && 'object' === typeof node.content && null !== node.content && 'content' in node.content) {
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
function treeOf(source: string): ts.SourceFile {
  return ts.createSourceFile('source.ts', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
}
