import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import CookieNotice from '../../../src/presentation/layout/CookieNotice.vue'
import { COOKIE_NOTICE_STORAGE_KEY } from '../../../src/application/cookieNotice/useCookieNotice'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

const Stub = { template: '<div />' }

async function mountNotice(locale: 'fr' | 'en' = 'fr') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: Stub },
      { path: '/:locale(fr|en)/privacy-policy', name: 'privacy-policy', component: Stub },
    ],
  })
  await router.push(`/${locale}`)
  await router.isReady()

  const i18n = createAppI18n()
  i18n.global.locale.value = locale

  return mount(CookieNotice, { global: { plugins: [router, i18n] } })
}

describe('CookieNotice', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('is a labelled region, so it is reachable as a landmark', async () => {
    const wrapper = await mountNotice()

    const region = wrapper.get('section')
    expect(region.attributes('aria-label')).toBe('Information sur les cookies')
  })

  it('links to the privacy policy of the active locale', async () => {
    const wrapper = await mountNotice('en')

    expect(wrapper.get('a').attributes('href')).toBe('/en/privacy-policy')
    expect(wrapper.text()).toContain('nothing to accept or refuse')
  })

  it('offers a single action: there is no choice to make, hence no refusal', async () => {
    const wrapper = await mountNotice()

    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(1)
    expect(buttons[0].attributes('type')).toBe('button')
    expect(buttons[0].text()).toBe('Compris')
  })

  it('disappears once dismissed and stays gone on the next visit', async () => {
    const wrapper = await mountNotice()

    await wrapper.get('button').trigger('click')

    expect(wrapper.find('section').exists()).toBe(false)
    expect(localStorage.getItem(COOKIE_NOTICE_STORAGE_KEY)).toBe('1')
    expect((await mountNotice()).find('section').exists()).toBe(false)
  })

  it('is neither a dialog nor a focus trap: it must never block the page', async () => {
    const wrapper = await mountNotice()

    expect(wrapper.find('[role="dialog"], [role="alertdialog"], [aria-modal]').exists()).toBe(false)
  })

  it('has no accessibility violation', async () => {
    await expectNoAccessibilityViolation(await mountNotice())
  })
})
