import { inject, ref, type InjectionKey, type Ref } from 'vue'
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
  isTranslating: Ref<boolean>
  errorReason: Ref<AdminTranslationErrorReason | null>
  translate: (sourceLocale: Locale, targetLocale: Locale, fields: TranslationFields) => Promise<TranslationProposal | null>
  /** Périme la traduction en vol (« Annuler ») : sa réponse rend `null` et ne pose ni état ni raison. */
  discard: () => void
}

export interface UseAdminTranslationOptions {
  /** Vrai quand la page ne veut aucune traduction (une proposition est déjà affichée) : `translate()` ne lance rien. */
  readonly isRefused?: () => boolean
}

/**
 * Partagé par toutes les pages admin qui offrent la traduction assistée : la
 * page fournit les champs de prose, reçoit la proposition (ou `null` en cas
 * d'échec, la raison étant exposée) et décide seule quoi en faire — ce
 * composable ne touche jamais au formulaire ni à la persistance (ADR 0004, D4).
 * Pas de dépendance à useI18n() : la page traduit `errorReason`.
 *
 * Le composable se refuse lui-même, écrit ici une fois pour tous les formulaires : pas de requête tant qu'une
 * traduction est en vol, ni quand la page le lui dit (`isRefused`). Le refus vient d'ici, pas seulement du bouton
 * désactivé : un évènement `translate` émis par programme, ou une double activation avant le rendu, passerait.
 */
export function useAdminTranslation(options: UseAdminTranslationOptions = {}): UseAdminTranslationResult {
  const repository = inject(ADMIN_TRANSLATION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminTranslationRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_TRANSLATION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const isTranslating = ref(false)
  const errorReason = ref<AdminTranslationErrorReason | null>(null)
  const requests = createStaleRequestGuard()

  const translate = async (
    sourceLocale: Locale,
    targetLocale: Locale,
    fields: TranslationFields,
  ): Promise<TranslationProposal | null> => {
    if (isTranslating.value || options.isRefused?.()) {
      return null
    }

    const token = requests.begin()
    isTranslating.value = true
    errorReason.value = null

    try {
      const proposal = await repository.translate(sourceLocale, targetLocale, fields)

      return requests.isCurrent(token) ? proposal : null
    } catch (error) {
      if (!requests.isCurrent(token)) {
        return null
      }

      // Une session finie ne pose aucune raison : le bandeau de session finie l'annonce seul (#748).
      errorReason.value =
        visibleMutationError(error, AdminTranslationError, () => new AdminTranslationError('unknown', 'Unknown error'))?.reason ?? null
      return null
    } finally {
      // Une traduction périmée ne touche plus l'état : `discard()` l'a déjà rendu.
      if (requests.isCurrent(token)) {
        isTranslating.value = false
      }
    }
  }

  const discard = (): void => {
    requests.begin()
    isTranslating.value = false
    errorReason.value = null
  }

  return { isTranslating, errorReason, translate, discard }
}
