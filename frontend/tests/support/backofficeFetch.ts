import { vi } from 'vitest'

/** L'adresse de l'API que les tests donnent au dépôt HTTP réel qu'ils montent sous une page d'admin. */
export const BACKOFFICE_TEST_API_URL = 'https://api.example.test'

/**
 * Fait répondre `fetch` par un 200 portant `body` en JSON : le dépôt HTTP réel d'une page d'admin lit alors cette réponse
 * comme celle du backend (#748, R229, Sp18-3). À défaire par `vi.unstubAllGlobals()` dans l'`afterEach` du spec : un
 * `vi.restoreAllMocks()` ne retire pas un global posé par `vi.stubGlobal`.
 */
export function stubBackofficeJson(body: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })),
  )
}
