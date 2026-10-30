import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { AUTH_REPOSITORY, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import type { AuthSession } from '../../../src/domain/auth/entities/AuthSession'
import { sessionFor } from '../../support/authSession'
import { BASE_ACCESS_SESSION } from '../../../src/domain/auth/entities/AuthSession'
import { createAppI18n } from '../../../src/presentation/i18n'
import { HttpAuthRepository, ME_TIMEOUT_MS } from '../../../src/infrastructure/auth/HttpAuthRepository'

/**
 * Exerce le routeur singleton (presentation/router/index.ts) plutôt qu'une
 * instance locale : le garde `requiresAuth`/`roles` protégeant /admin est
 * enregistré directement sur ce routeur via router.beforeEach(), il n'existe
 * pas ailleurs pour pouvoir le rejouer sur un routeur reconstruit.
 */

function createStubRepository(user: AuthenticatedUser | null): AuthRepository {
  return {
    login: vi.fn(async () => user ?? { username: 'jane', roles: ['ROLE_USER'] }),
    logout: vi.fn(async () => undefined),
    hasWriteToken: () => true,
    me: vi.fn(async () => sessionFor(user)),
  }
}

async function primeAuthState(user: AuthenticatedUser | null): Promise<void> {
  const repository = createStubRepository(user)
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

describe('router — garde /admin (ROLE_SUPER)', () => {
  beforeEach(async () => {
    await router.push('/fr')
    await router.isReady()
  })

  it('anonyme : redirige vers /login avec le chemin d\'origine en paramètre redirect', async () => {
    await primeAuthState(null)

    await router.push('/fr/admin/technologies')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/admin/technologies')
  })

  it('authentifié sans ROLE_SUPER : redirige vers /forbidden', async () => {
    await primeAuthState({ username: 'jane', roles: ['ROLE_USER'] })

    await router.push('/fr/admin/technologies')

    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('authentifié ROLE_SUPER : accède à la page demandée', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })

    await router.push('/fr/admin/technologies')

    expect(router.currentRoute.value.name).toBe('admin-technologies')
  })

  it('authentifié ROLE_SUPER : /admin redirige vers la première section', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })

    await router.push('/fr/admin')

    expect(router.currentRoute.value.name).toBe('admin-technologies')
  })

  it('route publique set-password : accessible sans authentification, le jeton dans le fragment (audit A7)', async () => {
    await primeAuthState(null)

    await router.push('/en/set-password#deadbeef')

    expect(router.currentRoute.value.name).toBe('set-password')
    expect(router.currentRoute.value.params.token).toBeUndefined()
    expect(router.currentRoute.value.hash).toBe('#deadbeef')
    expect(router.currentRoute.value.meta.noindex).toBe(true)
    expect(router.currentRoute.value.meta.requiresAuth).toBeUndefined()
  })

  it("un ancien lien à segment (`set-password/<jeton>`) n'est plus une route : 404 (T4.4, repli retiré)", async () => {
    await primeAuthState(null)

    await router.push('/fr/set-password/deadbeefcafe')

    // Le segment est retiré du routeur : un secret ne peut plus arriver par le
    // chemin, donc plus jamais dans un access log ni dans canonical/hreflang.
    expect(router.currentRoute.value.name).toBe('not-found')
  })

  it('route publique set-password : le canonical et les hreflang du vrai routeur ne portent jamais le jeton du fragment', async () => {
    await primeAuthState(null)

    await router.push('/fr/set-password#deadbeefcafe')

    const hrefs = Array.from(document.head.querySelectorAll('link')).map((link) => link.getAttribute('href') ?? '')
    expect(hrefs.length).toBeGreaterThan(0)
    for (const href of hrefs) {
      expect(href).not.toContain('deadbeefcafe')
    }
    expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(`${window.location.origin}/fr/set-password`)
  })

  it('attend la résolution de checkAuth() avant de trancher (évite une redirection prématurée au rechargement de page)', async () => {
    let resolveMe: (session: AuthSession) => void = () => {}
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(() => new Promise<AuthSession>((resolve) => (resolveMe = resolve))),
    }
    const Probe = defineComponent({
      setup() {
        return { auth: useAuth() }
      },
      template: '<div />',
    })
    const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })

    const checkAuthPromise = wrapper.vm.auth.checkAuth()
    const pushPromise = router.push('/fr/admin/technologies')

    resolveMe(sessionFor({ username: 'super', roles: ['ROLE_SUPER'] }))
    await checkAuthPromise
    await pushPromise

    expect(router.currentRoute.value.name).toBe('admin-technologies')
    wrapper.unmount()
  })
})

