import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

/**
 * Garde de source (#748, R4) : « non chargé » n'est pas « vide ». Une page d'administration qui dit « aucune … »
 * le dit d'une liste qu'elle a **lue** ; après une première lecture en session finie, la liste est à `[]` sans
 * que rien n'ait été lu, et l'état vide affirmerait que le contenu n'existe pas. Le composable expose pour cela
 * `isLoaded` (`useAdminLoad`) : tout fichier de `presentation/pages/admin` qui compose un état vide
 * (`t('admin.….empty')`) doit le lire au moins autant de fois qu'il a d'états vides.
 *
 * Les `.vue` se lisent par `node:fs` : Vitest 5 vide les imports bruts de fichiers non scripts, et un test de
 * source ne doit dépendre d'aucune transformation (comme `styleSheet.spec.ts`).
 * Ce que la garde ne voit pas : un état vide placé sous un `isLoaded` qui ne le gouverne pas. La relecture
 * et les tests de page (`AdminCaseStudiesPage.spec.ts`, `AdminWatchPage.spec.ts`) en tiennent le comportement.
 */
const PAGES_DIRECTORY = resolve(import.meta.dirname, '../../../../src/presentation/pages/admin')
const EMPTY_STATE = /t\('admin\.[A-Za-z.]+\.empty'\)/g
const IS_LOADED = /\bisLoaded\b|\bis[A-Z][A-Za-z]*Loaded\b/g

describe('un état vide ne se dit que d\'une liste lue (#748, R4)', () => {
  it('chaque fichier qui compose un état vide lit isLoaded au moins autant de fois', () => {
    const offenders: string[] = []
    let withEmptyState = 0

    for (const file of readdirSync(PAGES_DIRECTORY).filter((name) => name.endsWith('.vue'))) {
      const source = readFileSync(join(PAGES_DIRECTORY, file), 'utf8')
      const emptyStates = source.match(EMPTY_STATE)?.length ?? 0
      if (0 === emptyStates) continue
      withEmptyState++
      // Les occurrences de `isLoaded` comptées sur le seul gabarit : la déclaration du script n'est pas une garde.
      const template = source.slice(source.indexOf('<template>'))
      const guards = template.match(IS_LOADED)?.length ?? 0
      if (guards < emptyStates) offenders.push(`${file} : ${emptyStates} état(s) vide(s), ${guards} garde(s) isLoaded`)
    }

    // Le garde-fou de la garde : une expression régulière qui ne trouverait plus rien la ferait passer à vide.
    expect(withEmptyState).toBeGreaterThanOrEqual(10)
    expect(offenders, `états vides sans isLoaded :\n${offenders.join('\n')}`).toEqual([])
  })
})
