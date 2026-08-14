import { describe, expect, it } from 'vitest'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { SUPPORTED_LOCALES } from '../../../src/domain/portfolio/entities/Locale'

describe('StaticPortfolioContentRepository', () => {
  const repository = new StaticPortfolioContentRepository()

  it.each(SUPPORTED_LOCALES)(
    'active le lien de navigation "À propos" pour la locale %s, le fait pointer vers sa page dédiée et le place en dernier',
    (locale) => {
      const links = repository.getNavigationLinks(locale)
      const aboutLink = links.find((link) => link.to.endsWith('/about'))

      expect(aboutLink?.isEnabled).toBe(true)
      expect(aboutLink?.to).toBe(`/${locale}/about`)
      expect(links.at(-1)).toBe(aboutLink)
    },
  )

  it.each(SUPPORTED_LOCALES)(
    'active le lien de navigation "Expérience" pour la locale %s et le fait pointer vers sa page dédiée',
    (locale) => {
      const links = repository.getNavigationLinks(locale)
      const experienceLink = links.find((link) => link.to.endsWith('/experience'))

      expect(experienceLink?.isEnabled).toBe(true)
      expect(experienceLink?.to).toBe(`/${locale}/experience`)
    },
  )

  it.each(SUPPORTED_LOCALES)(
    'active le lien de navigation "Contact" pour la locale %s et le fait pointer vers sa page dédiée',
    (locale) => {
      const links = repository.getNavigationLinks(locale)
      const contactLink = links.find((link) => link.to.endsWith('/contact'))

      expect(contactLink?.isEnabled).toBe(true)
      expect(contactLink?.to).toBe(`/${locale}/contact`)
    },
  )

  it.each(SUPPORTED_LOCALES)('préfixe tous les liens de navigation par la locale %s', (locale) => {
    const links = repository.getNavigationLinks(locale)

    for (const link of links) {
      expect(link.to.startsWith(`/${locale}`)).toBe(true)
    }
  })

  it.each(SUPPORTED_LOCALES)(
    "ne divulgue aucune information personnelle identifiante dans le contenu \"Expérience\" (%s)",
    (locale) => {
      const experience = repository.getExperienceContent(locale)
      const fullText = [experience.eyebrow, experience.description].join(' ')

      expect(fullText).not.toMatch(/[\w.-]+@[\w.-]+\.\w+/)
      expect(fullText).not.toMatch(/https?:\/\//)
      expect(fullText).not.toMatch(/\b0[1-9](\s?\d{2}){4}\b/)
    },
  )

  it('fournit un contenu différent selon la locale (pas de contenu figé en français)', () => {
    expect(repository.getHeroContent('fr').eyebrow).not.toBe(repository.getHeroContent('en').eyebrow)
    expect(repository.getExperienceContent('fr').eyebrow).not.toBe(repository.getExperienceContent('en').eyebrow)
  })
})
