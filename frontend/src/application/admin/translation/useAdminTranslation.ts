import { computed, getCurrentScope, inject, readonly, ref, watch, type EffectScope, type InjectionKey, type Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationProposal, TranslationFields } from '../../../domain/admin/translation/entities/TranslationProposal'
import { sameFormIdentity, type FormIdentity } from './FormIdentity'
import type { AdminTranslationRepository } from '../../../domain/admin/translation/repositories/AdminTranslationRepository'
import {
  AdminTranslationError,
  type AdminTranslationErrorReason,
} from '../../../domain/admin/translation/errors/AdminTranslationError'
import { visibleMutationError } from '../shared/visibleMutationError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_TRANSLATION_REPOSITORY: InjectionKey<AdminTranslationRepository> = Symbol('AdminTranslationRepository')

export interface UseAdminTranslationResult {
  /** Vrai tant que la traduction courante est en vol et non périmée (par `expire()` ou par l'identité du formulaire). */
  isTranslating: Readonly<Ref<boolean>>
  /**
   * Vrai tant qu'un appel périmé (par `expire()` ou par l'identité du formulaire) n'a pas répondu : il continue côté
   * serveur, et `translate()` n'en lance pas un second avant sa réponse (jamais plus d'un appel au modèle en vol).
   */
  isExpiredCallPending: Readonly<Ref<boolean>>
  /**
   * Vrai quand l'appelant ne veut aucune traduction (`options.isRefused`) : l'unique source du refus, lue par `translate()`
   * et par ce qui désactive le bouton — personne ne réécrit le prédicat. L'attente d'une réponse, que le
   * composable tient seul, n'en fait pas partie : `isTranslating` et `isExpiredCallPending` la disent.
   */
  isRefused: Readonly<Ref<boolean>>
  /**
   * La raison de l'échec de la dernière traduction. Effacée à tout changement d'identité du formulaire et à la traduction
   * suivante, jamais rendue par un retour à l'identité de l'échec : « Modifier » A (échec), B, puis A n'affiche rien.
   */
  errorReason: Readonly<Ref<AdminTranslationErrorReason | null>>
  /**
   * Demande une proposition et la remet à `accept`, **appelée de façon synchrone dans le point de contrôle même qui juge la
   * réponse courante** : aucune micro-tâche ne sépare le jugement de la pose, si bien qu'un geste de la page résolu au même
   * point de contrôle que la réponse (une remise à zéro, une suppression) ne passe jamais entre les deux (R172). Rend vrai si
   * la proposition a été acceptée ; faux sur un refus, un échec (la raison est exposée) ou une réponse périmée, `accept` n'étant
   * alors pas appelée. Une exception de `accept` remonte à l'appelant : ce n'est pas un échec de la traduction. Appelée par le
   * gestionnaire `@translate` d'une page, elle devient son rejet, que Vue passe à `app.config.errorHandler` (R187).
   */
  translate: (
    sourceLocale: Locale,
    targetLocale: Locale,
    fields: TranslationFields,
    accept: (proposal: TranslationProposal) => void,
  ) => Promise<boolean>
  /**
   * Périme la traduction en vol : sa réponse ne pose ni état ni raison (`translate` rend faux sans appeler `accept`). Pour ce
   * que `formIdentity` ne voit pas — la suppression de l'entrée éditée ou de la source d'une création rattachée, qui ne change
   * rien au formulaire —, que l'appelant juge et dit ici ; le guetteur d'identité du composable l'appelle de lui-même pour tout
   * le reste : un geste qui change le formulaire change son identité.
   */
  expire: () => void
}

