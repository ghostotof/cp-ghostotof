/**
 * Un `fetch` qui ne répond jamais et n'en sort que par son signal (#748, R68, St6-9) : il ne résout pas, et rejette
 * `AbortError` (un `DOMException`, comme le vrai) quand le `signal` reçu est abandonné — la borne de temps de
 * `HttpAuthRepository.me()` en dépend. Écrit une fois pour `HttpAuthRepository.spec.ts` et `adminGuard.spec.ts`, qui en
 * avaient chacun une copie ; se pose avec `vi.stubGlobal('fetch', fetchSettledOnlyByAbort())` et se retire avec
 * `vi.unstubAllGlobals()`.
 */
export function fetchSettledOnlyByAbort(): typeof fetch {
  return (_input, init) =>
    new Promise<Response>((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
    })
}
