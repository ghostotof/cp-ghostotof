import { computed, getCurrentScope, inject, readonly, ref, watch, type EffectScope, type InjectionKey, type Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationProposal, TranslationFields } from '../../../domain/admin/translation/entities/TranslationProposal'
import { sameFormIdentity, type FormIdentity } from '../../../domain/admin/translation/entities/FormIdentity'
import type { AdminTranslationRepository } from '../../../domain/admin/translation/repositories/AdminTranslationRepository'
import {
  AdminTranslationError,
  type AdminTranslationErrorReason,
} from '../../../domain/admin/translation/errors/AdminTranslationError'
import { visibleMutationError } from '../shared/visibleMutationError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_TRANSLATION_REPOSITORY: InjectionKey<AdminTranslationRepository> = Symbol('AdminTranslationRepository')

export interface UseAdminTranslationResult {
  /** Vrai tant que la traduction courante est en vol et non périmée (par `discard()` ou par l'identité du formulaire). */
  isTranslating: Readonly<Ref<boolean>>
  /**
   * Vrai tant qu'un appel périmé (par `discard()` ou par l'identité du formulaire) n'a pas répondu : il continue côté
   * serveur, et `translate()` n'en lance pas un second avant sa réponse (jamais plus d'un appel au modèle en vol).
   */
  isDiscardPending: Readonly<Ref<boolean>>
  /**
   * Vrai quand la page ne veut aucune traduction (`options.isRefused`) : l'unique source du refus, lue par `translate()`
   * et par le `:disabled` du bouton de la page — aucune page ne réécrit le prédicat. L'attente d'une réponse, que le
   * composable tient seul, n'en fait pas partie : `isTranslating` et `isDiscardPending` la disent.
   */
  isRefused: Readonly<Ref<boolean>>
  /**
   * La raison de l'échec de la dernière traduction. Effacée à tout changement d'identité du formulaire et à la traduction
   * suivante, jamais rendue par un retour à l'identité de l'échec : « Modifier » A (échec), B, puis A n'affiche rien.
   */
  errorReason: Readonly<Ref<AdminTranslationErrorReason | null>>
  translate: (sourceLocale: Locale, targetLocale: Locale, fields: TranslationFields) => Promise<TranslationProposal | null>
  /**
   * Périme la traduction en vol : sa réponse rend `null` et ne pose ni état ni raison. Pour ce que `formIdentity` ne
   * voit pas — la suppression de l'entrée éditée ou de la source d'une création rattachée, qui ne change rien au
   * formulaire : `useTranslatedForm.discardIfDeleted` la juge et l'appelle.
   */
  discard: () => void
}

export interface UseAdminTranslationOptions {
  /**
   * Vrai quand la page ne veut aucune traduction : `translate()` ne lance rien. La page l'écrit ici une fois par
   * condition (une proposition affichée, un brouillon d'ordre, un enregistrement en vol, l'absence de prose…), hors
   * l'attente d'une réponse que le composable tient seul, et le bouton lit le résultat (`isRefused`) : un seul
   * prédicat. Lu par un `computed` : il ne dépend que de sources réactives (`ref`, `props`, `reactive`).
   */
  readonly isRefused?: () => boolean
  /**
   * Ce qui fait l'identité du formulaire (`FormIdentity`) : l'entrée éditée, la langue, le groupe source, la génération.
   * Comparée champ par champ (`sameFormIdentity`) ; dès que le formulaire n'a plus l'identité relevée à l'envoi, la
   * traduction en vol est périmée pour de bon, même s'il la retrouve ensuite, quel que soit le geste qui l'a remplacé
   * (« Annuler », « Modifier » une autre entrée, « Créer la version XX », un changement de langue, toute remise à zéro du
   * formulaire, qui fait avancer la génération) : `isTranslating` retombe aussitôt et la réponse rend `null`. Rouvrir la même
   * entrée ne change rien au formulaire : rien n'est périmé. Ce que l'identité ne voit pas — la suppression de l'entrée
   * éditée ou de la source d'une création rattachée —, `discard()` le périme. La prose n'en fait pas partie : la page la met
   * en lecture seule pendant la traduction, et la raison d'un échec ne disparaît que par un changement d'identité ou la
   * traduction suivante, pas par la frappe qui corrige la prose.
   */
  readonly formIdentity?: () => FormIdentity
}

/** Deux identités de formulaire sont la même si aucune n'est posée, ou si chacun de leurs champs l'est. */
function isSameIdentity(a: FormIdentity | null, b: FormIdentity | null): boolean {
  return null === a || null === b ? a === b : sameFormIdentity(a, b)
}

