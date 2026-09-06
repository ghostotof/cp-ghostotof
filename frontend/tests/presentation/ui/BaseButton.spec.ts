import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseButton from '../../../src/presentation/ui/BaseButton.vue'
import { createAppI18n } from '../../../src/presentation/i18n'

/**
 * BaseButton consomme useI18n() pour la mention « nouvelle fenêtre » des liens
 * externes : chaque montage a donc besoin d'une instance i18n. On en crée une
 * par test plutôt que de réutiliser le singleton de l'application, pour que les
 * tests restent isolés les uns des autres (même convention que les specs de
 * router, qui construisent leur propre createRouter).
 */
function mountButton(props: Record<string, unknown>, slots: Record<string, string> = {}) {
  return mount(BaseButton, {
    props,
    slots,
    global: { plugins: [createAppI18n()] },
  })
}

describe('BaseButton', () => {
  it('rend un lien avec le href fourni et le contenu du slot', () => {
    const wrapper = mountButton({ href: '#contact' }, { default: 'Me contacter' })

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('#contact')
    expect(link.text()).toContain('Me contacter')
  })

  it('applique la classe btn-gradient par défaut (variant primary)', () => {
    const wrapper = mountButton({ href: '#' })

    expect(wrapper.classes()).toContain('btn-gradient')
    expect(wrapper.classes()).not.toContain('btn-outline-light')
  })

  it('applique la classe btn-outline-light pour la variant secondary', () => {
    const wrapper = mountButton({ href: '#', variant: 'secondary' })

    expect(wrapper.classes()).toContain('btn-outline-light')
    expect(wrapper.classes()).not.toContain('btn-gradient')
  })

  it("n'affiche pas d'icône quand iconKey est absent", () => {
    const wrapper = mountButton({ href: '#' })

    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('affiche une icône quand iconKey correspond à une clé connue', () => {
    const wrapper = mountButton({ href: '#', iconKey: 'arrow-right' })

    expect(wrapper.find('svg').exists()).toBe(true)
  })

  describe('liens externes', () => {
    it('reste dans l\'onglet courant par défaut, sans target ni rel', () => {
      const wrapper = mountButton({ href: '/fr/about' })

      const link = wrapper.get('a')
      expect(link.attributes('target')).toBeUndefined()
      expect(link.attributes('rel')).toBeUndefined()
    })

    it('ouvre un nouvel onglet et neutralise window.opener quand isExternal est vrai', () => {
      const wrapper = mountButton({ href: 'https://github.com/ghostotof/cp-ghostotof', isExternal: true })

      const link = wrapper.get('a')
      expect(link.attributes('target')).toBe('_blank')
      // noopener : sans lui, la page ouverte peut réécrire l'onglet d'origine
      // via window.opener (tabnabbing). noreferrer évite en prime la fuite
      // de l'URL de provenance.
      expect(link.attributes('rel')).toBe('noopener noreferrer')
    })

    it('annonce le changement de contexte aux lecteurs d\'écran', () => {
      const wrapper = mountButton({ href: 'https://example.com', isExternal: true })

      // Le texte est visuellement masqué mais restitué à l'oral : un lien qui
      // ouvre un onglet sans le dire laisse l'utilisateur devant un bouton
      // « retour » inopérant, sans explication.
      const hint = wrapper.get('.visually-hidden')
      expect(hint.text()).toBe('(nouvelle fenêtre)')
    })
  })
})
