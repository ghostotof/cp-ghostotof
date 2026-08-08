import { describe, expect, it } from 'vitest'
import { StaticPortfolioContentRepository } from './StaticPortfolioContentRepository'
import { SUPPORTED_LOCALES } from '../../domain/portfolio/entities/Locale'

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

  it.each(SUPPORTED_LOCALES)('préfixe tous les liens de navigation par la locale %s', (locale) => {
    const links = repository.getNavigationLinks(locale)

    for (const link of links) {
      expect(link.to.startsWith(`/${locale}`)).toBe(true)
    }
  })

  it.each(SUPPORTED_LOCALES)('fournit un contenu "À propos" concis (eyebrow, titre, message) pour %s', (locale) => {
    const about = repository.getAboutContent(locale)

    expect(about.eyebrow).not.toBe('')
    expect(about.title).not.toBe('')
    expect(about.message).not.toBe('')
  })

  it.each(SUPPORTED_LOCALES)(
    "ne divulgue aucune information personnelle identifiante avant authentification (%s)",
    (locale) => {
      const about = repository.getAboutContent(locale)
      const fullText = [about.eyebrow, about.title, about.message].join(' ')

      // Le site doit rester générique tant que l'utilisateur n'est pas authentifié (cf. CLAUDE.md, objectif n°9) :
      // pas d'adresse e-mail, pas d'URL externe, pas de numéro de téléphone en clair.
      expect(fullText).not.toMatch(/[\w.-]+@[\w.-]+\.\w+/)
      expect(fullText).not.toMatch(/https?:\/\//)
      expect(fullText).not.toMatch(/\b0[1-9](\s?\d{2}){4}\b/)
    },
  )

  it('fournit un contenu différent selon la locale (pas de contenu figé en français)', () => {
    expect(repository.getHeroContent('fr').eyebrow).not.toBe(repository.getHeroContent('en').eyebrow)
    expect(repository.getAboutContent('fr').message).not.toBe(repository.getAboutContent('en').message)
  })
})