describe('router — garde /assistant (ROLE_TRUSTED ou ROLE_SUPER)', () => {
  beforeEach(async () => {
    await router.push('/fr')
    await router.isReady()
  })

  it('anonyme : redirige vers /login avec redirect=/fr/assistant', async () => {
    await primeAuthState(null)

    await router.push('/fr/assistant')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/assistant')
  })

  it('palier de base sans compte : redirige vers /login (décision du 2026-10-02)', async () => {
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(async () => BASE_ACCESS_SESSION),
    }
    const Probe = defineComponent({
      setup() {
        return { auth: useAuth() }
      },
      template: '<div />',
    })
    const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
    await wrapper.vm.auth.checkAuth()
    wrapper.unmount()

    await router.push('/fr/assistant')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/assistant')
  })

  it('compte ROLE_USER seul : redirige vers /forbidden', async () => {
    await primeAuthState({ username: 'jane', roles: ['ROLE_USER'] })

    await router.push('/fr/assistant')

    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('ROLE_TRUSTED : accède à la page', async () => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push('/fr/assistant')

    expect(router.currentRoute.value.name).toBe('assistant')
    expect(router.currentRoute.value.meta.noindex).toBe(true)
  })

  it('ROLE_SUPER sans ROLE_TRUSTED en clair : accède à la page (un rôle suffit)', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER'] })

    await router.push('/en/assistant')

    expect(router.currentRoute.value.name).toBe('assistant')
  })

  it('attend la résolution de checkAuth() avant de trancher', async () => {
    let resolveMe: (session: AuthSession) => void = () => {}
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(() => new Promise<AuthSession>((resolve) => (resolveMe = resolve))),
    }
    const Probe = defineComponent({
      setup() {
        return { auth: useAuth() }
      },
      template: '<div />',
    })
    const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })

    const checkAuthPromise = wrapper.vm.auth.checkAuth()
    const pushPromise = router.push('/fr/assistant')

    resolveMe(sessionFor({ username: 'guest', roles: ['ROLE_TRUSTED'] }))
    await checkAuthPromise
    await pushPromise

    expect(router.currentRoute.value.name).toBe('assistant')
    wrapper.unmount()
  })
})

describe('router — page de connexion d’une personne déjà connectée', () => {
  const CONSENT = '/fr/oauth/consent?client_id=claude-code&state=a%26b'

  beforeEach(async () => {
    await router.push('/fr')
    await router.isReady()
  })

  it('sans drapeau : renvoyée à l’accueil, la page de connexion n’a rien à lui offrir', async () => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push({ name: 'login', params: { locale: 'fr' }, query: { redirect: CONSENT } })

    expect(router.currentRoute.value.path).toBe('/fr')
  })

  it('`reauth=1` (le consentement MCP exige une authentification récente, #647) : la page de connexion s’ouvre, la requête intacte', async () => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push({ name: 'login', params: { locale: 'fr' }, query: { reauth: '1', redirect: CONSENT } })

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe(CONSENT)
  })

  it("connectée, `expired=1` : la page de connexion s'ouvre (B/F2), la requête intacte", async () => {
    // Un `BEARER` vivant sans `XSRF-TOKEN` : le bandeau dit « session finie », son lien (`expired=1`) ne doit pas
    // renvoyer à l'accueil une personne dont `authState.user` est encore posé. Le drapeau n'ouvre que la page.
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push({ name: 'login', params: { locale: 'fr' }, query: { expired: '1', redirect: CONSENT } })

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe(CONSENT)
  })

  it.each(['true', '0', ''])('une autre valeur que `expired=1` (`expired=%s`) ne compte pas', async (value) => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push({ name: 'login', params: { locale: 'fr' }, query: { expired: value } })

    expect(router.currentRoute.value.path).toBe('/fr')
  })

  it.each(['true', '0', ''])('une autre valeur que `1` (`reauth=%s`) ne compte pas', async (value) => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push({ name: 'login', params: { locale: 'fr' }, query: { reauth: value } })

    expect(router.currentRoute.value.path).toBe('/fr')
  })
})

