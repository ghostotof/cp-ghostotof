import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminVulnerability } from '../../../domain/admin/watch/entities/AdminVulnerability'
import type { AdminVulnerabilityRepository } from '../../../domain/admin/watch/repositories/AdminVulnerabilityRepository'

export const ADMIN_VULNERABILITY_REPOSITORY: InjectionKey<AdminVulnerabilityRepository> =
  Symbol('AdminVulnerabilityRepository')

export interface UseAdminVulnerabilitiesResult {
  vulnerabilities: Ref<readonly AdminVulnerability[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge le détail des vulnérabilités connues.
 *
 * Plus simple que les autres composables d'administration : la liste est en
 * lecture seule, il n'y a donc ni mutation à orchestrer ni erreur de formulaire
 * à distinguer d'une erreur de chargement.
 */
export function useAdminVulnerabilities(): UseAdminVulnerabilitiesResult {
  const repository = inject(ADMIN_VULNERABILITY_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminVulnerabilityRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_VULNERABILITY_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const vulnerabilities = ref<readonly AdminVulnerability[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)

  const load = async (): Promise<void> => {
    isLoading.value = true
    hasError.value = false

    try {
      vulnerabilities.value = await repository.list()
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  void load()

  return { vulnerabilities, isLoading, hasError }
}
