import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import SetPasswordPage from '../../../src/presentation/pages/SetPasswordPage.vue'
import { ACCOUNT_REPOSITORY } from '../../../src/application/account/useAccountPasswordSetup'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { AccountRepository } from '../../../src/domain/account/repositories/AccountRepository'
import { PasswordSetupLinkError } from '../../../src/domain/account/errors/PasswordSetupLinkError'

const StubPage = { template: '<div />' }

function createStubRepository(overrides: Partial<AccountRepository> = {}): AccountRepository {
  return {
    validateSetupToken: vi.fn(async () => undefined),
    completePasswordSetup: vi.fn(async () => undefined),
    ...overrides,
  }
}

function createTestRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/set-password/:token', name: 'set-password', component: SetPasswordPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
}

async function mountPage(repository: AccountRepository = createStubRepository(), path = '/fr/set-password/tok123') {
  const router = createTestRouter()
  await router.push(path)
  await router.isReady()

  const wrapper = mount(SetPasswordPage, {
    global: { plugins: [router, createAppI18n()], provide: { [ACCOUNT_REPOSITORY as symbol]: repository } },
  })
  await flushPromises()

  return wrapper
}

describe('SetPasswordPage', () => {
  it('valide le jeton au montage puis affiche le formulaire si le lien est bon', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    expect(repository.validateSetupToken).toHaveBeenCalledWith('tok123')
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })

  it('affiche un message et pas de formulaire si le lien est expiré', async () => {
    const repository = createStubRepository({
      validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('expired', 'x'))),
    })
    const wrapper = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
  })

  it('refuse un mot de passe trop court sans appeler le backend', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('short')
    await inputs[1].setValue('short')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('soumet le mot de passe puis affiche un écran de succès avec un lien vers la connexion', async () => {
    const repository = createStubRepository()
    const wrapper = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('NotCompromisedPass1')
    await inputs[1].setValue('NotCompromisedPass1')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).toHaveBeenCalledWith('tok123', 'NotCompromisedPass1')
    expect(wrapper.find('a[href="/fr/login"]').exists()).toBe(true)
  })

  it('affiche un message si le backend rejette le mot de passe, en gardant le formulaire', async () => {
    const repository = createStubRepository({
      completePasswordSetup: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('weak-password', 'x'))),
    })
    const wrapper = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('NotCompromisedPass1')
    await inputs[1].setValue('NotCompromisedPass1')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })
})
