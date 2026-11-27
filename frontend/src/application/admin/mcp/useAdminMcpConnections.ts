import { computed, inject, ref, shallowRef, type Ref } from 'vue'
import type { AdminMcpConnection } from '../../../domain/admin/mcp/entities/AdminMcpConnection'
import { AdminMcpError } from '../../../domain/admin/mcp/errors/AdminMcpError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'
// La clé vient du composable des clients, qui la réexporte : la couche application ne la prend qu'à un endroit.
import { ADMIN_MCP_REPOSITORY } from './useAdminMcpClients'

export interface UseAdminMcpConnectionsResult extends AdminLoadState {
  /**
   * Les connexions de tous les comptes — toutes les vivantes, plus les dernières terminées —, dans l'ordre que le
   * serveur rend : la page ne retrie pas, et n'annonce la borne des terminées que par une note fixe (décision D-D).
   */
  connections: Ref<readonly AdminMcpConnection[]>
  /**
   * L'erreur de la lecture en échec, avec sa raison, que la page peut traduire ; `null` tant que `hasError` est faux.
   * Une session finie ou un refus réel ne lèvent ni l'un ni l'autre (`useAdminLoad`, `visibleMutationError`).
   */
  errorMessage: Readonly<Ref<AdminMcpError | null>>
  load: () => Promise<void>
}

/**
 * Les connexions MCP vues par l'administration (#652, spec 0007 T13), **en lecture seule** : l'administrateur ne
 * révoque pas (la personne révoque les siennes, « Mes connexions MCP »), il n'y a donc aucune mutation ici.
 *
 * `errorMessage` n'est pas, comme ailleurs, l'échec d'une mutation, mais la **raison** de l'échec de la lecture, que
 * `hasError` dit seulement booléen. Elle passe par le même filtre que les mutations (`visibleMutationError`) : un
 * échec étranger au contexte devient `unknown`, une session finie ou un refus réel ne posent rien.
 *
 * Elle reste cohérente avec `hasError` sans recopier la garde de fraîcheur d'`useAdminLoad` :
 * - elle n'est montrée que tant que `hasError` est vrai (`computed`), si bien qu'une lecture plus récente — qui
 *   rabaisse `hasError` dès son départ — la masque, et que l'échec tardif d'une lecture périmée, qui ne lève pas
 *   `hasError`, ne montre rien ;
 * - seule la dernière lecture lancée l'écrit (`readSequence`) : deux échecs qui se croisent sur le réseau ne
 *   remplacent pas la raison de la lecture courante par celle d'une lecture périmée.
 */
export function useAdminMcpConnections(): UseAdminMcpConnectionsResult {
  const repository = inject(ADMIN_MCP_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminMcpRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_MCP_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const connections = ref<readonly AdminMcpConnection[]>([])
  // `shallowRef` : une instance d'erreur n'a pas à devenir un proxy réactif, seul son remplacement compte.
  const lastReadFailure = shallowRef<AdminMcpError | null>(null)
  // Numéro de la dernière lecture lancée ; `useAdminLoad` appelle `read` une fois par `load()`, au départ de celui-ci.
  let readSequence = 0

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: async () => {
      const sequence = ++readSequence
      lastReadFailure.value = null

      try {
        return await repository.listConnections()
      } catch (error) {
        if (sequence === readSequence) {
          lastReadFailure.value = visibleMutationError(error, AdminMcpError, () => new AdminMcpError('unknown', 'Unknown error'))
        }

        // `useAdminLoad` doit voir l'échec : c'est lui qui juge `hasError`, la session finie et le refus réel.
        throw error
      }
    },
    apply: (value) => {
      connections.value = value
    },
  })

  const errorMessage = computed(() => (hasError.value ? lastReadFailure.value : null))

  // Dans `setup`, jamais dans `onMounted` (garde `noLoadInOnMounted.spec.ts`).
  void load()

  return { connections, isLoading, hasError, isLoaded, errorMessage, load }
}
