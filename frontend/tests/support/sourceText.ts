/**
 * Le lecteur de source des gardes de `tests/` (#748, R68, Sp6-11, St6-9).
 *
 * Trois gardes lisent le texte de `src/` : `noOmittedCredentials.spec.ts` (aucune chaîne `'omit'`) et, dans
 * `sessionStateEncapsulation.spec.ts`, les imports de `sessionState` et les blocs `export { … }`. Elles retiraient les
 * commentaires par deux regex (ceux de bloc, puis ceux de ligne) : une regex ne connaît pas les chaînes, de sorte qu'un `'/*'` ou
 * un `'//'` écrit entre guillemets ouvrait un faux commentaire et masquait tout ce qui le suivait jusqu'au prochain
 * fermant de commentaire — la garde ne voyait plus un `'omit'` ou un import qui s'y trouvait. Ce module parcourt le texte caractère par
 * caractère et sait où est une chaîne, un gabarit (`${…}` imbriqués compris) et un commentaire.
 *
 * Limites écrites (aucune n'existe dans `src/` aujourd'hui, la relecture et la règle `frontend-session.md` restent le
 * filet) :
 * - une **regex littérale** n'est pas reconnue : si elle contient `//` ou une quote non échappée (y compris dans une
 *   classe de caractères `[^'"]`), le parcours peut ouvrir un faux commentaire ou une fausse chaîne ; une chaîne à
 *   guillemets simples ou doubles s'arrête de toute façon à la fin de sa ligne, ce qui borne le dégât à cette ligne ;
 * - un **`.vue`** est lu comme du texte JavaScript : le `<template>` n'a pas de commentaire JS, et une apostrophe de son
 *   texte (`l'accès`) ouvre une chaîne qui s'arrête à la fin de la ligne ; un `//` hors guillemets dans ce texte est
 *   pris pour un commentaire de ligne ;
 * - les échappements d'un littéral sont rendus tels quels (`'it\'s'` donne `it\'s`) : `'om\x69t'` n'est pas `omit`.
 */

/** Ce que le parcours relève : le texte sans commentaires, et le contenu de chaque littéral. */
interface Scan {
  readonly text: string
  readonly literals: readonly string[]
}

/**
 * Parcourt `source` une fois. `text` est la source dont chaque commentaire est remplacé par un espace (les fins de ligne
 * d'un commentaire de bloc sont gardées, pour que les numéros de ligne restent ceux de la source) ; `literals` le
 * contenu des chaînes `'…'`, `"…"` et des gabarits sans `${` (un gabarit à expression n'a pas de contenu fixe, mais les
 * chaînes de ses expressions sont relevées à part).
 */
function scan(source: string): Scan {
  const length = source.length
  const literals: string[] = []
  let text = ''
  let i = 0

  /** Une chaîne à guillemets simples ou doubles, `i` sur la quote ouvrante ; s'arrête à la fin de la ligne si non fermée. */
  function quoted(quote: string): void {
    let value = ''
    let j = i + 1
    let closed = false

    while (j < length) {
      const char = source.charAt(j)
      if ('\\' === char) {
        value += source.slice(j, j + 2)
        j += 2
        continue
      }
      if (quote === char) {
        closed = true
        j++
        break
      }
      if ('\n' === char) {
        break
      }
      value += char
      j++
    }
    if (closed) {
      literals.push(value)
    }
    text += source.slice(i, j)
    i = j
  }

  /** Un gabarit, `i` sur le backtick ouvrant ; chaque `${` rend la main à `code()` jusqu'à son `}`. */
  function template(): void {
    let value = ''
    let interpolated = false

    text += '`'
    i++
    while (i < length) {
      const char = source.charAt(i)
      if ('\\' === char) {
        const escaped = source.slice(i, i + 2)
        value += escaped
        text += escaped
        i += 2
        continue
      }
      if ('`' === char) {
        text += '`'
        i++
        if (!interpolated) {
          literals.push(value)
        }
        return
      }
      if ('$' === char && '{' === source.charAt(i + 1)) {
        interpolated = true
        text += '${'
        i += 2
        code(true)
        if (i < length) {
          // `code(true)` s'arrête sur l'accolade fermante de l'expression sans la consommer.
          text += '}'
          i++
        }
        continue
      }
      value += char
      text += char
      i++
    }
  }

  /** Du code : jusqu'à la fin du texte, ou (dans une expression de gabarit) jusqu'à l'accolade qui la ferme. */
  function code(inTemplateExpression: boolean): void {
    let depth = 0

    while (i < length) {
      const char = source.charAt(i)
      const next = source.charAt(i + 1)

      if ('/' === char && '/' === next) {
        const end = source.indexOf('\n', i)
        text += ' '
        i = -1 === end ? length : end
        continue
      }
      if ('/' === char && '*' === next) {
        const end = source.indexOf('*/', i + 2)
        const stop = -1 === end ? length : end + 2
        text += ' ' + source.slice(i, stop).replace(/[^\n]/g, '')
        i = stop
        continue
      }
      if ("'" === char || '"' === char) {
        quoted(char)
        continue
      }
      if ('`' === char) {
        template()
        continue
      }
      if (inTemplateExpression) {
        if ('{' === char) {
          depth++
        } else if ('}' === char) {
          if (0 === depth) {
            return
          }
          depth--
        }
      }
      text += char
      i++
    }
  }

  code(false)

  return { text, literals }
}

/**
 * La source sans ses commentaires de ligne et de bloc, chacun remplacé par un espace (les fins de ligne sont gardées) ;
 * les chaînes, les gabarits et leurs expressions sont rendus tels quels.
 */
export function withoutComments(source: string): string {
  return scan(source).text
}

/**
 * Le contenu de chaque littéral de la source, hors commentaires : chaînes `'…'` et `"…"`, gabarits sans `${` (ceux qui
 * en ont n'ont pas de contenu fixe ; les chaînes de leurs expressions sont relevées, elles).
 */
export function stringLiterals(source: string): string[] {
  return [...scan(source).literals]
}
