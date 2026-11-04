import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../src/domain/portfolio/entities/Locale'
import LocaleSwitcher from '../../../src/presentation/ui/LocaleSwitcher.vue'
import { createAppI18n, i18n as appI18n } from '../../../src/presentation/i18n'
import { LOCALE_STORAGE_KEY } from '../../../src/presentation/router/preferredLocale'
import { accessibleNameOf, expectLabelInName, expectNoAccessibilityViolation } from '../../support/axe'
import { setUnsupportedLocale } from '../../support/unsupportedLocale'
import en from '../../../src/infrastructure/i18n/locales/en.json'
import fr from '../../../src/infrastructure/i18n/locales/fr.json'

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

/**
 * Le bouton de la langue `locale`, trouvé par son attribut `lang` : son texte n'est plus seulement le code affiché (« FR »), il
 * porte aussi le nom natif masqué (R243 de la vingtième relecture), donc `buttonLabelled` (égalité du texte) ne le trouve plus.
 */
function localeButton(wrapper: VueWrapper, locale: Locale) {
  const button = wrapper.findAll<HTMLButtonElement>('button').find((candidate) => candidate.attributes('lang') === locale)
  if (!button) throw new Error(`Bouton de la langue « ${locale} » introuvable.`)
  return button
}

/** Le texte que voit une personne : celui du bouton sans ce que `.visually-hidden` retire à l'écran. */
function visibleTextOf(button: Element): string {
  const copy = button.cloneNode(true) as Element
  copy.querySelectorAll('.visually-hidden').forEach((hidden) => hidden.remove())
  return (copy.textContent ?? '').trim()
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

  // R243 de la vingtième relecture : WCAG 2.5.3 (Label in Name, niveau A), règle axe `label-content-name-mismatch`. Un nom
  // accessible « Français » pour un bouton qui affiche « FR » ne contient pas le texte visible : la commande vocale « cliquer FR »
  // ne trouve rien. Le nom commence par le texte visible et ajoute le nom natif de la langue.
  it.each(SUPPORTED_LOCALES)('le nom accessible du bouton %s commence par son texte visible et contient le nom natif (R243)', async (locale) => {
    const { wrapper } = await mountSwitcher('/fr/about')
    const button = localeButton(wrapper, locale)

    const name = accessibleNameOf(button.element)
    expect(visibleTextOf(button.element)).toBe(locale.toUpperCase())
    expect(name.startsWith(visibleTextOf(button.element))).toBe(true)
    expect(name).toContain(LOCALE_NATIVE_NAMES[locale])
    expect(name).toBe(`${locale.toUpperCase()} (${LOCALE_NATIVE_NAMES[locale]})`)
    expect(button.attributes('aria-label')).toBeUndefined()
  })

  // Le nom natif est prononcé dans sa langue (« English » en anglais sur une page française).
  it.each(SUPPORTED_LOCALES)('le nom natif masqué du bouton %s porte sa langue (R243)', async (locale) => {
    const { wrapper } = await mountSwitcher('/fr/about')
    const hidden = localeButton(wrapper, locale).find('.visually-hidden')

    expect(hidden.exists()).toBe(true)
    expect(hidden.attributes('lang')).toBe(locale)
    expect(hidden.text()).toContain(LOCALE_NATIVE_NAMES[locale])
  })

  it('le groupe garde son étiquette, sans texte visible concurrent (R243)', async () => {
    const { wrapper } = await mountSwitcher('/fr/about')

    expect(wrapper.find('[role="group"]').attributes('aria-label')).toBe(fr.localeSwitcher.label)
  })

  // `expectLabelInName` allume la règle `label-content-name-mismatch` (expérimentale, éteinte par défaut dans axe).
  it('ne relève aucune violation axe, règle « Label in Name » comprise (R243)', async () => {
    const { wrapper } = await mountSwitcher('/fr/about')

    await expectNoAccessibilityViolation(wrapper)
    await expectLabelInName(wrapper)
  })

  it('marque la locale courante comme active (aria-current)', async () => {
    const { wrapper } = await mountSwitcher('/fr/about')

    expect(localeButton(wrapper, 'fr').attributes('aria-current')).toBe('true')
    expect(localeButton(wrapper, 'en').attributes('aria-current')).toBeUndefined()
  })

  it('change de langue en conservant le même chemin', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/about')

    await localeButton(wrapper, 'en').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/about')
  })

  // CR18-1 : la demande OAuth (PKCE, `state`) est dans la query ; la perdre casse le flux du consentement.
  it('garde la query et le hash en changeant de langue (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/oauth/consent?client_id=c&state=s&code_challenge=x#h')

    await localeButton(wrapper, 'en').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/en/oauth/consent?client_id=c&state=s&code_challenge=x#h')
    expect(router.currentRoute.value.query).toEqual({ client_id: 'c', state: 's', code_challenge: 'x' })
  })

  it('garde les drapeaux de la connexion en changeant de langue (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/login?redirect=%2Ffr%2Fadmin&reauth=1')

    await localeButton(wrapper, 'en').trigger('click')
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

    await localeButton(wrapper, 'en').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/login')
    expect(router.currentRoute.value.query).toEqual({ redirect: kept, reauth: '1' })
  })

  it("page 404 : la langue de l'interface est la langue courante (#748, R213)", async () => {
    const { wrapper } = await mountSwitcher('/frogs')

    expect(localeButton(wrapper, 'fr').attributes('aria-current')).toBe('true')
  })

  it("page 404 : un chemin qui ne commence pas par la langue reste tel quel, `/frogs` ne devient pas `/engs` (#748, R213)", async () => {
    const { wrapper, router } = await mountSwitcher('/frogs')

    await localeButton(wrapper, 'en').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/frogs')
  })

  // D4 de la dix-huitième relecture (CR18-2) : sur `/frogs`, pousser le même chemin ne change rien ;
  // le sélecteur pose la langue sans naviguer.
  it('sur une page sans préfixe, change la langue sans naviguer (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/frogs', appI18n)
    const push = vi.spyOn(router, 'push')

    await localeButton(wrapper, 'en').trigger('click')
    await flushPromises()

    expect(push).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/frogs')
    expect(appI18n.global.locale.value).toBe('en')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('en')
    expect(document.documentElement.lang).toBe('en')
    expect(document.title).toBe(`CP-Ghostotof — ${en.seo.notFound.title}`)
    expect(localeButton(wrapper, 'en').attributes('aria-current')).toBe('true')
    expect(localeButton(wrapper, 'fr').attributes('aria-current')).toBeUndefined()
  })

  it('page 404 sous un préfixe : `/fr/frogs` devient `/en/frogs` (#748, R227)', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/frogs')

    await localeButton(wrapper, 'en').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/frogs')
  })

  // Sp18-2 : une langue d'interface que le site ne porte pas (`de`) n'atteint aucune adresse.
  it('sous une langue hors du site, la langue de la route est la langue courante, aucune adresse `/de/` (#748, R227)', async () => {
    const i18n = createAppI18n()
    setUnsupportedLocale(i18n, 'de')
    const { wrapper, router } = await mountSwitcher('/en/about', i18n)

    expect(localeButton(wrapper, 'en').attributes('aria-current')).toBe('true')
    await localeButton(wrapper, 'fr').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/fr/about')
  })

  it('sous une langue hors du site, la page 404 est en `fr` (#748, R227)', async () => {
    const i18n = createAppI18n()
    setUnsupportedLocale(i18n, 'de')
    const { wrapper } = await mountSwitcher('/frogs', i18n)

    expect(localeButton(wrapper, 'fr').attributes('aria-current')).toBe('true')
    expect(localeButton(wrapper, 'en').attributes('aria-current')).toBeUndefined()
  })
})
