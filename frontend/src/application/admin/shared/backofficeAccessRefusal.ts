import { computed, shallowRef } from 'vue'
import type { RefusalScope } from '../../../domain/admin/shared/BackofficeSessionReaction'

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
 * **Ce qui est compté pendant qu'aucune alerte n'est montée** (#748, R64, décision 1 de la sixième relecture, qui amende
 * la réponse Q1 de la cinquième ; R76, décision 2 de la septième) : en pratique une écriture — le démontage de l'alerte
 * avance le périmètre, donc une lecture partie du backoffice porte un périmètre révolu et ne compte pas. Au montage
 * suivant (`settle…`), une lecture est oubliée (le palier a été relu), une **écriture** est dite avec son propre texte
 * (`earlierSaveRefused`) : la personne a fait le geste et doit apprendre qu'il a échoué (R38), même si elle n'était
 * plus sur le backoffice — **mais seulement au compte qui l'a envoyée** : le refus retient ce compte, et un autre compte
 * au montage (A refusé, déconnecté, B connecté dans l'onglet) remet tout à zéro, l'avis de A n'étant pas celui de B.
 * **Après le montage**, un refus **d'écriture** ramène le texte courant (c'est un nouvel enregistrement refusé, le dire
 * « précédent » serait faux). **Seule la première lecture refusée** garde l'avis (CR7-4, option C, bornée en R95,
 * décision 4 de la huitième relecture : le chargement de la page d'arrivée, parti avant le montage, peut être refusé à son
 * tour ; son texte dit déjà ce qu'une lecture refusée dirait) ; **tout refus suivant, lecture ou écriture, ramène le
 * texte courant** (une relecture refusée plus tard, rôle perdu, n'est pas le chargement). Le compteur avance à chaque
 * refus compté, donc l'alerte se réannonce.
 *
 * Exposé à part de `useAuth` : la relecture du palier (`rereadAfterRefusal`) qui suit chaque refus est l'affaire
 * de `useAuth`, ce module ne tient que « combien de refus depuis le dernier effacement ».
 */

/**
 * Tout ce que le module sait d'un refus, **en un seul objet immuable remplacé en bloc** (#748, R84, St7-6 : trois, puis
 * quatre variables à remettre ensemble à la main, dont une oubliée à chaque retouche). Chaque transition est une seule
 * affectation de `state.value` ; aucun champ ne peut rester d'une transition à l'autre sans qu'elle le dise.
 *
 * - `count` : le nombre de refus réels depuis le dernier effacement (0 = aucun).
 * - `earlierSave` : où en est l'avis « enregistrement précédent » (R64, R76, bornée en R95) — `'none'` : le compteur dit
 *   le refus de la page ; `'atMount'` : le montage a réglé une écriture refusée pendant l'absence, l'avis est dit ;
 *   `'keptByARead'` : la première lecture refusée après le montage (le chargement de la page d'arrivée) l'a gardé, le
 *   suivant le rend à `'none'`. Une écriture comptée le remet à `'none'`.
 * - `countedWrite` : une union discriminée (St8-10) — `{ kind: 'none' }` : aucune écriture comptée depuis le dernier
 *   effacement ; `{ kind: 'write', account }` : une l'a été (R64), `account` étant le compte qui l'a envoyée (R76,
 *   décision 2 de la septième relecture), `null` quand elle est partie sous aucun compte — ce que « aucune écriture »
 *   ne dit pas. La dernière écriture comptée l'emporte. `settleBackofficeAccessRefusalsOnMount` la lit au montage pour
 *   distinguer une écriture d'une lecture ; le montage ne dit l'avis qu'à ce compte.
 */
type EarlierSave = 'none' | 'atMount' | 'keptByARead'

type CountedWrite = { readonly kind: 'none' } | { readonly kind: 'write'; readonly account: string | null }

interface RefusalState {
  readonly count: number
  readonly earlierSave: EarlierSave
  readonly countedWrite: CountedWrite
}

/** L'état sans refus : celui du chargement, de chaque effacement et de la remise des tests. Figé, jamais modifié en place. */
const NO_REFUSAL: RefusalState = Object.freeze<RefusalState>({ count: 0, earlierSave: 'none', countedWrite: { kind: 'none' } })

/**
 * `shallowRef` : l'objet est remplacé en bloc, jamais modifié champ à champ, donc sans proxy profond (un objet figé n'en
 * reçoit d'ailleurs pas). Pas exporté : les deux `computed` ci-dessous sont les seules lectures, rien ne l'écrit hors du module.
 */
const state = shallowRef<RefusalState>(NO_REFUSAL)

/**
 * Le périmètre des refus (#748, R28, CR3-8) : un nombre qui avance à chaque effacement (`clearBackofficeAccessRefusal`) —
 * un changement de chemin ou de requête (pas le seul fragment, R38), une fin de session, le démontage de l'alerte —, et
 * qu'aucune lecture n'avance. Le montage n'avance rien (R64). Une requête le lit à l'envoi (`{ kind: 'read', scope }`, voir
 * `RefusalScope`) et le rend au refus (`markBackofficeAccessRefused`) : un 403 arrivé sous un autre périmètre est celui
 * d'une page quittée, il ne compte pas sur la suivante — sauf une écriture, qui ne relève aucun périmètre
 * (`{ kind: 'write' }`, R38). Pas un simple « booléen de navigation » : A, B puis A font trois périmètres. Une
 * navigation vers l'adresse courante n'est pas exécutée par vue-router ; une permutation des paramètres de requête
 * l'est (R54, CR5-8), mais la clé triée de l'alerte n'y voit pas de changement : ni l'une ni l'autre n'avance le
 * périmètre. Pas de `ref` : rien ne l'affiche.
 */
let scope = 0

/** Le nombre de refus réels depuis le dernier effacement ; 0 = aucun, rien à afficher. */
export const backofficeAccessRefused = computed(() => state.value.count)

/** Vrai quand le compteur dit un enregistrement refusé pendant l'absence de l'alerte (R64) : l'alerte lui donne son propre texte. */
export const earlierSaveRefused = computed(() => 'none' !== state.value.earlierSave)

/** Le périmètre courant, à relever à l'envoi d'une requête. */
export function refusalScope(): number {
  return scope
}

/**
 * Un refus réel de plus : l'alerte s'insère si elle n'y est pas, sinon son message est remplacé. `sentIn` est ce que la
 * requête a relevé à l'envoi : une lecture dont le périmètre n'est plus le courant est partie d'une page quittée, rien
 * n'est compté et `false` est rendu (l'appelant ne relit pas non plus le palier). Une écriture compte toujours. `account`
 * est le compte relevé à l'envoi (`null` : aucun) ; seule une écriture le retient, pour que le montage suivant ne dise
 * l'avis qu'à lui (R76). **Une écriture comptée remet le texte courant** (`earlierSaveRefused` faux : le message redevient
 * celui du refus de la page, c'est un nouvel enregistrement refusé) ; **la première lecture comptée après le montage
 * garde l'avis** (CR7-4, option C : le compteur avance, l'alerte se réannonce, l'avis « enregistrement précédent »
 * reste), **la suivante le rend** (R95, décision 4 : `'atMount'` → `'keptByARead'` → `'none'`).
 */
export function markBackofficeAccessRefused(sentIn: RefusalScope, account: string | null): boolean {
  if ('read' === sentIn.kind && sentIn.scope !== scope) {
    return false
  }

  const count = state.value.count + 1
  // Une écriture remet le texte courant et retient son compte ; une lecture avance l'avis d'un cran (le chargement de la
  // page d'arrivée le garde, toute lecture suivante le rend) et laisse l'écriture comptée.
  state.value =
    'write' === sentIn.kind
      ? { count, earlierSave: 'none', countedWrite: { kind: 'write', account } }
      : { ...state.value, count, earlierSave: 'atMount' === state.value.earlierSave ? 'keptByARead' : 'none' }

  return true
}

/**
 * Efface les refus et avance le périmètre : à chaque changement de chemin ou de requête, à une fin de session (le
 * bandeau parle seul) et au démontage du layout (R48). Les refus des lectures déjà parties deviennent périmés. Oublie aussi
 * l'écriture comptée (R64).
 */
export function clearBackofficeAccessRefusal(): void {
  state.value = NO_REFUSAL
  scope += 1
}

/**
 * Règle au montage de l'alerte ce qui a été compté **sans alerte** (#748, R64, décision 1 de la sixième relecture,
 * amendant Q1 de la cinquième ; remplace `discardBackofficeAccessRefusals`, R48, CR5-1) : une écriture refusée après que
 * la personne a quitté le backoffice est dite au retour, avec son propre texte (`earlierSave` à `'atMount'`, le compteur
 * garde sa valeur) — **si c'est le compte courant qui l'a envoyée** (R76, décision 2 de la septième relecture) ; sinon
 * (une lecture seule, ou l'écriture d'un autre compte : A refusé, B connecté depuis) le compteur, l'avis et l'écriture
 * comptée repartent à zéro. `currentAccount` est passé par l'appelant (`lastAccountName()`), non importé de `useAuth` :
 * le module reste exposé à part. **Le périmètre n'avance pas** : le chargement de la page d'arrivée est parti avant ce
 * montage, son refus doit compter, et **lui seul** garde l'avis (R95).
 */
export function settleBackofficeAccessRefusalsOnMount(currentAccount: string | null): void {
  const countedWrite = state.value.countedWrite
  state.value = 'write' === countedWrite.kind && countedWrite.account === currentAccount ? { ...state.value, earlierSave: 'atMount' } : NO_REFUSAL
}

/** Remet le compteur, le texte de l'écriture précédente et le périmètre à ceux du chargement. Réservée aux tests (`tests/support/setup.ts`), jamais appelée par `src/`. */
export function resetBackofficeAccessRefusalForTests(): void {
  state.value = NO_REFUSAL
  scope = 0
}
