import { afterEach, describe, expect, it } from 'vitest'
import { getApiUrl } from '../../../src/infrastructure/config/getApiUrl'

describe('getApiUrl', () => {
  afterEach(() => {
    delete window.__APP_CONFIG__
  })

  it('utilise window.__APP_CONFIG__.apiUrl quand il est présent (image déployée)', () => {
    window.__APP_CONFIG__ = { apiUrl: 'https://api.preprod.example.test' }

    expect(getApiUrl()).toBe('https://api.preprod.example.test')
  })

  it('retombe sur import.meta.env.VITE_API_URL quand __APP_CONFIG__ est absent (npm run dev)', () => {
    expect(window.__APP_CONFIG__).toBeUndefined()

    expect(getApiUrl()).toBe(import.meta.env.VITE_API_URL)
  })
})
