/**
 * Ignore les réponses obsolètes quand plusieurs appels asynchrones sont
 * déclenchés en rafale par le même composable (ex. clics successifs sur un
 * sélecteur de locale) : seule la réponse du DERNIER appel démarré doit
 * appliquer son résultat à l'état réactif, sous peine d'écraser un affichage
 * plus récent avec une réponse arrivée en retard.
 */
export function createStaleRequestGuard(): { begin: () => number; isCurrent: (token: number) => boolean } {
  let latest = 0

  return {
    begin: () => ++latest,
    isCurrent: (token: number) => token === latest,
  }
}
