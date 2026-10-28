import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick } from 'vue'
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
import { MalformedConsentDecisionError } from '../../../src/domain/mcp/errors/MalformedConsentDecisionError'
import { ReauthenticationRequiredError } from '../../../src/domain/mcp/errors/ReauthenticationRequiredError'
import { ACTIVATION_DELAY_MS } from '../../../src/presentation/ui/useDeliberateActivation'
import { createAppI18n, i18n as appI18n } from '../../../src/presentation/i18n'
import { router as appRouter } from '../../../src/presentation/router'
import { applySeoMeta } from '../../../src/presentation/router/seo'
import { SUPPORTED_LOCALES, type Locale } from '../../../src/domain/portfolio/entities/Locale'
import { sessionFor } from '../../support/authSession'
import { expectNoAccessibilityViolation } from '../../support/axe'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

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

/** Nom choisi pour être reconnaissable dans le texte, et distinct de l'identifiant ; l'hôte, celui des URL rendues. */
const SUMMARY: ConsentRequest = {
  client: { id: 'claude-code', name: 'Assistant Déclaré', registration: 'preregistered' },
  redirectHost: '127.0.0.1',
  scope: 'nominative:read',
  resource: 'https://site.example.test/api/mcp',
  // Un compte fictif, distinct de tout identifiant du dépôt.
  account: { username: 'trusted' },
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
  /**
   * Faux : la page n'a pas encore été visible et active le délai voulu, les
   * boutons restent inactifs (protection contre le double-clickjacking). Vrai
   * par défaut : le harnais fait passer le délai, la page a le focus.
   */
  arm?: boolean
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

  // jsdom n'a le focus que si un élément l'a reçu : la page, ici, l'a. Les
  // minuteries seules sont simulées (setImmediate reste réel pour flushPromises).
  vi.spyOn(document, 'hasFocus').mockReturnValue(true)
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

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

  if (false !== options.arm) {
    await passActivationDelay()
  }

  return { wrapper, router, describeRequest, decide, navigate }
}

async function passActivationDelay(): Promise<void> {
  vi.advanceTimersByTime(ACTIVATION_DELAY_MS)
  await nextTick()
}

/**
 * Un clic de pointeur (`detail` 1, comme un navigateur le compte), envoyé à la
 * main : `trigger()` ne sait pas poser `detail`, en lecture seule sur un
 * UIEvent construit.
 */
async function pointerClick(wrapper: VueWrapper, name: 'approve' | 'deny'): Promise<void> {
  button(wrapper, name).element.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 1 }))
  await nextTick()
}

/**
 * Un appui de pointeur, tel que la page le note (en capture, sur le document) :
 * le composant est monté détaché, un événement parti du bouton n'atteindrait
 * pas le document.
 */
function pointerDown(): void {
  document.dispatchEvent(new Event('pointerdown'))
}

