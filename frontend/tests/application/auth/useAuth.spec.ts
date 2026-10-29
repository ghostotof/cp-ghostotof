import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { AUTH_REPOSITORY, authState, markBaseAccessExpired, markBaseAccessGranted, markSessionExpired, sessionStamp, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import { BASE_ACCESS_SESSION, type AuthSession } from '../../../src/domain/auth/entities/AuthSession'
import { sessionFor } from '../../support/authSession'

function createStubRepository(overrides: Partial<AuthRepository> = {}): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER'] }) satisfies AuthenticatedUser),
    logout: vi.fn(async () => undefined),
    hasWriteToken: () => true,
    me: vi.fn(async () => sessionFor(null)),
    ...overrides,
  }
}

/**
 * Un seul site d'appel à defineComponent (vue/one-component-per-file), réutilisé
 * par mountWithComposable() et par le test "repository manquant" ci-dessous.
 */
function createProbe(register?: (auth: ReturnType<typeof useAuth>) => void) {
  let captured: ReturnType<typeof useAuth> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAuth()
      // Un abonnement pris dans le `setup`, donc dans le scope du composant : il tombe avec lui (`onScopeDispose`).
      register?.(captured)
      return () => h('div')
    },
  })

  return { Probe, getCaptured: () => captured }
}

function mountWithComposable(repository: AuthRepository, register?: (auth: ReturnType<typeof useAuth>) => void) {
  const { Probe, getCaptured } = createProbe(register)

  const wrapper = mount(Probe, {
    global: {
      provide: { [AUTH_REPOSITORY as symbol]: repository },
    },
  })

  const captured = getCaptured()
  if (!captured) {
    throw new Error('useAuth() did not run during mount')
  }

  return Object.assign(captured, { unmount: () => wrapper.unmount() })
}

