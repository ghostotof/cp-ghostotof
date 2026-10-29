import { computed, inject, ref, type ComputedRef, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutSettings } from '../../../domain/admin/about/entities/AdminAboutSettings'
import type {
  AdminAboutSettingsInput,
  AdminAboutSettingsRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSettingsRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import { SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_ABOUT_SETTINGS_REPOSITORY: InjectionKey<AdminAboutSettingsRepository> = Symbol('AdminAboutSettingsRepository')

export interface UseAdminAboutSettingsResult extends AdminLoadState {
  settings: Ref<AdminAboutSettings | null>
  /** L'erreur du dernier enregistrement, **si c'est celle de la langue montrée** ; `null` sinon (#748, R20). */
  errorMessage: ComputedRef<AdminAboutError | null>
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
  // Une erreur par langue (R31, Sp3-4) : l'échec d'un enregistrement reste sous la langue dans laquelle il est parti.
  // Une erreur FR arrivée quand la page est en EN ne se lit pas sous l'en-tête anglais (F10-2), et un enregistrement
  // d'une langue n'efface ni ne remplace l'erreur de l'autre.
  const failures = ref<Partial<Record<Locale, AdminAboutError>>>({})
  // Le numéro du dernier enregistrement parti de chaque langue (R43, CR4-6) : deux enregistrements d'une même langue
  // peuvent se chevaucher (double clic, réseau lent), et le plus ancien répond parfois en dernier. Seul le dernier
  // de sa langue pose son verdict (l'erreur) et sa valeur ; l'ancien rend son vrai sort (`true`/`false`) sans rien poser.
  // Un numéro par langue : un enregistrement EN ne rend pas périmé un enregistrement FR encore en vol.
  const lastSaveOf: Partial<Record<Locale, number>> = {}

  const { isLoading, hasError, isLoaded, load, write, isCurrentKey } = useAdminLoad<AdminAboutSettings, Locale>({
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

  // La clé courante est celle de `useAdminLoad` (R20), sans copie ici : l'erreur de la langue montrée revient avec
  // elle, jusqu'au prochain enregistrement de cette langue.
  const errorMessage = computed(() => {
    const current = SUPPORTED_LOCALES.find((locale) => isCurrentKey(locale))

    return undefined === current ? null : (failures.value[current] ?? null)
  })

  const save = async (locale: Locale, input: AdminAboutSettingsInput): Promise<boolean> => {
    // N'efface que l'erreur de la langue enregistrée : celle d'une autre langue attend son propre enregistrement.
    delete failures.value[locale]
    const mine = (lastSaveOf[locale] ?? 0) + 1
    lastSaveOf[locale] = mine

    try {
      const saved = await repository.update(locale, input)
      // Un enregistrement parti dans une langue que la personne a quittée depuis a bien abouti côté serveur
      // (on rend vrai), mais sa réponse ne remplace pas les réglages d'une autre langue : le formulaire de
      // la langue affichée, enregistré ensuite, écraserait celle-ci par la première (R11). `write` ne pose que sous
      // la clé courante et périme les lectures en vol de cette clé : un `GET` parti avant le COMMIT rendrait
      // l'ancienne valeur et la reposerait par-dessus la neuve (F10-1).
      if (lastSaveOf[locale] === mine) write(locale, saved)

      return true
    } catch (error) {
      const visible = visibleMutationError(error, AdminAboutError, () => new AdminAboutError('unknown', 'Unknown error'))
      if (null !== visible && lastSaveOf[locale] === mine) failures.value[locale] = visible

      return false
    }
  }

  return { settings, isLoading, hasError, isLoaded, errorMessage, load, save }
}
