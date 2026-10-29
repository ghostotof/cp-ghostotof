import { enableAutoUnmount } from '@vue/test-utils'
import { afterEach, beforeEach } from 'vitest'
import { resetAuthStateForTests } from '../../src/application/auth/resetAuthStateForTests'
import { resetBackofficeAccessRefusalForTests } from '../../src/application/admin/shared/backofficeAccessRefusal'
import { setBackofficeSessionReaction } from '../../src/infrastructure/admin/shared/BackofficeHttpClient'

/**
 * Fichier d'amorçage de toute la suite (`vite.config.ts`, `test.setupFiles`, #748, R25). Il s'exécute avant chaque
 * fichier de spec, et ses crochets passent avant ceux du spec.
 *
 * - `resetAuthStateForTests` : l'état de la session est un singleton de module (`application/auth/`), partagé par
 *   tout ce qu'un spec monte. Remis à celui du chargement avant chaque test, aucun test ne dépend de ce qu'un
 *   précédent a laissé — ni de l'ordre d'exécution.
 * - `resetBackofficeAccessRefusalForTests` et `setBackofficeSessionReaction(null)` (R38) : le refus du backoffice
 *   (compteur et périmètre) et la réaction enregistrée sont d'autres états de module ; un spec qui en laisse un
 *   derrière lui ne le lègue pas au suivant.
 * - `enableAutoUnmount(afterEach)` : tout composant monté est démonté à la fin du test. VTU refuse un second appel
 *   (« enableAutoUnmount cannot be called more than once ») : un spec ne le redéclare pas.
 */
beforeEach(() => {
  resetAuthStateForTests()
  resetBackofficeAccessRefusalForTests()
  setBackofficeSessionReaction(null)
})

enableAutoUnmount(afterEach)
