import type { DOMWrapper, VueWrapper } from '@vue/test-utils'

/**
 * Le bouton de `scope` dont le texte est `label`, ou une erreur qui le nomme : un test qui cherche un bouton introuvable échoue
 * sur son libellé, jamais sur un `undefined?.trigger()` muet ni sur une assertion d'absence qui passerait sans bouton. Un
 * libellé traduit se lit du catalogue (`adminButtonTexts.ts`), jamais d'un littéral français, sauf dans un test qui juge le
 * texte lui-même ; un libellé que le catalogue ne porte pas (un code de langue en capitales) s'écrit tel qu'affiché.
 */
export function buttonLabelled(scope: VueWrapper | DOMWrapper<Element>, label: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find((candidate) => candidate.text() === label)
  if (!button) throw new Error(`Bouton « ${label} » introuvable.`)
  return button
}

/**
 * Fabrique le « Modifier » d'une page à plusieurs tableaux : le bouton de la ligne de `text` du tableau `which`. Ce que les
 * pages ont en propre — la ligne d'un tableau (`actionsForLine`) et le libellé de chaque tableau (`labelOf`, lu du catalogue,
 * une clé par tableau) — est passé en paramètre ; la recherche du bouton est écrite ici, une fois.
 */
export function editButtonFor(
  actionsForLine: (wrapper: VueWrapper, which: number, text: string) => DOMWrapper<Element>,
  labelOf: (which: number) => string,
): (wrapper: VueWrapper, which: number, text: string) => DOMWrapper<HTMLButtonElement> {
  return (wrapper, which, text) => buttonLabelled(actionsForLine(wrapper, which, text), labelOf(which))
}
