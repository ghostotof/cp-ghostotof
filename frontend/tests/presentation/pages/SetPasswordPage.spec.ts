import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, flushPromises } from '@vue/test-utils'
import { createRouter, createWebHistory, type Router } from 'vue-router'
import SetPasswordPage from '../../../src/presentation/pages/SetPasswordPage.vue'
import { ACCOUNT_REPOSITORY } from '../../../src/application/account/useAccountPasswordSetup'
import { createAppI18n } from '../../../src/presentation/i18n'
import type { AccountRepository } from '../../../src/domain/account/repositories/AccountRepository'
import { PasswordSetupLinkError } from '../../../src/domain/account/errors/PasswordSetupLinkError'
import { applySeoMeta } from '../../../src/presentation/router/seo'
import { expectNoAccessibilityViolation } from '../../support/axe'

const StubPage = { template: '<div />' }

function createStubRepository(overrides: Partial<AccountRepository> = {}): AccountRepository {
  return {
    validateSetupToken: vi.fn(async () => undefined),
    completePasswordSetup: vi.fn(async () => undefined),
    ...overrides,
  }
}

/**
 * Historique web (et non mémoire) : le nettoyage de l'URL se juge sur
 * `window.location` et `window.history.state`, ce que voit réellement le
 * navigateur. Même forme de route que presentation/router/index.ts : le jeton
 * n'arrive que par le fragment, aucun segment de chemin ne le porte (T4.4).
 */
