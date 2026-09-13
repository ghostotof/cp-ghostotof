import { describe, expect, it } from 'vitest'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { SUPPORTED_LOCALES, type Locale } from '../../../src/domain/portfolio/entities/Locale'
import { isNavigationGroup } from '../../../src/domain/portfolio/entities/NavigationEntry'
import type { NavigationLink } from '../../../src/domain/portfolio/entities/NavigationLink'

describe('StaticPortfolioContentRepository', () => {
  const repository = new StaticPortfolioContentRepository()

  /** Tous les liens, groupes dépliés : les assertions sur une page donnée ne dépendent pas de son rangement. */
  function flatLinks(locale: Locale): readonly NavigationLink[] {
    return repository.getNavigationLinks(locale).flatMap((entry) => (isNavigationGroup(entry) ? entry.links : [entry]))
  }

  it.each(SUPPORTED_LOCALES)(
    'active le lien de navigation "À propos" pour la locale %s, le fait pointer vers sa page dédiée et le place en dernier',
    (locale) => {
      const aboutLink = flatLinks(locale).find((link) => link.to.endsWith('/about'))

      expect(aboutLink?.isEnabled).toBe(true)
      expect(aboutLink?.to).toBe(`/${locale}/about`)
      expect(repository.getNavigationLinks(locale).at(-1)).toEqual(aboutLink)
    },
  )

  it.each(SUPPORTED_LOCALES)(
    'active le lien de navigation "Expérience" pour la locale %s et le fait pointer vers sa page dédiée',
    (locale) => {
      const experienceLink = flatLinks(locale).find((link) => link.to.endsWith('/experience'))

      expect(experienceLink?.isEnabled).toBe(true)
      expect(experienceLink?.to).toBe(`/${locale}/experience`)
    },
  )

  it.each(SUPPORTED_LOCALES)(
    'active le lien de navigation "Contact" pour la locale %s et le fait pointer vers sa page dédiée',
    (locale) => {
      const contactLink = flatLinks(locale).find((link) => link.to.endsWith('/contact'))

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
      const links = flatLinks(locale)

      expect(links.some((link) => link.to.includes('#technologies'))).toBe(false)

      const stackLink = links.find((link) => link.to.endsWith('/stack'))

      expect(stackLink?.isEnabled).toBe(true)
      expect(stackLink?.to).toBe(`/${locale}/stack`)
      expect(stackLink?.label).toBe('fr' === locale ? 'Ma stack' : 'My stack')
    },
  )

  it.each(SUPPORTED_LOCALES)('préfixe tous les liens de navigation par la locale %s', (locale) => {
    for (const link of flatLinks(locale)) {
      expect(link.to.startsWith(`/${locale}`)).toBe(true)
    }
  })

  /**
   * Issue #70 (décision du 2026-09-13) : neuf entrées ne tenaient plus sur
   * une ligne. Les contenus « matière » sont regroupés sous « Dossiers » ; les
   * URL ne changent pas, et le premier niveau retombe à six entrées.
   */
  it.each(SUPPORTED_LOCALES)('regroupe les quatre contenus « matière » sous « Dossiers », six entrées au premier niveau (%s)', (locale) => {
    const entries = repository.getNavigationLinks(locale)
    const group = entries.find(isNavigationGroup)

    expect(entries).toHaveLength(6)
    expect(group?.label).toBe('Dossiers')
    expect(group?.links.map((link) => link.to)).toEqual([
      `/${locale}/contributions`,
      `/${locale}/incidents`,
      `/${locale}/case-studies`,
      `/${locale}/anonymous-cv`,
    ])
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
