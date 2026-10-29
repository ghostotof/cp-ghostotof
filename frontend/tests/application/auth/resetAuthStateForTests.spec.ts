import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, effectScope, h } from 'vue'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { AUTH_REPOSITORY, authState, markBaseAccessGranted, markSessionExpired, onAccountSwitched, onSessionRestored, sessionStamp, useAuth } from '../../../src/application/auth/useAuth'
import { resetAuthStateForTests } from '../../../src/application/auth/resetAuthStateForTests'
import { sessionFor } from '../../support/authSession'

/** Un `useAuth()` hors composant, le dépôt fourni comme `main.ts` le fait (`app.runWithContext`). */
function useAuthWith(repository: AuthRepository): ReturnType<typeof useAuth> {
  let captured: ReturnType<typeof useAuth> | undefined
  const app = createApp(defineComponent({ setup: () => () => h('div') }))
  app.provide(AUTH_REPOSITORY, repository)
  app.runWithContext(() => {
    captured = useAuth()
  })
  if (!captured) {
    throw new Error('useAuth() did not run')
  }

  return captured
}

describe('resetAuthStateForTests', () => {
  afterEach(() => {
    vi.useRealTimers()
    resetAuthStateForTests()
  })

  it('remet chaque état de module à celui du chargement', async () => {
    vi.useFakeTimers()
    // Le premier `/api/me` reste en vol jusqu'à `release()` ; les suivants répondent aussitôt.
    let release: () => void = () => undefined
    const me = vi
      .fn<AuthRepository['me']>()
      .mockImplementationOnce(() => new Promise((resolve) => (release = () => resolve(sessionFor(null)))))
      .mockImplementation(async () => sessionFor({ username: 'jane', roles: ['ROLE_USER', 'ROLE_TRUSTED'] }))
    const repository: AuthRepository = { login: vi.fn(), logout: vi.fn(), hasWriteToken: () => true, me }
    const auth = useAuthWith(repository)
    const scope = effectScope()

    // Une session finie, une génération avancée, des abonnés, une minuterie armée, une relecture en vol.
    const restored = vi.fn()
    const switched = vi.fn()
    scope.run(() => {
      onSessionRestored(restored)
      onAccountSwitched(switched)
    })
    markBaseAccessGranted(new Date(Date.now() + 60_000))
    expect(localStorage.getItem('baseAccessExpiresAt')).not.toBeNull()
    markSessionExpired()
    expect(authState.sessionEnded).toBe(true)
    expect(sessionStamp()).toBeGreaterThan(0)
    const inFlight = auth.rereadAfterRefusal()
    expect(me).toHaveBeenCalledTimes(1)

    resetAuthStateForTests()

    expect(authState.tier).toBe('anonymous')
    expect(authState.user).toBeNull()
    expect(authState.isChecking).toBe(true)
    expect(authState.sessionEnded).toBe(false)
    expect(authState.sessionEndedCount).toBe(0)
    expect(sessionStamp()).toBe(0)
    expect(localStorage.getItem('baseAccessExpiresAt')).toBeNull()

    // La minuterie armée n'agit plus : le palier de base posé après la remise à zéro n'est pas retombé à son ancienne échéance.
    markBaseAccessGranted()
    await vi.advanceTimersByTimeAsync(120_000)
    expect(authState.tier).toBe('base')

    // Plus aucun abonné : une session qui finit puis revient n'appelle ni l'un ni l'autre.
    markSessionExpired()
    await auth.recheckEndedSession()
    expect(restored).not.toHaveBeenCalled()
    expect(switched).not.toHaveBeenCalled()

    // La relecture en vol est oubliée : un nouvel appel envoie un nouveau `/api/me`.
    resetAuthStateForTests()
    me.mockClear()
    await auth.rereadAfterRefusal()
    expect(me).toHaveBeenCalledTimes(1)
    release()
    await inFlight
  })
})