describe('router — garde /oauth/consent (ROLE_TRUSTED ou ROLE_SUPER, #647)', () => {
  /** La requête posée par le point d'autorisation : elle doit survivre au détour par la connexion. */
  const CONSENT_PATH =
    '/fr/oauth/consent?response_type=code&client_id=claude-code' +
    '&redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback&scope=nominative%3Aread&state=a%26b%3Dc' +
    '&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256' +
    '&resource=https%3A%2F%2Fsite.example.test%2Fapi%2Fmcp'

  beforeEach(async () => {
    await router.push('/fr')
    await router.isReady()
  })

  it('anonyme : redirige vers /login, le redirect garde toute la requête de la demande', async () => {
    await primeAuthState(null)

    await router.push(CONSENT_PATH)

    expect(router.currentRoute.value.name).toBe('login')
    const redirect = router.currentRoute.value.query.redirect
    expect(typeof redirect).toBe('string')
    // Le chemin que LoginPage acceptera (un seul `/` en tête), et qui ramène à
    // la même demande, paramètre par paramètre — `state` compris, avec ses `&` et `=`.
    expect(String(redirect).startsWith('/') && !String(redirect).startsWith('//')).toBe(true)
    const back = router.resolve(String(redirect))
    expect(back.name).toBe('oauth-consent')
    expect(back.query).toEqual(router.resolve(CONSENT_PATH).query)
    expect(back.query.state).toBe('a&b=c')
  })

  it('compte ROLE_USER seul : redirige vers /forbidden', async () => {
    await primeAuthState({ username: 'jane', roles: ['ROLE_USER'] })

    await router.push(CONSENT_PATH)

    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('palier de base sans compte : redirige vers /login', async () => {
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(async () => BASE_ACCESS_SESSION),
    }
    const Probe = defineComponent({
      setup() {
        return { auth: useAuth() }
      },
      template: '<div />',
    })
    const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
    await wrapper.vm.auth.checkAuth()
    wrapper.unmount()

    await router.push(CONSENT_PATH)

    expect(router.currentRoute.value.name).toBe('login')
  })

  it('ROLE_TRUSTED : accède à la page, non indexée', async () => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push(CONSENT_PATH)

    expect(router.currentRoute.value.name).toBe('oauth-consent')
    expect(router.currentRoute.value.meta.noindex).toBe(true)
    expect(router.currentRoute.value.meta.requiresAuth).toBe(true)
  })

  it('ROLE_SUPER sans ROLE_TRUSTED en clair : accède à la page', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER'] })

    await router.push(CONSENT_PATH.replace('/fr/', '/en/'))

    expect(router.currentRoute.value.name).toBe('oauth-consent')
  })
})

describe('router — garde /mcp-connections (ROLE_TRUSTED ou ROLE_SUPER, #650)', () => {
  beforeEach(async () => {
    await router.push('/fr')
    await router.isReady()
  })

  it('anonyme : redirige vers /login avec redirect=/fr/mcp-connections', async () => {
    await primeAuthState(null)

    await router.push('/fr/mcp-connections')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/mcp-connections')
  })

  it('palier de base sans compte : redirige vers /login', async () => {
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(async () => BASE_ACCESS_SESSION),
    }
    const Probe = defineComponent({
      setup() {
        return { auth: useAuth() }
      },
      template: '<div />',
    })
    const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
    await wrapper.vm.auth.checkAuth()
    wrapper.unmount()

    await router.push('/fr/mcp-connections')

    expect(router.currentRoute.value.name).toBe('login')
  })

  it('compte ROLE_USER seul : redirige vers /forbidden', async () => {
    await primeAuthState({ username: 'jane', roles: ['ROLE_USER'] })

    await router.push('/fr/mcp-connections')

    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('ROLE_TRUSTED : accède à la page, non indexée', async () => {
    await primeAuthState({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })

    await router.push('/fr/mcp-connections')

    expect(router.currentRoute.value.name).toBe('mcp-connections')
    expect(router.currentRoute.value.meta.noindex).toBe(true)
    expect(router.currentRoute.value.meta.requiresAuth).toBe(true)
    expect(router.currentRoute.value.meta.titleKey).toBe('seo.mcpConnections.title')
    expect(router.currentRoute.value.meta.descriptionKey).toBe('seo.mcpConnections.description')
  })

  it('ROLE_SUPER sans ROLE_TRUSTED en clair : accède à la page (un rôle suffit)', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER'] })

    await router.push('/en/mcp-connections')

    expect(router.currentRoute.value.name).toBe('mcp-connections')
  })
})

