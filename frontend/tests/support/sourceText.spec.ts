import { NodeTypes } from '@vue/compiler-dom'
import { describe, expect, it } from 'vitest'
import { parse } from 'vue/compiler-sfc'
import {
  deferredCallsOf,
  destructuredAliasesOf,
  fragmentsOfTemplateAst,
  scriptOf,
  stringLiterals,
  syntaxErrorsOf,
  templateFragmentsOf,
  withoutComments,
  type TemplateRoot,
} from './sourceText'

/**
 * Le lecteur de source des gardes (#748, R68, Sp6-11, St6-9) : il connaît les chaînes, donc un `'/*'`, un `'//'` ou
 * une URL dans une chaîne n'ouvre pas de commentaire. Les trois gardes de source (`noOmittedCredentials`,
 * `sessionStateEncapsulation` : imports et blocs `export`) le partagent : aucune ne retire les commentaires par une regex,
 * qui masquerait tout ce qui suit un `'/*'`. Il lit l'arbre syntaxique de TypeScript (R80, CR7-7) : ce que le compilateur
 * lit comme une chaîne, un gabarit, une regex ou un commentaire l'est ici aussi.
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

  it("une regex littérale qui contient '/*' ne masque pas la suite", () => {
    // `/^\/*$/` : le `/*` n'ouvre pas de commentaire, c'est un `\/` suivi de `*`.
    expect(
      withoutComments("const r = /^\\/*$/\nimport { x } from './sessionState'"),
    ).toBe("const r = /^\\/*$/\nimport { x } from './sessionState'")
  })

  it("garde le \\r d'un commentaire de bloc avec son \\n : les numéros de ligne d'une source CRLF restent ceux de la source", () => {
    expect(withoutComments('x /* a\r\nb */ y')).toBe('x  \r\n y')
  })

  it('retire un commentaire placé avant le premier jeton, entre deux jetons et avant la fin du fichier', () => {
    expect(withoutComments('// tête\nconst a = 1\n/* fin */')).toBe(' \nconst a = 1\n ')
  })
})

describe('stringLiterals', () => {
  it("rend le contenu des chaînes simples, doubles et des gabarits, avec la partie fixe d'un gabarit à expression", () => {
    expect(stringLiterals("f('a', \"b\", `c`, `d${x}`)")).toEqual(['a', 'b', 'c', 'd', ''])
  })

  it('ignore ce qui est dans un commentaire, et voit une chaîne dans une expression de gabarit', () => {
    expect(stringLiterals("// 'x'\n/* \"y\" */ const a = 'z'")).toEqual(['z'])
    expect(stringLiterals("`a ${'b'} c`")).toEqual(['a ', 'b', ' c'])
  })

  it("ne prend pas un '//' ou une URL de chaîne pour un commentaire", () => {
    expect(stringLiterals("const a = ['//', 'http://x/omit']")).toEqual(['//', 'http://x/omit'])
  })

  it("rend aussi les parties fixes d'un gabarit à expression : `${''}omit` contient omit", () => {
    expect(stringLiterals("const m = `${''}omit`")).toContain('omit')
    expect(stringLiterals('const m = `a${x}omit${y}b`')).toEqual(['a', 'omit', 'b'])
  })

  it("lit les échappements d'un littéral ('om\\x69t' est omit)", () => {
    expect(stringLiterals("'om\\x69t'")).toEqual(['omit'])
    expect(stringLiterals("const a = 'it\\'s'")).toEqual(["it's"])
  })

  it("une regex littérale qui contient un accent grave n'ouvre pas de gabarit (RichText.vue)", () => {
    expect(stringLiterals("const r = /`([^`]+)`/g\nconst m = 'omit'")).toEqual(['omit'])
  })

  it("une regex littérale qui contient une quote n'ouvre pas de chaîne", () => {
    expect(stringLiterals("const r = /[\"']/, m = 'omit'")).toEqual(['omit'])
  })
})

