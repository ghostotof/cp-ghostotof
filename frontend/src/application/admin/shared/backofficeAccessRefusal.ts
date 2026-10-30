import { readonly, ref } from 'vue'

/**
 * Le refus réel du backoffice (#748, R17, F4) : un 403 qui n'est pas une fin de session — une lecture, ou une écriture
 * partie avec son jeton CSRF. Le compte n'a pas, ou plus, `ROLE_SUPER` (rétrogradé pendant que la page était
 * ouverte), ou la signature CSRF est périmée ; se reconnecter au même compte ne le lèverait pas toujours.
 *
 * `BackofficeHttpClient` lève `AccessForbiddenError` après avoir appelé la réaction commune, qui compte le refus ici ;
 * `useAdminLoad` et `visibleMutationError` la taisent comme une session finie, et **une seule alerte**, celle
 * d'`AccessRefusedNotice` dans `AdminLayout`, le dit. Le nombre sert de `key` au message de l'alerte : un refus de
 * plus la réannonce sans la réinsérer. État de module, comme `authState` : la réaction vit dans `main.ts`, hors
 * de tout composant, et l'alerte dans un autre.
 *
 * Exposé à part de `useAuth` : la relecture du palier (`rereadAfterRefusal`) qui suit chaque refus est l'affaire
 * de `useAuth`, ce module ne tient que « combien de refus depuis la dernière navigation ».
 */
const refusals = ref(0)

/**
 * Le périmètre des refus (#748, R28, CR3-8) : un nombre qui avance à chaque effacement, donc à chaque navigation.
 * Une requête le lit à l'envoi (`refusalScope`) et le rend au refus (`markBackofficeAccessRefused`) : un 403 arrivé
 * sous un autre périmètre est celui d'une page quittée, il ne compte pas sur la suivante — sauf une écriture, qui ne
 * relève aucun périmètre (`null`, R38). Pas un simple « booléen de
 * navigation » : deux visites successives de la même route sont deux périmètres. Pas de `ref` : rien ne l'affiche.
 */
let scope = 0

/** Le nombre de refus réels depuis le dernier effacement ; 0 = aucun, rien à afficher. */
export const backofficeAccessRefused = readonly(refusals)

/** Le périmètre courant, à relever à l'envoi d'une requête. */
export function refusalScope(): number {
  return scope
}

/**
 * Un refus réel de plus : l'alerte s'insère si elle n'y est pas, sinon son message est remplacé. `sentIn` est le
 * périmètre relevé à l'envoi : s'il n'est plus le courant, la requête est partie d'une page quittée, rien n'est
 * compté et `false` est rendu (l'appelant ne relit pas non plus le palier). `null` (une écriture) compte toujours.
 */
export function markBackofficeAccessRefused(sentIn: number | null): boolean {
  if (null !== sentIn && sentIn !== scope) {
    return false
  }

  refusals.value += 1

  return true
}

/**
 * Efface les refus et avance le périmètre : à chaque navigation, à une fin de session (le bandeau parle seul) et au
 * démontage du layout. Les refus des requêtes déjà parties deviennent périmés.
 */
export function clearBackofficeAccessRefusal(): void {
  refusals.value = 0
  scope += 1
}

/**
 * Écarte les refus comptés **sans avancer le périmètre** (#748, R48, CR5-1) : à l'arrivée sur le backoffice, ce qui a
 * été compté pendant qu'aucune alerte n'était montée (une écriture refusée après que la personne a quitté le
 * backoffice) ne doit pas s'afficher sur une page sans rapport. Le périmètre reste : une lecture que la page
 * d'arrivée a déjà envoyée (son chargement, partie avant le montage de l'alerte) garde le sien et, refusée, compte.
 */
export function discardBackofficeAccessRefusals(): void {
  refusals.value = 0
}

/** Remet le compteur et le périmètre à ceux du chargement. Réservée aux tests (`tests/support/setup.ts`), jamais appelée par `src/`. */
export function resetBackofficeAccessRefusalForTests(): void {
  refusals.value = 0
  scope = 0
}
