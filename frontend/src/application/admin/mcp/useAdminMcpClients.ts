import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminMcpClient } from '../../../domain/admin/mcp/entities/AdminMcpClient'
import { AdminMcpError, type AdminMcpErrorReason } from '../../../domain/admin/mcp/errors/AdminMcpError'
import type {
  AdminMcpClientInput,
  AdminMcpClientRevision,
  AdminMcpRepository,
} from '../../../domain/admin/mcp/repositories/AdminMcpRepository'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

/**
 * Clé d'injection du dépôt du backoffice MCP, fournie une fois par `main.ts` et injectée par les deux composables
 * (`useAdminMcpConnections` l'importe d'ici). Déclarée dans la couche application, comme les autres clés de
 * `main.ts` : l'infrastructure ne fait qu'implémenter le port du domaine.
 */
export const ADMIN_MCP_REPOSITORY: InjectionKey<AdminMcpRepository> = Symbol('AdminMcpRepository')

/**
 * Les refus qui prouvent que la ligne affichée n'est plus celle du serveur (C-S6, tours 1 et 2) :
 * - `not-found` (404) : le client a été supprimé depuis la lecture ;
 * - `declared-by-document` (409) : le client montré comme pré-enregistré, donc révisable, est désormais déclaré par
 *   son document de métadonnées (CIMD), que seul ce document fait évoluer ;
 * - `id-of-verified-domain` (422) : son identifiant est devenu celui d'un domaine vérifié depuis la lecture.
 * Les deux derniers ne tombent que sur une révision. Les autres raisons (`validation`, `client-id-taken`, `unknown`)
 * ne disent rien de la ligne : elle reste juste.
 */
const STALE_ROW_REASONS: ReadonlySet<AdminMcpErrorReason> = new Set<AdminMcpErrorReason>([
  'not-found',
  'declared-by-document',
  'id-of-verified-domain',
])

/**
 * Vrai quand l'échec d'une mutation prouve que la ligne visée est périmée. Le composable s'en sert pour relire la
 * liste ; la page, pour quitter une révision qui n'a plus d'objet. Exporté pour que les deux lisent la même liste
 * de raisons, au lieu de la recopier dans chaque geste.
 */
export function refusalProvesStaleRow(error: AdminMcpError | null): boolean {
  return null !== error && STALE_ROW_REASONS.has(error.reason)
}

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
   *
   * Un refus qui prouve que la ligne est périmée relit la liste lui aussi (`refusalProvesStaleRow` : le 404 au
   * tour 1, le 409 « déclaré par son document » et le 422 « identifiant d'un domaine vérifié » au tour 2 de C-S6) :
   * la ligne montrée n'est plus celle du serveur, et sans relecture elle garderait « Modifier » ou « Supprimer », dont
   * chaque clic recevrait le même refus. La raison est posée avant la relecture, que `load()` ne touche pas : la page
   * dit l'échec pendant et après, la mutation rend toujours `false`. Relu ici plutôt que par la page : le composable
   * tient déjà « le serveur, seule source de l'état affiché », et la règle vaut pour la révision comme pour la
   * suppression, écrite une fois au lieu d'un appel à `load()` dans chaque geste. Les autres échecs ne relisent rien :
   * rien ne dit que la liste a changé.
   */
  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminMcpError, () => new AdminMcpError('unknown', 'Unknown error'))

      if (refusalProvesStaleRow(errorMessage.value)) await load()

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
