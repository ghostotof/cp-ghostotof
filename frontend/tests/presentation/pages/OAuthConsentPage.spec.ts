import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type RouteLocationNormalized, type Router } from 'vue-router'
import OAuthConsentPage from '../../../src/presentation/pages/OAuthConsentPage.vue'
import { CLIENT_REDIRECT, OAUTH_CONSENT_REPOSITORY } from '../../../src/application/mcp/useOAuthConsent'
import { AUTH_REPOSITORY, authState, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { OAuthConsentRepository } from '../../../src/domain/mcp/repositories/OAuthConsentRepository'
import type { ConsentParams, ConsentRequest } from '../../../src/domain/mcp/entities/ConsentRequest'
import { InvalidConsentRequestError } from '../../../src/domain/mcp/errors/InvalidConsentRequestError'
import { SessionExpiredError } from '../../../src/domain/mcp/errors/SessionExpiredError'
import { ConsentFailedError } from '../../../src/domain/mcp/errors/ConsentFailedError'
import { createAppI18n, i18n as appI18n } from '../../../src/presentation/i18n'
import { router as appRouter } from '../../../src/presentation/router'
import { applySeoMeta } from '../../../src/presentation/router/seo'
import { SUPPORTED_LOCALES, type Locale } from '../../../src/domain/portfolio/entities/Locale'
import { sessionFor } from '../../support/authSession'
import { expectNoAccessibilityViolation } from '../../support/axe'

enableAutoUnmount(afterEach)

const StubPage = { template: '<div />' }

/** La requête que le point d'autorisation pose sur la page (#647), encodée comme dans une vraie URL. */
const QUERY =
  'response_type=code&client_id=claude-code&redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback' +
  '&scope=nominative%3Aread&state=xyz&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' +
  '&code_challenge_method=S256&resource=https%3A%2F%2Fsite.example.test%2Fapi%2Fmcp'

/** Ce que la page doit renvoyer : exactement la requête reçue, en camelCase. */
const PARAMS: ConsentParams = {
  responseType: 'code',
  clientId: 'claude-code',
  redirectUri: 'http://127.0.0.1:33418/callback',
  scope: 'nominative:read',
  state: 'xyz',
  codeChallenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  codeChallengeMethod: 'S256',
  resource: 'https://site.example.test/api/mcp',
}

/** Nom choisi pour être reconnaissable dans le texte, et distinct de l'identifiant. */
const SUMMARY: ConsentRequest = {
  client: { id: 'claude-code', name: 'Assistant Déclaré', registration: 'preregistered' },
  redirectHost: 'retour.client.example',
  scope: 'nominative:read',
  resource: 'https://site.example.test/api/mcp',
}

const APPROVED_URL = 'http://127.0.0.1:33418/callback?code=abc&state=xyz&iss=https%3A%2F%2Fsite.example.test%2Fapi%2Foauth'
const DENIED_URL = 'http://127.0.0.1:33418/callback?error=access_denied&state=xyz'

interface Harness {
  wrapper: VueWrapper
  router: Router
  describeRequest: ReturnType<typeof vi.fn>
  decide: ReturnType<typeof vi.fn>
  navigate: ReturnType<typeof vi.fn>
}

interface Options {
  locale?: Locale
  query?: string
  describeRequest?: ReturnType<typeof vi.fn>
  decide?: ReturnType<typeof vi.fn>
}

async function mountPage(options: Options = {}): Promise<Harness> {
  const locale = options.locale ?? 'fr'
  const describeRequest = options.describeRequest ?? vi.fn(async () => SUMMARY)
  const decide = options.decide ?? vi.fn(async (_params: ConsentParams, approved: boolean) => (approved ? APPROVED_URL : DENIED_URL))
  const navigate = vi.fn()
  const repository = { describe: describeRequest, decide } as unknown as OAuthConsentRepository

  const i18n = createAppI18n()
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/oauth/consent', name: 'oauth-consent', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push(`/${locale}/oauth/consent?${options.query ?? QUERY}`)
  await router.isReady()

  const wrapper = mount(OAuthConsentPage, {
    global: {
      plugins: [router, i18n],
      provide: {
        [OAUTH_CONSENT_REPOSITORY as symbol]: repository,
        [CLIENT_REDIRECT as symbol]: navigate,
      },
    },
  })
  await flushPromises()

  return { wrapper, router, describeRequest, decide, navigate }
}

function button(wrapper: VueWrapper, name: 'approve' | 'deny') {
  return wrapper.find(`[data-testid="consent-${name}"]`)
}

/** Une session de confiance, pour constater que la page la marque expirée. */
async function primeTrustedSession(): Promise<void> {
  const repository: AuthRepository = {
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(async () => sessionFor({ username: 'guest', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })),
  }
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

describe('OAuthConsentPage — résumé de la demande', () => {
  it('demande le résumé avec exactement la requête reçue', async () => {
    const { describeRequest } = await mountPage()

    expect(describeRequest).toHaveBeenCalledOnce()
    expect(describeRequest).toHaveBeenCalledWith(PARAMS)
  })

  it('en français : titre, nom déclaré par le client, enregistrement, hôte de retour, corpus et fournisseur d’IA', async () => {
    const { wrapper } = await mountPage()
    const text = wrapper.text()

    expect(wrapper.find('h1').text()).toBe('Autoriser une connexion MCP')
    expect(wrapper.find('[data-testid="consent-client-name"]').text()).toContain('Assistant Déclaré')
    expect(text).toContain('Nom déclaré par le client')
    expect(text).toContain('le site ne l’a pas vérifié')
    expect(text).toContain('enregistré à l’avance par l’administrateur du site')
    expect(wrapper.find('[data-testid="consent-redirect-host"]').text()).toBe('retour.client.example')
    expect(text).toContain('corpus nominatif')
    expect(text).toContain('en lecture seule')
    expect(text).toContain('fournisseur d’IA de ce client')
    expect(button(wrapper, 'approve').text()).toBe('Autoriser')
    expect(button(wrapper, 'deny').text()).toBe('Refuser')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('en anglais : mêmes informations', async () => {
    const { wrapper } = await mountPage({ locale: 'en' })
    const text = wrapper.text()

    expect(wrapper.find('h1').text()).toBe('Authorize an MCP connection')
    expect(wrapper.find('[data-testid="consent-client-name"]').text()).toContain('Assistant Déclaré')
    expect(text).toContain('Name declared by the client')
    expect(text).toContain('the site has not verified it')
    expect(text).toContain('registered in advance by the site’s administrator')
    expect(wrapper.find('[data-testid="consent-redirect-host"]').text()).toBe('retour.client.example')
    expect(text).toContain('nominative corpus')
    expect(text).toContain('read-only')
    expect(text).toContain('this client’s AI provider')
    expect(button(wrapper, 'approve').text()).toBe('Authorize')
    expect(button(wrapper, 'deny').text()).toBe('Deny')
  })

  it('rend un nom déclaré contenant du HTML en texte, jamais en éléments', async () => {
    const describeRequest = vi.fn(async () => ({
      ...SUMMARY,
      client: { ...SUMMARY.client, name: '<img src=x onerror=alert(1)>' },
    }))
    const { wrapper } = await mountPage({ describeRequest })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[data-testid="consent-client-name"]').text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('aucun bouton pendant le chargement du résumé', async () => {
    const { wrapper } = await mountPage({ describeRequest: vi.fn(() => new Promise<ConsentRequest>(() => {})) })

    expect(button(wrapper, 'approve').exists()).toBe(false)
    expect(button(wrapper, 'deny').exists()).toBe(false)
  })
})

describe('OAuthConsentPage — décision', () => {
  it('« Autoriser » : decide(requête reçue, true) puis navigation vers l’URL rendue', async () => {
    const { wrapper, decide, navigate } = await mountPage()

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(decide).toHaveBeenCalledOnce()
    expect(decide).toHaveBeenCalledWith(PARAMS, true)
    expect(navigate).toHaveBeenCalledOnce()
    expect(navigate).toHaveBeenCalledWith(APPROVED_URL)
  })

  it('« Refuser » : decide(requête reçue, false) puis navigation vers l’URL rendue', async () => {
    const { wrapper, decide, navigate } = await mountPage()

    await button(wrapper, 'deny').trigger('click')
    await flushPromises()

    expect(decide).toHaveBeenCalledWith(PARAMS, false)
    expect(navigate).toHaveBeenCalledWith(DENIED_URL)
  })

  it('pendant la décision : les deux boutons sont désactivés, un second clic n’envoie rien', async () => {
    const decide = vi.fn(() => new Promise<string>(() => {}))
    const { wrapper } = await mountPage({ decide })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'deny').attributes('disabled')).toBeDefined()
    await button(wrapper, 'deny').trigger('click')
    await button(wrapper, 'approve').trigger('click')
    expect(decide).toHaveBeenCalledOnce()
  })

  it('les boutons restent désactivés une fois la navigation lancée', async () => {
    const { wrapper, decide } = await mountPage()

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()
    await button(wrapper, 'approve').trigger('click')

    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    expect(decide).toHaveBeenCalledOnce()
  })

  it.each(['javascript:alert(1)', 'data:text/html,x', 'cursor://callback?code=a'])(
    'URL rendue « %s » : aucune navigation, message d’erreur',
    async (url) => {
      const { wrapper, navigate } = await mountPage({ decide: vi.fn(async () => url) })

      await button(wrapper, 'approve').trigger('click')
      await flushPromises()

      expect(navigate).not.toHaveBeenCalled()
      expect(wrapper.find('[role="alert"]').text()).toContain('n’est pas une adresse web')
      expect(button(wrapper, 'approve').exists()).toBe(false)
    },
  )

  it('échec générique de la décision : message, les boutons reviennent pour réessayer', async () => {
    const decide = vi.fn().mockRejectedValueOnce(new ConsentFailedError()).mockResolvedValueOnce(APPROVED_URL)
    const { wrapper, navigate } = await mountPage({ decide })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('Une erreur inattendue')
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()
    expect(navigate).toHaveBeenCalledWith(APPROVED_URL)
  })
})

describe('OAuthConsentPage — demande invalide', () => {
  it('400 au résumé : message « invalide ou expirée », aucun bouton', async () => {
    const { wrapper } = await mountPage({
      describeRequest: vi.fn(async () => {
        throw new InvalidConsentRequestError()
      }),
    })

    expect(wrapper.find('[role="alert"]').text()).toContain('invalide ou a expiré')
    expect(wrapper.find('[role="alert"]').text()).toContain('Recommencez depuis votre assistant')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('400 en anglais', async () => {
    const { wrapper } = await mountPage({
      locale: 'en',
      describeRequest: vi.fn(async () => {
        throw new InvalidConsentRequestError()
      }),
    })

    expect(wrapper.find('[role="alert"]').text()).toContain('invalid or has expired')
  })

  it('400 à la décision : même message, plus aucun bouton', async () => {
    const { wrapper, navigate } = await mountPage({
      decide: vi.fn(async () => {
        throw new InvalidConsentRequestError()
      }),
    })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('invalide ou a expiré')
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(navigate).not.toHaveBeenCalled()
  })

  it('paramètre répété dans la requête : demande invalide, aucun appel au serveur', async () => {
    const { wrapper, describeRequest } = await mountPage({ query: `${QUERY}&state=autre` })

    expect(describeRequest).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').text()).toContain('invalide ou a expiré')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})

describe('OAuthConsentPage — session', () => {
  it('401/403 au résumé : session marquée expirée, message et lien de reconnexion qui garde la requête', async () => {
    await primeTrustedSession()
    expect(authState.user).not.toBeNull()

    const { wrapper, router } = await mountPage({
      describeRequest: vi.fn(async () => {
        throw new SessionExpiredError()
      }),
    })

    expect(authState.user).toBeNull()
    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toContain('session')
    const href = alert.find('a').attributes('href') ?? ''
    const target = router.resolve(href)
    expect(target.name).toBe('login')
    const redirect = router.resolve(String(target.query.redirect))
    expect(redirect.name).toBe('oauth-consent')
    expect(redirect.query).toEqual(router.currentRoute.value.query)
    expect(button(wrapper, 'approve').exists()).toBe(false)
  })

  it('401/403 à la décision : session marquée expirée, aucune navigation', async () => {
    await primeTrustedSession()
    const { wrapper, navigate } = await mountPage({
      decide: vi.fn(async () => {
        throw new SessionExpiredError()
      }),
    })

    await button(wrapper, 'deny').trigger('click')
    await flushPromises()

    expect(authState.user).toBeNull()
    expect(navigate).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('échec générique au résumé : message, aucun bouton', async () => {
    const { wrapper } = await mountPage({
      describeRequest: vi.fn(async () => {
        throw new ConsentFailedError()
      }),
    })

    expect(wrapper.find('[role="alert"]').text()).toContain('Une erreur inattendue')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})

describe('OAuthConsentPage — accessibilité', () => {
  it.each(SUPPORTED_LOCALES)('aucune violation détectable, demande affichée (%s)', async (locale) => {
    const { wrapper } = await mountPage({ locale })

    await expectNoAccessibilityViolation(wrapper)
  })

  it('aucune violation détectable, demande invalide', async () => {
    const { wrapper } = await mountPage({
      describeRequest: vi.fn(async () => {
        throw new InvalidConsentRequestError()
      }),
    })

    await expectNoAccessibilityViolation(wrapper)
  })
})

/** Même dispositif que ConnectAssistantPage.spec.ts : la vraie route, la vraie applySeoMeta. */
describe('OAuthConsentPage — SEO', () => {
  const description = () => document.querySelector('meta[name="description"]')?.getAttribute('content')
  const robots = () => document.querySelector('meta[name="robots"]')?.getAttribute('content')

  function applySeoFor(path: string): void {
    const route = appRouter.resolve(path)
    appI18n.global.locale.value = route.params.locale as Locale
    applySeoMeta(route as RouteLocationNormalized)
  }

  afterEach(() => {
    appI18n.global.locale.value = 'fr'
    document.head.querySelectorAll('meta, link').forEach((node) => node.remove())
    document.title = ''
  })

  it('route nommée, réservée au palier de confiance, non indexée, titre en français', () => {
    applySeoFor(`/fr/oauth/consent?${QUERY}`)

    expect(appRouter.resolve('/fr/oauth/consent').name).toBe('oauth-consent')
    expect(document.title).toBe('CP-Ghostotof — Autoriser une connexion MCP')
    expect(description()).toBeTruthy()
    expect(robots()).toBe('noindex, nofollow')
  })

  it('titre en anglais, non indexée', () => {
    applySeoFor('/en/oauth/consent')

    expect(document.title).toBe('CP-Ghostotof — Authorize an MCP connection')
    expect(robots()).toBe('noindex, nofollow')
  })

  it('le canonical ne recopie pas la requête (state, défi PKCE)', () => {
    applySeoFor(`/fr/oauth/consent?${QUERY}`)

    const hrefs = Array.from(document.head.querySelectorAll('link')).map((link) => link.getAttribute('href') ?? '')
    expect(hrefs.length).toBeGreaterThan(0)
    for (const href of hrefs) {
      expect(href).not.toContain('code_challenge')
      expect(href).not.toContain('state')
    }
  })
})
