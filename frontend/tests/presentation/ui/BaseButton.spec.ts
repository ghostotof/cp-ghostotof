import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import BaseButton from '../../../src/presentation/ui/BaseButton.vue'
import { createAppI18n } from '../../../src/presentation/i18n'

type BaseButtonProps = InstanceType<typeof BaseButton>['$props']

/**
 * BaseButton consomme useI18n() pour la mention « nouvelle fenêtre », et rend
 * un RouterLink pour les cibles internes : chaque montage a donc besoin d'une
 * instance i18n et d'un routeur. On en construit de nouveaux à chaque test
 * plutôt que de réutiliser les singletons de l'application, pour que les tests
 * restent isolés les uns des autres (même convention que les specs de router).
 */
function createTestRouter(): Router {
  return createRouter({
    // createMemoryHistory (et non createWebHistory) : convention des specs de
    // ce projet, l'historique navigateur n'ayant rien à synchroniser sous jsdom.
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/fr/about', component: { template: '<div />' } },
    ],
  })
}

function mountButton(props: BaseButtonProps, slots: Record<string, string> = {}, router: Router = createTestRouter()) {
  return mount(BaseButton, {
    props,
    slots,
    global: { plugins: [router, createAppI18n()] },
  })
}

describe('BaseButton', () => {
  it('rend un lien avec le href fourni et le contenu du slot', () => {
    const wrapper = mountButton({ href: '/fr/about' }, { default: 'Me contacter' })

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('/fr/about')
    expect(link.text()).toContain('Me contacter')
  })

  it('applique la classe btn-gradient par défaut (variant primary)', () => {
    const wrapper = mountButton({ href: '/' })

    expect(wrapper.classes()).toContain('btn-gradient')
    expect(wrapper.classes()).not.toContain('btn-outline-light')
  })

  it('applique la classe btn-outline-light pour la variant secondary', () => {
    const wrapper = mountButton({ href: '/', variant: 'secondary' })

    expect(wrapper.classes()).toContain('btn-outline-light')
    expect(wrapper.classes()).not.toContain('btn-gradient')
  })

  it("n'affiche pas d'icône quand iconKey est absent", () => {
    const wrapper = mountButton({ href: '/' })

    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('affiche une icône quand iconKey correspond à une clé connue', () => {
    const wrapper = mountButton({ href: '/', iconKey: 'arrow-right' })

    expect(wrapper.find('svg').exists()).toBe(true)
  })

  describe('cibles internes', () => {
    it('navigue via le routeur, sans recharger le document', async () => {
      const router = createTestRouter()
      await router.push('/')
      await router.isReady()
      const wrapper = mountButton({ href: '/fr/about' }, {}, router)

      await wrapper.get('a').trigger('click')
      await flushPromises()

      // Régression : un <a href> brut provoquait un rechargement complet du
      // bundle à chaque clic sur un appel à l'action interne. Un clic
      // intercepté par le routeur change la route en place.
      expect(router.currentRoute.value.path).toBe('/fr/about')
    })

    it('reste dans l\'onglet courant, sans target ni rel', () => {
      const wrapper = mountButton({ href: '/fr/about' })

      const link = wrapper.get('a')
      expect(link.attributes('target')).toBeUndefined()
      expect(link.attributes('rel')).toBeUndefined()
      expect(wrapper.find('.visually-hidden').exists()).toBe(false)
    })
  })

  describe('cibles externes', () => {
    it('porte l\'URL absolue telle quelle, sans passer par le routeur', () => {
      const wrapper = mountButton({ href: 'https://github.com/ghostotof/cp-ghostotof', isExternal: true })

      // Confier une URL absolue au routeur produirait une navigation interne
      // vers un chemin inexistant, pas une sortie du site : l'ancre doit
      // porter l'URL telle quelle.
      expect(wrapper.get('a').attributes('href')).toBe('https://github.com/ghostotof/cp-ghostotof')
    })

    it('ouvre un nouvel onglet et neutralise window.opener', () => {
      const wrapper = mountButton({ href: 'https://example.com', isExternal: true })

      const link = wrapper.get('a')
      expect(link.attributes('target')).toBe('_blank')
      // noopener : sans lui, la page ouverte peut réécrire l'onglet d'origine
      // via window.opener (tabnabbing). noreferrer évite en prime la fuite
      // de l'URL de provenance.
      expect(link.attributes('rel')).toBe('noopener noreferrer')
    })

    it('annonce le changement de contexte aux lecteurs d\'écran', () => {
      const wrapper = mountButton({ href: 'https://example.com', isExternal: true })

      const hint = wrapper.get('.visually-hidden')
      expect(hint.text()).toBe('(nouvelle fenêtre)')
    })
  })
})