function createTestRouter(): Router {
  return createRouter({
    history: createWebHistory(),
    routes: [
      {
        path: '/:locale(fr|en)/set-password',
        name: 'set-password',
        component: SetPasswordPage,
        meta: { noindex: true },
      },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
}

async function mountPage(repository: AccountRepository = createStubRepository(), path = '/fr/set-password#tok123') {
  // L'URL d'arrivée est celle du lien de l'e-mail : posée dans le navigateur
  // AVANT la création du routeur, comme lors d'un vrai chargement de page.
  window.history.replaceState(null, '', path)

  const router = createTestRouter()
  router.afterEach((to) => applySeoMeta(to))
  await router.push(path)
  await router.isReady()

  const wrapper = mount(SetPasswordPage, {
    global: { plugins: [router, createAppI18n()], provide: { [ACCOUNT_REPOSITORY as symbol]: repository } },
  })
  await flushPromises()

  return { wrapper, router }
}

/** Tout ce que le DOM de <head> publie comme URL (canonical, hreflang). */
function headLinkHrefs(): string[] {
  return Array.from(document.head.querySelectorAll('link')).map((link) => link.getAttribute('href') ?? '')
}

enableAutoUnmount(afterEach)

afterEach(() => {
  window.history.replaceState(null, '', '/')
  document.head.querySelectorAll('link, meta[name="robots"], meta[name="description"]').forEach((node) => node.remove())
})

describe('SetPasswordPage — lecture du jeton et nettoyage de l\'URL (audit A7)', () => {
  const TOKEN = 'a'.repeat(64)

  it('lit le jeton dans le fragment du lien', async () => {
    const repository = createStubRepository()
    await mountPage(repository, `/fr/set-password#${TOKEN}`)

    expect(repository.validateSetupToken).toHaveBeenCalledWith(TOKEN)
  })

  it.each([
    ['fragment', `/fr/set-password#${TOKEN}`],
  ])("efface le jeton de l'URL affichée après lecture — %s", async (_label, path) => {
    await mountPage(createStubRepository(), path)

    expect(window.location.pathname).toBe('/fr/set-password')
    expect(window.location.hash).toBe('')
    expect(window.location.href).not.toContain(TOKEN)
  })

  it.each([
    ['fragment', `/fr/set-password#${TOKEN}`],
  ])("ne laisse le jeton ni dans history.state ni dans la route courante, et l'état de vue-router reste cohérent — %s", async (_label, path) => {
    const { router } = await mountPage(createStubRepository(), path)

    // vue-router range sa position dans history.state (`current`, `back`,
    // `position`…) : elle doit survivre au nettoyage, sans le jeton.
    const state = window.history.state as { current?: unknown; position?: unknown } | null
    expect(state?.current).toBe('/fr/set-password')
    expect(typeof state?.position).toBe('number')
    expect(JSON.stringify(window.history.state)).not.toContain(TOKEN)

    expect(router.currentRoute.value.fullPath).toBe('/fr/set-password')
    expect(router.currentRoute.value.name).toBe('set-password')
  })

  it("remplace l'entrée d'historique au lieu d'en ajouter une (« Précédent » ne ramène pas sur l'URL à jeton)", async () => {
    const lengthBefore = window.history.length
    await mountPage(createStubRepository(), `/fr/set-password#${TOKEN}`)

    // Une seule entrée ajoutée : le push initial du test. Le nettoyage est un replace.
    expect(window.history.length).toBeLessThanOrEqual(lengthBefore + 1)
  })

  it('garde le jeton en mémoire après le nettoyage : la soumission l\'envoie bien', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository, `/fr/set-password#${TOKEN}`)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('NotCompromisedPass1')
    await inputs[1].setValue('NotCompromisedPass1')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).toHaveBeenCalledWith(TOKEN, 'NotCompromisedPass1')
  })

  it("rendue par <RouterView> comme dans l'application : le nettoyage ne remonte pas la page, le jeton en mémoire survit", async () => {
    const repository = createStubRepository()
    window.history.replaceState(null, '', `/fr/set-password#${TOKEN}`)
    const router = createTestRouter()
    await router.push(`/fr/set-password#${TOKEN}`)
    await router.isReady()

    const wrapper = mount(
      { template: '<RouterView />' },
      { global: { plugins: [router, createAppI18n()], provide: { [ACCOUNT_REPOSITORY as symbol]: repository } } },
    )
    await flushPromises()

    expect(window.location.pathname).toBe('/fr/set-password')
    // Un remontage relancerait la validation avec un jeton vide -> `invalid`.
    expect(repository.validateSetupToken).toHaveBeenCalledTimes(1)

    const inputs = wrapper.findAll('input[type="password"]')
    expect(inputs).toHaveLength(2)
    await inputs[0].setValue('NotCompromisedPass1')
    await inputs[1].setValue('NotCompromisedPass1')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).toHaveBeenCalledWith(TOKEN, 'NotCompromisedPass1')
  })

  it('ne range le jeton dans aucun stockage du navigateur', async () => {
    await mountPage(createStubRepository(), `/fr/set-password#${TOKEN}`)

    expect(JSON.stringify({ ...window.localStorage })).not.toContain(TOKEN)
    expect(JSON.stringify({ ...window.sessionStorage })).not.toContain(TOKEN)
  })

  it('aucun jeton (pas de fragment) : lien invalide, sans appel au backend', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository, '/fr/set-password')

    expect(repository.validateSetupToken).not.toHaveBeenCalled()
    // Cas aussi atteint en rechargeant la page après le nettoyage de l'URL :
    // le message doit dire quoi faire, pas seulement que ça a échoué.
    expect(wrapper.get('[role="alert"]').text()).toContain("n'est pas valide")
    expect(wrapper.get('[role="alert"]').text()).toContain("e-mail d'invitation")
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
  })

  it('fragment vide (« # » seul) : traité comme une absence de jeton', async () => {
    const repository = createStubRepository()
    await mountPage(repository, '/fr/set-password#')

    expect(repository.validateSetupToken).not.toHaveBeenCalled()
  })

  it.each([
    ['fragment', `/fr/set-password#${TOKEN}`],
  ])('le jeton n\'apparaît dans aucun canonical/hreflang, ni dans le titre — %s', async (_label, path) => {
    await mountPage(createStubRepository(), path)

    const hrefs = headLinkHrefs()
    expect(hrefs.length).toBeGreaterThan(0)
    for (const href of hrefs) {
      expect(href).not.toContain(TOKEN)
    }
    expect(document.title).not.toContain(TOKEN)
    expect(document.head.innerHTML).not.toContain(TOKEN)
  })
})

