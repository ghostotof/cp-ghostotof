import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LoginPage from './LoginPage.vue'
import { AUTH_REPOSITORY } from '../../application/auth/useAuth'
import { InvalidCredentialsError } from '../../domain/auth/errors/InvalidCredentialsError'
import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import { createAppI18n } from '../i18n'

const StubPage = { template: '<div />' }

function createStubRepository(overrides: Partial<AuthRepository> = {}): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'jane' })),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => null),
    ...overrides,
  }
}

async function mountLoginPage(repository: AuthRepository) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: LoginPage },
    ],
  })
  await router.push('/fr/login')
  await router.isReady()

  const wrapper = mount(LoginPage, {
    global: {
      plugins: [router, createAppI18n()],
      provide: { [AUTH_REPOSITORY as symbol]: repository },
    },
  })

  return { wrapper, router }
}

describe('LoginPage', () => {
  it('soumet le nom d\'utilisateur et le mot de passe à AuthRepository.login()', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountLoginPage(repository)

    await wrapper.get('#login-username').setValue('jane')
    await wrapper.get('#login-password').setValue('password123')
    await wrapper.get('form').trigger('submit')
    await wrapper.vm.$nextTick()

    expect(repository.login).toHaveBeenCalledWith('jane', 'password123')
  })

  it('redirige vers la page d\'accueil de la locale courante après un login réussi', async () => {
    const repository = createStubRepository()
    const { wrapper, router } = await mountLoginPage(repository)

    await wrapper.get('#login-username').setValue('jane')
    await wrapper.get('#login-password').setValue('password123')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/fr')
  })

  it('affiche un message dédié en cas d\'identifiants invalides', async () => {
    const repository = createStubRepository({ login: vi.fn(async () => Promise.reject(new InvalidCredentialsError())) })
    const { wrapper } = await mountLoginPage(repository)

    await wrapper.get('#login-username').setValue('jane')
    await wrapper.get('#login-password').setValue('wrong')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('affiche un message générique pour les autres erreurs', async () => {
    const repository = createStubRepository({ login: vi.fn(async () => Promise.reject(new Error('network down'))) })
    const { wrapper } = await mountLoginPage(repository)

    await wrapper.get('#login-username').setValue('jane')
    await wrapper.get('#login-password').setValue('whatever')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})

async function flushPromises(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}