describe('scriptOf', () => {
  const component = "<template><p>l'accès // ici</p></template>\n<script setup lang=\"ts\">\nconst m = 'omit'\n</script>\n"

  it("ne garde d'un .vue que le contenu de ses blocs <script> : l'apostrophe du gabarit n'ouvre rien", () => {
    expect(stringLiterals(scriptOf('X.vue', component))).toEqual(['omit'])
  })

  it('garde les blocs à leur place : mêmes fins de ligne, donc mêmes numéros de ligne que la source', () => {
    const script = scriptOf('X.vue', component)

    expect(script.split('\n')).toHaveLength(component.split('\n').length)
    expect(script.indexOf("const m = 'omit'")).toBe(component.indexOf("const m = 'omit'"))
  })

  it('garde les deux blocs, <script> et <script setup>', () => {
    const both = [
      '<script lang="ts">',
      "export const a = 'un'",
      '</script>',
      '<script setup lang="ts">',
      "const b = 'deux'",
      '</script>',
      "<template><p>'trois'</p></template>",
    ].join('\n')

    expect(stringLiterals(scriptOf('X.vue', both))).toEqual(['un', 'deux'])
  })

  it("refuse un .vue illisible en nommant son chemin : une garde qui le sauterait le laisserait sans garde", () => {
    expect(() => scriptOf('X.vue', '<template><p></template>')).toThrow(/X\.vue/)
  })

  it("rend la source telle quelle pour un chemin qui n'est pas un .vue", () => {
    expect(scriptOf('x.ts', "<p>l'accès</p>")).toBe("<p>l'accès</p>")
  })
})

