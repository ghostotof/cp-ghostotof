import { describe, expect, it } from 'vitest'
import { StaticPortfolioContentRepository } from './StaticPortfolioContentRepository'

describe('StaticPortfolioContentRepository', () => {
  const repository = new StaticPortfolioContentRepository()

  it('active le lien de navigation "À propos" et le fait pointer vers sa page dédiée', () => {
    const aboutLink = repository.getNavigationLinks().find((link) => link.label === 'À propos')

    expect(aboutLink?.isEnabled).toBe(true)
    expect(aboutLink?.to).toBe('/a-propos')
  })

  it('fournit un contenu "À propos" concis (eyebrow, titre, message)', () => {
    const about = repository.getAboutContent()

    expect(about.eyebrow).not.toBe('')
    expect(about.title).not.toBe('')
    expect(about.message).not.toBe('')
  })

  it("ne divulgue aucune information personnelle identifiante avant authentification", () => {
    const about = repository.getAboutContent()
    const fullText = [about.eyebrow, about.title, about.message].join(' ')

    // Le site doit rester générique tant que l'utilisateur n'est pas authentifié (cf. CLAUDE.md, objectif n°9) :
    // pas d'adresse e-mail, pas d'URL externe, pas de numéro de téléphone en clair.
    expect(fullText).not.toMatch(/[\w.-]+@[\w.-]+\.\w+/)
    expect(fullText).not.toMatch(/https?:\/\//)
    expect(fullText).not.toMatch(/\b0[1-9](\s?\d{2}){4}\b/)
  })
})