export interface UseAdminTranslationOptions {
  /**
   * Vrai quand l'appelant ne veut aucune traduction : `translate()` ne lance rien. Il l'écrit ici une fois par
   * condition (une proposition affichée, un brouillon d'ordre, un enregistrement en vol, l'absence de prose…), hors
   * l'attente d'une réponse que le composable tient seul, et ce qui désactive le bouton lit le résultat (`isRefused`) :
   * un seul prédicat. Lu par un `computed` : il ne dépend que de sources réactives (`ref`, `props`, `reactive`).
   */
  readonly isRefused?: () => boolean
  /**
   * Ce qui fait l'identité du formulaire (`FormIdentity`) : l'entrée éditée, la langue, le groupe source, la génération.
   * Comparée champ par champ (`sameFormIdentity`) ; dès que le formulaire n'a plus l'identité relevée à l'envoi, la
   * traduction en vol est périmée pour de bon, même s'il la retrouve ensuite, quel que soit le geste qui l'a remplacé :
   * `isTranslating` retombe aussitôt et sa réponse ne pose rien (`translate` rend faux sans appeler `accept`). Ce que
   * l'identité ne voit pas — la suppression de l'entrée éditée ou de la source d'une création rattachée —, `expire()` le
   * périme. La prose n'en fait pas partie : elle est en lecture seule pendant la traduction, et la raison d'un échec ne
   * disparaît que par un changement d'identité ou la traduction suivante, pas par la frappe qui corrige la prose.
   */
  readonly formIdentity?: () => FormIdentity
}

/** Deux identités de formulaire sont la même si aucune n'est posée, ou si chacun de leurs champs l'est. */
function isSameIdentity(a: FormIdentity | null, b: FormIdentity | null): boolean {
  return null === a || null === b ? a === b : sameFormIdentity(a, b)
}

/**
 * Partagé par tous les formulaires admin qui offrent la traduction assistée : l'appelant fournit les champs de prose,
 * reçoit la proposition par son rappel `accept` (rien en cas d'échec, la raison étant exposée) et décide seul quoi en faire — ce
 * composable ne touche jamais au formulaire ni à la persistance (ADR 0004, D4).
 * Pas de dépendance à useI18n() : l'appelant traduit `errorReason`.
 *
 * Le composable se refuse lui-même, écrit ici une fois pour tous les formulaires : pas de requête tant qu'un appel
 * est en vol — la traduction courante comme une périmée qui n'a pas répondu —, ni quand l'appelant le lui dit
 * (`options.isRefused`, exposé en `isRefused`). Le refus vient d'ici, pas seulement du bouton désactivé : un évènement
 * `translate` émis par programme, ou une double activation avant le rendu, passerait.
 */
