import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import IconIllustration from './IconIllustration.vue'

describe('IconIllustration', () => {
  it('rend une image avec un texte alternatif descriptif', () => {
    const wrapper = mount(IconIllustration)

    const img = wrapper.get('img')
    expect(img.attributes('alt')).toBeTruthy()
    expect(img.attributes('src')).toBeTruthy()
  })
})
