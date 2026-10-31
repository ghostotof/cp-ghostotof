import { computed, shallowRef } from 'vue'
import type { ReadRefusalScope, RefusalScope } from '../../../domain/admin/shared/BackofficeSessionReaction'

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
 * Ce que le module tient : le compteur de refus, l'avis « enregistrement précédent », l'écriture comptée et le compte
 * qui l'a envoyée, le périmètre et l'époque (deux nombres que la présentation fait avancer, voir plus bas) et l'ouverture
 * du chargement de la page d'arrivée (R122), ces trois derniers en un seul objet (`RefusalModuleState`). La relecture
 * du palier qui suit chaque refus (`rereadAfterRefusal`) est l'affaire de `useAuth`, exposé à part.
 *
 * **Ce qui est compté pendant qu'aucune alerte n'est montée** (#748, R64, décision 1 de la sixième relecture, qui amende
 * la réponse Q1 de la cinquième ; R76, décision 2 de la septième) : en pratique une écriture — le démontage de l'alerte
 * avance le périmètre, donc une lecture partie du backoffice porte un périmètre révolu et ne compte pas. Au montage
 * suivant (`settle…`), une lecture est oubliée (le palier a été relu), une **écriture** est dite avec son propre texte
 * (`earlierSaveRefused`) : la personne a fait le geste et doit apprendre qu'il a échoué (R38), même si elle n'était
 * plus sur le backoffice — **mais seulement au compte qui l'a envoyée** : le refus retient ce compte, et un autre compte
 * au montage (A refusé, déconnecté, B connecté dans l'onglet) remet tout à zéro, l'avis de A n'étant pas celui de B.
 * **Après le montage**, un refus **d'écriture** ramène le texte courant (c'est un nouvel enregistrement refusé, le dire
 * « précédent » serait faux). **Une lecture partie avant le montage de l'alerte est du chargement de la page d'arrivée
 * et garde l'avis** (#748, R112, décision 3 de la dixième relecture) : ce chargement, parti dans le `setup` de la page
 * avant l'`onMounted` de l'alerte, peut être refusé en plusieurs lectures, et son texte dit déjà ce qu'une lecture
 * refusée dirait. **Il se clôt à la première lecture estampillée après le montage** (R122, Q2 de la onzième relecture ;
 * `stampBackofficeRead`) : quelle qu'en soit l'origine — une relecture après un enregistrement, une section rendue plus
 * tard par un `v-if`, un watcher, un rejeu —, elle l'emporte, et le refus tardif d'une lecture du chargement dit alors le
 * texte courant. **Toute autre lecture refusée**, partie après le montage (une relecture après un enregistrement, rôle
 * perdu), **ramène le texte courant** : ce n'est plus le chargement. L'époque, relevée à l'envoi, distingue les lectures
 * d'avant le montage ; la borne est un envoi, non un délai. Le compteur avance à chaque refus compté, donc l'alerte se
 * réannonce.
 *
 * **Limite acceptée** (Q3 (a) de la douzième relecture) : le module ne distingue pas une lecture que la personne a relancée
 * d'une lecture que la page a lancée seule ; la première des deux, après le montage, clôt le chargement. Le texte rendu
 * ensuite, `common.accessRefused`, est vrai aussi : rien n'est perdu que l'avis « enregistrement précédent » disait déjà.
 */

/**
 * Tout ce que le module sait d'un refus, **en un seul objet immuable remplacé en bloc** (#748, R84, St7-6 : trois, puis
 * quatre variables à remettre ensemble à la main, dont une oubliée à chaque retouche). Chaque transition est une seule
 * affectation de `state.value` ; aucun champ ne peut rester d'une transition à l'autre sans qu'elle le dise.
 *
 * - `count` : le nombre de refus réels depuis le dernier effacement (0 = aucun).
 * - `earlierSave` : vrai quand le montage a réglé une écriture refusée pendant l'absence (R64, R76) et qu'aucun refus
 *   venu depuis ne l'a ramené à faux : l'alerte dit alors l'avis « enregistrement précédent ». Une écriture comptée, ou une
 *   lecture partie après le montage, le remet à faux ; une lecture partie avant le montage le garde (R112).
 * - `countedWrite` : une union discriminée (St8-10) — `{ kind: 'none' }` : aucune écriture comptée depuis le dernier
 *   effacement ; `{ kind: 'write', account }` : une l'a été (R64), `account` étant le compte qui l'a envoyée (R76,
 *   décision 2 de la septième relecture), `null` quand elle est partie sous aucun compte — ce que « aucune écriture »
 *   ne dit pas. La dernière écriture comptée l'emporte. `settleBackofficeAccessRefusalsOnMount` la lit au montage pour
 *   distinguer une écriture d'une lecture ; le montage ne dit l'avis qu'à ce compte.
 */
type CountedWrite = { readonly kind: 'none' } | { readonly kind: 'write'; readonly account: string | null }

