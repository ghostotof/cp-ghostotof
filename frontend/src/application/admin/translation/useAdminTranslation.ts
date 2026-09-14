import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationDraft, TranslationFields } from '../../../domain/admin/translation/entities/TranslationDraft'
import type { AdminTranslationRepository } from '../../../domain/admin/translation/repositories/AdminTranslationRepository'
import {
  AdminTranslationError,
  type AdminTranslationErrorReason,
} from '../../../domain/admin/translation/errors/AdminTranslationError'

export const ADMIN_TRANSLATION_REPOSITORY: InjectionKey<AdminTranslationRepository> = Symbol('AdminTranslationRepository')

export interface UseAdminTranslationResult {
  isTranslating: Ref<boolean>
  errorReason: Ref<AdminTranslationErrorReason | null>
  translate: (sourceLocale: Locale, targetLocale: Locale, fields: TranslationFields) => Promise<TranslationDraft | null>
}

/**
 * Partagé par toutes les pages admin qui proposent un brouillon traduit : la
 * page fournit les champs de prose, reçoit le brouillon (ou `null` en cas
 * d'échec, la raison étant exposée) et décide seule quoi en faire — ce
 * composable ne touche jamais au formulaire ni à la persistance (ADR 0004, D4).
 * Pas de dépendance à useI18n() : la page traduit `errorReason`.
 */
export function useAdminTranslation(): UseAdminTranslationResult {
  const repository = inject(ADMIN_TRANSLATION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminTranslationRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_TRANSLATION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const isTranslating = ref(false)
  const errorReason = ref<AdminTranslationErrorReason | null>(null)

  const translate = async (
    sourceLocale: Locale,
    targetLocale: Locale,
    fields: TranslationFields,
  ): Promise<TranslationDraft | null> => {
    isTranslating.value = true
    errorReason.value = null

    try {
      return await repository.translate(sourceLocale, targetLocale, fields)
    } catch (error) {
      errorReason.value = error instanceof AdminTranslationError ? error.reason : 'unknown'
      return null
    } finally {
      isTranslating.value = false
    }
  }

  return { isTranslating, errorReason, translate }
}
