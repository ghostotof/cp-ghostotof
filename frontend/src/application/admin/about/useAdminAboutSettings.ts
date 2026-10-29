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

  const { isLoading, hasError, isLoaded, load: loadKey } = useAdminLoad<AdminAboutSettings, Locale>({
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

  // La locale demandée en dernier : celle que la page montre, ou est en train de lire. Une réponse
  // d'enregistrement n'a le droit de poser des réglages que si c'est la sienne (#748, R11).
  let requestedLocale: Locale | null = null

  const load = (locale: Locale): Promise<void> => {
    requestedLocale = locale

    return loadKey(locale)
  }

  const save = async (locale: Locale, input: AdminAboutSettingsInput): Promise<boolean> => {
    errorMessage.value = null

    try {
      const saved = await repository.update(locale, input)
      // Un enregistrement parti dans une langue que la personne a quittée depuis a bien abouti côté serveur
      // (on rend vrai), mais sa réponse ne remplace pas les réglages d'une autre langue : le formulaire de
      // la langue affichée, enregistré ensuite, écraserait celle-ci par la première. Même famille que CR2,
      // côté écriture : `useAdminLoad` garde les lectures, cette comparaison garde les réponses d'écriture.
      if (null === requestedLocale || requestedLocale === locale) {
        settings.value = saved
      }

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminAboutError, () => new AdminAboutError('unknown', 'Unknown error'))

      return false
    }
  }

  return { settings, isLoading, hasError, isLoaded, errorMessage, load, save }
}
