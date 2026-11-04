import { resetSessionEventsForTests } from './sessionEvents'
import { resetSessionGenerationForTests } from './sessionGeneration'
import { resetSessionStateForTests } from './sessionState'
import { resetSessionRereadsForTests } from './useAuth'

/**
 * Remet **tout l'état de module de la session** à celui du chargement (#748, R25) : anonyme, `isChecking` vrai,
 * `sessionEnded` faux, compteur et génération à 0, aucun dernier compte retenu (`sessionState`), aucune relecture en vol, aucun abonné, la
 * minuterie de l'accès de base arrêtée et sa clé `localStorage` effacée.
 *
 * Réservée aux tests, **jamais appelée par `src/`** : `tests/support/setup.ts` l'appelle avant chaque test, si bien
 * qu'aucun test ne dépend de ce qu'un autre a laissé. Chaque module remet à zéro ce qu'il possède (`reset…ForTests`) ;
 * celle-ci les appelle tous — un nouvel état de module de `application/auth/` doit y être ajouté.
 */
export function resetAuthStateForTests(): void {
  resetSessionStateForTests()
  resetSessionGenerationForTests()
  resetSessionEventsForTests()
  resetSessionRereadsForTests()
}