interface RefusalState {
  readonly count: number
  readonly earlierSave: boolean
  readonly countedWrite: CountedWrite
}

/** L'état sans refus : celui du chargement, de chaque effacement et de la remise des tests. Figé, jamais modifié en place. */
const NO_REFUSAL: RefusalState = Object.freeze<RefusalState>({ count: 0, earlierSave: false, countedWrite: { kind: 'none' } })

/**
 * `shallowRef` : l'objet est remplacé en bloc, jamais modifié champ à champ, donc sans proxy profond (un objet figé n'en
 * reçoit d'ailleurs pas). Pas exporté : les deux `computed` ci-dessous sont les seules lectures, rien ne l'écrit hors du module.
 */
const state = shallowRef<RefusalState>(NO_REFUSAL)

/**
 * Ce que le module retient de la **position** des lectures, **en un seul objet immuable remplacé en bloc** (#748, R143, St12-5 :
 * trois variables remises à zéro ensemble à trois endroits, forme déjà reprochée à `RefusalState` par St10-2). Chaque
 * transition est une seule affectation de `moduleState` ; aucune n'oublie un champ sans que `INITIAL_STATE` ne le dise.
 * Pas de `ref` : rien ne l'affiche, et il n'entre pas dans `state`, que seuls les refus comptés remplacent.
 *
 * - `scope` : **le périmètre des refus** (#748, R28, CR3-8), un nombre qui avance à chaque effacement
 *   (`clearBackofficeAccessRefusal`) — un changement de chemin ou de requête (pas le seul fragment, R38), une fin de session,
 *   le démontage de l'alerte —, et que ni le montage ni une lecture n'avance. Une requête le relève à l'envoi
 *   (`stampBackofficeRead()`) et le rend au refus (`markBackofficeAccessRefused`) : un 403 arrivé sous un autre périmètre est
 *   celui d'une page quittée, il ne compte pas sur la suivante — sauf une écriture, qui ne relève aucun périmètre
 *   (`{ kind: 'write' }`, R38). Pas un simple « booléen de navigation » : A, B puis A font trois périmètres. Une navigation
 *   vers l'adresse courante n'est pas exécutée par vue-router ; une permutation des paramètres de requête l'est (R54, CR5-8),
 *   mais la clé triée de l'alerte n'y voit pas de changement : ni l'une ni l'autre n'avance le périmètre.
 * - `epoch` : **l'époque de montage** (#748, R112, décision 3 de la dixième relecture), un nombre qui n'avance qu'au montage
 *   de l'alerte (`settleBackofficeAccessRefusalsOnMount`), et que nul effacement n'avance (le périmètre isole déjà une
 *   page). Une lecture le relève à l'envoi avec le périmètre : si, à son refus, l'époque a avancé, la lecture est partie
 *   avant le montage, c'est du chargement de la page d'arrivée tant que `isLandingOpen`. Deux nombres, deux sens : le
 *   périmètre dit « quelle page », l'époque « avant ou après le montage ».
 * - `isLandingOpen` : **le chargement de la page d'arrivée est-il encore ouvert** (#748, R122, Q2 de la onzième relecture) ?
 *   Vrai du montage de l'alerte à la première lecture estampillée ensuite (`stampBackofficeRead`), faux avant tout montage,
 *   après cette lecture et après chaque effacement. Tant qu'il est vrai, une lecture partie avant le montage et refusée
 *   garde l'avis ; ensuite, quelque lecture que ce soit rend le texte courant.
 */
interface RefusalModuleState {
  readonly scope: number
  readonly epoch: number
  readonly isLandingOpen: boolean
}

/** La position de départ : celle du chargement et de la remise des tests. Figée, jamais modifiée en place. */
const INITIAL_STATE: RefusalModuleState = Object.freeze<RefusalModuleState>({ scope: 0, epoch: 0, isLandingOpen: false })

let moduleState: RefusalModuleState = INITIAL_STATE

/** Le compteur de refus réels depuis le dernier effacement ; 0 = aucun, rien à afficher. */
export const backofficeAccessRefused = computed(() => state.value.count)

/** Vrai quand le compteur dit un enregistrement refusé pendant l'absence de l'alerte (R64) : l'alerte lui donne son propre texte. */
export const earlierSaveRefused = computed(() => state.value.earlierSave)

/**
 * Le périmètre et l'époque courants, **sans effet** : une requête pure, pour qui veut les lire sans rien envoyer (les
 * specs, qui comparaient autrefois `stampBackofficeRead().scope` et clôturaient au passage le chargement, R143, St12-5).
 * Une lecture qui part n'appelle pas celle-ci : elle estampille.
 */
export function currentRefusalScope(): ReadRefusalScope {
  return { kind: 'read', scope: moduleState.scope, epoch: moduleState.epoch }
}