describe('templateFragmentsOf', () => {
  const wrap = (template: string): string => `<template>${template}</template>\n<script setup lang="ts">\n</script>\n`
  const literalsOf = (template: string): string[] =>
    templateFragmentsOf('X.vue', wrap(template)).flatMap((expression) => stringLiterals(expression))

  it("rend l'expression d'un gestionnaire en ligne : un 'omit' qui y est écrit est vu", () => {
    expect(literalsOf('<button @click="go({ credentials: \'omit\' })">x</button>')).toEqual(['omit'])
  })

  it("rend les expressions d'une directive, d'un argument dynamique et d'une interpolation", () => {
    expect(literalsOf('<p :title="\'a\'" v-bind:[\'b\']="c">{{ \'d\' }}</p>')).toEqual(expect.arrayContaining(['a', 'b', 'd']))
  })

  it("ne rend rien sans gabarit, ni pour un chemin qui n'est pas un .vue", () => {
    expect(templateFragmentsOf('X.vue', '<script setup lang="ts">\nconst m = 1\n</script>\n')).toEqual([])
    expect(templateFragmentsOf('x.ts', wrap('<p>{{ \'omit\' }}</p>'))).toEqual([])
  })

  it('refuse un .vue illisible en nommant son chemin', () => {
    expect(() => templateFragmentsOf('X.vue', '<template><p></template>')).toThrow(/X\.vue/)
  })

  describe('chaque expression est rendue comme le fragment JavaScript que Vue compile', () => {
    const fragmentsOf = (template: string): string[] => templateFragmentsOf('X.vue', wrap(template))

    it("lit l'expression d'un v-for : un 'omit' écrit dans la source de la boucle est vu", () => {
      expect(literalsOf('<ul><li v-for="m in [\'omit\']">{{ m }}</li></ul>')).toContain('omit')
      expect(fragmentsOf('<ul><li v-for="m in [\'omit\']">x</li></ul>')).toEqual(["(['omit'])", '(m) => 0'])
    })

    it("lit chaque alias d'un v-for (valeur déstructurée avec défaut, clé, index) comme un paramètre de fonction", () => {
      expect(fragmentsOf('<li v-for="({ mode = \'omit\' }, k, i) in items">x</li>')).toEqual([
        '(items)',
        "({ mode = 'omit' }) => 0",
        '(k) => 0',
        '(i) => 0',
      ])
    })

    it('lit un v-slot déstructuré, long ou abrégé, comme un paramètre de fonction', () => {
      expect(fragmentsOf('<C v-slot="{ mode = \'omit\' }">x</C>')).toEqual(["({ mode = 'omit' }) => 0"])
      expect(fragmentsOf('<C #row="{ item, index }">x</C>')).toEqual(['({ item, index }) => 0'])
    })

    it('lit un littéral objet lié entre parenthèses : ce n\'est pas un bloc étiqueté', () => {
      expect(fragmentsOf('<C :opts="{ credentials: \'omit\' }" />')).toEqual(["({ credentials: 'omit' })"])
    })

    it("lit un gestionnaire en ligne comme le corps d'une fonction (plusieurs instructions), et y voit le 'omit'", () => {
      const template = '<button @click="a(); b(\'omit\')">x</button>'

      expect(fragmentsOf(template)).toEqual(["($event) => {\na(); b('omit')\n}"])
      expect(literalsOf(template)).toContain('omit')
    })

    it("lit un gestionnaire en ligne comme Vue : un objet ou un appel est enveloppé dans une fonction de $event (#748, R130)", () => {
      // Formes confirmées à l'écriture par la sortie de `compileTemplate` de `vue/compiler-sfc` sur le même gabarit :
      // `$event => ({ credentials: 'omit' })` et `$event => (_ctx.b('omit'))`.
      expect(fragmentsOf('<button @click="{ credentials: \'omit\' }">x</button>')).toEqual(["($event) => ({ credentials: 'omit' })"])
      expect(literalsOf('<button @click="{ credentials: \'omit\' }">x</button>')).toEqual(['omit'])
      expect(fragmentsOf('<button @click="b(\'omit\')">x</button>')).toEqual(["($event) => (b('omit'))"])
    })

    it("lit un chemin de membre et une fonction écrite en flèche comme une expression, telle quelle (#748, R130)", () => {
      expect(fragmentsOf('<button @click="a.b">x</button>')).toEqual(['(a.b)'])
      expect(fragmentsOf('<button @click="() => b(\'omit\')">x</button>')).toEqual(["(() => b('omit'))"])
    })

    it("a pour type d'interpolation celui que Vue donne à un {{ a }} analysé (#748, R130)", () => {
      const { descriptor } = parse(wrap('<p>{{ a }}</p>'), { filename: 'X.vue' })
      const paragraph = descriptor.template?.ast?.children[0]

      expect(paragraph && 'children' in paragraph ? paragraph.children[0] : undefined).toMatchObject({ type: NodeTypes.INTERPOLATION })
    })

    it("lit la valeur d'un attribut statique : un mode=\"omit\" écrit en dur dans le gabarit est vu (#748, R119)", () => {
      expect(literalsOf('<Fetcher mode="omit" />')).toContain('omit')
      expect(fragmentsOf('<Fetcher mode="omit" />')).toEqual(['"omit"'])
    })

    it('refuse un <template> sans arbre (lang, src) en nommant son chemin : une garde qui le sauterait le laisserait sans garde', () => {
      expect(() => templateFragmentsOf('X.vue', '<template lang="pug">p x</template>')).toThrow('X.vue')
      expect(() => templateFragmentsOf('X.vue', '<template src="./x.html"></template>')).toThrow('X.vue')
      expect(templateFragmentsOf('X.vue', '<template lang="html"><p :a="b" /></template>')).toEqual(['(b)'])
    })

    it('lit un v-on sans argument (objet de gestionnaires) comme une expression : ce n\'est pas un bloc étiqueté', () => {
      const fragments = fragmentsOf('<C v-on="{ click: a, keydown: b }" />')

      expect(fragments).toEqual(['({ click: a, keydown: b })'])
      expect(syntaxErrorsOf(fragments[0] ?? '')).toEqual([])
    })

    it("lit un gestionnaire écrit en fonction comme une expression, et y voit le 'omit' qu'il porte", () => {
      const fragments = fragmentsOf('<button @click="function () { b(\'omit\') }">x</button>')

      expect(fragments).toEqual(["(function () { b('omit') })"])
      expect(syntaxErrorsOf(fragments[0] ?? '')).toEqual([])
      expect(literalsOf('<button @click="function () { b(\'omit\') }">x</button>')).toContain('omit')
    })

    it("lit une interpolation et un argument dynamique entre parenthèses, et ne rend pas un argument statique", () => {
      expect(fragmentsOf("<p :title=\"t\" :[dyn]=\"u\">{{ 'd' }}</p>")).toEqual(['(t)', '(u)', '(dyn)', "('d')"])
      expect(fragmentsOf('<p :title="t" />')).toEqual(['(t)'])
    })

    it('chaque fragment se lit sans erreur de syntaxe', () => {
      const template =
        '<ul><li v-for="({ mode = \'omit\' }, k, i) in items" :key="k" @click="a(); b(\'omit\')">{{ mode }}</li></ul>' +
        '<C v-slot="{ a = 1 }" :opts="{ credentials: 1 }" />'

      for (const fragment of fragmentsOf(template)) {
        expect(syntaxErrorsOf(fragment), fragment).toEqual([])
      }
    })
  })
})

