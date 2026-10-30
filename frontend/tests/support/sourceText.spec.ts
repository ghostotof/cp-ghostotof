import { describe, expect, it } from 'vitest'
import { stringLiterals, withoutComments } from './sourceText'

/**
 * Le lecteur de source des gardes (#748, R68, Sp6-11, St6-9) : il connaît les chaînes, donc un `'/*'`, un `'//'` ou
 * une URL dans une chaîne n'ouvre pas de commentaire. Les trois gardes de source (`noOmittedCredentials`,
 * `sessionStateEncapsulation` : imports et blocs `export`) le partagent au lieu de retirer les commentaires chacune par
 * une regex, qui masquait tout ce qui suivait un `'/*'`.
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

  it("une chaîne non terminée s'arrête à la fin de sa ligne (une apostrophe de texte de gabarit Vue ne mange pas la suite)", () => {
    expect(withoutComments("<p>l'accès</p>\nconst a = 1 // c")).toBe("<p>l'accès</p>\nconst a = 1  ")
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

  it('garde les échappements tels quels', () => {
    expect(stringLiterals("const a = 'it\\'s'")).toEqual(["it\\'s"])
  })
})
