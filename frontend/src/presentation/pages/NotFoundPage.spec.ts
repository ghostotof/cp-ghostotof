import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import NotFoundPage from './NotFoundPage.vue'
import { createAppI18n } from '../i18n'

const StubPage = { template: '<div />' }

async function mountNotFound() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
    ],
  })
  await router.push('/nowhere')
  await router.isReady()

  return mount(NotFoundPage, { global: { plugins: [router, createAppI18n()] } })
}

describe('NotFoundPage', () => {
  it('affiche un message et un lien de retour vers la locale par défaut', async () => {
    const wrapper = await mountNotFound()

    expect(wrapper.find('h1').exists()).toBe(true)
    const backLink = wrapper.get('a')
    expect(backLink.attributes('href')).toBe('/fr')
  })
})
