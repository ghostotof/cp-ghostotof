import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent, h, nextTick } from 'vue'
import AccessRefusedNotice from '../../../../src/presentation/ui/admin/AccessRefusedNotice.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { AUTH_REPOSITORY, lastAccountName, markSessionExpired, useAuth } from '../../../../src/application/auth/useAuth'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  markBackofficeAccessRefused,
  refusalScope,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'
import type { AuthRepository } from '../../../../src/domain/auth/repositories/AuthRepository'
import { sessionFor } from '../../../support/authSession'
import { sessionForUser } from '../../../../src/domain/auth/services/sessionForUser'
import { signInAgain } from '../../../support/signInAgain'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const StubPage = defineComponent({ setup: () => () => h('div') })

const repository: AuthRepository = {
  login: vi.fn(async () => ({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
  logout: vi.fn(async () => undefined),
  hasWriteToken: () => true,
  me: vi.fn(async () => sessionFor(null)),
}

async function prepareRouter(): Promise<Router> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      // `requiresAuth`, comme les vraies routes : sans lui le lien de connexion n'a pas de `redirect` (#748, R16, F8).
      { path: '/:locale(fr|en)/admin/case-studies', name: 'admin-case-studies', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/admin/technologies', name: 'admin-technologies', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push('/fr/admin/case-studies')
  await router.isReady()

  return router
}

/** Monte l'alerte de façon synchrone : le premier rendu est observable avant que le moindre `await` ne l'ait laissé se compléter. */
function mountWith(router: Router, language: 'fr' | 'en' = 'fr'): VueWrapper {
  const i18n = createAppI18n()
  i18n.global.locale.value = language

  return mount(AccessRefusedNotice, {
    attachTo: document.body,
    global: { plugins: [i18n, router], provide: { [AUTH_REPOSITORY as symbol]: repository } },
  })
}

async function mountNotice(language: 'fr' | 'en' = 'fr'): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = await prepareRouter()

  return { wrapper: mountWith(router, language), router }
}

/**
 * Connecte `username` dans l'onglet : c'est le « dernier compte affiché » que le montage de l'alerte compare au compte
 * de l'écriture refusée (R76). Un `login` simple, hors session finie.
 */
async function signInAs(username: string): Promise<void> {
  const account = { username, roles: ['ROLE_SUPER', 'ROLE_USER'] }
  const accountRepository: AuthRepository = {
    login: vi.fn(async () => account),
    logout: vi.fn(async () => undefined),
    hasWriteToken: () => true,
    me: vi.fn(async () => sessionForUser(account)),
  }
  let auth: ReturnType<typeof useAuth> | undefined
  const Probe = defineComponent({
    setup() {
      auth = useAuth()
      return () => h('div')
    },
  })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: accountRepository } } })
  await auth?.login(username, 'x')
  probe.unmount()
  await flushPromises()
}

/**
 * L'alerte « accès refusé » du backoffice (#748, R17, F4), rendue une fois par `AdminLayout` : un refus réel
 * (un 403 qui n'est pas une fin de session) la lève, la navigation et la fin de session l'effacent.
 */
/** Un refus réel d'une requête partie sous le périmètre courant (jamais d'une page quittée, voir R28). */
function refuse(): void {
  markBackofficeAccessRefused({ kind: 'read', scope: refusalScope() }, lastAccountName())
}

