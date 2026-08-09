import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import QualitySection from '../../../src/presentation/sections/QualitySection.vue'
import type { QualityPrinciple } from '../../../src/domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../../src/domain/portfolio/entities/QualityTrait'
import { createAppI18n } from '../../../src/presentation/i18n'

const qualityPrinciples: readonly QualityPrinciple[] = [
  { title: 'DDD', description: 'Modélisation du domaine métier.', iconKey: 'boxes' },
  { title: 'SOLID', description: 'Des bases solides et maintenables.', iconKey: 'columns-3' },
]

const qualityTraits: readonly QualityTrait[] = [{ label: 'Architecture propre' }, { label: 'Tests automatisés' }]

function mountSection() {
  return mount(QualitySection, {
    props: { qualityPrinciples, qualityTraits },
    global: { plugins: [createAppI18n()] },
  })
}

describe('QualitySection', () => {
  it('rend une carte par principe de qualité', () => {
    const wrapper = mountSection()

    for (const principle of qualityPrinciples) {
      expect(wrapper.text()).toContain(principle.title)
      expect(wrapper.text()).toContain(principle.description)
    }
  })

  it('rend un badge par trait de qualité', () => {
    const wrapper = mountSection()

    const badges = wrapper.findAll('.badge-soft')
    expect(badges).toHaveLength(qualityTraits.length)
    expect(badges[0]?.text()).toContain('Architecture propre')
  })
})
