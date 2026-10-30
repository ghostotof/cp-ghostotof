import { describe, expect, it } from 'vitest'
import { scriptOf, stringLiterals, withoutComments } from './sourceText'

/**
 * Le lecteur de source des gardes (#748, R68, Sp6-11, St6-9) : il connaît les chaînes, donc un `'/*'`, un `'//'` ou
 * une URL dans une chaîne n'ouvre pas de commentaire. Les trois gardes de source (`noOmittedCredentials`,
 * `sessionStateEncapsulation` : imports et blocs `export`) le partagent au lieu de retirer les commentaires chacune par
 * une regex, qui masquait tout ce qui suivait un `'/*'`. Depuis R80 (CR7-7) il lit l'arbre syntaxique de TypeScript : ce
 * que le compilateur lit comme une chaîne, un gabarit, une regex ou un commentaire l'est ici aussi.
 */
describe('withoutComments', () => {
  it('garde tels quels une URL, un "/*" entre guillemets doubles et un "//" dans une expression de gabarit', () => {
    expect(withoutComments("const u = 'http://x'")).toBe("const u = 'http://x'")
    expect(withoutComments('const a = "/*"')).toBe('const a = "/*"')
    expect(withoutComments("const t = `a ${'//'} b`")).toBe("const t = `a ${'//'} b`")
  })

  it('retire un commentaire de ligne et un commentaire de bloc, en gardant les fins de ligne', () => {
    expect(withoutComments('a // c\nb')).toBe('a  \nb')
    expect(withoutComments('a /* c */ b')).toBe('a   b')
    expect(withoutComments('a /* c\nd */ b')).toBe('a  \n b')
  })

  it("un '/*' dans une chaîne ne masque pas la suite, et le commentaire qui suit est retiré", () => {
    expect(withoutComments("const a = '/*'\nconst b = 'omit' // */")).toBe("const a = '/*'\nconst b = 'omit'  ")
  })

  it('suit les échappements et les gabarits imbriqués', () => {
    expect(withoutComments("const a = 'it\\'s // not a comment'")).toBe("const a = 'it\\'s // not a comment'")
    expect(withoutComments('const a = `x ${f(`y ${"//"} z`)} w` // c')).toBe('const a = `x ${f(`y ${"//"} z`)} w`  ')
    // Une accolade de bloc dans l'expression ne ferme pas le gabarit.
    expect(withoutComments('const a = `${ (() => { return 1 })() } // t`')).toBe('const a = `${ (() => { return 1 })() } // t`')
  })

  it("une regex littérale qui contient '/*' ne masque pas la suite (CR7-7)", () => {
    // `/^\/*$/` : le `/*` n'ouvre pas de commentaire, c'est un `\/` suivi de `*`.
    expect(withoutComments("const r = /^\\/*$/\nimport { x } from './sessionState'")).toBe("const r = /^\\/*$/\nimport { x } from './sessionState'")
  })

  it('retire un commentaire placé avant le premier jeton, entre deux jetons et avant la fin du fichier', () => {
    expect(withoutComments('// tête\nconst a = 1\n/* fin */')).toBe(' \nconst a = 1\n ')
  })
})

describe('stringLiterals', () => {
  it('rend le contenu des chaînes simples, doubles et des gabarits sans expression', () => {
    expect(stringLiterals("f('a', \"b\", `c`, `d${x}`)")).toEqual(['a', 'b', 'c'])
  })

  it('ignore ce qui est dans un commentaire, et voit une chaîne dans une expression de gabarit', () => {
    expect(stringLiterals("// 'x'\n/* \"y\" */ const a = 'z'")).toEqual(['z'])
    expect(stringLiterals("`a ${'b'} c`")).toEqual(['b'])
  })

  it("ne prend pas un '//' ou une URL de chaîne pour un commentaire", () => {
    expect(stringLiterals("const a = ['//', 'http://x/omit']")).toEqual(['//', 'http://x/omit'])
  })

  it("lit les échappements d'un littéral ('om\\x69t' est omit)", () => {
    expect(stringLiterals("'om\\x69t'")).toEqual(['omit'])
    expect(stringLiterals("const a = 'it\\'s'")).toEqual(["it's"])
  })

  it("une regex littérale qui contient un accent grave n'ouvre pas de gabarit (RichText.vue, CR7-7)", () => {
    expect(stringLiterals("const r = /`([^`]+)`/g\nconst m = 'omit'")).toEqual(['omit'])
  })

  it("une regex littérale qui contient une quote n'ouvre pas de chaîne (CR7-7)", () => {
    expect(stringLiterals("const r = /[\"']/, m = 'omit'")).toEqual(['omit'])
  })
})

describe('scriptOf', () => {
  const component = "<template><p>l'accès // ici</p></template>\n<script setup lang=\"ts\">\nconst m = 'omit'\n</script>\n"

  it("ne garde d'un .vue que le contenu de ses blocs <script> : l'apostrophe du gabarit n'ouvre rien (CR7-7, Sp7-8)", () => {
    expect(stringLiterals(scriptOf('X.vue', component))).toEqual(['omit'])
  })

  it('garde les blocs à leur place : mêmes fins de ligne, donc mêmes numéros de ligne que la source', () => {
    const script = scriptOf('X.vue', component)

    expect(script.split('\n')).toHaveLength(component.split('\n').length)
    expect(script.indexOf("const m = 'omit'")).toBe(component.indexOf("const m = 'omit'"))
  })

  it('garde les deux blocs, <script> et <script setup>', () => {
    const both = "<script lang=\"ts\">\nexport const a = 'un'\n</script>\n<script setup lang=\"ts\">\nconst b = 'deux'\n</script>\n<template><p>'trois'</p></template>"

    expect(stringLiterals(scriptOf('X.vue', both))).toEqual(['un', 'deux'])
  })

  it("rend la source telle quelle pour un chemin qui n'est pas un .vue", () => {
    expect(scriptOf('x.ts', "<p>l'accès</p>")).toBe("<p>l'accès</p>")
  })
})
