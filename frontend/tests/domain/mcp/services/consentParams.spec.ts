import { describe, expect, it } from 'vitest'
import {
  consentParamsFromQuery,
  consentQuery,
  isNavigableRedirect,
} from '../../../../src/domain/mcp/services/consentParams'

/** La requête telle que le point d'autorisation la pose sur la page (#647). */
const QUERY = {
  response_type: 'code',
  client_id: 'claude-code',
  redirect_uri: 'http://127.0.0.1:33418/callback',
  scope: 'nominative:read',
  state: 'xyz',
  code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  code_challenge_method: 'S256',
  resource: 'https://site.example.test/api/mcp',
}

describe('consentParamsFromQuery', () => {
  it('reprend chaque paramètre reçu, du snake_case au camelCase, sans le modifier', () => {
    expect(consentParamsFromQuery(QUERY)).toEqual({
      responseType: 'code',
      clientId: 'claude-code',
      redirectUri: 'http://127.0.0.1:33418/callback',
      scope: 'nominative:read',
      state: 'xyz',
      codeChallenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
      codeChallengeMethod: 'S256',
      resource: 'https://site.example.test/api/mcp',
    })
  })

  it('un paramètre absent reste absent (null) : le serveur seul le juge', () => {
    expect(consentParamsFromQuery({ ...QUERY, state: undefined })?.state).toBeNull()
  })

  it('une valeur vide reste vide, jamais remplacée', () => {
    expect(consentParamsFromQuery({ ...QUERY, state: '' })?.state).toBe('')
  })

  it("ignore une clé qui n'est pas un paramètre de la demande", () => {
    expect(consentParamsFromQuery({ ...QUERY, approved: 'true' })).not.toHaveProperty('approved')
  })

  it('un paramètre répété rend la demande illisible (null) : aucune valeur choisie à sa place', () => {
    expect(consentParamsFromQuery({ ...QUERY, state: ['a', 'b'] })).toBeNull()
  })

  it('un paramètre sans valeur (`?state`) rend la demande illisible (null)', () => {
    expect(consentParamsFromQuery({ ...QUERY, state: null })).toBeNull()
  })
})

describe('consentQuery', () => {
  it('rend la requête reçue, en snake_case, sans les paramètres absents', () => {
    const params = consentParamsFromQuery({ ...QUERY, state: undefined })

    expect(params).not.toBeNull()
    const expected: Record<string, string> = { ...QUERY }
    delete expected.state
    expect(consentQuery(params!)).toEqual(expected)
  })
})

describe('isNavigableRedirect', () => {
  it.each([
    ['https://client.example/cb?code=a', 'client.example'],
    ['http://127.0.0.1:33418/callback?code=a', '127.0.0.1'],
    ['http://[::1]:33418/callback?code=a', '[::1]'],
    // L'hôte annoncé vient du serveur tel que l'URL enregistrée l'écrit ; le navigateur le met en minuscules.
    ['https://Client.Example/cb?code=a', 'Client.Example'],
  ])('accepte %s vers l’hôte annoncé %s', (url, host) => {
    expect(isNavigableRedirect(url, host)).toBe(true)
  })

  it.each([
    'javascript:alert(1)',
    'JavaScript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'cursor://client.example/oauth/callback?code=a',
    '/relative/path',
    '',
    'pas une url',
  ])('refuse le schéma ou la forme de %s', (url) => {
    expect(isNavigableRedirect(url, 'client.example')).toBe(false)
  })

  it.each([
    'https://other.example/cb?code=a',
    'https://client.example.evil.test/cb?code=a',
    'https://evil.test/client.example?code=a',
    'https://client.example@evil.test/cb?code=a',
  ])('refuse %s : http(s), mais pas l’hôte annoncé', (url) => {
    expect(isNavigableRedirect(url, 'client.example')).toBe(false)
  })
})
