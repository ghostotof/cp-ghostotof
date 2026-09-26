// La version affichée est celle de l'IMAGE, fixée au build Vite par
// VITE_APP_VERSION (docker/node/Dockerfile, --build-arg APP_VERSION passé par
// le Makefile avec le TAG de la pipeline, `<version>-<sha>`). C'est le
// contraire délibéré de getApiUrl.ts : l'URL de l'API dépend de l'environnement
// et se lit au runtime, la version dépend de l'artefact et une image promue de
// la préprod vers la prod affiche la même — ce qui est exactement ce qu'elle
// doit dire. Absente en `npm run dev` : le footer n'affiche alors rien.

const RELEASE_URL_BASE = 'https://github.com/ghostotof/cp-ghostotof/releases/tag/v'

export interface AppVersion {
  /** Version sémantique, `0.17.0`, ou le tag brut s'il n'en est pas une (build local). */
  readonly version: string
  /** Sept caractères du commit, `2c86b65`, quand le tag en porte un. */
  readonly build: string | null
  /** Page de la release GitHub, seulement pour une version sémantique — un tag local n'en a pas. */
  readonly releaseUrl: string | null
}

const RELEASE_TAG = /^(\d+\.\d+\.\d+)(?:-([0-9a-f]{7,40}))?$/

/**
 * Lit VITE_APP_VERSION à chaque appel (et non à l'import), pour que les tests
 * puissent la faire varier avec `vi.stubEnv`.
 */
export function getAppVersion(): AppVersion | null {
  const raw = import.meta.env.VITE_APP_VERSION?.trim()
  if (!raw) {
    return null
  }

  const match = RELEASE_TAG.exec(raw)
  if (!match) {
    return { version: raw, build: null, releaseUrl: null }
  }

  const [, version, build = null] = match
  return { version, build, releaseUrl: `${RELEASE_URL_BASE}${version}` }
}
