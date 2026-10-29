import { describe, expect, it } from 'vitest'

/**
 * Garde de source (#748, R23) : tout `fetch(` de `src/infrastructure/` écrit ses `credentials`.
 *
 * Pourquoi : la valeur par défaut, `same-origin`, envoie les cookies en préprod et en prod (une seule origine) et pas
 * en dev (Vite sur 5173, nginx sur 8080 : deux origines). Un dépôt public qui l'ignore envoie donc un `BEARER` mort à
 * une page publique en production et y prend le 401 — défaut invisible en dev, c'est ce qui l'a caché. Un dépôt
 * public écrit `'omit'` ; un dépôt qui porte une session écrit `'include'` et figure ci-dessous avec sa raison ;
 * `sessionAwareFetch.ts` est l'envoi partagé, qui pose `'include'` pour tous les autres clients.
 * Un dépôt public qui deviendrait authentifié doit changer sa valeur : cette garde le rappelle.
 */
const sources = import.meta.glob('../../../src/infrastructure/**/*.ts', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** Dépôts qui portent une session : chacun écrit `credentials: 'include'`, pour la raison donnée. */
const SESSION_REPOSITORIES: Readonly<Record<string, string>> = {
  'auth/HttpAuthRepository.ts': 'le login reçoit le Set-Cookie ; /api/me lit la session',
  'baseAccess/HttpBaseAccessRepository.ts': "l'accès de base reçoit le Set-Cookie (le rejeu sur 401 est R24)",
  'caseStudies/HttpCaseStudyRepository.ts': 'contenu du palier de base : lit le BEARER',
  'anonymousCv/HttpAnonymousCvRepository.ts': 'contenu du palier de base : lit le BEARER',
  'cv/HttpCvRepository.ts': 'CV nominatif : lit le BEARER du palier de confiance',
}

const SHARED_SEND = 'http/sessionAwareFetch.ts'

/** Chemin relatif à `src/infrastructure/` => source, hors fichiers qui ne font que citer `fetch(` en commentaire. */
function infrastructureFiles(): Array<[string, string]> {
  return Object.entries(sources).map(([path, source]) => [path.replace('../../../src/infrastructure/', ''), source])
}

/** Vrai si le fichier appelle `fetch(` dans son code (les lignes de commentaire sont ignorées). */
function callsFetch(source: string): boolean {
  return source
    .split('\n')
    .filter((line) => !/^\s*(\*|\/\/|\/\*)/.test(line))
    .some((line) => /\bfetch\(/.test(line))
}

describe('credentials explicites sur chaque fetch', () => {
  it("tout fetch d'infrastructure écrit ses credentials", () => {
    const offenders = infrastructureFiles()
      .filter(([path, source]) => path !== SHARED_SEND && callsFetch(source))
      .filter(([path, source]) => {
        const expected = path in SESSION_REPOSITORIES ? "credentials: 'include'" : "credentials: 'omit'"

        return !source.includes(expected)
      })
      .map(([path]) => path)
      .sort()

    expect(offenders).toEqual([])
  })

  it('chaque dépôt de session est encore un appelant de fetch', () => {
    const callers = new Set(infrastructureFiles().filter(([, source]) => callsFetch(source)).map(([path]) => path))

    const stale = Object.keys(SESSION_REPOSITORIES).filter((path) => !callers.has(path))

    expect(stale).toEqual([])
  })
})