/**
 * Partagé par toutes les pages admin qui offrent la traduction assistée : la
 * page fournit les champs de prose, reçoit la proposition (ou `null` en cas
 * d'échec, la raison étant exposée) et décide seule quoi en faire — ce
 * composable ne touche jamais au formulaire ni à la persistance (ADR 0004, D4).
 * Pas de dépendance à useI18n() : la page traduit `errorReason`.
 *
 * Le composable se refuse lui-même, écrit ici une fois pour tous les formulaires : pas de requête tant qu'un appel
 * est en vol — la traduction courante comme une périmée qui n'a pas répondu —, ni quand la page le lui dit
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

  // L'identité courante du formulaire. Un calculé : relu seulement quand le formulaire change. La page rend un objet neuf à
  // chaque lecture, que Vue croirait toujours changé : le calculé garde l'objet précédent tant que le contenu est le même
  // (`sameFormIdentity`), si bien qu'une réaffectation à l'identique ne réveille pas le guetteur et ne remplace rien.
  const identity = computed<FormIdentity | null>((previous) => {
    const next = options.formIdentity?.() ?? null

    return isSameIdentity(previous ?? null, next) ? (previous ?? null) : next
  })
  // La traduction courante attend sa réponse, non périmée (ni par `discard()` ni par l'identité du formulaire).
  const isAwaitingCurrent = ref(false)
  // Un appel au dépôt est parti et n'a pas répondu, périmé ou non : `AbortSignal` écarté, l'appel déjà parti côté serveur
  // coûte ce qu'il coûte, et le borner à un par formulaire borne ce coût.
  const isAnyCallRunning = ref(false)
  const failure = ref<AdminTranslationErrorReason | null>(null)
  const requests = createStaleRequestGuard()

  const isTranslating = readonly(isAwaitingCurrent)
  const isDiscardPending = computed(() => isAnyCallRunning.value && !isAwaitingCurrent.value)
  const isRefused = computed(() => options.isRefused?.() ?? false)
  // L'échec n'est rendu qu'à l'identité où il est né : le guetteur d'identité l'efface à tout changement (voir `translate`).
  const errorReason = readonly(failure)
  // La portée du composant : le guetteur d'identité, né plus tard, s'y arrête avec lui.
  const scope: EffectScope | undefined = getCurrentScope()
  let isWatchingIdentity = false

  /**
   * Un changement d'identité du formulaire périme la traduction en vol pour de bon, même si le formulaire retrouve ensuite
   * la même, et efface l'échec : la personne est passée par un autre formulaire, ce que `discard()` fait déjà. Le guetteur
   * naît à la première traduction, pas au montage : la page déclare le composable avant son formulaire, que l'identité
   * lit. Il reste ensuite, car l'échec d'une traduction survit à l'appel qui l'a posé.
   */
  function watchIdentity(): void {
    if (isWatchingIdentity || (undefined !== scope && !scope.active)) {
      return
    }
    isWatchingIdentity = true
    const start = (): void => {
      watch(identity, () => discard(), { flush: 'sync' })
    }
    if (undefined === scope) {
      start()
    } else {
      scope.run(start)
    }
  }

  const translate = async (
    sourceLocale: Locale,
    targetLocale: Locale,
    fields: TranslationFields,
  ): Promise<TranslationProposal | null> => {
    if (isAnyCallRunning.value || isRefused.value) {
      return null
    }

    const token = requests.begin()
    isAnyCallRunning.value = true
    isAwaitingCurrent.value = true
    failure.value = null
    watchIdentity()

    try {
      const proposal = await repository.translate(sourceLocale, targetLocale, fields)

      return requests.isCurrent(token) ? proposal : null
    } catch (error) {
      if (!requests.isCurrent(token)) {
        return null
      }

      // Une session finie ne pose aucune raison : le bandeau de session finie l'annonce seul (#748).
      const reason =
        visibleMutationError(error, AdminTranslationError, () => new AdminTranslationError('unknown', 'Unknown error'))?.reason ?? null
      failure.value = reason
      return null
    } finally {
      isAnyCallRunning.value = false
      // Une traduction périmée ne touche plus l'état : `discard()` l'a déjà rendu.
      if (requests.isCurrent(token)) {
        isAwaitingCurrent.value = false
      }
    }
  }

  function discard(): void {
    requests.begin()
    isAwaitingCurrent.value = false
    failure.value = null
  }

  return { isTranslating, isDiscardPending, isRefused, errorReason, translate, discard }
}
