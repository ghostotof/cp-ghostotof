import axe from 'axe-core'
import type { VueWrapper } from '@vue/test-utils'

/**
 * Audit d'accessibilité d'un composant monté, avec axe-core.
 *
 * Complète, sans le remplacer, `eslint-plugin-vuejs-accessibility` : le lint ne
 * voit que le template, là où axe inspecte le DOM une fois rendu. Il attrape
 * donc ce qui n'existe qu'après rendu — un niveau de titre sauté, un `id`
 * dupliqué par une boucle, une association `th`/`td` incorrecte, une région
 * ARIA mal imbriquée.
 *
 * `axe-core` directement plutôt que `vitest-axe`, resté en 0.1.0 et peu suivi :
 * l'adaptateur tient en quelques lignes, autant ne pas dépendre d'un
 * intermédiaire pour ça.
 *
 * ## Ce que cet audit ne verra JAMAIS, et pourquoi il faut le savoir
 *
 * **jsdom ne fait ni mise en page ni calcul de couleur.** Les règles qui en
 * dépendent — `color-contrast` au premier chef — s'y désactivent
 * **silencieusement** : axe ne les signale pas comme échouées, il ne les
 * exécute simplement pas. Un test vert ne dit donc rien du contraste, et c'est
 * le piège principal de cet outillage : il donne l'impression d'avoir vérifié.
 *
 * D'où `assertRulesActuallyRan` ci-dessous, qui échoue si les règles attendues
 * n'ont pas tourné. Sans lui, une mise à jour d'axe pourrait désactiver une
 * règle sans que rien ne l'annonce.
 *
 * Restent hors de portée, et donc à la main : le contraste, la visibilité du
 * focus, l'ordre de tabulation, la pertinence d'un texte alternatif. Un
 * navigateur réel les couvrirait (Playwright + `@axe-core/playwright`), c'est
 * un chantier distinct.
 */

/**
 * Règles dont on veut la garantie qu'elles ont réellement tourné.
 *
 * Elles ne dépendent ni de la mise en page ni des couleurs, donc jsdom les
 * exécute — et si l'une venait à ne plus tourner, le test doit le dire plutôt
 * que de virer au vert par défaut.
 */
const RULES_THAT_MUST_RUN = [
  'heading-order',
  'duplicate-id-aria',
  'aria-required-attr',
  'aria-valid-attr-value',
  'label',
  'image-alt',
  'list',
] as const

/**
 * Analyse le DOM rendu et échoue si axe relève la moindre violation, ou si les
 * règles attendues n'ont pas tourné.
 */
export async function expectNoAccessibilityViolation(wrapper: VueWrapper): Promise<void> {
  const element = wrapper.element as HTMLElement

  // @vue/test-utils monte dans un nœud détaché, or axe n'analyse que ce qui est
  // dans le document — sans cela il répond « No elements found for include in
  // page Context », ce qui ressemble à une absence de violation. On rattache le
  // temps de l'analyse, plutôt que d'imposer `attachTo` à chaque appelant et de
  // laisser le piège se reproduire.
  const detache = !document.body.contains(element)
  if (detache) {
    document.body.append(element)
  }

  try {
    const results = await axe.run(element, {
      // `color-contrast` est écarté explicitement plutôt que laissé se
      // désactiver tout seul : nommer ce qu'on ne vérifie pas vaut mieux que de
      // le laisser croire vérifié.
      rules: { 'color-contrast': { enabled: false } },
    })

    assertRulesActuallyRan(results)

    if (results.violations.length > 0) {
      throw new Error(formatViolations(results.violations))
    }
  } finally {
    if (detache) {
      element.remove()
    }
  }
}

function assertRulesActuallyRan(results: axe.AxeResults): void {
  const executed = new Set([
    ...results.violations.map((r) => r.id),
    ...results.passes.map((r) => r.id),
    ...results.incomplete.map((r) => r.id),
    // `inapplicable` compte comme exécutée : la règle a tourné et n'a rien
    // trouvé à inspecter — une page sans image n'a pas d'`image-alt` à vérifier.
    ...results.inapplicable.map((r) => r.id),
  ])

  const manquantes = RULES_THAT_MUST_RUN.filter((rule) => !executed.has(rule))

  if (manquantes.length > 0) {
    throw new Error(
      `Ces règles axe n'ont pas tourné : ${manquantes.join(', ')}.\n`
        + "Un audit qui n'exécute pas ses règles passe au vert sans rien avoir vérifié.",
    )
  }
}

/**
 * Un message qui dit quoi corriger et où, plutôt qu'un identifiant de règle
 * qu'il faut aller chercher.
 */
function formatViolations(violations: axe.Result[]): string {
  const details = violations
    .map((violation) => {
      const cibles = violation.nodes.map((node) => `      ${node.html}`).join('\n')

      return `  [${violation.impact ?? 'inconnu'}] ${violation.id} — ${violation.help}\n`
        + `    ${violation.helpUrl}\n${cibles}`
    })
    .join('\n\n')

  return `${violations.length} violation(s) d'accessibilité :\n\n${details}`
}