export function useAdminTranslation(options: UseAdminTranslationOptions = {}): UseAdminTranslationResult {
  const repository = inject(ADMIN_TRANSLATION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminTranslationRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_TRANSLATION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  // L'identité courante du formulaire. Un calculé : relu seulement quand le formulaire change. L'appelant rend un objet neuf à
  // chaque lecture, que Vue croirait toujours changé : le calculé garde l'objet précédent tant que le contenu est le même
  // (`sameFormIdentity`), si bien qu'une réaffectation à l'identique ne réveille pas le guetteur et ne remplace rien.
  const identity = computed<FormIdentity | null>((previous) => {
    const next = options.formIdentity?.() ?? null

    return isSameIdentity(previous ?? null, next) ? (previous ?? null) : next
  })
  // La traduction courante attend sa réponse, non périmée (ni par `expire()` ni par l'identité du formulaire).
  const isAwaitingCurrent = ref(false)
  // Un appel au dépôt est parti et n'a pas répondu, périmé ou non : `AbortSignal` écarté, l'appel déjà parti côté serveur
  // coûte ce qu'il coûte, et le borner à un par formulaire borne ce coût.
  const isAnyCallRunning = ref(false)
  const failure = ref<AdminTranslationErrorReason | null>(null)
  const requests = createStaleRequestGuard()

  const isTranslating = readonly(isAwaitingCurrent)
  const isExpiredCallPending = computed(() => isAnyCallRunning.value && !isAwaitingCurrent.value)
  const isRefused = computed(() => options.isRefused?.() ?? false)
  // L'échec n'est rendu qu'à l'identité où il est né : le guetteur d'identité l'efface à tout changement (voir `watchIdentity()`).
  const errorReason = readonly(failure)
  // La portée du composant : le guetteur d'identité, né plus tard, s'y arrête avec lui.
  const scope: EffectScope | undefined = getCurrentScope()
  let isWatchingIdentity = false

  /**
   * Un changement d'identité du formulaire périme la traduction en vol pour de bon, même si le formulaire retrouve ensuite
   * la même, et efface l'échec : la personne est passée par un autre formulaire, ce que `expire()` fait déjà. Le guetteur
   * naît à la première traduction, pas au montage : l'appelant déclare le composable avant son formulaire, que l'identité
   * lit. Il reste ensuite, car l'échec d'une traduction survit à l'appel qui l'a posé.
   */
  function watchIdentity(): void {
    if (isWatchingIdentity || (undefined !== scope && !scope.active)) {
      return
    }
    isWatchingIdentity = true
    const start = (): void => {
      watch(identity, () => expire(), { flush: 'sync' })
    }
    if (undefined === scope) {
      start()
    } else {
      scope.run(start)
    }
  }

  /**
   * L'appel part : il devient la traduction courante, l'échec précédent s'efface, et le guetteur d'identité est là pour la
   * suite. Rend le jeton que la réponse présentera à `retainIfCurrent` et à `recordFailure`.
   */
  function startCall(): number {
    const token = requests.begin()
    isAnyCallRunning.value = true
    isAwaitingCurrent.value = true
    failure.value = null
    watchIdentity()

    return token
  }

  /** Le jugement de la réponse : la proposition seulement si l'appel est encore le courant (ni `expire()` ni l'identité n'ont joué). */
  function retainIfCurrent(token: number, proposal: TranslationProposal): TranslationProposal | null {
    return requests.isCurrent(token) ? proposal : null
  }

  /** L'échec de l'appel : sa raison est posée, sauf s'il est périmé ou si la session est finie (le bandeau l'annonce seul, #748). */
  function recordFailure(token: number, error: unknown): void {
    if (!requests.isCurrent(token)) {
      return
    }

    failure.value =
      visibleMutationError(error, AdminTranslationError, () => new AdminTranslationError('unknown', 'Unknown error'))?.reason ?? null
  }

  /** La fin de l'appel, réponse ou échec : plus rien en vol, et plus rien d'attendu si c'est encore la traduction courante. */
  function endCall(token: number): void {
    isAnyCallRunning.value = false
    // Une traduction périmée ne touche plus l'état : `expire()` l'a déjà rendu.
    if (requests.isCurrent(token)) {
      isAwaitingCurrent.value = false
    }
  }

  // Une fonction fléchée : le dépôt, vérifié plus haut, y reste rétréci à « fourni ».
  const translate = async (
    sourceLocale: Locale,
    targetLocale: Locale,
    fields: TranslationFields,
    accept: (proposal: TranslationProposal) => void,
  ): Promise<boolean> => {
    if (isAnyCallRunning.value || isRefused.value) {
      return false
    }

    const token = startCall()
    // La proposition retenue : jugée dans le point de contrôle qui suit la réponse, posée dans ce même point.
    let retained: TranslationProposal | null = null
    try {
      retained = retainIfCurrent(token, await repository.translate(sourceLocale, targetLocale, fields))
    } catch (error) {
      recordFailure(token, error)

      return false
    } finally {
      endCall(token)
    }

    if (null === retained) {
      return false
    }

    // Hors du `try` : une exception de `accept` n'est pas un échec de la traduction, elle ne pose aucune raison. Aucun `await`
    // entre le jugement (`retainIfCurrent`) et ici (le `finally` est synchrone) : la pose est sous le jugement.
    accept(retained)

    return true
  }

  function expire(): void {
    requests.begin()
    isAwaitingCurrent.value = false
    failure.value = null
  }

  return { isTranslating, isExpiredCallPending, isRefused, errorReason, translate, expire }
}
