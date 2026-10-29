import { afterEach, describe, expect, it } from 'vitest'
import { hasCsrfToken, readCsrfToken } from '../../../src/infrastructure/auth/csrfCookie'

function setCsrfCookie(rawValue: string): void {
  document.cookie = `XSRF-TOKEN=${rawValue}; path=/`
}

function clearCsrfCookie(): void {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
}

/**
 * Le cookie `XSRF-TOKEN` se lit en un seul endroit (#748, R12) : une valeur qui ne se décode pas est
 * « absente », jamais une exception (F13 : `decodeURIComponent('%E0')` lève `URIError`, et chaque écriture
 * échouait avant tout envoi).
 */
describe('csrfCookie', () => {
  afterEach(clearCsrfCookie)

  describe('readCsrfToken()', () => {
    it('lit la valeur décodée', () => {
      setCsrfCookie('a%2Fb%3D')

      expect(readCsrfToken()).toBe('a/b=')
    })

    it('rend null sans cookie', () => {
      expect(readCsrfToken()).toBeNull()
    })

    it('XSRF-TOKEN=%E0 : aucune exception, absent', () => {
      setCsrfCookie('%E0')

      expect(() => readCsrfToken()).not.toThrow()
      expect(readCsrfToken()).toBeNull()
    })
  })

  describe('hasCsrfToken()', () => {
    it.each([
      ['absent', null],
      ['vide', ''],
      ['indécodable', '%E0'],
    ])('%s → faux', (_label, rawValue) => {
      if (null !== rawValue) {
        setCsrfCookie(rawValue)
      }

      expect(hasCsrfToken()).toBe(false)
    })

    it('une valeur → vrai', () => {
      setCsrfCookie('jeton-de-test')

      expect(hasCsrfToken()).toBe(true)
    })
  })
})