/**
 * Estampille une lecture qui part : relève le périmètre et l'époque courants, ensemble, et **clôt le chargement de la
 * page d'arrivée** si le montage l'a ouvert (R122), quelle que soit l'origine de la lecture (voir la limite acceptée en
 * tête de module). Appelée une fois par lecture, à l'envoi, jamais pour une écriture : c'est une commande, d'où le verbe ;
 * la lecture sans effet est `currentRefusalScope()`.
 */
export function stampBackofficeRead(): ReadRefusalScope {
  moduleState = { ...moduleState, isLandingOpen: false }

  return currentRefusalScope()
}

/**
 * Un refus réel de plus : l'alerte s'insère si elle n'y est pas, sinon son message est remplacé. `sentIn` est ce que la
 * requête a relevé à l'envoi : une lecture dont le périmètre n'est plus le courant est partie d'une page quittée, rien
 * n'est compté et `false` est rendu (l'appelant ne relit pas non plus le palier). Une écriture compte toujours. `account`
 * est le compte relevé à l'envoi (`null` : aucun) ; seule une écriture le retient, pour que le montage suivant ne dise
 * l'avis qu'à lui (R76). **Une écriture comptée remet le texte courant** (`earlierSaveRefused` faux : le message redevient
 * celui du refus de la page, c'est un nouvel enregistrement refusé). Une lecture comptée **partie avant le montage**
 * (`sentIn.epoch` n'est plus l'époque) est du chargement de la page d'arrivée et garde l'avis **tant que ce chargement est
 * ouvert** (aucune lecture envoyée depuis le montage, R122), quel que soit le moment de sa réponse ; **partie après**, ou
 * le chargement clos, elle ramène le texte courant.
 */
export function markBackofficeAccessRefused(sentIn: RefusalScope, account: string | null): boolean {
  if ('read' === sentIn.kind && sentIn.scope !== moduleState.scope) {
    return false
  }

  const count = state.value.count + 1
  if ('write' === sentIn.kind) {
    state.value = { count, earlierSave: false, countedWrite: { kind: 'write', account } }

    return true
  }

  const isLandingRead = sentIn.epoch !== moduleState.epoch && moduleState.isLandingOpen
  state.value = { ...state.value, count, earlierSave: isLandingRead && state.value.earlierSave }

  return true
}

/**
 * Efface les refus et avance le périmètre : à chaque changement de chemin ou de requête, à une fin de session (le
 * bandeau parle seul) et au démontage du layout (R48). Les refus des lectures déjà parties deviennent périmés. Oublie aussi
 * l'écriture comptée (R64) et ferme le chargement de la page d'arrivée (ses lectures sont périmées par le périmètre). L'époque
 * ne bouge pas.
 */
export function clearBackofficeAccessRefusal(): void {
  state.value = NO_REFUSAL
  moduleState = { ...INITIAL_STATE, scope: moduleState.scope + 1, epoch: moduleState.epoch }
}

/**
 * Règle au montage de l'alerte ce qui a été compté **sans alerte** (#748, R64, décision 1 de la sixième relecture,
 * amendant Q1 de la cinquième ; remplace `discardBackofficeAccessRefusals`, R48, CR5-1) : une écriture refusée après que
 * la personne a quitté le backoffice est dite au retour, avec son propre texte (`earlierSave` vrai ; le compteur garde sa
 * valeur) — **si c'est le compte courant qui l'a envoyée** (R76, décision 2 de la septième relecture) ; sinon (une lecture
 * seule, ou l'écriture d'un autre compte : A refusé, B connecté depuis) le compteur, l'avis et l'écriture comptée repartent
 * à zéro. `currentAccount` est passé par l'appelant (`lastAccountName()`), non importé de `useAuth` : le module reste
 * exposé à part. **Le montage avance l'époque, jamais le périmètre** (R112) : le chargement de la page d'arrivée est parti
 * avant ce montage, son refus doit compter, et **ses lectures, elles seules**, portent l'ancienne époque et gardent l'avis,
 * **jusqu'à la première lecture envoyée après le montage** (R122) : le montage ouvre ce chargement, que `stampBackofficeRead`
 * clôt.
 */
export function settleBackofficeAccessRefusalsOnMount(currentAccount: string | null): void {
  moduleState = { ...moduleState, epoch: moduleState.epoch + 1, isLandingOpen: true }

  const countedWrite = state.value.countedWrite
  if ('write' !== countedWrite.kind || countedWrite.account !== currentAccount) {
    state.value = NO_REFUSAL

    return
  }

  state.value = { ...state.value, earlierSave: true }
}

/**
 * Remet le compteur, le texte de l'écriture précédente, le périmètre, l'époque et le chargement ouvert à ceux du chargement.
 * Réservée aux tests (`tests/support/setup.ts`), jamais appelée par `src/`.
 */
export function resetBackofficeAccessRefusalForTests(): void {
  state.value = NO_REFUSAL
  moduleState = INITIAL_STATE
}
