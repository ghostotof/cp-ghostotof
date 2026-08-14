import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'
import type { AdminUserRepository } from '../../../domain/admin/users/repositories/AdminUserRepository'
import { AdminUserError } from '../../../domain/admin/users/errors/AdminUserError'

export const ADMIN_USER_REPOSITORY: InjectionKey<AdminUserRepository> = Symbol('AdminUserRepository')

export interface UseAdminUsersResult {
  users: Ref<readonly AdminUser[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminUserError | null>
  load: () => Promise<void>
  remove: (id: number) => Promise<void>
  changePassword: (id: number, newPassword: string) => Promise<void>
}

/**
 * `hasError` reflète un échec du chargement initial de la liste (affichage
 * plein écran) ; `errorMessage` reflète l'échec d'une action ponctuelle
 * (remove/changePassword, affiché près de l'action concernée) — même
 * distinction que useAdminExperienceTechnologies.
 */
export function useAdminUsers(): UseAdminUsersResult {
  const repository = inject(ADMIN_USER_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminUserRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_USER_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const users = ref<readonly AdminUser[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminUserError | null>(null)

  const load = async (): Promise<void> => {
    isLoading.value = true
    hasError.value = false

    try {
      users.value = await repository.list()
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  const runAction = async (action: () => Promise<unknown>, reloadAfter: boolean): Promise<void> => {
    errorMessage.value = null

    try {
      await action()
      if (reloadAfter) {
        await load()
      }
    } catch (error) {
      errorMessage.value = error instanceof AdminUserError ? error : new AdminUserError('unknown', 'Unknown error')
    }
  }

  const remove = (id: number): Promise<void> => runAction(() => repository.remove(id), true)

  const changePassword = (id: number, newPassword: string): Promise<void> =>
    runAction(() => repository.changePassword(id, newPassword), false)

  void load()

  return { users, isLoading, hasError, errorMessage, load, remove, changePassword }
}
