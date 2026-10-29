import { enableAutoUnmount } from '@vue/test-utils'
import { afterEach, beforeEach } from 'vitest'
import { resetAuthStateForTests } from '../../src/application/auth/resetAuthStateForTests'

/**
 * Fichier d'amorçage de toute la suite (`vite.config.ts`, `test.setupFiles`, #748, R25). Il s'exécute avant chaque
 * fichier de spec, et ses crochets passent avant ceux du spec.
 *
 * - `resetAuthStateForTests` : l'état de la session est un singleton de module (`application/auth/`), partagé par
 *   tout ce qu'un spec monte. Remis à celui du chargement avant chaque test, aucun test ne dépend de ce qu'un
 *   précédent a laissé — ni de l'ordre d'exécution.
 * - `enableAutoUnmount(afterEach)` : tout composant monté est démonté à la fin du test. VTU refuse un second appel
 *   (« enableAutoUnmount cannot be called more than once ») : un spec ne le redéclare pas.
 */
beforeEach(() => {
  resetAuthStateForTests()
})

enableAutoUnmount(afterEach)
