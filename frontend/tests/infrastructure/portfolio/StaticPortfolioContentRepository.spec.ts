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

  it.each(SUPPORTED_LOCALES)('préfixe tous les liens de navigation par la locale %s', (locale) => {
    const links = repository.getNavigationLinks(locale)

    for (const link of links) {
      expect(link.to.startsWith(`/${locale}`)).toBe(true)
    }
  })

  it.each(SUPPORTED_LOCALES)(
    'fournit un contenu "À propos" structuré (site + moi, chacun avec des cartes) pour %s',
    (locale) => {
      const about = repository.getAboutContent(locale)

      expect(about.site.eyebrow).not.toBe('')
      expect(about.site.cards.length).toBeGreaterThan(0)

      expect(about.me.eyebrow).not.toBe('')
      expect(about.me.technicalCards.length).toBeGreaterThan(0)
      expect(about.me.personalCards.length).toBeGreaterThan(0)
    },
  )

  it.each(SUPPORTED_LOCALES)(
    "ne divulgue aucune information personnelle identifiante avant authentification (%s)",
    (locale) => {
      const about = repository.getAboutContent(locale)
      const allCards = [...about.site.cards, ...about.me.technicalCards, ...about.me.personalCards]
      const fullText = [
        about.site.eyebrow,
        about.me.eyebrow,
        ...allCards.map((card) => `${card.title} ${card.description}`),
      ].join(' ')

      // Le site doit rester générique tant que l'utilisateur n'est pas authentifié (cf. CLAUDE.md, objectif n°9) :
      // pas d'adresse e-mail, pas d'URL externe, pas de numéro de téléphone en clair.
      expect(fullText).not.toMatch(/[\w.-]+@[\w.-]+\.\w+/)
      expect(fullText).not.toMatch(/https?:\/\//)
      expect(fullText).not.toMatch(/\b0[1-9](\s?\d{2}){4}\b/)
    },
  )

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
    expect(repository.getAboutContent('fr').site.eyebrow).not.toBe(repository.getAboutContent('en').site.eyebrow)
  })
})
