import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import LoginPage from '../../../src/presentation/pages/LoginPage.vue'
import { AUTH_REPOSITORY, useAuth } from '../../../src/application/auth/useAuth'
import { InvalidCredentialsError } from '../../../src/domain/auth/errors/InvalidCredentialsError'
import { LoginRateLimitedError } from '../../../src/domain/auth/errors/LoginRateLimitedError'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
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

  describe('session finie (`expired`, posé par la redirection d\'une page à laquelle l\'API a refusé la session, #650 R5a)', () => {
    it.each([
      ['fr', 'Votre session a pris fin, reconnectez-vous.'],
      ['en', 'Your session has ended, please sign in again.'],
    ])('annonce que la session a pris fin, dans une région role="status" (%s)', async (locale, notice) => {
      const { wrapper } = await mountLoginPage(createStubRepository(), locale, { expired: '1', redirect: '/fr/admin/users' })

      const banner = wrapper.get('[data-testid="login-session-ended-notice"]')
      expect(banner.text()).toBe(notice)
      expect(banner.attributes('role')).toBe('status')
    })

    it.each([[{}], [{ redirect: '/fr/admin/users' }], [{ expired: '0' }], [{ expired: 'true' }], [{ expired: '' }]])(
      'sans le drapeau (ou avec une autre valeur que 1), aucune annonce : %j',
      async (query) => {
        const { wrapper } = await mountLoginPage(createStubRepository(), 'fr', query)

        expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('a pris fin')
      },
    )

    it('après la connexion, retour à la page d\'où l\'on venait', async () => {
      const { wrapper, router } = await mountLoginPage(createStubRepository(), 'fr', { expired: '1', redirect: '/fr/oauth/consent?client_id=claude-code' })

      await wrapper.get('#login-username').setValue('jane')
      await wrapper.get('#login-password').setValue('password123')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe('/fr/oauth/consent?client_id=claude-code')
    })

    it.each(['//evil.example/fr/admin', 'https://evil.example/', 'javascript:alert(1)'])(
      'le drapeau n’affaiblit pas la garde de redirection ouverte : %s',
      async (redirect) => {
        const { wrapper, router } = await mountLoginPage(createStubRepository(), 'fr', { expired: '1', redirect })

        await wrapper.get('#login-username').setValue('jane')
        await wrapper.get('#login-password').setValue('password123')
        await wrapper.get('form').trigger('submit')
        await flushPromises()

        expect(router.currentRoute.value.path).toBe('/fr')
      },
    )
  })

  describe('reconnexion demandée par le consentement MCP (`reauth`, #647)', () => {
    const CONSENT = '/fr/oauth/consent?client_id=claude-code&state=a%26b'

    /** La session que le reste de l'application voit (authState, partagé d'un test à l'autre). */
    async function primeSession(user: AuthenticatedUser | null): Promise<void> {
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor(user)) })
      const Probe = defineComponent({
        setup() {
          return { auth: useAuth() }
        },
        template: '<div />',
      })
      const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
      await probe.vm.auth.checkAuth()
      probe.unmount()
    }

    afterEach(async () => {
      await primeSession(null)
    })

    it.each([
      ['fr', 'Confirmez votre identité'],
      ['en', 'Confirm your identity'],
    ])('annonce pourquoi la page de connexion s’ouvre alors que la personne est connectée (%s)', async (locale, notice) => {
      await primeSession({ username: 'jane', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })
      const { wrapper } = await mountLoginPage(createStubRepository(), locale, { reauth: '1', redirect: CONSENT })

      expect(wrapper.get('[data-testid="login-reauth-notice"]').text()).toContain(notice)
    })

    it.each(['fr', 'en'])(
      'drapeau présent mais plus aucune session (expirée entre-temps) : aucune annonce de reconfirmation (%s)',
      async (locale) => {
        await primeSession(null)
        const { wrapper } = await mountLoginPage(createStubRepository(), locale, { reauth: '1', redirect: CONSENT })

        expect(wrapper.find('[data-testid="login-reauth-notice"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Confirmez votre identité')
        expect(wrapper.text()).not.toContain('Confirm your identity')
      },
    )

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

  describe('garde de la cible de retour (`redirect`) : un chemin interne, rien d\'autre (#650, F-I1)', () => {
    async function signInAndReadPath(redirect: string): Promise<{ fullPath: string; path: string }> {
      const { wrapper, router } = await mountLoginPage(createStubRepository(), 'fr', { redirect })

      await wrapper.get('#login-username').setValue('jane')
      await wrapper.get('#login-password').setValue('password123')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      return { fullPath: router.currentRoute.value.fullPath, path: router.currentRoute.value.path }
    }

    it.each([
      ['une barre oblique inverse que le navigateur lit comme //', '/\\evil.example/fr'],
      ['deux barres obliques inverses', '\\\\evil.example'],
      ['une tabulation que le navigateur retire (/\t/ devient //)', '/\t/evil.example'],
      ['un saut de ligne que le navigateur retire', '/\n/evil.example'],
      ['un retour chariot que le navigateur retire', '/\r/evil.example'],
      ['un caractère nul', '/fr/\u0000'],
      ['une origine absolue', 'https://evil.example/'],
      ['un schéma javascript', 'javascript:alert(1)'],
      ['un chemin sans barre oblique initiale', 'evil.example/fr'],
      ['deux barres obliques', '//evil.example/fr'],
    ])('retombe sur l\'accueil de la langue : %s', async (_label, redirect) => {
      const { path } = await signInAndReadPath(redirect)

      expect(path).toBe('/fr')
    })

    it.each([
      '/fr/oauth/consent?client_id=claude-code&state=a%26b#decision',
      '/fr/admin/users',
      '/fr/oauth/consent',
    ])('suit un chemin interne légitime, requête et ancre comprises : %s', async (redirect) => {
      const { fullPath } = await signInAndReadPath(redirect)

      expect(fullPath).toBe(redirect)
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
