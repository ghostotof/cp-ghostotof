import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutSettings } from '../../../domain/admin/about/entities/AdminAboutSettings'
import type {
  AdminAboutSettingsInput,
  AdminAboutSettingsRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSettingsRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_ABOUT_SETTINGS_REPOSITORY: InjectionKey<AdminAboutSettingsRepository> = Symbol('AdminAboutSettingsRepository')

export interface UseAdminAboutSettingsResult {
  settings: Ref<AdminAboutSettings | null>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminAboutError | null>
  load: (locale: Locale) => Promise<void>
  save: (locale: Locale, input: AdminAboutSettingsInput) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminStats) : la page appelante
 * possède le sélecteur de locale et pilote load(locale). Pas de liste ici
 * (singleton par locale) : `settings` est un objet unique ou `null` tant que
 * non chargé.
 */
export function useAdminAboutSettings(): UseAdminAboutSettingsResult {
  const repository = inject(ADMIN_ABOUT_SETTINGS_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminAboutSettingsRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_ABOUT_SETTINGS_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const settings = ref<AdminAboutSettings | null>(null)
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminAboutError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (locale: Locale): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.get(locale)
      if (!requestGuard.isCurrent(token)) return
      settings.value = result
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  const save = async (locale: Locale, input: AdminAboutSettingsInput): Promise<void> => {
    errorMessage.value = null

    try {
      settings.value = await repository.update(locale, input)
    } catch (error) {
      errorMessage.value = error instanceof AdminAboutError ? error : new AdminAboutError('unknown', 'Unknown error')
    }
  }

  return { settings, isLoading, hasError, errorMessage, load, save }
}