describe('SetPasswordPage', () => {
  it('valide le jeton au montage puis affiche le formulaire si le lien est bon', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

    expect(repository.validateSetupToken).toHaveBeenCalledWith('tok123')
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })

  it('affiche un message et pas de formulaire si le lien est expiré', async () => {
    const repository = createStubRepository({
      validateSetupToken: vi.fn(async () => Promise.reject(new PasswordSetupLinkError('expired', 'x'))),
    })
    const { wrapper } = await mountPage(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
  })

  it('état d\'erreur (ex. rate-limit) : propose un bouton « Réessayer » qui relance la validation', async () => {
    const validateSetupToken = vi.fn(async () => Promise.reject(new PasswordSetupLinkError('rate-limited', 'x')))
    const repository = createStubRepository({ validateSetupToken })
    const { wrapper } = await mountPage(repository)

    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    const retryButton = wrapper.findAll('button').find((b) => b.text().includes('Réessayer'))
    expect(retryButton).toBeDefined()

    await retryButton?.trigger('click')
    await flushPromises()

    expect(validateSetupToken).toHaveBeenCalledTimes(2)
  })

  it('refuse un mot de passe trop court sans appeler le backend', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('short')
    await inputs[1].setValue('short')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('compte la longueur en points de code comme le backend : quatre emojis sont refusés localement (#410)', async () => {
    // 4 points de code, mais 8 unités UTF-16 : `string.length` les laissait
    // passer, et le backend (`mb_strlen`) répondait 422.
    const fourEmojis = '🔑🔑🔑🔑'
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue(fourEmojis)
    await inputs[1].setValue(fourEmojis)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('au moins 8 caractères')
  })

  it('refuse localement un mot de passe de plus de 4 096 octets UTF-8, avec un message qui le dit (#410)', async () => {
    // 2 049 « é » : 2 049 caractères (sous toute borne en caractères), mais
    // 4 098 octets. Sans ce contrôle, le 422 du backend s'affichait comme un
    // mot de passe « trop courant ou présent dans une fuite », ce qui est faux.
    const tooLong = 'é'.repeat(2049)
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue(tooLong)
    await inputs[1].setValue(tooLong)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(repository.completePasswordSetup).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('trop long')
    expect(wrapper.get('[role="alert"]').text()).toContain('4096 octets')
  })

  it('relie le champ mot de passe à la règle de longueur, puis au message d\'erreur quand il apparaît (a11y)', async () => {
    const { wrapper } = await mountPage(createStubRepository())

    const passwordInput = wrapper.get('#set-password-password')
    const describedBy = (): string[] => (passwordInput.attributes('aria-describedby') ?? '').split(' ').filter(Boolean)
    // Avant toute saisie : l'indication « Au moins 8 caractères » seule.
    expect(describedBy()).toHaveLength(1)
    expect(wrapper.get(`#${describedBy()[0]}`).text()).toContain('Au moins 8 caractères')

    await passwordInput.setValue('🔑🔑🔑🔑')
    await wrapper.get('#set-password-confirmation').setValue('🔑🔑🔑🔑')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    // Le message d'erreur, rendu dans une région `alert`, est aussi annoncé
    // quand on revient sur le champ.
    expect(describedBy()).toHaveLength(2)
    expect(wrapper.get(`#${describedBy()[1]}`).attributes('role')).toBe('alert')
    expect(wrapper.get(`#${describedBy()[1]}`).text()).toContain('au moins 8 caractères')
  })

  it('formulaire avec une erreur affichée : aucune violation axe', async () => {
    const { wrapper } = await mountPage(createStubRepository())

    await wrapper.get('#set-password-password').setValue('short')
    await wrapper.get('#set-password-confirmation').setValue('short')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
  })

  it('soumet le mot de passe puis affiche un écran de succès avec un lien vers la connexion', async () => {
    const repository = createStubRepository()
    const { wrapper } = await mountPage(repository)

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
    const { wrapper } = await mountPage(repository)

    const inputs = wrapper.findAll('input[type="password"]')
    await inputs[0].setValue('NotCompromisedPass1')
    await inputs[1].setValue('NotCompromisedPass1')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })
})
