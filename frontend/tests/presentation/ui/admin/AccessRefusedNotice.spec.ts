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
  currentRefusalScope,
  markBackofficeAccessRefused,
  stampBackofficeRead,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'
import type { ReadRefusalScope } from '../../../../src/domain/admin/shared/BackofficeSessionReaction'
import type { AuthRepository } from '../../../../src/domain/auth/repositories/AuthRepository'
import { sessionFor } from '../../../support/authSession'
import { sessionForUser } from '../../../../src/domain/auth/services/sessionForUser'
import { signInAgain } from '../../../support/signInAgain'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import { setUnsupportedLocale } from '../../../support/unsupportedLocale'
import { accessRefusalTexts } from '../../../support/accessRefusalTexts'

const REFUSAL = accessRefusalTexts('fr')
const REFUSAL_EN = accessRefusalTexts('en')

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
/**
 * Un refus réel d'une lecture : par défaut partie maintenant (le périmètre et l'époque courants, jamais d'une page
 * quittée, voir R28) ; `sentIn` est l'estampille relevée plus tôt, celle d'une lecture partie avant le montage de l'alerte.
 */
function refuse(sentIn: ReadRefusalScope = stampBackofficeRead()): void {
  markBackofficeAccessRefused(sentIn, lastAccountName())
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
    expect(alert.get('span').text()).toBe(REFUSAL.accessRefused)
    const link = alert.get('a')
    expect(link.text()).toBe(REFUSAL.switchAccount)
    expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/admin/case-studies&reauth=1')
    expect(link.attributes('target')).toBeUndefined()
    await expectNoAccessibilityViolation(wrapper)
    wrapper.unmount()
  })

  it("une langue d'interface non prise en charge, route sans langue : connexion dans la langue par défaut (#748, R204)", async () => {
    // Une route hors `/:locale(fr|en)` : le lien ne peut lire sa langue que de celle de l'interface, ici hors des langues du site.
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/admin/sans-langue', component: StubPage, meta: { requiresAuth: true } },
        { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
      ],
    })
    await router.push('/admin/sans-langue')
    await router.isReady()
    const i18n = createAppI18n()
    setUnsupportedLocale(i18n, 'de')
    const wrapper = mount(AccessRefusedNotice, {
      attachTo: document.body,
      global: { plugins: [i18n, router], provide: { [AUTH_REPOSITORY as symbol]: repository } },
    })

    refuse()
    await flushPromises()

    expect(wrapper.get('a').attributes('href')).toBe('/fr/login?redirect=/admin/sans-langue&reauth=1')
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
    const scope = currentRefusalScope().scope

    await router.push({ hash: '#main-content' })
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies#main-content')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(currentRefusalScope().scope).toBe(scope)
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

  it("une requête aux paramètres permutés garde l'alerte : vue-router exécute la navigation, la clé triée l'ignore (R54)", async () => {
    const { wrapper, router } = await mountNotice()
    await router.push('/fr/admin/case-studies?a=1&b=2')
    await flushPromises()
    refuse()
    await flushPromises()
    const scope = currentRefusalScope().scope

    // vue-router compare la requête dans l'ordre où elle est écrite : il exécute cette navigation (sans le tri de la clé,
    // R54, le test serait rouge). C'est la clé triée de l'alerte qui n'y voit pas de changement, et la garde.
    await router.push({ query: { b: '2', a: '1' } })
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies?b=2&a=1')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(currentRefusalScope().scope).toBe(scope)
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
    /** Un enregistrement refusé alors qu'aucune alerte n'était montée : la personne avait quitté le backoffice. */
    function writeRefusedWhileAway(): void {
      markBackofficeAccessRefused({ kind: 'write' }, lastAccountName())
    }

    it("un refus d'écriture après le démontage s'affiche au montage suivant, avec son propre texte, inséré après le montage", async () => {
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
      expect(alert.get('span').text()).toBe(REFUSAL.earlierSaveRefused)
      expect(alert.get('a').text()).toBe(REFUSAL.switchAccount)
      wrapper.unmount()
    })

    it("un refus de lecture compté sans alerte est oublié au montage", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      // Ce que le périmètre rend impossible hors du backoffice (le démontage l'avance) mais que le module accepte :
      // une lecture du périmètre courant, comptée alors qu'aucune alerte n'est montée.
      refuse()

      const second = await mountNotice()
      await flushPromises()

      expect(second.wrapper.find('[data-testid="admin-access-refused"]').exists()).toBe(false)
      expect(backofficeAccessRefused.value).toBe(0)
      second.wrapper.unmount()
    })

    it("un nouvel enregistrement refusé sur la page remplace le texte du précédent par celui du refus courant", async () => {
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      const second = await mountNotice()
      await flushPromises()
      const alert = second.wrapper.get('[role="alert"]').element
      const link = second.wrapper.get('a').element
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)

      // Une écriture : le geste courant, il a le texte courant (R76). Une lecture, elle, garde l'avis (test suivant).
      markBackofficeAccessRefused({ kind: 'write' }, lastAccountName())
      await flushPromises()

      expect(second.wrapper.get('span').text()).toBe(REFUSAL.accessRefused)
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
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)

      await second.router.push('/fr/admin/technologies')
      await flushPromises()
      expect(second.wrapper.find('[role="alert"]').exists()).toBe(false)

      refuse()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.accessRefused)
      second.wrapper.unmount()
    })

    it("l'avis de l'enregistrement précédent reste quand la lecture de la page d'arrivée est refusée (R76)", async () => {
      await signInAs('compte-a')
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      // Le chargement de la page d'arrivée part dans le `setup` de la page, avant le montage de l'alerte.
      const landingRead = stampBackofficeRead()
      const second = await mountNotice()
      await flushPromises()
      const message = second.wrapper.get('span').element
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)

      // Le chargement de la page d'arrivée, parti avant le montage, est refusé : son refus compte et se réannonce, mais
      // le texte de l'enregistrement précédent, qui dit déjà ce que dirait une lecture refusée, reste.
      refuse(landingRead)
      await flushPromises()

      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)
      expect(backofficeAccessRefused.value).toBe(2)
      // Le `<span>` est remplacé (sa `key` est le compteur) : c'est ce qui réannonce l'alerte.
      expect(second.wrapper.get('span').element).not.toBe(message)
      second.wrapper.unmount()
    })

    it("l'avis tient toutes les lectures parties avant le montage, et part au refus d'une lecture partie après (#748, R112)", async () => {
      await signInAs('compte-a')
      const first = await mountNotice()
      first.wrapper.unmount()
      writeRefusedWhileAway()
      // Une page à deux listes : deux lectures estampillées avant le montage de l'alerte.
      const firstLandingRead = stampBackofficeRead()
      const secondLandingRead = stampBackofficeRead()
      const second = await mountNotice()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)

      // Les deux lectures du chargement, refusées : l'avis est dit aux deux annonces (le `<span>` est remplacé à chacune).
      const afterMount = second.wrapper.get('span').element
      refuse(firstLandingRead)
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)
      const afterFirstRead = second.wrapper.get('span').element
      expect(afterFirstRead).not.toBe(afterMount)

      refuse(secondLandingRead)
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)
      const afterSecondRead = second.wrapper.get('span').element
      expect(afterSecondRead).not.toBe(afterFirstRead)

      // Le texte de l'alerte déjà affichée ne change pas sans refus…
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.earlierSaveRefused)
      expect(second.wrapper.get('span').element).toBe(afterSecondRead)

      // … et le refus d'une lecture partie après le montage (une relecture, rôle perdu) ramène le texte courant, réannoncé.
      refuse()
      await flushPromises()
      expect(second.wrapper.get('span').text()).toBe(REFUSAL.accessRefused)
      expect(second.wrapper.get('span').element).not.toBe(afterSecondRead)
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

      expect(second.wrapper.get('span').text()).toBe(REFUSAL_EN.earlierSaveRefused)
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
