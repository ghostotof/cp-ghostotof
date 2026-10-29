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

/** Le nombre de refus réels depuis le dernier effacement ; 0 = aucun, rien à afficher. */
export const backofficeAccessRefused = readonly(refusals)

/** Un refus réel de plus : l'alerte s'insère si elle n'y est pas, sinon son message est remplacé. */
export function markBackofficeAccessRefused(): void {
  refusals.value += 1
}

/** Efface les refus : à chaque navigation, à une fin de session (le bandeau parle seul) et au démontage du layout. */
export function clearBackofficeAccessRefusal(): void {
  refusals.value = 0
}
