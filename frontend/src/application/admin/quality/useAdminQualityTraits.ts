import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'
import type {
  AdminQualityTraitInput,
  AdminQualityTraitRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityTraitRepository'
import { AdminQualityError } from '../../../domain/admin/quality/errors/AdminQualityError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_QUALITY_TRAIT_REPOSITORY: InjectionKey<AdminQualityTraitRepository> = Symbol('AdminQualityTraitRepository')

export interface UseAdminQualityTraitsResult {
  traits: Ref<readonly AdminQualityTrait[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminQualityError | null>
  load: (locale: Locale) => Promise<void>
  create: (input: AdminQualityTraitInput) => Promise<void>
  update: (id: number, input: AdminQualityTraitInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminStats) : la page appelante
 * possède le sélecteur de locale et pilote load(locale).
 */
export function useAdminQualityTraits(): UseAdminQualityTraitsResult {
  const repository = inject(ADMIN_QUALITY_TRAIT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminQualityTraitRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_QUALITY_TRAIT_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const traits = ref<readonly AdminQualityTrait[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminQualityError | null>(null)
  const requestGuard = createStaleRequestGuard()
  let currentLocale: Locale | null = null

  const load = async (locale: Locale): Promise<void> => {
    currentLocale = locale
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list(locale)
      if (!requestGuard.isCurrent(token)) return
      traits.value = result
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  const runMutation = async (mutation: () => Promise<unknown>): Promise<void> => {
    errorMessage.value = null

    try {
      await mutation()
      if (currentLocale) {
        await load(currentLocale)
      }
    } catch (error) {
      errorMessage.value = error instanceof AdminQualityError ? error : new AdminQualityError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminQualityTraitInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminQualityTraitInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  return { traits, isLoading, hasError, errorMessage, load, create, update, remove }
}
