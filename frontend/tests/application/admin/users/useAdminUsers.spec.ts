import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ADMIN_USER_REPOSITORY, useAdminUsers } from '../../../../src/application/admin/users/useAdminUsers'
import type { AdminUserRepository } from '../../../../src/domain/admin/users/repositories/AdminUserRepository'
import type { AdminUser } from '../../../../src/domain/admin/users/entities/AdminUser'
import { AdminUserError } from '../../../../src/domain/admin/users/errors/AdminUserError'

const USER_ID = '019968a0-0000-7000-8000-000000000001'
const OTHER_USER_ID = '019968a0-0000-7000-8000-000000000002'
const CREATED_USER_ID = '019968a0-0000-7000-8000-000000000009'
const USER: AdminUser = { id: USER_ID, username: 'jane', email: null, roles: ['ROLE_USER'], status: 'active' }

function createStubRepository(overrides: Partial<AdminUserRepository> = {}): AdminUserRepository {
  return {
    list: vi.fn(async () => [USER]),
    invite: vi.fn(async () => USER),
    setSuperAdmin: vi.fn(async () => undefined),
    resendInvitation: vi.fn(async () => undefined),
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

    await composable.remove(USER_ID)

    expect(repository.remove).toHaveBeenCalledWith(USER_ID)
    expect(repository.list).toHaveBeenCalledTimes(1)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('remove() propage errorMessage sans planter en cas d\'échec (ex. auto-suppression)', async () => {
    const repository = createStubRepository({
      remove: vi.fn(async () => Promise.reject(new AdminUserError('cannot-delete-self', "Vous ne pouvez pas supprimer votre propre compte."))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.remove(USER_ID)

    expect(composable.errorMessage.value?.reason).toBe('cannot-delete-self')
  })

  it('invite() appelle le repository, recharge la liste et retourne l\'utilisateur créé', async () => {
    const created: AdminUser = { id: CREATED_USER_ID, username: 'jean.dupont', email: 'jean.dupont@example.com', roles: ['ROLE_USER'], status: 'pending' }
    const repository = createStubRepository({ invite: vi.fn(async () => created) })
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    const result = await composable.invite('jean.dupont@example.com', 'fr')

    expect(repository.invite).toHaveBeenCalledWith('jean.dupont@example.com', 'fr')
    expect(result).toEqual(created)
    expect(repository.list).toHaveBeenCalledTimes(1)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('invite() propage errorMessage et retourne null sans casser la liste en cas d\'échec', async () => {
    const repository = createStubRepository({
      invite: vi.fn(async () => Promise.reject(new AdminUserError('email-taken', 'Adresse déjà utilisée'))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    const result = await composable.invite('x@y.fr', 'fr')

    expect(result).toBeNull()
    expect(composable.errorMessage.value?.reason).toBe('email-taken')
    expect(composable.users.value).toEqual([USER])
  })

  it('setSuperAdmin() appelle le repository puis recharge la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.setSuperAdmin(OTHER_USER_ID, true)

    expect(repository.setSuperAdmin).toHaveBeenCalledWith(OTHER_USER_ID, true)
    expect(repository.list).toHaveBeenCalledTimes(1)
    expect(composable.errorMessage.value).toBeNull()
  })

  it('setSuperAdmin() propage errorMessage en cas d\'échec', async () => {
    const repository = createStubRepository({
      setSuperAdmin: vi.fn(async () => Promise.reject(new AdminUserError('cannot-modify-own-roles', 'Interdit'))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.setSuperAdmin(USER_ID, false)

    expect(composable.errorMessage.value?.reason).toBe('cannot-modify-own-roles')
  })

  it('resendInvitation() appelle le repository SANS recharger la liste', async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.resendInvitation(OTHER_USER_ID, 'en')

    expect(repository.resendInvitation).toHaveBeenCalledWith(OTHER_USER_ID, 'en')
    expect(repository.list).not.toHaveBeenCalled()
    expect(composable.errorMessage.value).toBeNull()
  })

  it('resendInvitation() propage errorMessage en cas d\'échec', async () => {
    const repository = createStubRepository({
      resendInvitation: vi.fn(async () => Promise.reject(new AdminUserError('already-activated', 'Déjà activé'))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.resendInvitation(OTHER_USER_ID, 'fr')

    expect(composable.errorMessage.value?.reason).toBe('already-activated')
  })

  it("changePassword() appelle le repository SANS recharger la liste", async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)
    await composable.load()
    vi.mocked(repository.list).mockClear()

    await composable.changePassword(USER_ID, 'NewPassword123')

    expect(repository.changePassword).toHaveBeenCalledWith(USER_ID, 'NewPassword123')
    expect(repository.list).not.toHaveBeenCalled()
    expect(composable.errorMessage.value).toBeNull()
  })

  it('changePassword() propage errorMessage sans planter en cas d\'échec', async () => {
    const repository = createStubRepository({
      changePassword: vi.fn(async () => Promise.reject(new AdminUserError('validation', 'Mot de passe trop court'))),
    })
    const composable = mountWithComposable(repository)
    await composable.load()

    await composable.changePassword(USER_ID, 'short')

    expect(composable.errorMessage.value?.reason).toBe('validation')
  })
})
