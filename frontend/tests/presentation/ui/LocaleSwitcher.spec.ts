import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LocaleSwitcher from '../../../src/presentation/ui/LocaleSwitcher.vue'
import { createAppI18n } from '../../../src/presentation/i18n'

const StubPage = { template: '<div />' }

async function mountSwitcher(initialPath = '/fr/about') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/about', name: 'about', component: StubPage },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(LocaleSwitcher, { global: { plugins: [router, createAppI18n()] } })
  return { wrapper, router }
}

describe('LocaleSwitcher', () => {
  it('marque la locale courante comme active (aria-current)', async () => {
    const { wrapper } = await mountSwitcher('/fr/about')

    const buttons = wrapper.findAll('button')
    const fr = buttons.find((button) => button.text() === 'FR')
    const en = buttons.find((button) => button.text() === 'EN')

    expect(fr?.attributes('aria-current')).toBe('true')
    expect(en?.attributes('aria-current')).toBeUndefined()
  })

  it('change de langue en conservant le même chemin', async () => {
    const { wrapper, router } = await mountSwitcher('/fr/about')

    const enButton = wrapper.findAll('button').find((button) => button.text() === 'EN')
    await enButton?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/en/about')
  })
})
