import { readonly, ref } from 'vue'
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
 * (`earlierWriteRefused`) : la personne a fait le geste et doit apprendre qu'il a échoué (R38), même si elle n'était
 * plus sur le backoffice — **mais seulement au compte qui l'a envoyée** : le refus retient ce compte, et un autre compte
 * au montage (A refusé, déconnecté, B connecté dans l'onglet) remet tout à zéro, l'avis de A n'étant pas celui de B.
 * **Après le montage**, un refus **d'écriture** ramène le texte courant (c'est un nouvel enregistrement refusé, le dire
 * « précédent » serait faux), un refus **de lecture** garde l'avis (CR7-4, option C : le chargement de la page d'arrivée,
 * parti avant le montage, peut être refusé à son tour ; son texte dit déjà ce qu'une lecture refusée dirait) et le
 * compteur avance, donc l'alerte se réannonce.
 *
 * Exposé à part de `useAuth` : la relecture du palier (`rereadAfterRefusal`) qui suit chaque refus est l'affaire
 * de `useAuth`, ce module ne tient que « combien de refus depuis le dernier effacement ».
 */
const refusals = ref(0)

/** Le compteur dit un enregistrement refusé pendant l'absence de l'alerte, non un refus de la page (R64) ; gardé par une lecture refusée ensuite (R76). */
const earlierWrite = ref(false)

/**
 * Une écriture a été comptée depuis le dernier effacement (R64) : ce que `settleBackofficeAccessRefusalsOnMount` lit au
 * montage pour distinguer une écriture d'une lecture. Pas de `ref` : rien ne l'affiche.
 */
let writeCountedSinceClear = false

/**
 * Le compte qui a envoyé l'écriture comptée depuis le dernier effacement (R76, décision 2 de la septième relecture), ou
 * `null` quand elle est partie sous aucun compte ; sans objet tant que `writeCountedSinceClear` est faux. Le dernier
 * refus d'écriture compté l'emporte. Le montage ne dit l'avis qu'à ce compte. Pas de `ref` : rien ne l'affiche.
 */
let writeAccount: string | null = null

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
export const backofficeAccessRefused = readonly(refusals)

/** Vrai quand le compteur dit un enregistrement refusé pendant l'absence de l'alerte (R64) : l'alerte lui donne son propre texte. */
export const earlierWriteRefused = readonly(earlierWrite)

/** Le périmètre courant, à relever à l'envoi d'une requête. */
export function refusalScope(): number {
  return scope
}

/**
 * Un refus réel de plus : l'alerte s'insère si elle n'y est pas, sinon son message est remplacé. `sentIn` est ce que la
 * requête a relevé à l'envoi : une lecture dont le périmètre n'est plus le courant est partie d'une page quittée, rien
 * n'est compté et `false` est rendu (l'appelant ne relit pas non plus le palier). Une écriture compte toujours. `account`
 * est le compte relevé à l'envoi (`null` : aucun) ; seule une écriture le retient, pour que le montage suivant ne dise
 * l'avis qu'à lui (R76). **Une écriture comptée remet le texte courant** (`earlierWriteRefused` faux : le message redevient
 * celui du refus de la page, c'est un nouvel enregistrement refusé) ; **une lecture comptée le laisse** (CR7-4, option C :
 * le compteur avance, l'alerte se réannonce, l'avis « enregistrement précédent » reste).
 */
export function markBackofficeAccessRefused(sentIn: RefusalScope, account: string | null): boolean {
  if ('read' === sentIn.kind && sentIn.scope !== scope) {
    return false
  }

  refusals.value += 1
  if ('write' === sentIn.kind) {
    earlierWrite.value = false
    writeCountedSinceClear = true
    writeAccount = account
  }

  return true
}

/**
 * Efface les refus et avance le périmètre : à chaque changement de chemin ou de requête, à une fin de session (le
 * bandeau parle seul) et au démontage du layout (R48). Les refus des lectures déjà parties deviennent périmés. Oublie aussi
 * l'écriture comptée (R64).
 */
export function clearBackofficeAccessRefusal(): void {
  refusals.value = 0
  earlierWrite.value = false
  writeCountedSinceClear = false
  writeAccount = null
  scope += 1
}

/**
 * Règle au montage de l'alerte ce qui a été compté **sans alerte** (#748, R64, décision 1 de la sixième relecture,
 * amendant Q1 de la cinquième ; remplace `discardBackofficeAccessRefusals`, R48, CR5-1) : une écriture refusée après que
 * la personne a quitté le backoffice est dite au retour, avec son propre texte (`earlierWriteRefused` vrai, le compteur
 * garde sa valeur) — **si c'est le compte courant qui l'a envoyée** (R76, décision 2 de la septième relecture) ; sinon
 * (une lecture seule, ou l'écriture d'un autre compte : A refusé, B connecté depuis) le compteur, l'avis et l'écriture
 * comptée repartent à zéro. `currentAccount` est passé par l'appelant (`lastAccountName()`), non importé de `useAuth` :
 * le module reste exposé à part. **Le périmètre n'avance pas** : le chargement de la page d'arrivée est parti avant ce
 * montage, son refus doit compter.
 */
export function settleBackofficeAccessRefusalsOnMount(currentAccount: string | null): void {
  if (writeCountedSinceClear && writeAccount === currentAccount) {
    earlierWrite.value = true

    return
  }

  refusals.value = 0
  earlierWrite.value = false
  writeCountedSinceClear = false
  writeAccount = null
}

/** Remet le compteur, le texte de l'écriture précédente et le périmètre à ceux du chargement. Réservée aux tests (`tests/support/setup.ts`), jamais appelée par `src/`. */
export function resetBackofficeAccessRefusalForTests(): void {
  refusals.value = 0
  earlierWrite.value = false
  writeCountedSinceClear = false
  writeAccount = null
  scope = 0
}
