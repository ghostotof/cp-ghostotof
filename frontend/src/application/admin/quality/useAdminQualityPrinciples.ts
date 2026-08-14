import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminQualityPrinciple } from '../../../domain/admin/quality/entities/AdminQualityPrinciple'
import type {
  AdminQualityPrincipleInput,
  AdminQualityPrincipleRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import { AdminQualityError } from '../../../domain/admin/quality/errors/AdminQualityError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

export const ADMIN_QUALITY_PRINCIPLE_REPOSITORY: InjectionKey<AdminQualityPrincipleRepository> = Symbol('AdminQualityPrincipleRepository')

export interface UseAdminQualityPrinciplesResult {
  principles: Ref<readonly AdminQualityPrinciple[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminQualityError | null>
  load: (locale: Locale) => Promise<void>
  create: (input: AdminQualityPrincipleInput) => Promise<void>
  update: (id: number, input: AdminQualityPrincipleInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminStats) : la page appelante
 * possède le sélecteur de locale et pilote load(locale).
 */
export function useAdminQualityPrinciples(): UseAdminQualityPrinciplesResult {
  const repository = inject(ADMIN_QUALITY_PRINCIPLE_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminQualityPrincipleRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_QUALITY_PRINCIPLE_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const principles = ref<readonly AdminQualityPrinciple[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminQualityError | null>(null)
  let currentLocale: Locale | null = null

  const load = async (locale: Locale): Promise<void> => {
    currentLocale = locale
    isLoading.value = true
    hasError.value = false

    try {
      principles.value = await repository.list(locale)
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
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

  const create = (input: AdminQualityPrincipleInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminQualityPrincipleInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  return { principles, isLoading, hasError, errorMessage, load, create, update, remove }
}
