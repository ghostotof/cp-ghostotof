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

  /**
   * Décision D8 : l'entrée mène à la page de veille plutôt qu'à l'ancre de la
   * section Technologies, ce qui évite une huitième entrée au menu. Son libellé
   * a suivi — « Compétences » aurait annoncé autre chose que ce qu'il ouvre,
   * la page parlant de versions installées et non de savoir-faire.
   */
  it.each(SUPPORTED_LOCALES)(
    'fait pointer le lien de veille vers la page dédiée pour la locale %s, et non vers une ancre',
    (locale) => {
      const links = repository.getNavigationLinks(locale)

      expect(links.some((link) => link.to.includes('#technologies'))).toBe(false)

      const stackLink = links.find((link) => link.to.endsWith('/stack'))

      expect(stackLink?.isEnabled).toBe(true)
      expect(stackLink?.to).toBe(`/${locale}/stack`)
      expect(stackLink?.label).toBe('fr' === locale ? 'Ma stack' : 'My stack')
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
