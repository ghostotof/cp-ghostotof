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

  it('validate() : lien inutilisable (404) -> unusable', async () => {
    const composable = mountWithComposable(
      createStubRepository({ validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('unusable', 'x'))) }),
    )

    await composable.validate('token')

    expect(composable.state.value).toBe('unusable')
  })

  it("validate() : jeton vide -> incomplete, sans aucun appel au backend (rien à vérifier, et le quota par IP n'est pas entamé)", async () => {
    const repository = createStubRepository()
    const composable = mountWithComposable(repository)

    await composable.validate('')

    expect(composable.state.value).toBe('incomplete')
    expect(composable.errorReason.value).toBe('incomplete')
    expect(repository.validateSetupToken).not.toHaveBeenCalled()
  })

  it('validate() : jeton refusé à la vérification de forme (422) -> incomplete', async () => {
    const composable = mountWithComposable(
      createStubRepository({ validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('incomplete', 'x'))) }),
    )

    await composable.validate('token')

    expect(composable.state.value).toBe('incomplete')
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

  it('submit() : lien devenu inutilisable entre-temps (404) -> unusable', async () => {
    const composable = mountWithComposable(
      createStubRepository({ completePasswordSetup: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('unusable', 'x'))) }),
    )
    await composable.validate('token')

    await composable.submit('token', 'NewSecurePass1')

    expect(composable.state.value).toBe('unusable')
  })
})
