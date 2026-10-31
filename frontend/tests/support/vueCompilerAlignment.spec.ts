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

/** Un intervalle ou une version, lus dans `dependencies` puis `devDependencies`. */
function declaredRange(manifest: PackageJson, name: string): string | undefined {
  return manifest.dependencies?.[name] ?? manifest.devDependencies?.[name]
}

/**
 * Les écarts d'alignement, chacun une phrase qui dit la correction ; vide quand tout est aligné.
 * Pure (les deux fichiers lus passés en paramètre) pour que ses cas rouges s'écrivent sans toucher au lockfile réel.
 */
function alignmentProblems(manifest: PackageJson, lock: PackageLock): string[] {
  const problems: string[] = []
  const vueRange = declaredRange(manifest, 'vue')
  const vueVersion = lock.packages['node_modules/vue']?.version
  const fix = `npm install --save-dev ${COMPILER}@${vueVersion ?? '<version de vue>'}`

  const compilerRange = declaredRange(manifest, COMPILER)
  if (undefined === compilerRange || compilerRange !== vueRange) {
    problems.push(
      `package.json : ${COMPILER} est déclaré « ${compilerRange ?? 'absent'} », vue « ${vueRange ?? 'absent'} » : ${fix}`,
    )
  }

  const entries = Object.keys(lock.packages).filter((path) => COMPILER_ENTRY.test(path))
  if (1 !== entries.length) {
    problems.push(
      `package-lock.json : ${entries.length} installation(s) de ${COMPILER} (${entries.join(', ') || 'aucune'}), ` +
        `une seule attendue : ${fix}`,
    )
  }

  const installed = lock.packages[`node_modules/${COMPILER}`]?.version
  const required = lock.packages['node_modules/vue']?.dependencies?.[COMPILER]
  if (undefined === installed || installed !== required || installed !== vueVersion) {
    problems.push(
      `package-lock.json : ${COMPILER} installé en ${installed ?? 'absent'}, ` +
        `exigé par vue en ${required ?? 'absent'}, vue en ${vueVersion ?? 'absent'} : ${fix}`,
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
    expect(problems[0]).toContain('npm install --save-dev @vue/compiler-dom@3.5.44')
  })

  it('une seconde installation imbriquée est refusée et nommée', () => {
    const { manifest, lock } = alignedLock()
    lock.packages[`node_modules/x/node_modules/${COMPILER}`] = { version: '3.5.44' }

    const problems = alignmentProblems(manifest, lock)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain(`node_modules/x/node_modules/${COMPILER}`)
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
