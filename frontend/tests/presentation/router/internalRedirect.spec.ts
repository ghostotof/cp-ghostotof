import { describe, expect, it } from 'vitest'
import { internalRedirectTarget } from '../../../src/presentation/router/internalRedirect'

describe('internalRedirectTarget (#650, F-I1)', () => {
  it.each([
    '/fr',
    '/fr/admin/users',
    '/fr/oauth/consent?client_id=claude-code&state=a%26b#decision',
    '/fr/mcp-connections?x=1',
    // Une barre oblique inverse encodée n'est pas décodée dans un chemin : elle reste sur l'origine.
    '/%5Cevil.example',
    '/fr/a%2Fb',
  ])('garde un chemin interne tel quel : %s', (target) => {
    expect(internalRedirectTarget(target)).toBe(target)
  })

  it.each([
    ['une origine absolue', 'https://evil.example/'],
    ['une URL sans schéma de lecture relative', 'evil.example/fr'],
    ['un schéma javascript', 'javascript:alert(1)'],
    ['un schéma data', 'data:text/html,<script>alert(1)</script>'],
    ['deux barres obliques (protocole relatif)', '//evil.example'],
    ['trois barres obliques', '///evil.example'],
    ['une barre oblique inverse après la barre oblique', '/\\evil.example'],
    ['deux barres obliques inverses', '\\\\evil.example'],
    ['une barre oblique inverse plus loin dans le chemin', '/fr\\admin'],
    ['une tabulation entre les barres obliques', '/\t/evil.example'],
    ['un saut de ligne entre les barres obliques', '/\n/evil.example'],
    ['un retour chariot entre les barres obliques', '/\r/evil.example'],
    ['un caractère nul', '/fr/\u0000'],
    ['le caractère DEL', '/fr/\u007f'],
    ['une chaîne vide', ''],
    ['la racine d\'un autre type : relative', './fr'],
  ])('refuse %s', (_label, target) => {
    expect(internalRedirectTarget(target)).toBeNull()
  })

  it.each([
    ['absent', undefined],
    ['nul', null],
    ['un tableau (?redirect=a&redirect=b)', ['/fr', '/en']],
    ['un nombre', 1],
    ['un objet', { redirect: '/fr' }],
  ])('refuse une valeur qui n\'est pas une chaîne : %s', (_label, value) => {
    expect(internalRedirectTarget(value)).toBeNull()
  })
})
