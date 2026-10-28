import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { CLIENT_REDIRECT, OAUTH_CONSENT_REPOSITORY, useOAuthConsent } from '../../../src/application/mcp/useOAuthConsent'
import type { OAuthConsentRepository } from '../../../src/domain/mcp/repositories/OAuthConsentRepository'
import type { ConsentParams, ConsentRequest } from '../../../src/domain/mcp/entities/ConsentRequest'
import { ConsentFailedError } from '../../../src/domain/mcp/errors/ConsentFailedError'
import { MalformedConsentDecisionError } from '../../../src/domain/mcp/errors/MalformedConsentDecisionError'
import { ConsentAccountChangedError } from '../../../src/domain/mcp/errors/ConsentAccountChangedError'
import { ReauthenticationRequiredError } from '../../../src/domain/mcp/errors/ReauthenticationRequiredError'

enableAutoUnmount(afterEach)

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

/** Le compte que le résumé nomme : la décision le renvoie (#650, F5). */
const ACCOUNT_ID = '01a181e2-9a50-7567-9420-a8142a191787'

const SUMMARY: ConsentRequest = {
  client: { id: 'claude-code', name: 'Claude Code', registration: 'preregistered' },
  redirectHost: '127.0.0.1',
  scope: 'nominative:read',
  resource: 'https://site.example.test/api/mcp',
  account: { id: ACCOUNT_ID, username: 'trusted' },
}

const APPROVED_URL = 'http://127.0.0.1:33418/callback?code=abc&state=xyz'

function mountComposable(decide: OAuthConsentRepository['decide'], describeRequest: OAuthConsentRepository['describe'] = vi.fn(async () => SUMMARY)) {
  const navigate = vi.fn()
  const repository: OAuthConsentRepository = { describe: describeRequest, decide }
  let captured: ReturnType<typeof useOAuthConsent> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useOAuthConsent()
      return () => h('div')
    },
  })
  mount(Probe, {
    global: {
      provide: {
        [OAUTH_CONSENT_REPOSITORY as symbol]: repository,
        [CLIENT_REDIRECT as symbol]: navigate,
      },
    },
  })

  if (!captured) {
    throw new Error('useOAuthConsent() did not run during mount')
  }

  return { consent: captured, navigate }
}

