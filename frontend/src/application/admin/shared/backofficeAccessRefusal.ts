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
 * sous un autre périmètre est celui d'une page quittée, il ne compte pas sur la suivante. Pas un simple « booléen de
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
 * compté et `false` est rendu (l'appelant ne relit pas non plus le palier).
 */
export function markBackofficeAccessRefused(sentIn: number): boolean {
  if (sentIn !== scope) {
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