describe('AccessRefusedNotice (#748, R17)', () => {
  afterEach(async () => {
    clearBackofficeAccessRefusal()
    document.body.innerHTML = ''
    await signInAgain()
  })

  it("n'affiche rien tant qu'aucun refus n'a eu lieu", async () => {
    const { wrapper } = await mountNotice()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
    wrapper.unmount()
  })

  it("un refus insère une alerte neutre et le lien « Changer de compte », dans le même onglet", async () => {
    const { wrapper } = await mountNotice()

    refuse()
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('Le serveur refuse cette demande à votre session')
    const link = alert.get('a')
    expect(link.text()).toBe('Changer de compte')
    expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/admin/case-studies&reauth=1')
    expect(link.attributes('target')).toBeUndefined()
    await expectNoAccessibilityViolation(wrapper)
    wrapper.unmount()
  })

  it("un second refus remplace le message : l'alerte et son lien restent le même élément", async () => {
    const { wrapper } = await mountNotice()
    refuse()
    await flushPromises()
    const alert = wrapper.get('[role="alert"]').element
    const link = wrapper.get('a').element

    refuse()
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.get('[role="alert"]').element).toBe(alert)
    expect(wrapper.get('a').element).toBe(link)
    wrapper.unmount()
  })

  it("la navigation efface l'alerte", async () => {
    const { wrapper, router } = await mountNotice()
    refuse()
    await flushPromises()

    await router.push('/fr/admin/technologies')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("un changement du seul fragment garde l'alerte et le périmètre (le lien d'évitement, R38)", async () => {
    const { wrapper, router } = await mountNotice()
    refuse()
    await flushPromises()
    const scope = refusalScope()

    await router.push({ hash: '#main-content' })
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies#main-content')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(refusalScope()).toBe(scope)
    wrapper.unmount()
  })

  it("un changement de requête efface l'alerte", async () => {
    const { wrapper, router } = await mountNotice()
    refuse()
    await flushPromises()

    await router.push({ query: { page: '2' } })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("une requête aux paramètres permutés garde l'alerte : vue-router exécute la navigation, la clé triée l'ignore (R54, CR5-8)", async () => {
    const { wrapper, router } = await mountNotice()
    await router.push('/fr/admin/case-studies?a=1&b=2')
    await flushPromises()
    refuse()
    await flushPromises()
    const scope = refusalScope()

    // vue-router compare la requête dans l'ordre où elle est écrite : il exécute cette navigation (le test était rouge
    // avant le tri de la clé, R54). C'est la clé triée de l'alerte qui n'y voit pas de changement, et la garde.
    await router.push({ query: { b: '2', a: '1' } })
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies?b=2&a=1')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(refusalScope()).toBe(scope)
    wrapper.unmount()
  })

  it("une session finie masque l'alerte et l'efface : elle ne revient pas avec la session", async () => {
    const { wrapper } = await mountNotice()
    refuse()
    await flushPromises()

    markSessionExpired()
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    await signInAgain()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("le démontage efface le refus : une autre visite du backoffice repart sans alerte", async () => {
    const first = await mountNotice()
    refuse()
    await flushPromises()
    first.wrapper.unmount()

    const second = await mountNotice()

    expect(second.wrapper.find('[role="alert"]').exists()).toBe(false)
    second.wrapper.unmount()
  })

  describe("l'enregistrement refusé pendant l'absence (R64, décision 1 de la sixième relecture)", () => {
    const earlierSaveFr =
      "Un enregistrement précédent a été refusé par le serveur : votre compte n'a peut-être pas, ou plus, l'accès de confiance, ou la session doit être rouverte. Changez de compte, ou reconnectez-vous au même, pour continuer."
    const earlierSaveEn =
      'An earlier save was refused by the server: your account may not have, or may no longer have, trusted access, or the session needs to be reopened. Switch account, or sign in again to the same one, to continue.'

    /** Un enregistrement refusé alors qu'aucune alerte n'était montée : la personne avait quitté le backoffice. */
    function writeRefusedWhileAway(): void {
      markBackofficeAccessRefused({ kind: 'write' }, lastAccountName())
    }

    it("un refus d'écriture arrivé après le démontage s'affiche au montage suivant, avec son propre texte, inséré après le montage", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const router = await prepareRouter()

      const wrapper = mountWith(router)

      // Annoncée : l'alerte est insérée après le premier rendu (une région présente dès le premier rendu peut ne pas
      // l'être, `frontend-a11y.md`).
      expect(wrapper.find('[data-testid="admin-access-refused"]').exists()).toBe(false)
      await nextTick()
      const alert = wrapper.get('[data-testid="admin-access-refused"]')
      expect(alert.attributes('role')).toBe('alert')
      expect(alert.get('span').text()).toBe(earlierSaveFr)
      expect(alert.get('a').text()).toBe('Changer de compte')
      wrapper.unmount()
    })

    it("un refus de lecture compté sans alerte est oublié au montage", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      // Ce que le périmètre rend impossible hors du backoffice (le démontage l'avance) mais que le module accepte :
      // une lecture du périmètre courant, comptée alors qu'aucune alerte n'est montée.
      markBackofficeAccessRefused({ kind: 'read', scope: refusalScope() }, lastAccountName())

      const second = await mountNotice()
      await flushPromises()

      expect(second.wrapper.find('[data-testid="admin-access-refused"]').exists()).toBe(false)
      expect(backofficeAccessRefused.value).toBe(0)
      second.wrapper.unmount()
    })

    it("un nouvel enregistrement refusé sur la page remplace le texte de l'enregistrement précédent par celui du refus courant", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const second = await mountNotice()
      await flushPromises()
      const alert = second.wrapper.get('[role="alert"]').element
      const link = second.wrapper.get('a').element
      expect(second.wrapper.get('span').text()).toBe(earlierSaveFr)

      // Une écriture : le geste courant, il a le texte courant (R76, CR7-4). Une lecture, elle, garde l'avis (test suivant).
      markBackofficeAccessRefused({ kind: 'write' }, lastAccountName())
      await flushPromises()

      expect(second.wrapper.get('span').text()).toContain('Le serveur refuse cette demande à votre session')
      expect(second.wrapper.get('span').text()).not.toBe(earlierSaveFr)
      expect(second.wrapper.get('[role="alert"]').element).toBe(alert)
      expect(second.wrapper.get('a').element).toBe(link)
      second.wrapper.unmount()
    })

    it("une navigation efface l'alerte de l'enregistrement précédent, et le refus suivant redit le texte courant", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const second = await mountNotice()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(earlierSaveFr)

      await second.router.push('/fr/admin/technologies')
      await flushPromises()
      expect(second.wrapper.find('[role="alert"]').exists()).toBe(false)

      refuse()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toContain('Le serveur refuse cette demande à votre session')
      second.wrapper.unmount()
    })

    it("l'avis de l'enregistrement précédent reste quand la lecture de la page d'arrivée est refusée (R76, CR7-4)", async () => {
      await signInAs('compte-a')
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const second = await mountNotice()
      await flushPromises()
      const message = second.wrapper.get('span').element
      expect(second.wrapper.get('span').text()).toBe(earlierSaveFr)

      // Le chargement de la page d'arrivée, parti avant le montage, est refusé : son refus compte et se réannonce, mais
      // le texte de l'enregistrement précédent, qui dit déjà ce que dirait une lecture refusée, reste.
      refuse()
      await flushPromises()

      expect(second.wrapper.get('span').text()).toBe(earlierSaveFr)
      expect(backofficeAccessRefused.value).toBe(2)
      // Le `<span>` est remplacé (sa `key` est le compteur) : c'est ce qui réannonce l'alerte.
      expect(second.wrapper.get('span').element).not.toBe(message)
      second.wrapper.unmount()
    })

    it("l'avis de l'enregistrement précédent tient la lecture de chargement, pas la suivante (R95, décision 4)", async () => {
      await signInAs('compte-a')
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const second = await mountNotice()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(earlierSaveFr)

      // La lecture de chargement, refusée : l'avis reste, le `<span>` est remplacé (réannoncé).
      const afterMount = second.wrapper.get('span').element
      refuse()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(earlierSaveFr)
      expect(second.wrapper.get('span').element).not.toBe(afterMount)

      // Une relecture refusée plus tard : le texte courant, et l'alerte se réannonce encore.
      const afterLoad = second.wrapper.get('span').element
      refuse()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toContain('Le serveur refuse cette demande à votre session')
      expect(second.wrapper.get('span').text()).not.toBe(earlierSaveFr)
      expect(second.wrapper.get('span').element).not.toBe(afterLoad)
      second.wrapper.unmount()
    })

    it("une écriture refusée sous A, B connecté dans l'onglet : aucune alerte au montage (R76, décision 2)", async () => {
      await signInAs('compte-a')
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      // A se déconnecte (le compte retenu est oublié), B se connecte dans l'onglet et ouvre le backoffice.
      await signInAs('compte-b')

      const second = await mountNotice()
      await flushPromises()

      expect(second.wrapper.find('[data-testid="admin-access-refused"]').exists()).toBe(false)
      expect(backofficeAccessRefused.value).toBe(0)
      second.wrapper.unmount()
    })

    it("le texte de l'enregistrement précédent en anglais", async () => {
      const first = await mountNotice('en')
      first.wrapper.unmount()
      writeRefusedWhileAway()

      const second = await mountNotice('en')
      await flushPromises()

      expect(second.wrapper.get('span').text()).toBe(earlierSaveEn)
      second.wrapper.unmount()
    })

    it("l'alerte de l'enregistrement précédent n'a aucune violation d'accessibilité", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const second = await mountNotice()
      await flushPromises()

      await expectNoAccessibilityViolation(second.wrapper)
      second.wrapper.unmount()
    })
  })
})