describe('syntaxErrorsOf', () => {
  it('rend les erreurs de syntaxe de TypeScript pour un fragment, aucune pour un fragment valide', () => {
    expect(syntaxErrorsOf('(a, b) => 0')).toEqual([])
    expect(syntaxErrorsOf('(a, ')).not.toEqual([])
  })
})

describe('fragmentsOfTemplateAst', () => {
  it("ne visite jamais un contenu nul ou absent : `typeof null` est 'object'", () => {
    const root = { type: 0, children: [{ type: 5, content: null }, { type: 5 }] } as unknown as TemplateRoot

    expect(fragmentsOfTemplateAst(root)).toEqual([])
  })
})

describe('deferredCallsOf', () => {
  it('deferredCallsOf rend le callee de chaque appel des fonctions passées à onMounted, imbriquées comprises (#748, R121, R144)', () => {
    expect(deferredCallsOf('onMounted(() => { a(); x.y(); const f = () => load() }) ; b()')).toEqual(['a', 'x.y', 'load'])
  })

  it('deferredCallsOf rend le nom passé par référence, simple ou membre, et ignore les autres arguments (#748, R144)', () => {
    expect(deferredCallsOf('onMounted(load)')).toEqual(['load'])
    expect(deferredCallsOf('onMounted(page.load)')).toEqual(['page.load'])
    expect(deferredCallsOf('setTimeout(load, delay)')).toEqual(['load'])
    expect(deferredCallsOf('watch(source, load, { flush: "post" })')).toEqual(['load'])
  })

  it("deferredCallsOf ne rend rien pour un appel qui n'est pas un hook différé (#748, R121, R144)", () => {
    expect(deferredCallsOf('other(() => a())')).toEqual([])
    expect(deferredCallsOf('other(load)')).toEqual([])
    expect(deferredCallsOf('x.onMounted(() => a())')).toEqual([])
  })

  it("deferredCallsOf lit onMounted sous l'alias de son import de vue, et non un autre module (#748, R144)", () => {
    expect(deferredCallsOf("import { onMounted as whenMounted } from 'vue'\nwhenMounted(() => go())")).toEqual(['go'])
    expect(deferredCallsOf("import { nextTick as tick } from 'vue'\ntick(load)")).toEqual(['load'])
    expect(deferredCallsOf("import { onMounted as whenMounted } from './elsewhere'\nwhenMounted(() => go())")).toEqual([])
  })

  it('deferredCallsOf rend les appels de chaque appel du hook, dans l\'ordre du source, `function` comprise (#748, R121, R144)', () => {
    const source = 'onMounted(function () { first() })\nonMounted(async () => { await second.call() })'

    expect(deferredCallsOf(source)).toEqual(['first', 'second.call'])
  })

  it('deferredCallsOf lit nextTick, les minuteurs et les rappels de frame (#748, R144)', () => {
    expect(deferredCallsOf('nextTick(() => a())')).toEqual(['a'])
    expect(deferredCallsOf('setTimeout(() => b(), 0)')).toEqual(['b'])
    expect(deferredCallsOf('window.setTimeout(load, 0)')).toEqual(['load'])
    expect(deferredCallsOf('queueMicrotask(() => c())')).toEqual(['c'])
    expect(deferredCallsOf('requestAnimationFrame(() => d())')).toEqual(['d'])
    expect(deferredCallsOf('watchPostEffect(() => e())')).toEqual(['e'])
  })

  // Vue : un rappel `immediate` court dans le `setup` quel que soit `flush` (`reactivity.cjs.js`, `watch` : `job(true)`) ;
  // un `watch` sans `immediate` ne court qu'à un changement de sa source, donc après le montage (CR13-3, R159).
  it("deferredCallsOf lit un watch sans immediate : son rappel ne court jamais dans le setup (#748, R159)", () => {
    expect(deferredCallsOf('watch(route, () => load())')).toEqual(['load'])
    expect(deferredCallsOf("watch(x, () => b(), { flush: 'post' })")).toEqual(['b'])
    expect(deferredCallsOf("watch(x, () => b(), { flush: 'sync' })")).toEqual(['b'])
    expect(deferredCallsOf('watch(x, () => b(), { immediate: false })')).toEqual(['b'])
    expect(deferredCallsOf("watch(x, cb, { 'deep': true })")).toEqual(['cb'])
  })

  it("deferredCallsOf ne lit pas un watch immediate, posté ou non : sa première exécution est celle du setup (#748, R159)", () => {
    expect(deferredCallsOf('watch(x, () => a(), { immediate: true })')).toEqual([])
    expect(deferredCallsOf("watch(x, () => b(), { flush: 'post', immediate: true })")).toEqual([])
    expect(deferredCallsOf("watch(x, () => b(), { immediate: true, flush: 'post' })")).toEqual([])
    expect(deferredCallsOf("watch(x, () => b(), { 'immediate': true, flush: 'sync' })")).toEqual([])
    expect(deferredCallsOf('watch(x, load, { immediate: true })')).toEqual([])
  })

  it("deferredCallsOf lit un watch à options non littérales ou dont immediate n'est pas true (#748, R159)", () => {
    expect(deferredCallsOf('watch(x, cb, opts)')).toEqual(['cb'])
    expect(deferredCallsOf('watch(x, () => b(), { ...opts })')).toEqual(['b'])
    expect(deferredCallsOf('watch(x, () => b(), { immediate })')).toEqual(['b'])
    expect(deferredCallsOf('watch(x, () => b(), { immediate: isFirst })')).toEqual(['b'])
  })

  it("deferredCallsOf ne lit un watchEffect que posté ; watchPostEffect l'est toujours (#748, R144, R159)", () => {
    expect(deferredCallsOf("watchEffect(() => c(), { flush: 'post' })")).toEqual(['c'])
    expect(deferredCallsOf('watchEffect(() => f())')).toEqual([])
    expect(deferredCallsOf("watchEffect(() => f(), { flush: 'sync' })")).toEqual([])
    expect(deferredCallsOf('watchPostEffect(() => e())')).toEqual(['e'])
  })

  it('deferredCallsOf lit les hooks de mise à jour et de réactivation, en fonction ou en référence (#748, R159)', () => {
    expect(deferredCallsOf('onUpdated(() => c())')).toEqual(['c'])
    expect(deferredCallsOf('onBeforeUpdate(d)')).toEqual(['d'])
    expect(deferredCallsOf('onActivated(() => e())')).toEqual(['e'])
    expect(deferredCallsOf("import { onUpdated as whenUpdated } from 'vue'\nwhenUpdated(page.refresh)")).toEqual(['page.refresh'])
  })

  it('deferredCallsOf lit la source de watch ni comme un rappel, ni comme un appel du hook (#748, R144)', () => {
    expect(deferredCallsOf("watch(() => source(), () => b(), { flush: 'post' })")).toEqual(['b'])
  })

  it("deferredCallsOf lit aussi un hook posé dans la fonction d'un autre hook (#748, R144)", () => {
    expect(deferredCallsOf('onMounted(() => { nextTick(load) })')).toEqual(['nextTick', 'load'])
  })
})

describe('destructuredAliasesOf', () => {
  it('destructuredAliasesOf rend le nom local lié à une clé, pas la clé laissée telle quelle (#748, R144)', () => {
    const source = 'const { load: refresh, reload: again, other: x, load2 } = useX()'

    expect([...destructuredAliasesOf(source, ['load', 'reload'])]).toEqual(['refresh', 'again'])
  })

  it('destructuredAliasesOf lit un paramètre déstructuré et une valeur par défaut (#748, R144)', () => {
    expect([...destructuredAliasesOf('const f = ({ load: refresh = noop }) => refresh()', ['load'])]).toEqual(['refresh'])
  })

  it("destructuredAliasesOf ne prend pas un type, un littéral objet ni une chaîne pour un alias (#748, R144)", () => {
    expect([...destructuredAliasesOf('const f: { load: () => void } = x', ['load'])]).toEqual([])
    expect([...destructuredAliasesOf('const o = { load: refresh }', ['load'])]).toEqual([])
    expect([...destructuredAliasesOf("const s = 'const { load: refresh } = y'", ['load'])]).toEqual([])
  })
})
