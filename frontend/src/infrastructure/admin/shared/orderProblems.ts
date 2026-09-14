import type { ApiProblemBody } from './BackofficeHttpClient'

/**
 * Les deux `type` de problem+json que le backend renvoie en 422 quand
 * l'ensemble de clés envoyé ne correspond plus au périmètre (spec 0004, D4) :
 * une entrée a été créée ou supprimée entre le chargement de la page et
 * l'enregistrement. Ce n'est pas une erreur de saisie, c'est un conflit de
 * concurrence — d'où `stale-order`, qui déclenche un rechargement.
 *
 * Partagé par les repositories admin plutôt que recopié dans chacun : ces deux
 * slugs viennent des exceptions de `Portfolio/Shared`, une seule et même règle
 * côté serveur, et neuf copies auraient divergé au premier renommage.
 */
export const STALE_ORDER_PROBLEM_TYPES = ['unknown-order-entry', 'incomplete-order'] as const

/** Le `type` est un slug stable (`/errors/<slug>`), contrairement au `detail` localisé. */
export function hasProblemType(body: ApiProblemBody, slug: string): boolean {
  return true === body.type?.endsWith(`/${slug}`)
}
