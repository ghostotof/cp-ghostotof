import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'
import type { AdminUserRepository } from '../../../domain/admin/users/repositories/AdminUserRepository'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { AdminUserError } from '../../../domain/admin/users/errors/AdminUserError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_USER_REPOSITORY: InjectionKey<AdminUserRepository> = Symbol('AdminUserRepository')

export interface UseAdminUsersResult extends AdminLoadState {
  users: Ref<readonly AdminUser[]>
  errorMessage: Ref<AdminUserError | null>
  load: () => Promise<void>
  /** Retourne le compte créé, ou `null` si l'invitation a échoué (errorMessage renseigné). */
  invite: (email: string, locale: Locale) => Promise<AdminUser | null>
  setSuperAdmin: (id: string, grant: boolean) => Promise<boolean>
  resendInvitation: (id: string, locale: Locale) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
  changePassword: (id: string, newPassword: string) => Promise<boolean>
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
  const errorMessage = ref<AdminUserError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      users.value = value
    },
  })

  const runAction = async (action: () => Promise<unknown>, reloadAfter: boolean): Promise<boolean> => {
    errorMessage.value = null

    try {
      await action()
      if (reloadAfter) {
        await load()
      }

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminUserError, () => new AdminUserError('unknown', 'Unknown error'))

      return false
    }
  }

  const invite = async (email: string, locale: Locale): Promise<AdminUser | null> => {
    errorMessage.value = null

    try {
      const created = await repository.invite(email, locale)
      await load()

      return created
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminUserError, () => new AdminUserError('unknown', 'Unknown error'))

      return null
    }
  }

  const setSuperAdmin = (id: string, grant: boolean): Promise<boolean> =>
    runAction(() => repository.setSuperAdmin(id, grant), true)

  const resendInvitation = (id: string, locale: Locale): Promise<boolean> =>
    runAction(() => repository.resendInvitation(id, locale), false)

  const remove = (id: string): Promise<boolean> => runAction(() => repository.remove(id), true)

  const changePassword = (id: string, newPassword: string): Promise<boolean> =>
    runAction(() => repository.changePassword(id, newPassword), false)

  void load()

  return { users, isLoading, hasError, isLoaded, errorMessage, load, invite, setSuperAdmin, resendInvitation, remove, changePassword }
}
