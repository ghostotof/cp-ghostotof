import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ACCOUNT_REPOSITORY, useAccountPasswordSetup } from '../../../src/application/account/useAccountPasswordSetup'
import type { AccountRepository } from '../../../src/domain/account/repositories/AccountRepository'
import { PasswordSetupLinkError } from '../../../src/domain/account/errors/PasswordSetupLinkError'

function createStubRepository(overrides: Partial<AccountRepository> = {}): AccountRepository {
  return {
    validateSetupToken: vi.fn(async () => undefined),
    completePasswordSetup: vi.fn(async () => undefined),
    ...overrides,
  }
}

function mountWithComposable(repository: AccountRepository) {
  let captured: ReturnType<typeof useAccountPasswordSetup> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAccountPasswordSetup()
      return () => h('div')
    },
  })

  mount(Probe, { global: { provide: { [ACCOUNT_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useAccountPasswordSetup() did not run during mount')
  }

  return captured
}

describe('useAccountPasswordSetup', () => {
  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const Probe = defineComponent({
      setup() {
        useAccountPasswordSetup()
        return () => h('div')
      },
    })
    expect(() => mount(Probe)).toThrow(/AccountRepository/)
  })

  it('état initial : checking', () => {
    const composable = mountWithComposable(createStubRepository())
    expect(composable.state.value).toBe('checking')
  })

  it('validate() : lien valide -> ready', async () => {
    const composable = mountWithComposable(createStubRepository())

    await composable.validate('token')

    expect(composable.state.value).toBe('ready')
    expect(composable.errorReason.value).toBeNull()
  })

  it('validate() : 404 -> invalid', async () => {
    const composable = mountWithComposable(
      createStubRepository({ validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('invalid', 'x'))) }),
    )

    await composable.validate('token')

    expect(composable.state.value).toBe('invalid')
  })

  it('validate() : 410 -> expired', async () => {
    const composable = mountWithComposable(
      createStubRepository({ validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('expired', 'x'))) }),
    )

    await composable.validate('token')

    expect(composable.state.value).toBe('expired')
  })

  it('validate() : rate-limited -> error avec errorReason', async () => {
    const composable = mountWithComposable(
      createStubRepository({ validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('rate-limited', 'x'))) }),
    )

    await composable.validate('token')

    expect(composable.state.value).toBe('error')
    expect(composable.errorReason.value).toBe('rate-limited')
  })

  it('submit() : succès -> done', async () => {
    const composable = mountWithComposable(createStubRepository())
    await composable.validate('token')

    await composable.submit('token', 'NewSecurePass1')

    expect(composable.state.value).toBe('done')
  })

  it('submit() : mot de passe faible -> retour à ready avec errorReason weak-password', async () => {
    const composable = mountWithComposable(
      createStubRepository({ completePasswordSetup: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('weak-password', 'x'))) }),
    )
    await composable.validate('token')

    await composable.submit('token', 'short')

    expect(composable.state.value).toBe('ready')
    expect(composable.errorReason.value).toBe('weak-password')
  })

  it('submit() : jeton consommé entre-temps (410) -> expired', async () => {
    const composable = mountWithComposable(
      createStubRepository({ completePasswordSetup: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('expired', 'x'))) }),
    )
    await composable.validate('token')

    await composable.submit('token', 'NewSecurePass1')

    expect(composable.state.value).toBe('expired')
  })
})