/** Un clic au clavier (Entrée, Espace) : `detail` 0, aucun appui de pointeur. */
async function keyboardClick(wrapper: VueWrapper, name: 'approve' | 'deny'): Promise<void> {
  button(wrapper, name).element.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 0 }))
  await nextTick()
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

  it('en français : titre, nom enregistré par l’administrateur, enregistrement, hôte de retour, corpus et fournisseur d’IA', async () => {
    const { wrapper } = await mountPage()
    const text = wrapper.text()

    expect(wrapper.find('h1').text()).toBe('Autoriser une connexion MCP')
    expect(wrapper.find('[data-testid="consent-client-name"]').text()).toContain('Assistant Déclaré')
    expect(text).toContain('Nom du client')
    expect(text).toContain("Le nom sous lequel l'administrateur du site a enregistré ce client")
    // Un client pré-enregistré ne s'est pas nommé lui-même : rien ne le dit « déclaré ».
    expect(text).not.toContain('Nom déclaré')
    expect(text).not.toContain('fourni par le client')
    expect(text).not.toContain('pas vérifié')
    expect(text).toContain("enregistré à l'avance par l'administrateur du site")
    expect(wrapper.find('[data-testid="consent-redirect-host"]').text()).toBe('127.0.0.1')
    expect(text).toContain('corpus nominatif')
    expect(text).toContain('en lecture seule')
    expect(text).toContain("fournisseur d'IA de ce client")
    expect(button(wrapper, 'approve').text()).toBe('Autoriser')
    expect(button(wrapper, 'deny').text()).toBe('Refuser')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('en anglais : mêmes informations', async () => {
    const { wrapper } = await mountPage({ locale: 'en' })
    const text = wrapper.text()

    expect(wrapper.find('h1').text()).toBe('Authorize an MCP connection')
    expect(wrapper.find('[data-testid="consent-client-name"]').text()).toContain('Assistant Déclaré')
    expect(text).toContain('Client name')
    expect(text).toContain('The name under which the site’s administrator registered this client')
    expect(text).not.toContain('Name declared')
    expect(text).not.toContain('supplied by the client')
    expect(text).not.toContain('not verified')
    expect(text).toContain('registered in advance by the site’s administrator')
    expect(wrapper.find('[data-testid="consent-redirect-host"]').text()).toBe('127.0.0.1')
    expect(text).toContain('nominative corpus')
    expect(text).toContain('read-only')
    expect(text).toContain('this client’s AI provider')
    expect(button(wrapper, 'approve').text()).toBe('Authorize')
    expect(button(wrapper, 'deny').text()).toBe('Deny')
  })

  it('rend un nom contenant du HTML en texte, jamais en éléments', async () => {
    const describeRequest = vi.fn(async () => ({
      ...SUMMARY,
      client: { ...SUMMARY.client, name: '<img src=x onerror=alert(1)>' },
    }))
    const { wrapper } = await mountPage({ describeRequest })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[data-testid="consent-client-name"]').text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('dit quel compte accorde, en français, avec un lien « Changer de compte » vers la connexion', async () => {
    const { wrapper, router } = await mountPage()

    expect(wrapper.find('[data-testid="consent-account"]').text()).toBe('Vous accordez cet accès en tant que trusted')
    const link = wrapper.find('[data-testid="consent-switch-account"]')
    expect(link.text()).toBe('Changer de compte')
    // Hors de la zone des boutons de décision : un clic sur ce lien n'est pas une décision.
    expect(link.element.closest('[data-testid="consent-decision"]')).toBeNull()
    const target = router.resolve(link.attributes('href') ?? '')
    expect(target.name).toBe('login')
    expect(target.params.locale).toBe('fr')
    // Un retour à la même demande (toute la requête), et le drapeau qui ouvre la connexion à une personne déjà connectée.
    expect(target.query.reauth).toBe('1')
    const back = router.resolve(String(target.query.redirect))
    expect(back.name).toBe('oauth-consent')
    expect(back.query).toEqual(router.currentRoute.value.query)
  })

  it('en anglais : le compte et « Switch account »', async () => {
    const { wrapper, router } = await mountPage({ locale: 'en' })

    expect(wrapper.find('[data-testid="consent-account"]').text()).toBe('You are granting this access as trusted')
    const link = wrapper.find('[data-testid="consent-switch-account"]')
    expect(link.text()).toBe('Switch account')
    expect(router.resolve(link.attributes('href') ?? '').params.locale).toBe('en')
  })

  it('rend l’identifiant du compte en texte, jamais en éléments', async () => {
    const describeRequest = vi.fn(async () => ({ ...SUMMARY, account: { username: '<img src=x onerror=alert(1)>' } }))
    const { wrapper } = await mountPage({ describeRequest })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[data-testid="consent-account"]').text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('aucun compte affiché pendant le chargement du résumé', async () => {
    const { wrapper } = await mountPage({ describeRequest: vi.fn(() => new Promise<ConsentRequest>(() => {})) })

    expect(wrapper.find('[data-testid="consent-account"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="consent-switch-account"]').exists()).toBe(false)
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
      expect(wrapper.find('[role="alert"]').text()).toContain("n'est pas une adresse web de l'hôte annoncé")
      expect(button(wrapper, 'approve').exists()).toBe(false)
    },
  )

  it('URL rendue en https vers un autre hôte que celui annoncé : aucune navigation, message d’erreur', async () => {
    const { wrapper, navigate } = await mountPage({ decide: vi.fn(async () => 'https://evil.example/callback?code=abc') })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(navigate).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').text()).toContain("n'est pas une adresse web de l'hôte annoncé")
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('422 à la décision : message terminal, plus aucun bouton, aucun nouvel envoi', async () => {
    const decide = vi.fn(async () => {
      throw new MalformedConsentDecisionError()
    })
    const { wrapper, navigate } = await mountPage({ decide })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain("La page n'a pas pu transmettre votre décision")
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(decide).toHaveBeenCalledOnce()
    expect(navigate).not.toHaveBeenCalled()
  })

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

  it('401/403 en anglais : lien « Sign in again »', async () => {
    const { wrapper } = await mountPage({
      locale: 'en',
      describeRequest: vi.fn(async () => {
        throw new SessionExpiredError()
      }),
    })

    expect(wrapper.find('[role="alert"] a').text()).toBe('Sign in again')
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

describe('OAuthConsentPage — authentification récente (#647, F1)', () => {
  it('401 « authentification trop ancienne » à l’accord : message, lien de reconnexion qui revient à la même demande', async () => {
    await primeTrustedSession()
    const { wrapper, router, navigate } = await mountPage({
      decide: vi.fn(async () => {
        throw new ReauthenticationRequiredError()
      }),
    })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    // La session vaut toujours pour le reste du site : elle n'est pas marquée expirée.
    expect(authState.user).not.toBeNull()
    expect(navigate).not.toHaveBeenCalled()
    const alert = wrapper.find('[role="alert"]')
    expect(alert.text()).toContain('reconnectez-vous')
    const link = alert.find('a')
    expect(link.text()).toBe('Me reconnecter pour autoriser')
    const target = router.resolve(link.attributes('href') ?? '')
    expect(target.name).toBe('login')
    // Le drapeau que la route de connexion honore pour une personne déjà connectée.
    expect(target.query.reauth).toBe('1')
    const back = router.resolve(String(target.query.redirect))
    expect(back.name).toBe('oauth-consent')
    expect(back.query).toEqual(router.currentRoute.value.query)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('en anglais', async () => {
    const { wrapper } = await mountPage({
      locale: 'en',
      decide: vi.fn(async () => {
        throw new ReauthenticationRequiredError()
      }),
    })

    await button(wrapper, 'approve').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('sign in again')
    expect(wrapper.find('[role="alert"] a').text()).toBe('Sign in again to authorize')
  })
})

describe('OAuthConsentPage — clic délibéré (double-clickjacking)', () => {
  it('résumé reçu après le délai : les boutons apparaissent inactifs, le délai court à leur apparition', async () => {
    // Le résumé arrive 700 ms après le montage, fenêtre déjà active : un délai
    // compté depuis le montage serait écoulé à l'apparition des boutons.
    const describeRequest = vi.fn(() => new Promise<ConsentRequest>((resolve) => setTimeout(() => resolve(SUMMARY), ACTIVATION_DELAY_MS + 100)))
    const { wrapper, decide } = await mountPage({ arm: false, describeRequest })

    vi.advanceTimersByTime(ACTIVATION_DELAY_MS + 100)
    await flushPromises()

    expect(button(wrapper, 'approve').exists()).toBe(true)
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'deny').attributes('disabled')).toBeDefined()

    // Un clic juste après l'apparition (le second clic d'un double-clic) : ignoré.
    pointerDown()
    await pointerClick(wrapper, 'approve')
    await flushPromises()
    expect(decide).not.toHaveBeenCalled()

    vi.advanceTimersByTime(ACTIVATION_DELAY_MS - 1)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    vi.advanceTimersByTime(1)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()
    expect(decide).not.toHaveBeenCalled()
  })

  it(`les boutons ne s’activent qu’après ${ACTIVATION_DELAY_MS} ms de page visible et active`, async () => {
    const { wrapper } = await mountPage({ arm: false })

    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'deny').attributes('disabled')).toBeDefined()

    vi.advanceTimersByTime(ACTIVATION_DELAY_MS - 1)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()

    vi.advanceTimersByTime(1)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()
    expect(button(wrapper, 'deny').attributes('disabled')).toBeUndefined()
  })

  it('page cachée : rien ne s’active ; le délai court de nouveau quand elle redevient visible', async () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    const { wrapper } = await mountPage({ arm: false })

    vi.advanceTimersByTime(ACTIVATION_DELAY_MS * 2)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()

    visibility.mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    vi.advanceTimersByTime(ACTIVATION_DELAY_MS - 1)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    vi.advanceTimersByTime(1)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()
  })

  it('page sans le focus : rien ne s’active avant qu’elle le reçoive, puis le délai', async () => {
    const focus = vi.spyOn(document, 'hasFocus')
    const { wrapper } = await mountPage({ arm: false })
    // La fenêtre perd le focus avant la fin du délai (une autre fenêtre passe devant).
    focus.mockReturnValue(false)
    window.dispatchEvent(new Event('blur'))
    vi.advanceTimersByTime(ACTIVATION_DELAY_MS * 2)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()

    focus.mockReturnValue(true)
    window.dispatchEvent(new Event('focus'))
    vi.advanceTimersByTime(ACTIVATION_DELAY_MS)
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()
  })

  it('la page perd le focus une fois active : les boutons se désactivent, le délai repart au retour', async () => {
    const { wrapper } = await mountPage()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()

    vi.spyOn(document, 'hasFocus').mockReturnValue(false)
    window.dispatchEvent(new Event('blur'))
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()

    vi.spyOn(document, 'hasFocus').mockReturnValue(true)
    window.dispatchEvent(new Event('focus'))
    await nextTick()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeDefined()
    await passActivationDelay()
    expect(button(wrapper, 'approve').attributes('disabled')).toBeUndefined()
  })

  it('un clic dont l’appui a commencé avant l’activation est ignoré ; un appui suivant passe', async () => {
    const { wrapper, decide } = await mountPage({ arm: false })

    // L'appui arrive pendant le délai (le second clic d'un double-clic posé sur une autre fenêtre)…
    pointerDown()
    await passActivationDelay()
    // … et son relâchement, une fois les boutons actifs : ignoré.
    await pointerClick(wrapper, 'approve')
    await flushPromises()
    expect(decide).not.toHaveBeenCalled()

    pointerDown()
    await pointerClick(wrapper, 'approve')
    await flushPromises()
    expect(decide).toHaveBeenCalledOnce()
    expect(decide).toHaveBeenCalledWith(PARAMS, true)
  })

  it('au clavier (un clic sans appui de pointeur), une fois actifs : la décision part', async () => {
    const { wrapper, decide } = await mountPage()

    await keyboardClick(wrapper, 'deny')
    await flushPromises()

    expect(decide).toHaveBeenCalledWith(PARAMS, false)
  })
})

describe('OAuthConsentPage — accessibilité', () => {
  it.each(SUPPORTED_LOCALES)('aucune violation détectable, demande affichée (%s)', async (locale) => {
    const { wrapper } = await mountPage({ locale })
    // axe programme ses propres minuteries : elles doivent tourner.
    vi.useRealTimers()

    await expectNoAccessibilityViolation(wrapper)
  })

  it('aucune violation détectable, demande invalide', async () => {
    const { wrapper } = await mountPage({
      describeRequest: vi.fn(async () => {
        throw new InvalidConsentRequestError()
      }),
    })
    vi.useRealTimers()

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
