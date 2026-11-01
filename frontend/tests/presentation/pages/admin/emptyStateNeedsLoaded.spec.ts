import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

/**
 * Garde de source (#748, R4) : « non chargé » n'est pas « vide ». Une page d'administration qui dit « aucune … »
 * le dit d'une liste qu'elle a **lue** ; après une première lecture en session finie, la liste est à `[]` sans
 * que rien n'ait été lu, et l'état vide affirmerait que le contenu n'existe pas. Le composable expose pour cela
 * `isLoaded` (`useAdminLoad`) : tout fichier de `presentation/pages/admin` qui compose un état vide dans son gabarit
 * doit le lire, dans ce même gabarit, au moins autant de fois qu'il a d'états vides. Un état vide s'écrit de deux façons :
 * `t('admin.….empty')`, et `{{ texts.empty }}` dans `AdminQualityEntrySection` (#748, R190), dont la page traduit le texte
 * (une clé littérale, que `no-missing-keys` vérifie) et que la section affiche : le compte se fait donc sur le gabarit, où
 * l'état vide est composé, et non sur le script, où la page de Qualité écrit seulement les clés.
 *
 * Les `.vue` se lisent par `node:fs` : Vitest 5 vide les imports bruts de fichiers non scripts, et un test de
 * source ne doit dépendre d'aucune transformation (comme `styleSheet.spec.ts`).
 * Ce que la garde ne voit pas : un état vide placé sous un `isLoaded` qui ne le gouverne pas. La relecture
 * et les tests de page (`AdminCaseStudiesPage.spec.ts`, `AdminWatchPage.spec.ts`) en tiennent le comportement.
 */
const PAGES_DIRECTORY = resolve(import.meta.dirname, '../../../../src/presentation/pages/admin')
const EMPTY_STATE = /t\('admin\.[A-Za-z.]+\.empty'\)|\{\{\s*texts\.empty\s*\}\}/g
const IS_LOADED = /\bisLoaded\b|\bis[A-Z][A-Za-z]*Loaded\b/g
/**
 * Les fichiers de `PAGES_DIRECTORY` qui composent un état vide, tous lus par la garde (#748, R203, Q12 (A)) : dix des treize `.vue`
 * du dossier (les deux sections de Qualité n'en font qu'une, R190 ; `AdminAboutPage`, `AdminAboutSettingsForm` et `AdminQualityPage`
 * n'en composent aucun dans leur gabarit). Une égalité, non un plancher : un fichier qui perd son état vide, ou que l'expression
 * ne reconnaît plus, rougit au lieu de sortir en silence, et un fichier qui en gagne un aussi, lu alors par la garde.
 */
const FILES_WITH_EMPTY_STATE = 10

describe('un état vide ne se dit que d\'une liste lue (#748, R4)', () => {
  it('chaque fichier qui compose un état vide lit isLoaded au moins autant de fois', () => {
    const offenders: string[] = []
    const withEmptyState: string[] = []

    for (const file of readdirSync(PAGES_DIRECTORY).filter((name) => name.endsWith('.vue'))) {
      const source = readFileSync(join(PAGES_DIRECTORY, file), 'utf8')
      // Les états vides et les occurrences de `isLoaded` sont comptés sur le seul gabarit : la déclaration du script n'est pas
      // une garde, et les clés que la page de Qualité traduit pour sa section n'y sont pas des états vides.
      const template = source.slice(source.indexOf('<template>'))
      const emptyStates = template.match(EMPTY_STATE)?.length ?? 0
      if (0 === emptyStates) continue
      withEmptyState.push(file)
      const guards = template.match(IS_LOADED)?.length ?? 0
      if (guards < emptyStates) offenders.push(`${file} : ${emptyStates} état(s) vide(s), ${guards} garde(s) isLoaded`)
    }

    // Le garde-fou de la garde : une expression régulière qui ne trouverait plus rien la ferait passer à vide, et un fichier qui sort
    // du relevé n'est plus gardé. Le message dit la constante à changer.
    expect(
      withEmptyState.length,
      `fichiers qui composent un état vide : ${withEmptyState.join(', ')}. Changer FILES_WITH_EMPTY_STATE, en tête de ce fichier`,
    ).toBe(FILES_WITH_EMPTY_STATE)
    expect(offenders, `états vides sans isLoaded :\n${offenders.join('\n')}`).toEqual([])
  })
})
