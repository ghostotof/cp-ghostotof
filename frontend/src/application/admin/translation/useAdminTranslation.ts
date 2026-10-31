import { computed, inject, readonly, ref, watch, type InjectionKey, type Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationProposal, TranslationFields } from '../../../domain/admin/translation/entities/TranslationProposal'
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
  /** La raison de l'échec de la dernière traduction, tant que le formulaire a l'identité qu'il avait à son échec. */
  errorReason: Readonly<Ref<AdminTranslationErrorReason | null>>
  translate: (sourceLocale: Locale, targetLocale: Locale, fields: TranslationFields) => Promise<TranslationProposal | null>
  /**
   * Périme la traduction en vol : sa réponse rend `null` et ne pose ni état ni raison. Pour ce que `formIdentity` ne
   * voit pas — la suppression de l'entrée éditée, qui ne change rien au formulaire.
   */
  discard: () => void
}

export interface UseAdminTranslationOptions {
  /**
   * Vrai quand la page ne veut aucune traduction : `translate()` ne lance rien. Ce que le bouton refuse, `translate` le
   * refuse, et la page l'écrit ici une fois par condition de son `:disabled` (une proposition affichée, un brouillon
   * d'ordre, un enregistrement en vol, l'absence de prose…), hors l'attente d'une réponse que le composable tient seul.
   */
  readonly isRefused?: () => boolean
  /**
   * Ce qui fait l'identité du formulaire : l'entrée éditée, la langue, le groupe source. Comparée par son contenu
   * (`JSON.stringify`) ; dès que le formulaire n'a plus l'identité relevée à l'envoi, la traduction en vol est
   * périmée pour de bon, même s'il la retrouve ensuite, quel que soit le geste qui l'a remplacé (« Annuler »,
   * « Modifier » une autre entrée, « Créer la version XX », un changement de langue) : `isTranslating` retombe
   * aussitôt et la réponse rend `null`. Rouvrir la même entrée ne change rien au formulaire : rien n'est périmé.
   * La prose n'en fait pas partie : la page la met en lecture seule pendant la traduction, et la raison d'un échec ne
   * disparaît que par un changement d'identité ou la traduction suivante, pas par la frappe qui corrige la prose.
   */
  readonly formIdentity?: () => unknown
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
 * (`isRefused`). Le refus vient d'ici, pas seulement du bouton désactivé : un évènement `translate` émis par
 * programme, ou une double activation avant le rendu, passerait.
 */
export function useAdminTranslation(options: UseAdminTranslationOptions = {}): UseAdminTranslationResult {
  const repository = inject(ADMIN_TRANSLATION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminTranslationRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_TRANSLATION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  // L'identité courante du formulaire, sérialisée : deux identités égales par le contenu sont la même (une
  // réaffectation à l'identique ne remplace rien). Un calculé : relu seulement quand le formulaire change.
  const identity = computed(() => JSON.stringify(options.formIdentity?.() ?? null))
  const inFlight = ref(false)
  // Un appel au dépôt est parti et n'a pas répondu, périmé ou non : `AbortSignal` écarté, l'appel déjà parti côté serveur
  // coûte ce qu'il coûte, et le borner à un par formulaire borne ce coût.
  const callRunning = ref(false)
  const failure = ref<{ readonly reason: AdminTranslationErrorReason; readonly identity: string } | null>(null)
  const requests = createStaleRequestGuard()

  const isTranslating = readonly(inFlight)
  const isDiscardPending = computed(() => callRunning.value && !inFlight.value)
  const errorReason = computed(() => (failure.value?.identity === identity.value ? failure.value.reason : null))

  const translate = async (
    sourceLocale: Locale,
    targetLocale: Locale,
    fields: TranslationFields,
  ): Promise<TranslationProposal | null> => {
    if (callRunning.value || options.isRefused?.()) {
      return null
    }

    const token = requests.begin()
    const sentIdentity = identity.value
    callRunning.value = true
    inFlight.value = true
    failure.value = null
    // Le formulaire qui change d'identité pendant le vol périme la traduction pour de bon, même s'il retrouve ensuite
    // la même : la personne est passée par un autre formulaire. Le guetteur naît ici, pas au montage : la page
    // déclare le composable avant son formulaire, que l'identité lit.
    const stopWatching = watch(
      identity,
      () => {
        if (requests.isCurrent(token)) {
          discard()
        }
      },
      { flush: 'sync' },
    )

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
      failure.value = null === reason ? null : { reason, identity: sentIdentity }
      return null
    } finally {
      stopWatching()
      callRunning.value = false
      // Une traduction périmée ne touche plus l'état : `discard()` l'a déjà rendu.
      if (requests.isCurrent(token)) {
        inFlight.value = false
      }
    }
  }

  function discard(): void {
    requests.begin()
    inFlight.value = false
    failure.value = null
  }

  return { isTranslating, isDiscardPending, errorReason, translate, discard }
}
