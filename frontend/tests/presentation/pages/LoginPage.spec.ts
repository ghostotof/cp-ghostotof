import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LoginPage from '../../../src/presentation/pages/LoginPage.vue'
import { AUTH_REPOSITORY } from '../../../src/application/auth/useAuth'
import { InvalidCredentialsError } from '../../../src/domain/auth/errors/InvalidCredentialsError'
import { LoginRateLimitedError } from '../../../src/domain/auth/errors/LoginRateLimitedError'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { createAppI18n } from '../../../src/presentation/i18n'
import { sessionFor } from '../../support/authSession'

const StubPage = { template: '<div />' }

function createStubRepository(overrides: Partial<AuthRepository> = {}): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER'] })),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => sessionFor(null)),
    ...overrides,
  }
}

async function mountLoginPage(repository: AuthRepository, locale = 'fr', query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: LoginPage },
      { path: '/:locale(fr|en)/oauth/consent', name: 'oauth-consent', component: StubPage },
    ],
  })
  await router.push({ name: 'login', params: { locale }, query })
  await router.isReady()

  const i18n = createAppI18n()
  i18n.global.locale.value = locale as typeof i18n.global.locale.value

  const wrapper = mount(LoginPage, {
    global: {
      plugins: [router, i18n],
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

  it.each([
    ['fr', null, 'Trop de tentatives de connexion. Réessayez dans quelques minutes.'],
    ['en', null, 'Too many login attempts. Please try again in a few minutes.'],
    ['fr', 900, 'Trop de tentatives de connexion. Réessayez dans 15 minutes.'],
    ['en', 900, 'Too many login attempts. Please try again in 15 minutes.'],
    ['fr', 30, 'Trop de tentatives de connexion. Réessayez dans 1 minute.'],
    ['en', 30, 'Too many login attempts. Please try again in 1 minute.'],
  ])('affiche un message dédié au throttling du login (%s, Retry-After %s, issue #399)', async (locale, retryAfterSeconds, message) => {
    const repository = createStubRepository({ login: vi.fn(async () => Promise.reject(new LoginRateLimitedError(retryAfterSeconds))) })
    const { wrapper } = await mountLoginPage(repository, locale)

    await wrapper.get('#login-username').setValue('jane')
    await wrapper.get('#login-password').setValue('wrong')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe(message)
  })

  describe('reconnexion demandée par le consentement MCP (`reauth`, #647)', () => {
    const CONSENT = '/fr/oauth/consent?client_id=claude-code&state=a%26b'

    it.each([
      ['fr', 'Confirmez votre identité'],
      ['en', 'Confirm your identity'],
    ])('annonce pourquoi la page de connexion s’ouvre alors que la personne est connectée (%s)', async (locale, notice) => {
      const { wrapper } = await mountLoginPage(createStubRepository(), locale, { reauth: '1', redirect: CONSENT })

      expect(wrapper.get('[data-testid="login-reauth-notice"]').text()).toContain(notice)
    })

    it('sans le drapeau : aucune annonce', async () => {
      const { wrapper } = await mountLoginPage(createStubRepository(), 'fr', { redirect: CONSENT })

      expect(wrapper.find('[data-testid="login-reauth-notice"]').exists()).toBe(false)
    })

    it('après la connexion, retour à la même demande, requête comprise', async () => {
      const { wrapper, router } = await mountLoginPage(createStubRepository(), 'fr', { reauth: '1', redirect: CONSENT })

      await wrapper.get('#login-username').setValue('jane')
      await wrapper.get('#login-password').setValue('password123')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe(CONSENT)
    })

    it('le drapeau n’affaiblit pas la garde de redirection ouverte', async () => {
      const { wrapper, router } = await mountLoginPage(createStubRepository(), 'fr', { reauth: '1', redirect: '//evil.example/fr/oauth/consent' })

      await wrapper.get('#login-username').setValue('jane')
      await wrapper.get('#login-password').setValue('password123')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(router.currentRoute.value.path).toBe('/fr')
    })
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
