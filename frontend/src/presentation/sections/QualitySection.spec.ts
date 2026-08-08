import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import QualitySection from './QualitySection.vue'
import type { QualityPrinciple } from '../../domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../domain/portfolio/entities/QualityTrait'

const qualityPrinciples: readonly QualityPrinciple[] = [
  { title: 'DDD', description: 'Modélisation du domaine métier.', iconKey: 'boxes' },
  { title: 'SOLID', description: 'Des bases solides et maintenables.', iconKey: 'columns-3' },
]

const qualityTraits: readonly QualityTrait[] = [{ label: 'Architecture propre' }, { label: 'Tests automatisés' }]

describe('QualitySection', () => {
  it('rend une carte par principe de qualité', () => {
    const wrapper = mount(QualitySection, { props: { qualityPrinciples, qualityTraits } })

    for (const principle of qualityPrinciples) {
      expect(wrapper.text()).toContain(principle.title)
      expect(wrapper.text()).toContain(principle.description)
    }
  })

  it('rend un badge par trait de qualité', () => {
    const wrapper = mount(QualitySection, { props: { qualityPrinciples, qualityTraits } })

    const badges = wrapper.findAll('.badge-soft')
    expect(badges).toHaveLength(qualityTraits.length)
    expect(badges[0]?.text()).toContain('Architecture propre')
  })
})
