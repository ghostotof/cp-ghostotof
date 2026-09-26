import { afterEach, describe, expect, it, vi } from 'vitest'
import { getAppVersion } from '../../../src/infrastructure/config/getAppVersion'

describe('getAppVersion', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it("répond null quand VITE_APP_VERSION est absente (npm run dev) : le footer n'affiche rien", () => {
    vi.stubEnv('VITE_APP_VERSION', '')

    expect(getAppVersion()).toBeNull()
  })

  it('sépare version et build du tag de la pipeline, et pointe vers la release GitHub', () => {
    vi.stubEnv('VITE_APP_VERSION', '0.17.0-2c86b65')

    expect(getAppVersion()).toEqual({
      version: '0.17.0',
      build: '2c86b65',
      releaseUrl: 'https://github.com/ghostotof/cp-ghostotof/releases/tag/v0.17.0',
    })
  })

  it('accepte une version sémantique nue, sans build', () => {
    vi.stubEnv('VITE_APP_VERSION', '1.2.3')

    expect(getAppVersion()).toEqual({
      version: '1.2.3',
      build: null,
      releaseUrl: 'https://github.com/ghostotof/cp-ghostotof/releases/tag/v1.2.3',
    })
  })

  it("garde tel quel un tag qui n'est pas une release (build local sur un SHA), sans lien", () => {
    vi.stubEnv('VITE_APP_VERSION', 'a1b2c3d')

    expect(getAppVersion()).toEqual({ version: 'a1b2c3d', build: null, releaseUrl: null })
  })

  it('ignore les espaces autour de la valeur', () => {
    vi.stubEnv('VITE_APP_VERSION', '  0.17.0-2c86b65 \n')

    expect(getAppVersion()?.version).toBe('0.17.0')
  })
})
