import { describe, expect, it } from 'vitest'
import { StaticPortfolioContentRepository } from '../../../src/infrastructure/portfolio/StaticPortfolioContentRepository'
import { SUPPORTED_LOCALES, type Locale } from '../../../src/domain/portfolio/entities/Locale'

/**
 * La section « Cookies » de la politique de confidentialité (#748, R32, St3-1) : elle répond à
 * l'obligation d'information de la CNIL, donc elle dit ce que le backend fait. Depuis R21
 * (`set_cookies.BEARER.lifetime: 0`, AuthCookieFactory), `BEARER` et `XSRF-TOKEN` sont des cookies
 * de session, sans échéance ; seul le JWT garde son `exp` (1 h au login, 15 min à l'accès de base)
 * et `StaleSessionCookiesClearingListener` efface les deux cookies au premier refus.
 */
const EXPECTED: Record<Locale, { readonly bearer: string; readonly xsrf: string; readonly stale: readonly string[] }> = {
  fr: {
    bearer:
      'BEARER — jeton d’authentification (JWT), httpOnly, non lisible en JavaScript. Cookie de session : supprimé à la fermeture du navigateur (sauf si celui-ci restaure la session), à la déconnexion, ou dès qu’une page qui demande une connexion refuse le jeton qu’il porte (expiré — 1 heure pour un compte, 15 minutes pour l’accès instantané — ou invalidé).',
    xsrf: 'XSRF-TOKEN — jeton de protection contre les attaques CSRF, lisible en JavaScript, posé en même temps que le précédent ; cookie de session lui aussi, supprimé en même temps que lui.',
    stale: ['expire avec lui', 'expire à la déconnexion ou à l’expiration du jeton'],
  },
  en: {
    bearer:
      'BEARER — authentication token (JWT), httpOnly, not readable from JavaScript. Session cookie: deleted when the browser is closed (unless it restores the session), on logout, or as soon as a page that requires signing in refuses the token it carries (expired — 1 hour for an account, 15 minutes for instant access — or invalidated).',
    xsrf: 'XSRF-TOKEN — CSRF protection token, readable from JavaScript, set together with the previous one; also a session cookie, deleted together with it.',
    stale: ['expires with it', 'expires on logout or token expiry'],
  },
}

describe('politique de cookies publiée', () => {
  const repository = new StaticPortfolioContentRepository()

  function cookiesList(locale: Locale): readonly string[] {
    const section = repository.getPrivacyPolicyContent(locale).sections.find((s) => s.heading === 'Cookies')
    return section?.list ?? []
  }

  it.each(SUPPORTED_LOCALES)('%s : nomme BEARER et XSRF-TOKEN, deux cookies de session, dans les termes exacts', (locale) => {
    const list = cookiesList(locale)

    expect(list).toHaveLength(2)
    expect(list[0]).toBe(EXPECTED[locale].bearer)
    expect(list[1]).toBe(EXPECTED[locale].xsrf)
  })

  it.each(SUPPORTED_LOCALES)('%s : ne dit plus que les cookies expirent avec le jeton', (locale) => {
    const text = cookiesList(locale).join('\n')

    for (const stale of EXPECTED[locale].stale) {
      expect(text).not.toContain(stale)
    }
  })

  it.each(SUPPORTED_LOCALES)("%s : dit l'effacement au refus par une page qui demande une connexion, expiré ou invalidé", (locale) => {
    const bearer = cookiesList(locale)[0] ?? ''
    const expected = {
      fr: { refuses: 'refuse le jeton', invalidated: 'invalidé', stale: 'présenté après l’expiration' },
      en: { refuses: 'refuses the token', invalidated: 'invalidated', stale: 'presented after' },
    }[locale]

    expect(bearer).toContain(expected.refuses)
    expect(bearer).toContain(expected.invalidated)
    expect(bearer).not.toContain(expected.stale)
  })

  it.each(SUPPORTED_LOCALES)('%s : aucune autre section ne promet une durée de vie de ces cookies', (locale) => {
    const others = repository
      .getPrivacyPolicyContent(locale)
      .sections.filter((s) => s.heading !== 'Cookies')
      .flatMap((s) => [...s.paragraphs, ...(s.list ?? [])])
      .join('\n')

    expect(others).not.toMatch(/BEARER|XSRF/)
  })
})
