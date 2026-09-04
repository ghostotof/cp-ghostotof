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

const SUPER: AdminUser = { id: 1, username: 'super', email: null, roles: ['ROLE_SUPER', 'ROLE_USER'], status: 'active' }
const JANE: AdminUser = { id: 2, username: 'jane', email: null, roles: ['ROLE_USER'], status: 'active' }
const NEWCOMER: AdminUser = { id: 3, username: 'newcomer', email: 'newcomer@example.com', roles: ['ROLE_USER'], status: 'pending' }

function createStubRepository(overrides: Partial<AdminUserRepository> = {}): AdminUserRepository {
  return {
    list: vi.fn(async () => [SUPER, JANE, NEWCOMER]),
    invite: vi.fn(async () => NEWCOMER),
    setSuperAdmin: vi.fn(async () => undefined),
    resendInvitation: vi.fn(async () => undefined),
    remove: vi.fn(async () => undefined),
    changePassword: vi.fn(async () => undefined),
    ...overrides,
  }
}

function rowFor(wrapper: Awaited<ReturnType<typeof mountPage>>, username: string) {
  return wrapper.findAll('tbody tr').find((row) => row.text().includes(username))
}

/**
 * Les actions d'une ligne (promotion, renvoi, mot de passe, suppression) sont
 * regroupées derrière un bouton « ⋯ » : il faut ouvrir ce menu avant de
 * pouvoir cliquer une action.
 */
async function openRowMenu(wrapper: Awaited<ReturnType<typeof mountPage>>, username: string): Promise<void> {
  await rowFor(wrapper, username)?.get('button[aria-haspopup="true"]').trigger('click')
  await flushPromises()
}

function rowButton(wrapper: Awaited<ReturnType<typeof mountPage>>, username: string, text: string) {
  return rowFor(wrapper, username)
    ?.findAll('button')
    .find((button) => button.text().includes(text))
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

  it('le formulaire d\'invitation appelle invite avec {email, locale} et affiche le username retourné', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-user-invite-email').setValue('jean.dupont@example.com')
    await wrapper.get('form.admin-user-invite-form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.invite).toHaveBeenCalledWith('jean.dupont@example.com', 'fr')
    expect(wrapper.text()).toContain('newcomer')
    expect(wrapper.text()).toContain('Invitation envoyée')
  })

  it('affiche un message d\'erreur si l\'invitation échoue (email déjà pris) sans casser la liste', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository({
      invite: vi.fn(async () => Promise.reject(new AdminUserError('email-taken', 'x'))),
    })
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-user-invite-email').setValue('x@y.fr')
    await wrapper.get('form.admin-user-invite-form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Un compte existe déjà avec cette adresse e-mail.')
    expect(wrapper.text()).toContain('jane')
  })

  it('affiche « Adresse e-mail invalide » (et pas le message mot de passe) si l\'invitation renvoie 422', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository({
      invite: vi.fn(async () => Promise.reject(new AdminUserError('email-invalid', 'x'))),
    })
    const wrapper = await mountPage(repository)

    await wrapper.get('#admin-user-invite-email').setValue('not-an-email')
    await wrapper.get('form.admin-user-invite-form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Adresse e-mail invalide.')
  })

  it('le menu d\'actions n\'est ouvert que pour une ligne à la fois', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    expect(wrapper.findAll('.dropdown-item')).toHaveLength(0)

    await openRowMenu(wrapper, 'jane')
    expect(rowFor(wrapper, 'jane')?.findAll('.dropdown-item').length).toBeGreaterThan(0)

    await openRowMenu(wrapper, 'super')
    expect(rowFor(wrapper, 'jane')?.findAll('.dropdown-item')).toHaveLength(0)
    expect(rowFor(wrapper, 'super')?.findAll('.dropdown-item').length).toBeGreaterThan(0)
  })

  it('l\'action de rôle du menu promeut une autre ligne via setSuperAdmin(id, true)', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await openRowMenu(wrapper, 'jane')
    await rowButton(wrapper, 'jane', 'Promouvoir')?.trigger('click')
    await flushPromises()

    expect(repository.setSuperAdmin).toHaveBeenCalledWith(2, true)
  })

  it('l\'action de rôle est désactivée sur sa propre ligne', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    await openRowMenu(wrapper, 'super')
    expect(rowButton(wrapper, 'super', 'admin')?.attributes('disabled')).toBeDefined()
  })

  it('l\'action « Renvoyer l\'invitation » n\'apparaît que pour les lignes en attente', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await openRowMenu(wrapper, 'jane')
    expect(rowButton(wrapper, 'jane', 'Renvoyer')).toBeUndefined()

    await openRowMenu(wrapper, 'newcomer')
    await rowButton(wrapper, 'newcomer', 'Renvoyer')?.trigger('click')
    await flushPromises()

    expect(repository.resendInvitation).toHaveBeenCalledWith(3, 'fr')
  })

  it('le message « Invitation renvoyée » disparaît dès qu\'une autre action est déclenchée', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await openRowMenu(wrapper, 'newcomer')
    await rowButton(wrapper, 'newcomer', 'Renvoyer')?.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Invitation renvoyée.')

    await openRowMenu(wrapper, 'jane')
    await rowButton(wrapper, 'jane', 'Promouvoir')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('Invitation renvoyée.')
  })

  it('affiche le statut En attente / Actif', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    expect(rowFor(wrapper, 'newcomer')?.text()).toContain('En attente')
    expect(rowFor(wrapper, 'jane')?.text()).toContain('Actif')
  })

  it('affiche l\'e-mail lié dans une colonne dédiée, ou un tiret quand il n\'y en a pas', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    const headers = wrapper.findAll('thead th').map((th) => th.text())
    expect(headers).toContain('E-mail')

    expect(rowFor(wrapper, 'newcomer')?.text()).toContain('newcomer@example.com')
    expect(rowFor(wrapper, 'jane')?.get('td:nth-child(2)').text()).toBe('—')
  })

  it("affiche un message si le chargement échoue", async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository({ list: vi.fn(async () => Promise.reject(new Error('unavailable'))) })
    const wrapper = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('désactive l\'action Supprimer sur sa propre ligne', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const wrapper = await mountPage()

    await openRowMenu(wrapper, 'super')
    expect(rowButton(wrapper, 'super', 'Supprimer')?.attributes('disabled')).toBeDefined()
  })

  it('supprime un autre utilisateur après confirmation', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await openRowMenu(wrapper, 'jane')
    await rowButton(wrapper, 'jane', 'Supprimer')?.trigger('click')
    await flushPromises()

    expect(repository.remove).toHaveBeenCalledWith(2)
  })

  it('change le mot de passe via le formulaire dédié', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    await openRowMenu(wrapper, 'super')
    await rowButton(wrapper, 'super', 'mot de passe')?.trigger('click')

    await wrapper.get('input[type="password"]').setValue('NewPassword123')
    await wrapper.get('form.admin-user-password-form').trigger('submit.prevent')
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

    await openRowMenu(wrapper, 'super')
    await rowButton(wrapper, 'super', 'mot de passe')?.trigger('click')

    await wrapper.get('input[type="password"]').setValue('short')
    await wrapper.get('form.admin-user-password-form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Le mot de passe doit contenir au moins 8 caractères.')
  })
})
