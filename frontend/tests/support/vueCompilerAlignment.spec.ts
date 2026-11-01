/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * `@vue/compiler-dom` est déclaré dans `package.json` parce que le lecteur de gabarit des specs
 * (`tests/support/sourceText.ts`) l'importe directement : il juge un gabarit avec le compilateur
 * même de Vue. Une seconde version installée à côté de celle de `vue` ferait juger les gabarits
 * par un autre compilateur que celui qui les construit, et la garde passerait au vert à tort.
 * Ce spec épingle l'alignement : même intervalle déclaré, une seule installation, de la version
 * que `vue` exige et qui est la sienne (#748, constat C12-11).
 */

interface PackageJson {
  readonly dependencies?: Readonly<Record<string, string>>
  readonly devDependencies?: Readonly<Record<string, string>>
}

interface LockEntry {
  readonly version?: string
  readonly dependencies?: Readonly<Record<string, string>>
}

interface PackageLock {
  readonly packages: Readonly<Record<string, LockEntry>>
}

const COMPILER = '@vue/compiler-dom'

/** Toute entrée du lockfile qui installe le compilateur, à la racine ou imbriquée sous un autre paquet. */
const COMPILER_ENTRY = /(?:^|\/)node_modules\/@vue\/compiler-dom$/

type Section = 'dependencies' | 'devDependencies'

const SECTIONS: readonly Section[] = ['dependencies', 'devDependencies']

/** Toutes les sections où `name` est déclaré, dans l'ordre de `SECTIONS` ; vide s'il n'est dans aucune. */
function declaredSections(manifest: PackageJson, name: string): Section[] {
  return SECTIONS.filter((section) => undefined !== manifest[section]?.[name])
}

/** La section où `name` est déclaré, `dependencies` lue avant `devDependencies` ; `undefined` s'il n'est dans aucune. */
function declaredSection(manifest: PackageJson, name: string): Section | undefined {
  return declaredSections(manifest, name)[0]
}

/** Un intervalle ou une version, lus dans la section que `declaredSection` désigne. */
function declaredRange(manifest: PackageJson, name: string): string | undefined {
  const section = declaredSection(manifest, name)

  return undefined === section ? undefined : manifest[section]?.[name]
}

/**
 * `value` en un seul mot de shell, entre guillemets simples (une apostrophe devient `'\''`) : aucun `$(…)`, aucune
 * apostrophe ni espace d'une valeur lue du dépôt n'y est interprété (#748, C15-8). Le message se copie dans un terminal.
 */
function shellQuoted(value: string): string {
  return `'${value.replace(/'/g, "'\\''")}'`
}

/**
 * Ce que le shell lirait **hors** de guillemets simples dans `text` : le texte entre `'…'` est retiré, un `\x` hors guillemets
 * est un caractère littéral (`'\''` : une apostrophe). Un `$(` qui reste ici serait développé par un terminal qui reçoit le message.
 */
function outsideSingleQuotes(text: string): string {
  let outside = ''
  let quoted = false
  for (let index = 0; index < text.length; index += 1) {
    const char = text[index]
    if (quoted) {
      quoted = "'" !== char
    } else if ("'" === char) {
      quoted = true
    } else if ('\\' === char) {
      index += 1
    } else {
      outside += char
    }
  }

  return outside
}

/**
 * Les écarts d'alignement, chacun une phrase qui dit la correction ; vide quand tout est aligné.
 * Pure (les deux fichiers lus passés en paramètre) pour que ses cas rouges s'écrivent sans toucher au lockfile réel.
 */
function alignmentProblems(manifest: PackageJson, lock: PackageLock): string[] {
  const problems: string[] = []
  const vueRange = declaredRange(manifest, 'vue')
  const vueVersion = lock.packages['node_modules/vue']?.version
  // `npm install …@<version>` écrit `^<version>` : avec `vue` en `~` le test refuserait ce qu'il vient de faire faire
  // (#748, CR13-5). `npm pkg set` écrit l'intervalle de `vue` tel quel, `--package-lock-only` aligne l'entrée racine du
  // lockfile (sinon `npm ci` refuse un lockfile en retard sur `package.json`).
  // La commande écrit la section où le compilateur est déjà déclaré (`devDependencies` quand il est absent) : dans l'autre,
  // elle le déclarerait deux fois (#748, Sp14-8). `npm install --save-dev` déplace une entrée de `dependencies` vers
  // `devDependencies` (mesuré) : la réinstallation porte donc le drapeau de la même section.
  const section = declaredSection(manifest, COMPILER) ?? 'devDependencies'
  const saveFlag = 'dependencies' === section ? '--save-prod' : '--save-dev'
  // Les valeurs lues du dépôt (l'intervalle de `vue`, les versions et chemins du lockfile) sont entre guillemets simples
  // (`shellQuoted`), dans la commande comme dans la prose du message : il se copie en entier dans un terminal (C16-6).
  const realign =
    `npm pkg set ${shellQuoted(`${section}.${COMPILER}=${vueRange ?? '<intervalle de vue>'}`)} && npm install --package-lock-only`
  // Une installation en trop ou une version fausse se refont par `npm install`, puis l'intervalle se réécrit.
  const reinstall =
    `npm install ${saveFlag} ${shellQuoted(`${COMPILER}@${vueVersion ?? '<version de vue>'}`)} && ${realign}`

  // `npm pkg set` n'écrit qu'une section : déclaré dans les deux, il faut en retirer une avant de réaligner (#748, Sp15-11).
  const sections = declaredSections(manifest, COMPILER)
  if (sections.length > 1) {
    const removals = sections.map((each) => `npm pkg delete ${shellQuoted(`${each}.${COMPILER}`)}`).join(' ou ')
    problems.push(
      `package.json : ${COMPILER} est déclaré dans ${sections.join(' et ')}, une seule section attendue : retirer l'une (${removals}), ` +
        'puis relancer le test',
    )
  }

  const compilerRange = declaredRange(manifest, COMPILER)
  if (undefined === compilerRange || compilerRange !== vueRange) {
    problems.push(
      `package.json : ${COMPILER} est déclaré « ${shellQuoted(compilerRange ?? 'absent')} », ` +
        `vue « ${shellQuoted(vueRange ?? 'absent')} » : ${realign}`,
    )
  }

  const entries = Object.keys(lock.packages).filter((path) => COMPILER_ENTRY.test(path))
  if (1 !== entries.length) {
    problems.push(
      `package-lock.json : ${entries.length} installation(s) de ${COMPILER} (${entries.map(shellQuoted).join(', ') || 'aucune'}), ` +
        `une seule attendue : ${reinstall}`,
    )
  }

  const installed = lock.packages[`node_modules/${COMPILER}`]?.version
  const required = lock.packages['node_modules/vue']?.dependencies?.[COMPILER]
  if (undefined === installed || installed !== required || installed !== vueVersion) {
    problems.push(
      `package-lock.json : ${COMPILER} installé en ${shellQuoted(installed ?? 'absent')}, ` +
        `exigé par vue en ${shellQuoted(required ?? 'absent')}, vue en ${shellQuoted(vueVersion ?? 'absent')} : ${reinstall}`,
    )
  }

  return problems
}

const root = resolve(import.meta.dirname, '../..')
const readJson = <T>(file: string): T => JSON.parse(readFileSync(resolve(root, file), 'utf8')) as T

/** Un lockfile minimal et aligné, dont chaque cas rouge dérive par une seule modification. */
function alignedLock(): { manifest: PackageJson; lock: { packages: Record<string, LockEntry> } } {
  return {
    manifest: { dependencies: { vue: '^3.5.44' }, devDependencies: { [COMPILER]: '^3.5.44' } },
    lock: {
      packages: {
        'node_modules/vue': { version: '3.5.44', dependencies: { [COMPILER]: '3.5.44' } },
        [`node_modules/${COMPILER}`]: { version: '3.5.44' },
      },
    },
  }
}

describe('@vue/compiler-dom reste la version de vue', () => {
  it('les fichiers du dépôt sont alignés : même intervalle, une seule installation, la version de vue', () => {
    const manifest = readJson<PackageJson>('package.json')
    const lock = readJson<PackageLock>('package-lock.json')

    expect(alignmentProblems(manifest, lock)).toEqual([])
  })

  it('un lockfile aligné ne donne aucun écart', () => {
    const { manifest, lock } = alignedLock()

    expect(alignmentProblems(manifest, lock)).toEqual([])
  })

  it('un intervalle différent de celui de vue est refusé, avec la commande qui corrige', () => {
    const { manifest, lock } = alignedLock()

    const problems = alignmentProblems({ ...manifest, devDependencies: { [COMPILER]: '^3.5.0' } }, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain("npm pkg set 'devDependencies.@vue/compiler-dom=^3.5.44'")
  })

  // La boucle de CR13-5 : `vue` en `~`, la commande proposée écrivait `^<installée>`, que le test refusait de nouveau.
  it("la commande proposée écrit l'intervalle de vue, celui que le test compare (#748, R160)", () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '~3.5.40' }, devDependencies: { [COMPILER]: '^3.5.44' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain("npm pkg set 'devDependencies.@vue/compiler-dom=~3.5.40'")

    // Appliquer ce que la commande écrit réaligne le manifeste : le test ne redemande rien.
    const written = /npm pkg set 'devDependencies\.@vue\/compiler-dom=([^']+)'/.exec(problems[0])?.[1]
    expect(alignmentProblems({ ...manifest, devDependencies: { [COMPILER]: written ?? '' } }, lock)).toEqual([])
  })

  // Sp14-8 : la commande écrit la section où le compilateur est déclaré, celle que `alignmentProblems` a lue ; écrite dans
  // l'autre, elle le déclarerait deux fois et le test ne lirait toujours pas ce qu'elle vient d'écrire.
  it('un compilateur déclaré dans dependencies : la commande écrit dependencies et ne redemande rien (#748, R179)', () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '^3.5.44', [COMPILER]: '^3.5.0' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain("npm pkg set 'dependencies.@vue/compiler-dom=^3.5.44'")
    expect(problems[0]).not.toContain('devDependencies')

    // Appliquer ce que la commande écrit, dans la section qu'elle nomme, réaligne le manifeste.
    const [, section, written] = /npm pkg set '(\w+)\.@vue\/compiler-dom=([^']+)'/.exec(problems[0]) ?? []
    expect(section).toBe('dependencies')
    expect(alignmentProblems({ dependencies: { ...manifest.dependencies, [COMPILER]: written ?? '' } }, lock)).toEqual([])
  })

  // `npm install --save-dev` déplace une entrée de dependencies vers devDependencies (mesuré dans le conteneur) : la
  // réinstallation suit la section, sinon la commande qui la suit écrirait dans l'une ce que l'autre vient de retirer.
  it('une seconde installation, compilateur dans dependencies : la réinstallation garde la section (--save-prod, #748, R179)', () => {
    const { lock } = alignedLock()
    lock.packages[`node_modules/x/node_modules/${COMPILER}`] = { version: '3.5.44' }
    const manifest: PackageJson = { dependencies: { vue: '^3.5.44', [COMPILER]: '^3.5.44' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain(`npm install --save-prod '${COMPILER}@3.5.44'`)
    expect(problems[0]).not.toContain('--save-dev')
    expect(problems[0]).toContain("npm pkg set 'dependencies.@vue/compiler-dom=^3.5.44'")
  })

  it('une seconde installation imbriquée est refusée et nommée', () => {
    const { manifest, lock } = alignedLock()
    lock.packages[`node_modules/x/node_modules/${COMPILER}`] = { version: '3.5.44' }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain(`node_modules/x/node_modules/${COMPILER}`)
    // La réinstallation n'écrit pas `^` à la place de l'intervalle de vue : elle est suivie de la commande qui le réécrit.
    expect(problems[0]).toContain("npm pkg set 'devDependencies.@vue/compiler-dom=^3.5.44'")
  })

  it('une version installée qui n\'est pas celle que vue exige est refusée', () => {
    const { manifest, lock } = alignedLock()
    lock.packages[`node_modules/${COMPILER}`] = { version: '3.5.43' }

    expect(alignmentProblems(manifest, lock)).toHaveLength(1)
  })

  it('un compilateur non déclaré dans package.json est refusé', () => {
    const { lock } = alignedLock()

    expect(alignmentProblems({ dependencies: { vue: '^3.5.44' } }, lock)).toHaveLength(1)
  })

  // C15-8 : le message se copie dans un terminal, et l'intervalle de `vue` ou la version du lockfile y sont lus du dépôt : une
  // PR qui y met `$(…)` ferait exécuter sa commande à qui copie le message, si la valeur y était entre guillemets doubles.
  it("un intervalle hostile de vue est imprimé entre guillemets simples, que le shell n'interpole pas (#748, R192)", () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '^3.5.44$(id)' }, devDependencies: { [COMPILER]: '^3.5.0' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain("npm pkg set 'devDependencies.@vue/compiler-dom=^3.5.44$(id)'")
    expect(problems[0]).not.toContain('"')
  })

  it("une version hostile du lockfile est imprimée entre guillemets simples dans la réinstallation (#748, R192)", () => {
    const { manifest, lock } = alignedLock()
    lock.packages['node_modules/vue'] = { version: '3.5.44$(id)', dependencies: { [COMPILER]: '3.5.44$(id)' } }
    lock.packages[`node_modules/${COMPILER}`] = { version: '3.5.44$(id)' }
    lock.packages[`node_modules/x/node_modules/${COMPILER}`] = { version: '3.5.44$(id)' }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain("npm install --save-dev '@vue/compiler-dom@3.5.44$(id)'")
    expect(problems[0]).not.toContain('"')
  })

  it("une apostrophe de la valeur est échappée : la commande reste un seul mot pour le shell (#748, R192)", () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: "^3.5.44'; id; '" }, devDependencies: { [COMPILER]: '^3.5.0' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain("npm pkg set 'devDependencies.@vue/compiler-dom=^3.5.44'\\''; id; '\\'''")
  })

  // C16-6 : la prose du message (« déclaré … », « installé en … ») lit aussi des valeurs du dépôt. Le message se copie en entier
  // dans un terminal : un `$(…)` de la prose, hors de guillemets simples, s'y développerait comme celui de la commande.
  it("les valeurs du dépôt de la prose sont entre guillemets simples : aucun $( hors des guillemets (#748, R200)", () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '^3.5.0$(id)' }, devDependencies: { [COMPILER]: '^3.5.0$(whoami)' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(outsideSingleQuotes(problems[0])).not.toContain('$(')
    // Garde de la garde : les deux valeurs hostiles sont bien dans la prose, citées.
    expect(problems[0]).toContain("« '^3.5.0$(whoami)' »")
    expect(problems[0]).toContain("« '^3.5.0$(id)' »")
  })

  it("les chemins et versions du lockfile de la prose sont entre guillemets simples : aucun $( hors des guillemets (#748, R200)", () => {
    const { manifest, lock } = alignedLock()
    lock.packages['node_modules/vue'] = { version: '3.5.44$(id)', dependencies: { [COMPILER]: '3.5.43$(whoami)' } }
    lock.packages[`node_modules/${COMPILER}`] = { version: '3.5.42$(uname)' }
    lock.packages[`node_modules/$(hostname)/node_modules/${COMPILER}`] = { version: '3.5.44' }

    const problems = alignmentProblems(manifest, lock)

    expect(problems.length).toBeGreaterThan(0)
    const message = problems.join('\n')
    expect(outsideSingleQuotes(message)).not.toContain('$(')
    // Garde de la garde : la prose cite bien chaque valeur hostile (chemin, version installée, exigée, de vue).
    const cited = [`'node_modules/$(hostname)/node_modules/${COMPILER}'`, "'3.5.42$(uname)'", "'3.5.43$(whoami)'", "'3.5.44$(id)'"]
    for (const value of cited) {
      expect(message).toContain(value)
    }
  })

  it("une apostrophe d'une valeur de la prose reste dans les guillemets : rien n'en sort (#748, R200)", () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '^3.5.44' }, devDependencies: { [COMPILER]: "^3.5.0'$(id)'" } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(outsideSingleQuotes(problems[0])).not.toContain('$(')
  })

  // Sp15-11 : `npm pkg set` n'écrit qu'une section ; avec les deux déclarées, la commande seule ne réaligne rien.
  it('un compilateur déclaré dans dependencies et dans devDependencies est refusé, avec de quoi retirer l\'une (#748, R192)', () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = {
      dependencies: { vue: '^3.5.44', [COMPILER]: '^3.5.44' },
      devDependencies: { [COMPILER]: '^3.5.44' },
    }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('devDependencies')
    expect(problems[0]).toContain("npm pkg delete 'dependencies.@vue/compiler-dom'")
    expect(problems[0]).toContain("npm pkg delete 'devDependencies.@vue/compiler-dom'")

    // Retirer l'une des deux réaligne le manifeste.
    expect(alignmentProblems({ dependencies: manifest.dependencies }, lock)).toEqual([])
    expect(alignmentProblems({ dependencies: { vue: '^3.5.44' }, devDependencies: manifest.devDependencies }, lock)).toEqual([])
  })
})
