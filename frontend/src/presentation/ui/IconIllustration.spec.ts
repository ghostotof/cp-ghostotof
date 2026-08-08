import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import IconIllustration from './IconIllustration.vue'
import { createAppI18n } from '../i18n'

describe('IconIllustration', () => {
  it('rend une image avec un texte alternatif descriptif', () => {
    const wrapper = mount(IconIllustration, { global: { plugins: [createAppI18n()] } })

    const img = wrapper.get('img')
    expect(img.attributes('alt')).toBeTruthy()
    expect(img.attributes('src')).toBeTruthy()
  })
})
