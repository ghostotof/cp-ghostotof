import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import AdminUsersPage from '../../../../src/presentation/pages/admin/AdminUsersPage.vue'
import { ADMIN_USER_REPOSITORY } from '../../../../src/application/admin/users/useAdminUsers'
import { AUTH_REPOSITORY, useAuth } from '../../../../src/application/auth/useAuth'
import { createAppI18n } from '../../../../src/presentation/i18n'
import type { AdminUserRepository } from '../../../../src/domain/admin/users/repositories/AdminUserRepository'
import type { AdminUser } from '../../../../src/domain/admin/users/entities/AdminUser'
import type { AuthRepository } from '../../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../../src/domain/auth/entities/AuthenticatedUser'
import { AdminUserError } from '../../../../src/domain/admin/users/errors/AdminUserError'

const SUPER: AdminUser = { id: 1, username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }
const JANE: AdminUser = { id: 2, username: 'jane', roles: ['ROLE_USER'] }

function createStubRepository(overrides: Partial<AdminUserRepository> = {}): AdminUserRepository {
  return {
    list: vi.fn(async () => [SUPER, JANE]),
    remove: vi.fn(async () => undefined),
    changePassword: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createStubAuthRepository(user: AuthenticatedUser | null): AuthRepository {
  return {
    login: vi.fn(async () => user ?? { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => user),
  }
}

/**
 * L'état d'authentification est un singleton au niveau du module (cf.
 * application/auth/useAuth.ts) : on le fixe explicitement avant chaque test,
 * comme le fait déjà tests/presentation/pages/AboutPage.spec.ts.
 */
async function primeAuthState(user: AuthenticatedUser | null): Promise<void> {
  const repository = createStubAuthRepository(user)
  const Probe = defineComponent({
    setup() {
      return { auth: useAuth() }
    },
    template: '<div />',
  })
  const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await wrapper.vm.auth.checkAuth()
  wrapper.unmount()
}

async function mountPage(repository: AdminUserRepository = createStubRepository()) {
  const wrapper = mount(AdminUsersPage, {
    global: {
      plugins: [createAppI18n()],
      provide: {
        [ADMIN_USER_REPOSITORY as symbol]: repository,
        [AUTH_REPOSITORY as symbol]: createStubAuthRepository({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }),
      },
    },
  })
  await flushPromises()

  return wrapper
}

describe('AdminUsersPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('affiche la liste des utilisateurs avec leurs rôles', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('super')
    expect(wrapper.text()).toContain('jane')
    expect(wrapper.text()).toContain('ROLE_SUPER')
  })

  it("affiche un message si le chargement échoue", async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('désactive le bouton Supprimer sur sa propre ligne', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    const rows = wrapper.findAll('tbody tr')
    const superRow = rows.find((row) => row.text().includes('super'))
    const deleteButton = superRow?.findAll('button').find((button) => 'Supprimer' === button.text())

    expect(deleteButton?.attributes('disabled')).toBeDefined()
  })

  it('supprime un autre utilisateur après confirmation', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const rows = wrapper.findAll('tbody tr')
    const janeRow = rows.find((row) => row.text().includes('jane'))
    const deleteButton = janeRow?.findAll('button').find((button) => 'Supprimer' === button.text())
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(repository.remove).toHaveBeenCalledWith(2)
  })

  it('change le mot de passe via le formulaire dédié', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const changeButtons = wrapper.findAll('button').filter((button) => button.text().includes('mot de passe'))
    await changeButtons[0]?.trigger('click')

    await wrapper.get('input[type="password"]').setValue('NewPassword123')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.changePassword).toHaveBeenCalledWith(1, 'NewPassword123')
    expect(wrapper.text()).toContain('Mot de passe mis à jour.')
  })

  it("affiche un message traduit si le changement de mot de passe échoue (auto-suppression n'est pas le cas ici, mais validation)", async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository({
      changePassword: vi.fn(async () => Promise.reject(new AdminUserError('validation', 'Le mot de passe doit contenir au moins 8 caractères.'))),
    })
    const wrapper = await mountPage(repository)

    const changeButtons = wrapper.findAll('button').filter((button) => button.text().includes('mot de passe'))
    await changeButtons[0]?.trigger('click')

    await wrapper.get('input[type="password"]').setValue('short')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Le mot de passe doit contenir au moins 8 caractères.')
  })
})
