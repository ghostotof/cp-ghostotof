import { inject, ref, type Ref } from 'vue'
import type { AdminMcpClient } from '../../../domain/admin/mcp/entities/AdminMcpClient'
import { AdminMcpError } from '../../../domain/admin/mcp/errors/AdminMcpError'
import type { AdminMcpClientInput, AdminMcpClientRevision } from '../../../domain/admin/mcp/repositories/AdminMcpRepository'
// Seule importation de la couche application vers l'infrastructure : la clé d'injection est déclarée par
// l'adaptateur (#652, F2, pour qu'il soit livrable seul). Elle est réexportée ici, et c'est d'ici que la couche
// application l'injecte (`useAdminMcpConnections` comprise). Un symbole déclaré une seconde fois ne serait pas celui
// que `main.ts` fournit : l'injection échouerait. La ramener ici (l'adaptateur ne la déclarant plus, `main.ts`
// l'important d'ici comme les autres clés) touche deux fichiers hors de la tâche F3.
import { ADMIN_MCP_REPOSITORY } from '../../../infrastructure/admin/mcp/HttpAdminMcpRepository'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export { ADMIN_MCP_REPOSITORY }

export interface UseAdminMcpClientsResult extends AdminLoadState {
  /** Les clients MCP, dans l'ordre que le serveur rend : la page ne retrie pas. */
  clients: Ref<readonly AdminMcpClient[]>
  /**
   * L'échec de la dernière mutation, que la page traduit par sa `reason` (`admin.mcp.errors.*`, clés créées par
   * les pages) ; `null` après un succès, et pour une session finie ou un refus réel, que le bandeau ou l'alerte
   * d'`AdminLayout` annoncent seuls (`visibleMutationError`).
   */
  errorMessage: Ref<AdminMcpError | null>
  load: () => Promise<void>
  /** Enregistre un client pré-enregistré. `true` seulement en cas de succès : la page ne vide son formulaire que là. */
  register: (input: AdminMcpClientInput) => Promise<boolean>
  /** Révise le nom et les redirections du client désigné par son UUID (`AdminMcpClient.id`, jamais `clientId`). */
  revise: (id: string, revision: AdminMcpClientRevision) => Promise<boolean>
  /** Supprime le client désigné par son UUID ; ses connexions sont coupées par le serveur. */
  remove: (id: string) => Promise<boolean>
}

/**
 * Les clients MCP du backoffice (#652, spec 0007 T13), sur le modèle d'`useAdminUsers` et
 * d'`useAdminWatchedProducts` : pas de dépendance à `useI18n()`, la page traduit `errorMessage.reason`.
 *
 * `hasError` reflète l'échec de la lecture de la liste (affichage plein écran) ; `errorMessage` celui d'une
 * mutation (affiché près du formulaire ou de la ligne) — distincts, pour ne pas masquer une liste lue derrière
 * une erreur de formulaire.
 *
 * **Chaque mutation réussie relit la liste**, plutôt que d'y recopier le client que le serveur rend :
 * - un enregistrement ajoute une ligne dont la place est celle de l'ordre du serveur, que le frontend ne connaît pas ;
 * - une révision rend le vrai décompte des connexions, mais les autres lignes ont pu changer depuis la lecture ;
 * - une suppression coupe les connexions du client : le décompte des autres lignes n'est plus celui qu'on montre.
 * Le serveur reste la seule source de l'état affiché (comme « Mes connexions MCP ») ; le coût est une lecture de
 * plus par geste d'administration, une liste fermée de quelques clients.
 */
export function useAdminMcpClients(): UseAdminMcpClientsResult {
  const repository = inject(ADMIN_MCP_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminMcpRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_MCP_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const clients = ref<readonly AdminMcpClient[]>([])
  const errorMessage = ref<AdminMcpError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.listClients(),
    apply: (value) => {
      clients.value = value
    },
  })

  /**
   * Exécute une mutation puis relit la liste. `load()` ne rejette jamais (`useAdminLoad` absorbe l'échec dans
   * `hasError`) : une relecture ratée ne change pas le `true` d'une mutation que le serveur a acceptée.
   */
  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminMcpError, () => new AdminMcpError('unknown', 'Unknown error'))

      return false
    }
  }

  const register = (input: AdminMcpClientInput): Promise<boolean> => runMutation(() => repository.registerClient(input))

  const revise = (id: string, revision: AdminMcpClientRevision): Promise<boolean> =>
    runMutation(() => repository.reviseClient(id, revision))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.removeClient(id))

  // Dans `setup`, jamais dans `onMounted` (garde `noLoadInOnMounted.spec.ts`).
  void load()

  return { clients, isLoading, hasError, isLoaded, errorMessage, load, register, revise, remove }
}
