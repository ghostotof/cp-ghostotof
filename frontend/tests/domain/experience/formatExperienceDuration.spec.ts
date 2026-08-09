import { describe, expect, it } from 'vitest'
import { formatExperienceDuration } from '../../../src/domain/experience/services/formatExperienceDuration'

describe('formatExperienceDuration', () => {
  it('exprime une durée inférieure à un an en mois arrondis (fr)', () => {
    expect(formatExperienceDuration(0.5, 'fr')).toBe('~6 mois')
  })

  it('exprime une durée inférieure à un an en mois arrondis (en)', () => {
    expect(formatExperienceDuration(0.5, 'en')).toBe('~6 months')
  })

  it('gère le singulier "an"/"year" pour une durée d\'exactement 1 an', () => {
    expect(formatExperienceDuration(1, 'fr')).toBe('~1 an')
    expect(formatExperienceDuration(1, 'en')).toBe('~1 year')
  })

  it('formate les décimales avec une virgule en français', () => {
    expect(formatExperienceDuration(13.5, 'fr')).toBe('~13,5 ans')
  })

  it('formate les décimales avec un point en anglais', () => {
    expect(formatExperienceDuration(13.5, 'en')).toBe('~13.5 years')
  })

  it('utilise le pluriel pour une durée entière supérieure à 1 an', () => {
    expect(formatExperienceDuration(3, 'fr')).toBe('~3 ans')
    expect(formatExperienceDuration(3, 'en')).toBe('~3 years')
  })
})
