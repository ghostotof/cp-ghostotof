import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutSettings } from '../../../domain/admin/about/entities/AdminAboutSettings'
import type {
  AdminAboutSettingsInput,
  AdminAboutSettingsRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSettingsRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_ABOUT_SETTINGS_REPOSITORY: InjectionKey<AdminAboutSettingsRepository> = Symbol('AdminAboutSettingsRepository')

export interface UseAdminAboutSettingsResult extends AdminLoadState {
  settings: Ref<AdminAboutSettings | null>
  errorMessage: Ref<AdminAboutError | null>
  load: (locale: Locale) => Promise<void>
  save: (locale: Locale, input: AdminAboutSettingsInput) => Promise<boolean>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page appelante
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
  const errorMessage = ref<AdminAboutError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad<AdminAboutSettings, Locale>({
    read: (locale) => repository.get(locale),
    apply: (value) => {
      settings.value = value
    },
    // Le seul composable à clé : une locale nouvelle vide les réglages de l'ancienne avant de lire, pour que
    // le formulaire d'une langue ne reste jamais sous l'en-tête d'une autre (CR2, #748 R4).
    clear: () => {
      settings.value = null
    },
  })

  const save = async (locale: Locale, input: AdminAboutSettingsInput): Promise<boolean> => {
    errorMessage.value = null

    try {
      settings.value = await repository.update(locale, input)

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminAboutError, () => new AdminAboutError('unknown', 'Unknown error'))

      return false
    }
  }

  return { settings, isLoading, hasError, isLoaded, errorMessage, load, save }
}
