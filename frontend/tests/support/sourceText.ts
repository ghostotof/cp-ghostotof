import ts from 'typescript'
import { parse } from 'vue/compiler-sfc'

/**
 * Le lecteur de source des gardes de `tests/` (#748, R68, R80 ; Sp6-11, St6-9, CR7-7, St7-9, Sp7-8).
 *
 * Trois gardes lisent le texte de `src/` : `noOmittedCredentials.spec.ts` (aucune chaîne `'omit'`) et, dans
 * `sessionStateEncapsulation.spec.ts`, les imports de `sessionState` et les blocs `export { … }`. Elles retiraient les
 * commentaires par deux regex, puis (R68) par un parcours caractère par caractère : l'un comme l'autre devinaient où
 * commence une chaîne, un gabarit ou un commentaire, et se trompaient dès qu'une **regex littérale** les trompait —
 * `RichText.vue` écrit `` /`([^`]+)`/ `` (un gabarit ouvert que rien ne ferme avant le prochain accent grave du
 * fichier), `HttpCvRepository.ts` écrit `/filename="?([^";]+)"?/` (une chaîne ouverte jusqu'à la fin de sa ligne), et un
 * `/^\/*$/` avalerait tout jusqu'au prochain fermant de commentaire de bloc. La limite que R68 avait écrite (« aucune n'existe dans `src/` »)
 * était fausse.
 *
 * **Depuis R80 le module lit l'arbre syntaxique de TypeScript** (`typescript`, déjà en `devDependencies`) : ce que le
 * compilateur lit comme une chaîne, un gabarit, une regex ou un commentaire l'est ici aussi, et un échappement est lu
 * (`'om\x69t'` est `omit`). Un `.vue` passe d'abord par `scriptOf`, qui n'en garde que les blocs `<script>` (le
 * `<template>` n'est pas du JavaScript : son apostrophe ou son `//` ne doivent rien ouvrir).
 *
 * Ce que ça coûte : les gardes dépendent de l'API du compilateur (`createSourceFile`, `getLeadingCommentRanges`…) et de
 * celle de `vue/compiler-sfc` (`parse`). Un passage à TypeScript 7 (le portage natif) la changera : les gardes
 * rougiront alors bruyamment (une importation qui échoue), jamais en silence.
 *
 * Reste vrai : une chaîne non terminée n'a plus de limite écrite ici — c'est du code que `vue-tsc` refuse
 * (`make front-build`), donc jamais du code livré (Sp7-8).
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
    text += source.slice(from, start) + ' ' + source.slice(start, end).replace(/[^\n]/g, '')
    from = end
  }

  return text + source.slice(from)
}

/**
 * Le contenu de chaque littéral de la source, hors commentaires : chaînes `'…'` et `"…"` et gabarits sans `${`, lus comme
 * le compilateur les lit (échappements décodés : `'om\x69t'` donne `omit`). Un gabarit à expression n'a pas de contenu
 * fixe, mais les chaînes de ses expressions sont relevées, elles.
 */
export function stringLiterals(source: string): string[] {
  const literals: string[] = []

  const visit = (node: ts.Node): void => {
    if (ts.isStringLiteralLike(node)) {
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

/** L'arbre de `source`, lu comme du TypeScript (parents posés : `getChildren` en a besoin). */
function treeOf(source: string): ts.SourceFile {
  return ts.createSourceFile('source.ts', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
}