describe('useAuth', () => {
  beforeEach(async () => {
    // L'état est un singleton au niveau du module (partagé entre AppHeader et LoginPage) ; `tests/support/setup.ts`
    // le remet à celui du chargement avant chaque test (#748, R25). Cette première vérification n'est donc plus une
    // remise à zéro : elle pose l'état que la plupart des tests supposent, `isChecking` retombé et la première
    // vérification faite (les suivantes sont silencieuses).
    await mountWithComposable(createStubRepository()).checkAuth()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const { Probe } = createProbe()
    expect(() => mount(Probe)).toThrow(/AuthRepository/)
  })

  it('checkAuth() hydrate isAuthenticated depuis repository.me()', async () => {
    const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) })
    const auth = mountWithComposable(repository)

    expect(auth.isAuthenticated.value).toBe(false)

    await auth.checkAuth()

    expect(auth.isAuthenticated.value).toBe(true)
    expect(auth.user.value).toEqual({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })
  })

  it('isSuperAdmin reflète le rôle ROLE_SUPER du user courant', async () => {
    const repository = createStubRepository({
      me: vi.fn(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
    })
    const auth = mountWithComposable(repository)

    expect(auth.isSuperAdmin.value).toBe(false)

    await auth.checkAuth()

    expect(auth.isSuperAdmin.value).toBe(true)
  })

  it('login() authentifie et met à jour user/isAuthenticated', async () => {
    const repository = createStubRepository()
    const auth = mountWithComposable(repository)

    await auth.login('jane', 'password')

    expect(repository.login).toHaveBeenCalledWith('jane', 'password')
    expect(auth.isAuthenticated.value).toBe(true)
    expect(auth.user.value?.username).toBe('jane')
  })

  it('login() propage les erreurs du repository (ex. identifiants invalides) sans modifier l\'état', async () => {
    const repository = createStubRepository({ login: vi.fn(async () => Promise.reject(new Error('bad credentials'))) })
    const auth = mountWithComposable(repository)

    await expect(auth.login('jane', 'wrong')).rejects.toThrow('bad credentials')
    expect(auth.isAuthenticated.value).toBe(false)
  })

  it('logout() déconnecte et vide user', async () => {
    const repository = createStubRepository()
    const auth = mountWithComposable(repository)
    await auth.login('jane', 'password')

    await auth.logout()

    expect(repository.logout).toHaveBeenCalled()
    expect(auth.isAuthenticated.value).toBe(false)
    expect(auth.user.value).toBeNull()
  })

  it("logout() refusé par le serveur (403 du contrôle CSRF) : l'état local revient quand même à anonyme, et l'erreur remonte", async () => {
    // Issue #455 : un XSRF-TOKEN signé sous l'ancienne clé fait répondre 403
    // à POST /api/logout. Rester « connecté » bloquerait la page de login,
    // donc la seule sortie : un nouveau login, qui remplace les deux cookies.
    const repository = createStubRepository()
    repository.logout = vi.fn(async () => {
      throw new Error('Logout failed with status 403')
    })
    const auth = mountWithComposable(repository)
    await auth.login('jane', 'password')

    await expect(auth.logout()).rejects.toThrow('403')

    expect(auth.isAuthenticated.value).toBe(false)
    expect(auth.user.value).toBeNull()
  })

  describe('fin de session annoncée (#650, S2)', () => {
    it("markSessionExpired() repasse à anonyme et lève hasSessionEnded, qu'une session de compte efface", async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.login('jane', 'password')
      expect(auth.hasSessionEnded.value).toBe(false)

      markSessionExpired()

      expect(auth.user.value).toBeNull()
      expect(auth.hasSessionEnded.value).toBe(true)
      expect(authState.sessionEnded).toBe(true)

      await auth.login('jane', 'password')
      expect(auth.hasSessionEnded.value).toBe(false)
    })

    it('chaque fin de session augmente sessionEndedCount, même quand la session est déjà finie', () => {
      const auth = mountWithComposable(createStubRepository())
      const before = auth.sessionEndedCount.value

      markSessionExpired()
      expect(auth.sessionEndedCount.value).toBe(before + 1)

      // Un second refus pendant que la session est déjà finie compte aussi : c'est ce qui réannonce le bandeau (S7).
      markSessionExpired()
      expect(auth.sessionEndedCount.value).toBe(before + 2)
    })

    it("une relecture qui répond encore « anonyme » ne lève pas l'annonce (la session n'est pas revenue)", async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.login('jane', 'password')
      markSessionExpired()

      await auth.checkAuth()

      expect(auth.hasSessionEnded.value).toBe(true)
    })

    it("une relecture qui retrouve un compte (reconnexion dans un autre onglet) lève l'annonce", async () => {
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_USER'] })) })
      const auth = mountWithComposable(repository)
      markSessionExpired()
      expect(auth.hasSessionEnded.value).toBe(true)

      await auth.checkAuth()

      expect(auth.hasSessionEnded.value).toBe(false)
    })
  })

  describe('onSessionRestored (#748, R13)', () => {
    const JANE_ME = (): Partial<AuthRepository> => ({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_USER'] })) })

    it("appelé une fois au retour, de façon synchrone, jamais à la fin de la session", async () => {
      const restored = vi.fn()
      const auth = mountWithComposable(createStubRepository(JANE_ME()), (probe) => probe.onSessionRestored(restored))
      await auth.login('jane', 'password')

      markSessionExpired()
      expect(restored).not.toHaveBeenCalled()

      // Une seconde fin ne l'appelle pas non plus : seule la descente de `sessionEnded` est le retour.
      markSessionExpired()
      expect(restored).not.toHaveBeenCalled()

      await auth.recheckEndedSession()
      expect(restored).toHaveBeenCalledTimes(1)

      // Plus de session finie : une relecture de plus n'est pas un retour.
      await auth.recheckEndedSession()
      expect(restored).toHaveBeenCalledTimes(1)
    })

    it("plus appelé après la fin du scope qui l'a pris", async () => {
      const restored = vi.fn()
      const auth = mountWithComposable(createStubRepository(JANE_ME()), (probe) => probe.onSessionRestored(restored))
      await auth.login('jane', 'password')
      auth.unmount()

      markSessionExpired()
      await mountWithComposable(createStubRepository(JANE_ME())).recheckEndedSession()

      expect(restored).not.toHaveBeenCalled()
    })

    it('tous les abonnés sont appelés, chacun une fois', async () => {
      const first = vi.fn()
      const second = vi.fn()
      mountWithComposable(createStubRepository(), (probe) => probe.onSessionRestored(first))
      const auth = mountWithComposable(createStubRepository(JANE_ME()), (probe) => probe.onSessionRestored(second))
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(first).toHaveBeenCalledTimes(1)
      expect(second).toHaveBeenCalledTimes(1)
    })

    it("la relecture d'un compte retrouvé (autre onglet) est aussi un retour", async () => {
      const restored = vi.fn()
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_USER'] })) })
      const auth = mountWithComposable(repository, (probe) => probe.onSessionRestored(restored))
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(restored).toHaveBeenCalledTimes(1)
    })
  })

  describe('le même compte est rétabli en place, un autre rejoue la navigation (#748, R18)', () => {
    const JANE = { username: 'jane', roles: ['ROLE_USER'] }
    const OTHER = { username: 'autre', roles: ['ROLE_USER'] }

    function subscribed(repository: AuthRepository) {
      const restored = vi.fn()
      const switched = vi.fn()
      const auth = mountWithComposable(repository, (probe) => {
        probe.onSessionRestored(restored)
        probe.onAccountSwitched(switched)
      })

      return { auth, restored, switched }
    }

    it("le même compte revient : rétabli en place", async () => {
      const { auth, restored, switched } = subscribed(createStubRepository({ me: vi.fn(async () => sessionFor(JANE)) }))
      await auth.login('jane', 'password')
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(restored).toHaveBeenCalledTimes(1)
      expect(switched).not.toHaveBeenCalled()
      expect(auth.user.value?.username).toBe('jane')
    })

    it("un autre compte revient : la session est appliquée, onAccountSwitched seul", async () => {
      const { auth, restored, switched } = subscribed(createStubRepository({ me: vi.fn(async () => sessionFor(OTHER)) }))
      await auth.login('jane', 'password')
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(switched).toHaveBeenCalledTimes(1)
      expect(restored).not.toHaveBeenCalled()
      // La session est appliquée avant le rappel : la garde de route juge le nouveau compte.
      expect(auth.hasSessionEnded.value).toBe(false)
      expect(auth.user.value?.username).toBe('autre')
    })

    it("onAccountSwitched : l'état est posé quand le rappel s'exécute", async () => {
      let seen: string | null | undefined
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor(OTHER)) })
      const auth = mountWithComposable(repository, (probe) => {
        probe.onAccountSwitched(() => {
          seen = authState.user?.username ?? null
        })
      })
      await auth.login('jane', 'password')
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(seen).toBe('autre')
    })

    it('checkAuth() retrouvant un autre compte est aussi un changement de compte', async () => {
      const { auth, restored, switched } = subscribed(createStubRepository({ me: vi.fn(async () => sessionFor(OTHER)) }))
      await auth.login('jane', 'password')
      markSessionExpired()

      await auth.checkAuth()

      expect(switched).toHaveBeenCalledTimes(1)
      expect(restored).not.toHaveBeenCalled()
    })

    it("sans compte affiché à la fin (palier de base, anonyme), n'importe quel compte rétablit en place", async () => {
      const { auth, restored, switched } = subscribed(createStubRepository({ me: vi.fn(async () => sessionFor(OTHER)) }))
      markBaseAccessGranted()
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(restored).toHaveBeenCalledTimes(1)
      expect(switched).not.toHaveBeenCalled()
    })

    it("le compte retenu est celui de la première fin : un refus de plus ne l'écrase pas", async () => {
      const { auth, restored, switched } = subscribed(createStubRepository({ me: vi.fn(async () => sessionFor(JANE)) }))
      await auth.login('jane', 'password')
      markSessionExpired()
      // L'en-tête est anonyme depuis la première fin : le second refus ne doit pas retenir « aucun compte ».
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(restored).toHaveBeenCalledTimes(1)
      expect(switched).not.toHaveBeenCalled()
    })

    it("le compte retenu est oublié au retour : une seconde fin retient le compte alors affiché", async () => {
      const me = vi.fn(async () => sessionFor(OTHER))
      const { auth, restored, switched } = subscribed(createStubRepository({ me }))
      await auth.login('jane', 'password')
      markSessionExpired()
      await auth.recheckEndedSession()
      expect(switched).toHaveBeenCalledTimes(1)

      // « autre » est maintenant le compte affiché : s'il revient, c'est le même.
      markSessionExpired()
      await auth.recheckEndedSession()

      expect(restored).toHaveBeenCalledTimes(1)
      expect(switched).toHaveBeenCalledTimes(1)
    })

    it("une connexion dans l'onglet n'appelle aucun des deux", async () => {
      const { auth, restored, switched } = subscribed(createStubRepository())
      await auth.login('jane', 'password')
      markSessionExpired()

      await auth.login('autre', 'password')

      expect(auth.hasSessionEnded.value).toBe(false)
      expect(restored).not.toHaveBeenCalled()
      expect(switched).not.toHaveBeenCalled()
    })

    it('plus appelé après la fin du scope qui l\'a pris', async () => {
      const switched = vi.fn()
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor(OTHER)) })
      const auth = mountWithComposable(repository, (probe) => probe.onAccountSwitched(switched))
      await auth.login('jane', 'password')
      auth.unmount()
      markSessionExpired()

      await mountWithComposable(repository).recheckEndedSession()

      expect(switched).not.toHaveBeenCalled()
    })
  })

  describe('un rappel de retour qui lève (#748, R14)', () => {
    afterEach(() => {
      vi.restoreAllMocks()
    })

    it("n'empêche ni les rappels suivants ni l'issue de la relecture, et l'erreur est rapportée", async () => {
      const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
      const broken = new Error('rappel défaillant')
      const after = vi.fn()
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_USER'] })) })
      const auth = mountWithComposable(repository, (probe) => {
        probe.onSessionRestored(() => {
          throw broken
        })
        probe.onSessionRestored(after)
      })
      markSessionExpired()

      await expect(auth.recheckEndedSession()).resolves.toBeUndefined()
      // Démonté avant les assertions : l'abonné défaillant ne doit pas survivre au test.
      auth.unmount()

      expect(after).toHaveBeenCalledTimes(1)
      expect(auth.hasSessionEnded.value).toBe(false)
      expect(error).toHaveBeenCalledWith(expect.anything(), broken)
    })

    it("n'empêche pas non plus checkAuth() ni recheckEndedSession() de se terminer", async () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined)
      const after = vi.fn()
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_USER'] })) })
      const auth = mountWithComposable(repository, (probe) => {
        probe.onSessionRestored(() => {
          throw new Error('rappel défaillant')
        })
        probe.onSessionRestored(after)
      })

      markSessionExpired()
      await expect(auth.checkAuth()).resolves.toBeUndefined()
      markSessionExpired()
      await expect(auth.recheckEndedSession()).resolves.toBeUndefined()
      auth.unmount()

      expect(after).toHaveBeenCalledTimes(2)
    })
  })

  describe('une génération de session (#748, R14)', () => {
    /** Un `me()` dont la réponse est tenue : le test fait arriver un verdict pendant qu'il est en vol. */
    function deferredMe(): { me: () => Promise<AuthSession>; settle: (session: AuthSession) => void } {
      let settle: (session: AuthSession) => void = () => undefined
      const pending = new Promise<AuthSession>((resolve) => {
        settle = resolve
      })

      return { me: () => pending, settle }
    }

    const JANE = sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })

    it("un refus parti avant le retour ne refinit pas la session (Review Focus 1)", async () => {
      const auth = mountWithComposable(createStubRepository())
      markSessionExpired()
      const sent = sessionStamp()
      await auth.login('jane', 'password')
      expect(auth.hasSessionEnded.value).toBe(false)
      const count = auth.sessionEndedCount.value

      // La réponse de l'écriture partie pendant la session finie arrive après le retour.
      expect(markSessionExpired(sent)).toBe(false)

      expect(auth.hasSessionEnded.value).toBe(false)
      expect(auth.user.value?.username).toBe('jane')
      expect(auth.sessionEndedCount.value).toBe(count)
    })

    it("session finie : un refus parti avant la fin ne réannonce pas, le verdict tient", () => {
      const auth = mountWithComposable(createStubRepository())
      const sent = sessionStamp()
      markSessionExpired()
      const count = auth.sessionEndedCount.value

      expect(markSessionExpired(sent)).toBe(true)

      expect(auth.hasSessionEnded.value).toBe(true)
      expect(auth.sessionEndedCount.value).toBe(count)
    })

    it('session finie : un refus parti après la fin réannonce', () => {
      const auth = mountWithComposable(createStubRepository())
      markSessionExpired()
      const count = auth.sessionEndedCount.value

      expect(markSessionExpired(sessionStamp())).toBe(true)

      expect(auth.sessionEndedCount.value).toBe(count + 1)
    })

    it("des refus partis sous la même génération (lectures parallèles) n'annoncent qu'une fois (Sp-c1)", () => {
      const auth = mountWithComposable(createStubRepository())
      const count = auth.sessionEndedCount.value
      const sent = sessionStamp()

      // Trois lectures du chargement d'une page : mêmes départs, arrivées à des instants distincts.
      expect(markSessionExpired(sent)).toBe(true)
      expect(markSessionExpired(sent)).toBe(true)
      expect(markSessionExpired(sent)).toBe(true)

      expect(auth.sessionEndedCount.value).toBe(count + 1)
    })

    it("un /api/me parti avant un nouveau refus ne rétablit pas la session", async () => {
      const restored = vi.fn()
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }), (probe) => probe.onSessionRestored(restored))
      markSessionExpired()

      const pending = auth.recheckEndedSession()
      markSessionExpired(sessionStamp())
      settle(JANE)
      await pending

      expect(auth.hasSessionEnded.value).toBe(true)
      expect(auth.user.value).toBeNull()
      expect(restored).not.toHaveBeenCalled()
    })

    it("un /api/me de checkAuth() parti avant un refus ne rétablit ni ne reconnecte l'en-tête", async () => {
      const restored = vi.fn()
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }), (probe) => probe.onSessionRestored(restored))
      markSessionExpired()

      const pending = auth.checkAuth()
      markSessionExpired(sessionStamp())
      settle(JANE)
      await pending

      expect(auth.hasSessionEnded.value).toBe(true)
      expect(auth.user.value).toBeNull()
      expect(restored).not.toHaveBeenCalled()
    })

    it("un /api/me parti avant le retour ne défait pas la session rétablie", async () => {
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }))
      await auth.login('jane', 'password')
      markSessionExpired()

      const pending = auth.checkAuth()
      await auth.login('jane', 'password')
      settle(sessionFor(null))
      await pending

      // La réponse anonyme était d'avant la reconnexion : elle ne repasse pas l'en-tête à anonyme.
      expect(auth.user.value?.username).toBe('jane')
      expect(auth.hasSessionEnded.value).toBe(false)
    })

    it("checkAuth() ne rétablit pas une session finie quand le compte n'a pas de jeton d'écriture (F5)", async () => {
      const restored = vi.fn()
      const repository = createStubRepository({ me: vi.fn(async () => JANE), hasWriteToken: () => false })
      const auth = mountWithComposable(repository, (probe) => probe.onSessionRestored(restored))
      markSessionExpired()

      await auth.checkAuth()

      expect(auth.hasSessionEnded.value).toBe(true)
      expect(restored).not.toHaveBeenCalled()
    })

    it('le retour avance la génération : un refus parti avant lui est jeté', async () => {
      const auth = mountWithComposable(createStubRepository())
      markSessionExpired()
      const before = sessionStamp()

      await auth.login('jane', 'password')

      expect(sessionStamp()).toBeGreaterThan(before)
    })
  })

  describe("relecture silencieuse de la session (#748, R8)", () => {
    /** Un `me()` dont la réponse est tenue : le test regarde l'état pendant que la relecture est en vol. */
    function deferredMe(): { me: () => Promise<AuthSession>; settle: (session: AuthSession) => void } {
      let settle: (session: AuthSession) => void = () => undefined
      const pending = new Promise<AuthSession>((resolve) => {
        settle = resolve
      })

      return { me: () => pending, settle }
    }

    it("checkAuth() après la première vérification ne remet pas isChecking à vrai : l'en-tête ne clignote pas", async () => {
      // La première vérification est faite par le beforeEach : isChecking est retombé.
      expect(authState.isChecking).toBe(false)
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }))

      const pending = auth.checkAuth()

      expect(authState.isChecking).toBe(false)
      settle(sessionFor(null))
      await pending
      expect(authState.isChecking).toBe(false)
    })

    it("un compte retrouvé avec son jeton d'écriture rétablit la session", async () => {
      const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) })
      const auth = mountWithComposable(repository)
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(auth.hasSessionEnded.value).toBe(false)
      expect(auth.user.value?.username).toBe('jane')
      expect(auth.tier.value).toBe('trusted')
    })

    it('un compte retrouvé sans XSRF-TOKEN : la session reste finie, rien ne dit « rétablie »', async () => {
      const repository = createStubRepository({
        me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })),
        hasWriteToken: () => false,
      })
      const auth = mountWithComposable(repository)
      markSessionExpired()

      await auth.recheckEndedSession()

      expect(repository.me).toHaveBeenCalledTimes(1)
      expect(auth.hasSessionEnded.value).toBe(true)
      expect(auth.user.value).toBeNull()
      expect(auth.tier.value).toBe('anonymous')
    })

    it("hors session finie, aucune relecture", async () => {
      const repository = createStubRepository()
      const auth = mountWithComposable(repository)
      await auth.login('jane', 'password')

      await auth.recheckEndedSession()

      expect(repository.me).not.toHaveBeenCalled()
    })

    it('deux appels rapprochés : une seule relecture en vol', async () => {
      const { me, settle } = deferredMe()
      const repository = createStubRepository({ me: vi.fn(me) })
      const auth = mountWithComposable(repository)
      markSessionExpired()

      const first = auth.recheckEndedSession()
      const second = auth.recheckEndedSession()
      settle(sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] }))
      await Promise.all([first, second])

      expect(repository.me).toHaveBeenCalledTimes(1)
      expect(auth.hasSessionEnded.value).toBe(false)
    })

    it("une relecture terminée libère la suivante : un nouveau retour relit", async () => {
      const repository = createStubRepository()
      const auth = mountWithComposable(repository)
      markSessionExpired()

      await auth.recheckEndedSession()
      await auth.recheckEndedSession()

      expect(repository.me).toHaveBeenCalledTimes(2)
    })

    it('une panne réseau laisse la session finie, sans rejet', async () => {
      const repository = createStubRepository({
        me: vi.fn(async () => {
          throw new Error('réseau')
        }),
      })
      const auth = mountWithComposable(repository)
      markSessionExpired()

      await expect(auth.recheckEndedSession()).resolves.toBeUndefined()

      expect(auth.hasSessionEnded.value).toBe(true)
    })

    it("la relecture ne touche pas à isChecking : le groupe de droite de l'en-tête reste rendu", async () => {
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }))
      markSessionExpired()

      const pending = auth.recheckEndedSession()

      expect(authState.isChecking).toBe(false)
      settle(sessionFor(null))
      await pending
    })
  })

  describe("relire le palier après un refus (#748, R15)", () => {
    /** Un `me()` dont la réponse est tenue : le test fait arriver un verdict pendant qu'il est en vol. */
    function deferredMe(): { me: () => Promise<AuthSession>; settle: (session: AuthSession) => void } {
      let settle: (session: AuthSession) => void = () => undefined
      const pending = new Promise<AuthSession>((resolve) => {
        settle = resolve
      })

      return { me: () => pending, settle }
    }

    afterEach(async () => {
      // Une session laissée finie par un test ne doit pas fuir dans le suivant : une connexion la rétablit.
      await mountWithComposable(createStubRepository()).login('jane', 'password')
    })

    it("deux refus rapprochés : un seul /api/me", async () => {
      const { me, settle } = deferredMe()
      const repository = createStubRepository({ me: vi.fn(me) })
      const first = mountWithComposable(repository)
      const second = mountWithComposable(repository)
      vi.mocked(repository.me).mockClear()

      const one = first.rereadAfterRefusal()
      const other = second.rereadAfterRefusal()
      settle(BASE_ACCESS_SESSION)

      await expect(Promise.all([one, other])).resolves.toEqual(['session-held', 'session-held'])
      expect(repository.me).toHaveBeenCalledTimes(1)
    })

    it("/api/me en 401 : la session est marquée finie, 'session-ended'", async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => sessionFor(null)) }))
      await auth.login('jane', 'password')
      expect(auth.hasSessionEnded.value).toBe(false)
      const count = auth.sessionEndedCount.value

      await expect(auth.rereadAfterRefusal()).resolves.toBe('session-ended')

      expect(auth.hasSessionEnded.value).toBe(true)
      expect(auth.sessionEndedCount.value).toBe(count + 1)
      expect(auth.tier.value).toBe('anonymous')
    })

    it("/api/me en 401 pendant une session déjà finie : 'session-ended', sans réannoncer", async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => sessionFor(null)) }))
      markSessionExpired()
      const count = auth.sessionEndedCount.value

      await expect(auth.rereadAfterRefusal()).resolves.toBe('session-ended')

      expect(auth.sessionEndedCount.value).toBe(count)
    })

    it("/api/me en 403 : palier de base, 'session-held', la session n'est pas finie", async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => BASE_ACCESS_SESSION) }))
      await auth.login('jane', 'password')

      await expect(auth.rereadAfterRefusal()).resolves.toBe('session-held')

      expect(auth.hasSessionEnded.value).toBe(false)
      expect(auth.tier.value).toBe('base')
    })

    it("/api/me en 401 parti avant un retour : la session rétablie tient, 'session-held'", async () => {
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }))
      await auth.login('jane', 'password')

      const pending = auth.rereadAfterRefusal()
      // Une fin puis un retour (autre onglet) pendant que /api/me est en vol : sa réponse est d'une session révolue.
      markSessionExpired()
      await auth.login('jane', 'password')
      settle(sessionFor(null))

      await expect(pending).resolves.toBe('session-held')
      expect(auth.hasSessionEnded.value).toBe(false)
      expect(auth.user.value?.username).toBe('jane')
    })

    it("panne de /api/me : avalée, 'session-held'", async () => {
      const auth = mountWithComposable(
        createStubRepository({
          me: vi.fn(async () => {
            throw new Error('réseau')
          }),
        }),
      )

      await expect(auth.rereadAfterRefusal()).resolves.toBe('session-held')
      expect(auth.hasSessionEnded.value).toBe(false)
    })

    it("isChecking n'est pas touché", async () => {
      const { me, settle } = deferredMe()
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(me) }))

      const pending = auth.rereadAfterRefusal()
      expect(authState.isChecking).toBe(false)
      settle(BASE_ACCESS_SESSION)
      await pending

      expect(authState.isChecking).toBe(false)
    })
  })

  describe('palier d\'accès (ADR 0003 D1 — trois cas)', () => {
    it('anonyme par défaut : tier=anonymous, ni authentifié ni de confiance', async () => {
      const auth = mountWithComposable(createStubRepository())

      await auth.checkAuth()

      expect(auth.tier.value).toBe('anonymous')
      expect(auth.isAuthenticated.value).toBe(false)
      expect(auth.isTrusted.value).toBe(false)
    })

    it('checkAuth() reconnaît le palier de base (403 sur /api/me) : authentifié, sans identité, pas de confiance', async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => BASE_ACCESS_SESSION) }))

      await auth.checkAuth()

      expect(auth.tier.value).toBe('base')
      expect(auth.isAuthenticated.value).toBe(true)
      expect(auth.isTrusted.value).toBe(false)
      expect(auth.user.value).toBeNull()
    })

    it('checkAuth() reconnaît le palier de confiance', async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) }))

      await auth.checkAuth()

      expect(auth.tier.value).toBe('trusted')
      expect(auth.isTrusted.value).toBe(true)
    })

    it('markBaseAccessGranted() fait passer un anonyme au palier de base, visible via authState (hors composant)', async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.checkAuth()

      markBaseAccessGranted()

      expect(auth.tier.value).toBe('base')
      expect(authState.tier).toBe('base')
      expect(authState.user).toBeNull()
    })

    it('login() depuis le palier de base avec un compte de confiance passe au palier de confiance', async () => {
      const auth = mountWithComposable(
        createStubRepository({ login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) }),
      )
      await auth.checkAuth()
      markBaseAccessGranted()

      await auth.login('jane', 'password')

      expect(auth.tier.value).toBe('trusted')
      expect(auth.user.value?.username).toBe('jane')
    })

    it('login() avec un compte réel sans ROLE_TRUSTED reste au palier de base, mais identifié', async () => {
      const auth = mountWithComposable(createStubRepository())

      await auth.login('jane', 'password')

      expect(auth.tier.value).toBe('base')
      expect(auth.user.value?.username).toBe('jane')
    })

    it('logout() depuis le palier de base ramène à anonyme', async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.checkAuth()
      markBaseAccessGranted()

      await auth.logout()

      expect(auth.tier.value).toBe('anonymous')
    })
  })

  /**
   * Le cookie D6 est httpOnly : le JWT qu'il porte meurt à 15 min, mais le cookie, de session (#748, R21), reste
   * jusqu'à la fermeture du navigateur ou au premier 401 qui l'efface. Sans ces mécanismes, l'en-tête afficherait
   * « Accès de base » jusqu'au prochain rechargement, bien après que le jeton ait cessé de valoir. L'expiration ne
   * lit que l'horloge du poste et ne fait qu'un effet d'affichage : aucun appel réseau (#748, R23).
   */
  describe("expiration du jeton du palier de base (ADR 0003 D6)", () => {
    const FIFTEEN_MINUTES = 15 * 60 * 1000

    beforeEach(() => {
      vi.useFakeTimers()
      localStorage.clear()
    })

    afterEach(() => {
      vi.useRealTimers()
      localStorage.clear()
    })

    it("à l'échéance annoncée, le palier de base retombe à anonyme sans aucun appel réseau", async () => {
      const repository = createStubRepository()
      const auth = mountWithComposable(repository)
      await auth.checkAuth()
      vi.mocked(repository.me).mockClear()

      markBaseAccessGranted(new Date(Date.now() + FIFTEEN_MINUTES))
      expect(auth.tier.value).toBe('base')

      vi.advanceTimersByTime(FIFTEEN_MINUTES - 1)
      expect(auth.tier.value).toBe('base')

      vi.advanceTimersByTime(1)
      expect(auth.tier.value).toBe('anonymous')
      expect(repository.me).not.toHaveBeenCalled()
      expect(repository.logout).not.toHaveBeenCalled()
    })

    it("le retour sur l'onglet après l'échéance ne fait aucun appel réseau", async () => {
      const repository = createStubRepository()
      const auth = mountWithComposable(repository)
      await auth.checkAuth()
      markBaseAccessGranted(new Date(Date.now() + FIFTEEN_MINUTES))
      vi.mocked(repository.me).mockClear()

      // L'onglet était en arrière-plan : l'horloge a avancé, la minuterie n'a pas tiré.
      vi.setSystemTime(Date.now() + FIFTEEN_MINUTES + 1)
      window.dispatchEvent(new Event('focus'))
      document.dispatchEvent(new Event('visibilitychange'))

      expect(repository.logout).not.toHaveBeenCalled()
      expect(repository.me).not.toHaveBeenCalled()
      expect(auth.hasSessionEnded.value).toBe(false)
    })

    it("sans échéance connue (contrat plus ancien), rien n'est programmé : comportement d'avant", async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.checkAuth()

      markBaseAccessGranted(null)
      vi.advanceTimersByTime(FIFTEEN_MINUTES * 10)

      expect(auth.tier.value).toBe('base')
    })

    it("un login avant l'échéance annule la minuterie : le compte n'est pas déconnecté par le timer du jeton D6", async () => {
      const auth = mountWithComposable(
        createStubRepository({ login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) }),
      )
      await auth.checkAuth()
      markBaseAccessGranted(new Date(Date.now() + FIFTEEN_MINUTES))

      await auth.login('jane', 'password')
      vi.advanceTimersByTime(FIFTEEN_MINUTES)

      expect(auth.tier.value).toBe('trusted')
      expect(localStorage.getItem('baseAccessExpiresAt')).toBeNull()
    })

    it("après un rechargement, checkAuth() reprend l'échéance mémorisée quand le serveur confirme le palier de base", async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => BASE_ACCESS_SESSION) }))
      localStorage.setItem('baseAccessExpiresAt', new Date(Date.now() + FIFTEEN_MINUTES).toISOString())

      await auth.checkAuth()
      expect(auth.tier.value).toBe('base')

      vi.advanceTimersByTime(FIFTEEN_MINUTES)
      expect(auth.tier.value).toBe('anonymous')
    })

    it("une échéance mémorisée mais passée est ignorée : c'est le serveur qui fait foi", async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => BASE_ACCESS_SESSION) }))
      localStorage.setItem('baseAccessExpiresAt', new Date(Date.now() - 1000).toISOString())

      await auth.checkAuth()
      vi.advanceTimersByTime(FIFTEEN_MINUTES)

      expect(auth.tier.value).toBe('base')
      expect(localStorage.getItem('baseAccessExpiresAt')).toBeNull()
    })

    it('markBaseAccessExpired() ramène le palier de base (sans compte) à anonyme, et ne touche pas à un compte identifié', async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.checkAuth()
      markBaseAccessGranted(new Date(Date.now() + FIFTEEN_MINUTES))

      markBaseAccessExpired()
      expect(auth.tier.value).toBe('anonymous')
      expect(localStorage.getItem('baseAccessExpiresAt')).toBeNull()

      await auth.login('jane', 'password') // compte réel au palier de base, identifié
      markBaseAccessExpired()
      expect(auth.tier.value).toBe('base')
      expect(auth.user.value?.username).toBe('jane')
    })
  })

  describe('markSessionExpired', () => {
    it('ramène une session nominative à anonyme, sans compte', async () => {
      const auth = mountWithComposable(
        createStubRepository({ login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER', 'ROLE_TRUSTED'] })) }),
      )
      await auth.login('jane', 'password')
      expect(auth.tier.value).toBe('trusted')

      markSessionExpired()

      expect(auth.tier.value).toBe('anonymous')
      expect(auth.user.value).toBeNull()
    })
  })
})
