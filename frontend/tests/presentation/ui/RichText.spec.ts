import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RichText from '../../../src/presentation/ui/RichText.vue'

describe('RichText', () => {
  it('rend un paragraphe par bloc séparé d\'une ligne vide', () => {
    const wrapper = mount(RichText, { props: { text: 'Un.\n\nDeux.\n\nTrois.' } })

    const paragraphs = wrapper.findAll('p')
    expect(paragraphs).toHaveLength(3)
    expect(paragraphs[2]?.text()).toBe('Trois.')
  })

  it('absorbe les lignes vides surnuméraires d\'une saisie manuelle', () => {
    const wrapper = mount(RichText, { props: { text: 'Un.\n\n\n   \n\nDeux.\n\n' } })

    expect(wrapper.findAll('p')).toHaveLength(2)
  })

  it('rend les passages entre accents graves en <code>, sans laisser les accents', () => {
    const wrapper = mount(RichText, { props: { text: 'Avec `maxRetries` dedans.' } })

    expect(wrapper.get('code').text()).toBe('maxRetries')
    expect(wrapper.text()).not.toContain('`')
    // La phrase reste continue : aucune espace parasite autour du code.
    expect(wrapper.text()).toBe('Avec maxRetries dedans.')
  })

  it('n\'interprète jamais le texte comme du HTML', () => {
    const wrapper = mount(RichText, { props: { text: 'Avant <img src=x onerror="alert(1)"> après.' } })

    // Le texte vient d'un champ de saisie du backoffice : l'injecter en v-html
    // échangerait une mise en forme contre une XSS stockée sur une page
    // publique. C'est la garantie que porte ce composant, et la raison pour
    // laquelle il est partagé plutôt que dupliqué.
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">')
  })

  it('applique la classe de paragraphe demandée', () => {
    const wrapper = mount(RichText, { props: { text: 'Un.', paragraphClass: 'mb-0 text-white-50' } })

    expect(wrapper.get('p').classes()).toEqual(expect.arrayContaining(['mb-0', 'text-white-50']))
  })
})
