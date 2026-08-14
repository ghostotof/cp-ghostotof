import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_USER_REPOSITORY, useAdminUsers } from '../../../../src/application/admin/users/useAdminUsers'
import type { AdminUserRepository } from '../../../../src/domain/admin/users/repositories/AdminUserRepository'
import type { AdminUser } from '../../../../src/domain/admin/users/entities/AdminUser'
import { AdminUserError } from '../../../../src/domain/admin/users/errors/AdminUserError'

const USER: AdminUser = { id: 1, username: 'jane', roles: ['ROLE_USER'] }

function createStubRepository(overrides: Partial<AdminUserRepository> = {}): AdminUserRepository {
  return {
    list: vi.fn(async () => [USER]),
    remove: vi.fn(async () => undefined),
    changePassword: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AdminUserRepository) {
  let captured: ReturnType<typeof useAdminUsers> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAdminUsers()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ADMIN_USER_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAdminUsers() did not run during mount')
  }

  return captured
}

describe('useAdminUsers', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAdminUsers()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AdminUserRepository/)
  })

  it('charge la liste au montage', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    expect(composable.isLoading.value).toBe(true)
    await composable.load()

    expect(repository.list).toHaveBeenCalled()
    expect(composable.users.value).toEqual([USER])
    expect(composable.isLoading.value).toBe(false)
  })

  it('hasError passe à true si le chargement échoue', async () => {
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const composable = mountWithComposable(repository)

    await composable.load()

    expect(composable.hasError.value).toBe(true)
  })

  it('remove() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.remove(1)

    expect(repository.remove).toHaveBeenCalledWith(1)
    expect(repository.list).toHaveBeenCalledTimes(1)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('remove() propage errorMessage sans planter en cas d\'échec (ex. auto-suppression)', async () => {
    const repository = createStubRepository({
      remove: vi.fn(async () => Promise.reject(new AdminUserError('cannot-delete-self', "Vous ne pouvez pas supprimer votre propre compte."))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.remove(1)

    expect(composable.errorMessage.value?.reason).toBe('cannot-delete-self')
  })

  it("changePassword() appelle le repository SANS recharger la liste", async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.changePassword(1, 'NewPassword123')

    expect(repository.changePassword).toHaveBeenCalledWith(1, 'NewPassword123')
    expect(repository.list).not.toHaveBeenCalled()
    expect(composable.errorMessage.value).toBeNull()
  })

  it('changePassword() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({
      changePassword: vi.fn(async () => Promise.reject(new AdminUserError('validation', 'Mot de passe trop court'))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.changePassword(1, 'short')

    expect(composable.errorMessage.value?.reason).toBe('validation')
  })
})
