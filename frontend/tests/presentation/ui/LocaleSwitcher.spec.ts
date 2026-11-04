import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LocaleSwitcher from '../../../src/presentation/ui/LocaleSwitcher.vue'
import { createAppI18n, i18n as appI18n } from '../../../src/presentation/i18n'
import { LOCALE_STORAGE_KEY } from '../../../src/presentation/router/preferredLocale'
import { buttonLabelled } from '../../support/buttonLabelled'
import { setUnsupportedLocale } from '../../support/unsupportedLocale'
import en from '../../../src/infrastructure/i18n/locales/en.json'

const StubPage = { template: '<div />' }

/**
 * `i18n` : une instance isolée par défaut ; l'instance de l'application (`appI18n`) pour les cas où le sélecteur pose la
 * langue d'interface lui-même (page sans préfixe, D4 de la dix-huitième relecture) : `applyInterfaceLocale` écrit celle-là,
 * comme le routeur, et `applySeoMeta` traduit le titre avec elle. Ces cas la remettent en `fr` en `afterEach`.
 */
async function mountSwitcher(initialPath = '/fr/about', i18n = createAppI18n()) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/about', name: 'about', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
      { path: '/:locale(fr|en)/oauth/consent', name: 'oauth-consent', component: StubPage },
      // Comme la page 404 de l'application : aucun segment `:locale`.
      { path: '/:pathMatch(.*)*', name: 'not-found', component: StubPage, meta: { titleKey: 'seo.notFound.title' } },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(LocaleSwitcher, { global: { plugins: [router, i18n] } })
  return { wrapper, router }
}

/** Remet l'état global que le sélecteur écrit sur une page sans préfixe : la langue de l'application et ses trois reflets. */
function resetInterfaceLocale(): void {
  appI18n.global.locale.value = 'fr'
  localStorage.removeItem(LOCALE_STORAGE_KEY)
  document.documentElement.lang = ''
  document.title = ''
}

describe('LocaleSwitcher', () => {
  beforeEach(resetInterfaceLocale)
  afterEach(resetInterfaceLocale)

  it('marque la locale courante comme active (aria-current)', async () => {
    const { wrapper } = await mountSwitcher('/fr/about')

    expect(buttonLabelled(wrapper, 'FR').attributes('aria-current')).toBe('true')
    expect(buttonLabelled(wrapper, 'EN').attributes('aria-current')).toBeUndefined()
  })

  it('change de langue en conservant le même chemin', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/about')

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/about')
  })

  // CR18-1 : la demande OAuth (PKCE, `state`) est dans la query ; la perdre casse le flux du consentement.
  it('garde la query et le hash en changeant de langue (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/oauth/consent?client_id=c&state=s&code_challenge=x#h')

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/en/oauth/consent?client_id=c&state=s&code_challenge=x#h')
    expect(router.currentRoute.value.query).toEqual({ client_id: 'c', state: 's', code_challenge: 'x' })
  })

  it('garde les drapeaux de la connexion en changeant de langue (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/login?redirect=%2Ffr%2Fadmin&reauth=1')

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/login')
    expect(router.currentRoute.value.query).toEqual({ redirect: '/en/admin', reauth: '1' })
  })

  // CR19-2 : une cible restée en `/fr/…` ramènerait le français après la connexion ; seule une cible interne se réécrit.
  it.each([
    ['une cible externe', '%2F%2Fevil.example%2Ffr', '//evil.example/fr'],
    ['une cible sans préfixe de langue', '%2Ffrogs', '/frogs'],
    ['une cible refusée par internalRedirectTarget', '%2Ffr%2F%5Cevil.example', '/fr/\\evil.example'],
  ])('garde %s telle quelle en changeant de langue (#748)', async (_label, encoded, kept) => {
    const { wrapper, router } = await mountSwitcher(`/fr/login?redirect=${encoded}&reauth=1`)

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/login')
    expect(router.currentRoute.value.query).toEqual({ redirect: kept, reauth: '1' })
  })

  it("page 404 : la langue de l'interface est la langue courante (#748, R213)", async () => {
    const { wrapper } = await mountSwitcher('/frogs')

    expect(buttonLabelled(wrapper, 'FR').attributes('aria-current')).toBe('true')
  })

  it("page 404 : un chemin qui ne commence pas par la langue reste tel quel, `/frogs` ne devient pas `/engs` (#748, R213)", async () => {
    const { wrapper, router } = await mountSwitcher('/frogs')

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/frogs')
  })

  // D4 de la dix-huitième relecture (CR18-2) : sur `/frogs`, pousser le même chemin ne change rien ;
  // le sélecteur pose la langue sans naviguer.
  it('sur une page sans préfixe, change la langue sans naviguer (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/frogs', appI18n)
    const push = vi.spyOn(router, 'push')

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(push).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/frogs')
    expect(appI18n.global.locale.value).toBe('en')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('en')
    expect(document.documentElement.lang).toBe('en')
    expect(document.title).toBe(`CP-Ghostotof — ${en.seo.notFound.title}`)
    expect(buttonLabelled(wrapper, 'EN').attributes('aria-current')).toBe('true')
    expect(buttonLabelled(wrapper, 'FR').attributes('aria-current')).toBeUndefined()
  })

  it('page 404 sous un préfixe : `/fr/frogs` devient `/en/frogs` (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/frogs')

    await buttonLabelled(wrapper, 'EN').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/frogs')
  })

  // Sp18-2 : une langue d'interface que le site ne porte pas (`de`) n'atteint aucune adresse.
  it('sous une langue hors du site, la langue de la route est la langue courante, aucune adresse `/de/` (#748, R227)', async () => {
    const i18n = createAppI18n()
    setUnsupportedLocale(i18n, 'de')
    const { wrapper, router } = await mountSwitcher('/en/about', i18n)

    expect(buttonLabelled(wrapper, 'EN').attributes('aria-current')).toBe('true')
    await buttonLabelled(wrapper, 'FR').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/fr/about')
  })

  it('sous une langue hors du site, la page 404 est en `fr` (#748, R227)', async () => {
    const i18n = createAppI18n()
    setUnsupportedLocale(i18n, 'de')
    const { wrapper } = await mountSwitcher('/frogs', i18n)

    expect(buttonLabelled(wrapper, 'FR').attributes('aria-current')).toBe('true')
    expect(buttonLabelled(wrapper, 'EN').attributes('aria-current')).toBeUndefined()
  })
})