describe('useOAuthConsent', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni", () => {
    const Probe = defineComponent({
      setup() {
        useOAuthConsent()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/OAuthConsentRepository/)
  })

  it('deux approve() de suite, sans attendre : une seule décision transmise', async () => {
    const decide = vi.fn(async () => APPROVED_URL)
    const { consent, navigate } = mountComposable(decide)
    await consent.load(PARAMS)

    const first = consent.approve()
    const second = consent.approve()
    await Promise.all([first, second])

    expect(decide).toHaveBeenCalledOnce()
    expect(navigate).toHaveBeenCalledOnce()
  })

  it('approve() puis deny() de suite : seule la première décision part', async () => {
    const decide = vi.fn(async () => APPROVED_URL)
    const { consent } = mountComposable(decide)
    await consent.load(PARAMS)

    await Promise.all([consent.approve(), consent.deny()])

    expect(decide).toHaveBeenCalledOnce()
    expect(decide).toHaveBeenCalledWith(PARAMS, true, ACCOUNT_ID)
  })

  it('aucune décision avant que la demande soit chargée', async () => {
    const decide = vi.fn(async () => APPROVED_URL)
    const { consent } = mountComposable(decide)

    await consent.approve()

    expect(decide).not.toHaveBeenCalled()
  })

  it('422 à la décision : défaut de la page, état terminal, aucun nouvel essai', async () => {
    const decide = vi.fn(async () => {
      throw new MalformedConsentDecisionError()
    })
    const { consent, navigate } = mountComposable(decide)
    await consent.load(PARAMS)

    await consent.approve()

    expect(consent.state.value).toBe('error')
    expect(consent.failure.value).toBe('malformed-decision')
    await consent.approve()
    expect(decide).toHaveBeenCalledOnce()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('authentification trop ancienne à l’accord : état terminal, à recommencer après reconnexion', async () => {
    const decide = vi.fn(async () => {
      throw new ReauthenticationRequiredError()
    })
    const { consent, navigate } = mountComposable(decide)
    await consent.load(PARAMS)

    await consent.approve()

    expect(consent.state.value).toBe('error')
    expect(consent.failure.value).toBe('reauthentication-required')
    // La demande reste lisible : la page la montre au-dessus du lien de reconnexion.
    expect(consent.request.value).toEqual(SUMMARY)
    await consent.approve()
    expect(decide).toHaveBeenCalledOnce()
    expect(navigate).not.toHaveBeenCalled()
  })

  it.each([
    ['approve', true],
    ['deny', false],
  ] as const)('%s() : l\'UUID du compte nommé par le résumé part avec la décision', async (method, approved) => {
    const decide = vi.fn(async () => APPROVED_URL)
    const { consent } = mountComposable(decide)
    await consent.load(PARAMS)

    await consent[method]()

    expect(decide).toHaveBeenCalledWith(PARAMS, approved, ACCOUNT_ID)
  })

  it('le compte connecté a changé (409) : état terminal, rien n\'est suivi, aucun nouvel essai', async () => {
    const decide = vi.fn(async () => {
      throw new ConsentAccountChangedError()
    })
    const { consent, navigate } = mountComposable(decide)
    await consent.load(PARAMS)

    await consent.approve()

    expect(consent.state.value).toBe('error')
    expect(consent.failure.value).toBe('account-changed')
    await consent.approve()
    expect(decide).toHaveBeenCalledOnce()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('reload() après un changement de compte : la demande est relue, le nouveau compte est celui qui décide', async () => {
    const otherAccount = '01a181e2-9b1e-7e55-8770-e3efdb34dfa2'
    const decide = vi.fn().mockRejectedValueOnce(new ConsentAccountChangedError()).mockResolvedValueOnce(APPROVED_URL)
    const describeRequest = vi
      .fn()
      .mockResolvedValueOnce(SUMMARY)
      .mockResolvedValueOnce({ ...SUMMARY, account: { id: otherAccount, username: 'other' } })
    const { consent, navigate } = mountComposable(decide, describeRequest)
    await consent.load(PARAMS)
    await consent.approve()
    expect(consent.failure.value).toBe('account-changed')

    await consent.reload()

    expect(describeRequest).toHaveBeenCalledTimes(2)
    expect(describeRequest).toHaveBeenLastCalledWith(PARAMS)
    expect(consent.state.value).toBe('ready')
    expect(consent.failure.value).toBeNull()
    expect(consent.request.value?.account.username).toBe('other')
    await consent.approve()
    expect(decide).toHaveBeenLastCalledWith(PARAMS, true, otherAccount)
    expect(navigate).toHaveBeenCalledWith(APPROVED_URL)
  })

  it('reload() sans demande lisible : sans appel au serveur, la demande reste invalide', async () => {
    const describeRequest = vi.fn(async () => SUMMARY)
    const { consent } = mountComposable(
      vi.fn(async () => APPROVED_URL),
      describeRequest,
    )
    await consent.load(null)

    await consent.reload()

    expect(describeRequest).not.toHaveBeenCalled()
    expect(consent.state.value).toBe('invalid')
  })

  it('échec générique à la décision : retour à ready, un nouvel essai part', async () => {
    const decide = vi.fn().mockRejectedValueOnce(new ConsentFailedError()).mockResolvedValueOnce(APPROVED_URL)
    const { consent, navigate } = mountComposable(decide)
    await consent.load(PARAMS)

    await consent.approve()
    expect(consent.state.value).toBe('ready')
    await consent.approve()

    expect(decide).toHaveBeenCalledTimes(2)
    expect(navigate).toHaveBeenCalledWith(APPROVED_URL)
  })

  it.each([
    ['même schéma, autre hôte', 'http://evil.example/callback?code=abc'],
    ['https, autre hôte', 'https://127.0.0.2/callback?code=abc'],
    ['hôte annoncé en sous-domaine', 'https://127.0.0.1.evil.example/callback?code=abc'],
  ])('URL rendue vers un autre hôte que celui annoncé (%s) : aucune navigation', async (_label, url) => {
    const { consent, navigate } = mountComposable(vi.fn(async () => url))
    await consent.load(PARAMS)

    await consent.approve()

    expect(navigate).not.toHaveBeenCalled()
    expect(consent.state.value).toBe('error')
    expect(consent.failure.value).toBe('unsafe-redirect')
  })

  it("le port ne compte pas : l'hôte annoncé n'en porte pas", async () => {
    const { consent, navigate } = mountComposable(vi.fn(async () => APPROVED_URL))
    await consent.load(PARAMS)

    await consent.approve()

    expect(navigate).toHaveBeenCalledWith(APPROVED_URL)
  })
})