describe('router — la page de connexion attend la première vérification (#748, R40, Sp4-1/C2)', () => {
  /**
   * Un onglet neuf : `main.ts` monte l'application puis lance `checkAuth()` ; la route `login` est ouverte pendant que
   * `/api/me` est en vol (`isChecking` vrai, remis à cet état par `tests/support/setup.ts`), `authState.user` est encore nul.
   */
  function deferredRepository(): { repository: AuthRepository; answer: (session: AuthSession) => void } {
    let answer: (session: AuthSession) => void = () => {}
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      hasWriteToken: () => true,
      me: vi.fn(() => new Promise<AuthSession>((resolve) => (answer = resolve))),
    }
    return { repository, answer: (session) => answer(session) }
  }

  function mountProbe(repository: AuthRepository) {
    return mount(
      defineComponent({
        setup() {
          return { auth: useAuth() }
        },
        template: '<div />',
      }),
      { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } },
    )
  }

  /** L'application telle que `App.vue` la rend : le routeur de production et sa page, sur une page neutre au départ. */
  async function mountApplication(repository: AuthRepository) {
    // La page de connexion est chargée à la demande : la charger d'avance, pour que « elle n'apparaît pas » soit
    // une attente du garde et non celle d'un `import()` en cours.
    await import('../../../src/presentation/pages/LoginPage.vue')
    await router.push('/fr/forbidden')
    await router.isReady()
    return mount(defineComponent({ template: '<RouterView />' }), {
      global: { plugins: [router, createAppI18n()], provide: { [AUTH_REPOSITORY as symbol]: repository } },
    })
  }

  it("onglet neuf, expired=1, /api/me résolu après le montage avec un compte : « session finie » n'apparaît jamais", async () => {
    const { repository, answer } = deferredRepository()
    const wrapper = await mountApplication(repository)
    const probe = mountProbe(repository)

    const pushed = router.push('/fr/login?expired=1')
    await flushPromises()

    // Pendant la vérification : la route n'est pas ouverte, ni l'une ni l'autre des annonces n'est rendue.
    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="login-reauth-notice"]').exists()).toBe(false)

    const checked = probe.vm.auth.checkAuth()
    answer(sessionFor({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] }))
    await checked
    await pushed
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(wrapper.find('[data-testid="login-reauth-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(false)
  })

  it('… avec l’anonyme : « session finie » après la vérification', async () => {
    const { repository, answer } = deferredRepository()
    const wrapper = await mountApplication(repository)
    const probe = mountProbe(repository)

    const pushed = router.push('/fr/login?expired=1')
    await flushPromises()
    expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(false)

    const checked = probe.vm.auth.checkAuth()
    answer(sessionFor(null))
    await checked
    await pushed
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="login-reauth-notice"]').exists()).toBe(false)
  })

  describe('un /api/me qui ne répond pas (#748, R49, CR5-7)', () => {
    afterEach(() => {
      // Les faux minuteurs ne survivent pas à leur test.
      vi.useRealTimers()
      vi.unstubAllGlobals()
    })

    it("onglet neuf, /api/me qui ne répond pas : la connexion s'ouvre après le délai, sans annonce avant", async () => {
      // Le vrai dépôt HTTP : la borne est la sienne. Un `fetch` qui ne répond jamais et obéit à son signal.
      vi.stubGlobal(
        'fetch',
        vi.fn(
          (_url: string, init?: RequestInit) =>
            new Promise<Response>((_resolve, reject) => {
              init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
            }),
        ),
      )
      const repository = new HttpAuthRepository('https://api.example.test')
      const wrapper = await mountApplication(repository)
      const probe = mountProbe(repository)
      // Seules les minuteries de la borne sont fausses : le routeur et `flushPromises` restent sur les vraies.
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

      const checked = probe.vm.auth.checkAuth()
      const pushed = router.push('/fr/login?expired=1')
      await flushPromises()

      // Avant le délai : la route n'est pas ouverte, aucune annonce n'est rendue.
      await vi.advanceTimersByTimeAsync(ME_TIMEOUT_MS - 1)
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('forbidden')
      expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(false)

      await vi.advanceTimersByTimeAsync(1)
      await checked
      await pushed
      await flushPromises()

      // Après le délai : la première vérification a fini (anonyme), la page s'ouvre et dit pourquoi.
      expect(router.currentRoute.value.name).toBe('login')
      expect(wrapper.find('[data-testid="login-session-ended-notice"]').exists()).toBe(true)
    })
  })

  it('une personne connectée qui ouvre /login sans drapeau dans un onglet neuf va à l’accueil', async () => {
    const { repository, answer } = deferredRepository()
    await router.push('/fr/forbidden')
    await router.isReady()
    const probe = mountProbe(repository)

    const pushed = router.push('/fr/login')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('forbidden')

    const checked = probe.vm.auth.checkAuth()
    answer(sessionFor({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] }))
    await checked
    await pushed
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/fr')
  })
})
