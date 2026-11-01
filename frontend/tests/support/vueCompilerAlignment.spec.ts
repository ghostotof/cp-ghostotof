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

/** La section où `name` est déclaré, `dependencies` lue avant `devDependencies` ; `undefined` s'il n'est dans aucune. */
function declaredSection(manifest: PackageJson, name: string): Section | undefined {
  if (undefined !== manifest.dependencies?.[name]) {
    return 'dependencies'
  }
  return undefined !== manifest.devDependencies?.[name] ? 'devDependencies' : undefined
}

/** Un intervalle ou une version, lus dans la section que `declaredSection` désigne. */
function declaredRange(manifest: PackageJson, name: string): string | undefined {
  const section = declaredSection(manifest, name)
  return undefined === section ? undefined : manifest[section]?.[name]
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
  const realign =
    `npm pkg set "${section}.${COMPILER}=${vueRange ?? '<intervalle de vue>'}" && npm install --package-lock-only`
  // Une installation en trop ou une version fausse se refont par `npm install`, puis l'intervalle se réécrit.
  const reinstall = `npm install ${saveFlag} ${COMPILER}@${vueVersion ?? '<version de vue>'} && ${realign}`

  const compilerRange = declaredRange(manifest, COMPILER)
  if (undefined === compilerRange || compilerRange !== vueRange) {
    problems.push(
      `package.json : ${COMPILER} est déclaré « ${compilerRange ?? 'absent'} », vue « ${vueRange ?? 'absent'} » : ${realign}`,
    )
  }

  const entries = Object.keys(lock.packages).filter((path) => COMPILER_ENTRY.test(path))
  if (1 !== entries.length) {
    problems.push(
      `package-lock.json : ${entries.length} installation(s) de ${COMPILER} (${entries.join(', ') || 'aucune'}), ` +
        `une seule attendue : ${reinstall}`,
    )
  }

  const installed = lock.packages[`node_modules/${COMPILER}`]?.version
  const required = lock.packages['node_modules/vue']?.dependencies?.[COMPILER]
  if (undefined === installed || installed !== required || installed !== vueVersion) {
    problems.push(
      `package-lock.json : ${COMPILER} installé en ${installed ?? 'absent'}, ` +
        `exigé par vue en ${required ?? 'absent'}, vue en ${vueVersion ?? 'absent'} : ${reinstall}`,
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
    expect(problems[0]).toContain('npm pkg set "devDependencies.@vue/compiler-dom=^3.5.44"')
  })

  // La boucle de CR13-5 : `vue` en `~`, la commande proposée écrivait `^<installée>`, que le test refusait de nouveau.
  it("la commande proposée écrit l'intervalle de vue, celui que le test compare (#748, R160)", () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '~3.5.40' }, devDependencies: { [COMPILER]: '^3.5.44' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('npm pkg set "devDependencies.@vue/compiler-dom=~3.5.40"')

    // Appliquer ce que la commande écrit réaligne le manifeste : le test ne redemande rien.
    const written = /npm pkg set "devDependencies\.@vue\/compiler-dom=([^"]+)"/.exec(problems[0])?.[1]
    expect(alignmentProblems({ ...manifest, devDependencies: { [COMPILER]: written ?? '' } }, lock)).toEqual([])
  })

  // Sp14-8 : la commande écrit la section où le compilateur est déclaré, celle que `alignmentProblems` a lue ; écrite dans
  // l'autre, elle le déclarerait deux fois et le test ne lirait toujours pas ce qu'elle vient d'écrire.
  it('un compilateur déclaré dans dependencies : la commande écrit dependencies et ne redemande rien (#748, R179)', () => {
    const { lock } = alignedLock()
    const manifest: PackageJson = { dependencies: { vue: '^3.5.44', [COMPILER]: '^3.5.0' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('npm pkg set "dependencies.@vue/compiler-dom=^3.5.44"')
    expect(problems[0]).not.toContain('devDependencies')

    // Appliquer ce que la commande écrit, dans la section qu'elle nomme, réaligne le manifeste.
    const [, section, written] = /npm pkg set "(\w+)\.@vue\/compiler-dom=([^"]+)"/.exec(problems[0]) ?? []
    expect(section).toBe('dependencies')
    expect(alignmentProblems({ dependencies: { ...manifest.dependencies, [COMPILER]: written ?? '' } }, lock)).toEqual([])
  })

  // `npm install --save-dev` déplace une entrée de dependencies vers devDependencies (mesuré dans le conteneur) : la
  // réinstallation suit la section, sinon la commande qui la suit écrirait dans l'une ce que l'autre vient de retirer.
  it('une seconde installation, compilateur dans dependencies : la réinstallation garde la section (--save-prod)', () => {
    const { lock } = alignedLock()
    lock.packages[`node_modules/x/node_modules/${COMPILER}`] = { version: '3.5.44' }
    const manifest: PackageJson = { dependencies: { vue: '^3.5.44', [COMPILER]: '^3.5.44' } }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain(`npm install --save-prod ${COMPILER}@3.5.44`)
    expect(problems[0]).not.toContain('--save-dev')
    expect(problems[0]).toContain('npm pkg set "dependencies.@vue/compiler-dom=^3.5.44"')
  })

  it('une seconde installation imbriquée est refusée et nommée', () => {
    const { manifest, lock } = alignedLock()
    lock.packages[`node_modules/x/node_modules/${COMPILER}`] = { version: '3.5.44' }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain(`node_modules/x/node_modules/${COMPILER}`)
    // La réinstallation n'écrit pas `^` à la place de l'intervalle de vue : elle est suivie de la commande qui le réécrit.
    expect(problems[0]).toContain('npm pkg set "devDependencies.@vue/compiler-dom=^3.5.44"')
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
})
