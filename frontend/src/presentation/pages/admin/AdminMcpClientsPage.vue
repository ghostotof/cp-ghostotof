<script setup lang="ts">
import { computed, nextTick, reactive, ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminMcpClients } from '../../../application/admin/mcp/useAdminMcpClients'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import { useFocusThenAnnounce } from '../../ui/useFocusThenAnnounce'
import type {
  AdminMcpClient,
  AdminMcpClientRegistration,
} from '../../../domain/admin/mcp/entities/AdminMcpClient'
import type { AdminMcpErrorReason } from '../../../domain/admin/mcp/errors/AdminMcpError'

/**
 * Les clients MCP du backoffice (#652, spec 0007 T13) : pré-enregistrer un client, réviser le nom et les
 * redirections d'un client pré-enregistré, supprimer n'importe quel client — ce qui coupe ses connexions.
 *
 * Ce que la page n'offre pas, et pourquoi :
 * - « Modifier » sur une ligne `cimd` : son document de métadonnées fait foi (le serveur refuserait par un 409) ;
 * - « Modifier » sur une ligne qui masque un domaine vérifié (R4) : réviser ses redirections entretiendrait le
 *   masquage (le serveur refuserait par un 422 typé) ; la ligne le dit par un texte visible, et se supprime.
 * Le bouton absent vaut mieux qu'un bouton désactivé : il n'y a rien à attendre, seulement un autre geste.
 */
const { t } = useI18n()
const { clients, isLoading, hasError, isLoaded, errorMessage, register, revise, remove } = useAdminMcpClients()

/** L'id de l'alerte du formulaire, que désignent les champs pendant qu'elle est affichée. */
const FORM_ERROR_ID = 'admin-mcp-client-error'

/**
 * Les libellés des deux modes, par des clés littérales : la règle `no-missing-keys` du lint les vérifie, et
 * `Record` impose une clé à chaque mode (un mode ajouté au domaine casse `vue-tsc` ici).
 */
const REGISTRATION_LABEL_KEYS: Record<AdminMcpClientRegistration, string> = {
  preregistered: 'admin.mcpClients.registrations.preregistered',
  cimd: 'admin.mcpClients.registrations.cimd',
}

/** Une clé par raison d'`AdminMcpError` (R8), exhaustive par le même `Record`. */
const ERROR_KEYS: Record<AdminMcpErrorReason, string> = {
  'not-found': 'admin.mcpClients.errors.not-found',
  'client-id-taken': 'admin.mcpClients.errors.client-id-taken',
  'declared-by-document': 'admin.mcpClients.errors.declared-by-document',
  'id-of-verified-domain': 'admin.mcpClients.errors.id-of-verified-domain',
  validation: 'admin.mcpClients.errors.validation',
  unknown: 'admin.mcpClients.errors.unknown',
}

/** L'issue d'une mutation réussie, dite par la région d'annonce une fois le focus déplacé. */
type ClientOutcome = 'registered' | 'revised' | 'removed'

/** Une clé par issue, exhaustive par le même `Record`. */
const OUTCOME_KEYS: Record<ClientOutcome, string> = {
  registered: 'admin.mcpClients.outcome.registered',
  revised: 'admin.mcpClients.outcome.revised',
  removed: 'admin.mcpClients.outcome.removed',
}

/** L'UUID de la ligne en cours de révision (`AdminMcpClient.id`, jamais `clientId`), `null` en création. */
const editingId = ref<string | null>(null)
/** L'identifiant public du client révisé, montré sans être proposé à la saisie. */
const editingClientId = ref('')
const isSubmitting = ref(false)
/** L'UUID du client dont la suppression est en vol, `null` sinon. */
const removingId = ref<string | null>(null)

/**
 * Une seule mutation à la fois (C-S6). Les trois partagent l'`errorMessage` du composable, que chacune remet à
 * `null` en partant : une suppression lancée pendant un envoi effaçait l'erreur du formulaire, ou faisait lire
 * la 422 d'une révision au-dessus du tableau ; un second clic sur « Supprimer » envoyait un second DELETE, dont
 * le 404 disait « n'existe plus » d'un client que le premier venait de supprimer. Tant qu'une mutation est en
 * vol, « Modifier », « Supprimer », « Enregistrer » et « Annuler » sont désactivés, et chaque geste refuse aussi
 * dans le code : deux clics partis avant le rendu trouvent encore le bouton actif.
 */
const isMutating = computed(() => isSubmitting.value || null !== removingId.value)

/** Les deux titres qui reçoivent le focus quand le bouton qui l'avait disparaît. */
const formHeading = useTemplateRef<HTMLHeadingElement>('formHeading')
const listHeading = useTemplateRef<HTMLHeadingElement>('listHeading')
/** Les boutons du formulaire, qu'un envoi remplace (« Annuler » disparaît avec la révision) : le focus qui y reste est perdu. */
const formActions = useTemplateRef<HTMLDivElement>('formActions')

/** L'issue que la région d'annonce dit : écrite un court délai après le déplacement du focus (`frontend-a11y.md`). */
const {
  announced: announcedOutcome,
  focusThenAnnounce,
  cancel: cancelAnnouncement,
  isUnmounted,
} = useFocusThenAnnounce<ClientOutcome>()

const outcomeText = computed<string>(() =>
  null === announcedOutcome.value ? '' : t(OUTCOME_KEYS[announcedOutcome.value]),
)

/**
 * Le composable n'a qu'un `errorMessage` pour ses trois mutations ; la page retient le geste qui l'a produit,
 * pour dire l'échec là où il a eu lieu : près du formulaire, ou près du tableau pour une suppression.
 */
const errorPlace = ref<'form' | 'remove' | null>(null)

/** Les redirections se saisissent comme un texte, une par ligne ; elles ne deviennent une liste qu'à l'envoi. */
const form = reactive({ clientId: '', name: '', redirectUris: '' })

const isEditing = computed(() => null !== editingId.value)

/** La raison traduite de l'échec de la dernière mutation, ou `null` (rien à dire, ou une session finie que le bandeau dit seul). */
const errorText = computed<string | null>(() => {
  const error = errorMessage.value

  return null === error ? null : t(ERROR_KEYS[error.reason])
})

const formErrorShown = computed(() => null !== errorText.value && 'form' === errorPlace.value)
const removeErrorShown = computed(() => null !== errorText.value && 'remove' === errorPlace.value)

/**
 * Les violations du serveur, montrées sous la raison traduite pour une 422 de saisie : l'adaptateur les a
 * composées des messages du serveur (`violationsMessage`), qui ne recopient jamais la valeur saisie. Rendues
 * par interpolation, jamais par `v-html`.
 */
const violationsText = computed<string | null>(() => {
  const error = errorMessage.value

  return null !== error && 'validation' === error.reason ? error.message : null
})

/** `aria-describedby` des champs : l'alerte du formulaire, seulement quand elle est rendue (un IDREF vers rien est une faute). */
const fieldDescribedBy = computed(() => (formErrorShown.value ? FORM_ERROR_ID : undefined))

/**
 * « Chargement » tant que rien n'a été lu. La relecture qui suit une mutation garde ce qui est affiché (le
 * tableau, marqué `aria-busy`) : le remplacer par « Chargement » détruisait le bouton qui avait le focus, et le
 * focus tombait sur `body` (C-S6). La chaîne `hasError` → `!isLoaded` → vide reste celle du gabarit.
 */
const showsLoading = computed(() => isLoading.value && !isLoaded.value)

/** L'élément qui a le focus au départ d'un geste, `null` s'il n'est sur rien (`body`). */
function focusedElement(): HTMLElement | null {
  const active = document.activeElement

  return active instanceof HTMLElement && document.body !== active ? active : null
}

/**
 * Vrai si le focus n'est plus là où la personne l'a mis : sur `body` (le navigateur le retire d'un bouton qui devient
 * `disabled`), sur un élément sorti du document, ou dans `vanished`, ce que le geste vient de faire disparaître ou
 * de remplacer (la ligne supprimée, les boutons du formulaire). Partout ailleurs, la personne y est allée pendant
 * l'attente — saisir le nom d'un autre client, par exemple — et la page ne le lui reprend pas (C-S6, tour 1).
 */
function focusIsLost(vanished: HTMLElement | null = null): boolean {
  const active = focusedElement()

  return null === active || !active.isConnected || (null !== vanished && vanished.contains(active))
}

/**
 * Rend le focus à `element` s'il est perdu après l'appel. Jamais pris à un autre élément : la personne a pu aller
 * ailleurs pendant l'attente.
 */
function restoreFocusIfLost(element: HTMLElement | null): void {
  if (!focusIsLost() || null === element || !element.isConnected) return

  element.focus()
}

/** Le titre du formulaire, après le rendu : il nomme le mode (création ou révision) où le formulaire vient de passer. */
async function focusFormHeading(): Promise<void> {
  await nextTick()
  formHeading.value?.focus()
}

/** Le texte du `<textarea>` en liste : une redirection par ligne, blancs autour et lignes vides écartés. */
function parseRedirectUris(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => '' !== line)
}

/** Seule une ligne pré-enregistrée qui ne masque aucun domaine vérifié se révise (R4). */
function isRevisable(client: AdminMcpClient): boolean {
  return 'preregistered' === client.registration && !client.shadowsVerifiedDomain
}

function registrationLabel(client: AdminMcpClient): string {
  return t(REGISTRATION_LABEL_KEYS[client.registration])
}

function resetForm(): void {
  editingId.value = null
  editingClientId.value = ''
  form.clientId = ''
  form.name = ''
  form.redirectUris = ''
  // L'échec d'un autre formulaire ne se lit pas au-dessus d'un formulaire vide.
  errorPlace.value = null
}

function startEdit(client: AdminMcpClient): void {
  if (isMutating.value) return

  // Un nouveau geste : l'issue déjà annoncée ne dit plus rien de la page.
  cancelAnnouncement()
  editingId.value = client.id
  editingClientId.value = client.clientId
  form.clientId = ''
  form.name = client.name
  form.redirectUris = client.redirectUris.join('\n')
  errorPlace.value = null
  // Le formulaire, plus haut, passe en révision : le focus y va, sans quoi le clavier resterait dans le tableau,
  // loin des champs à réviser. Son titre plutôt que le premier champ : il dit le mode, et le premier champ n'est
  // pas le même en création (l'identifiant) et en révision (le nom).
  void focusFormHeading()
}

/** « Annuler » disparaît avec la révision : le focus va au titre du formulaire vidé, sans quoi il tomberait sur `body`. */
function cancelEdit(): void {
  if (isMutating.value) return

  cancelAnnouncement()
  resetForm()
  void focusFormHeading()
}

async function handleSubmit(): Promise<void> {
  if (isMutating.value) return

  // Lus avant l'appel : le focus, que le navigateur retire d'« Enregistrer » désactivé, le mode, que le succès vide,
  // et le client révisé, que la suite compare à celui du formulaire.
  const focused = focusedElement()
  const outcome: ClientOutcome = isEditing.value ? 'revised' : 'registered'
  const editing = editingId.value
  cancelAnnouncement()
  isSubmitting.value = true
  errorPlace.value = 'form'
  const redirectUris = parseRedirectUris(form.redirectUris)
  let succeeded = false

  try {
    // Chaque corps est construit champ par champ, jamais par décomposition du formulaire : le serveur refuse
    // une clé en trop (400), et `clientId` ne se révise pas.
    succeeded =
      null !== editing
        ? await revise(editing, { name: form.name, redirectUris })
        : await register({ clientId: form.clientId, name: form.name, redirectUris })

    // Vidé sur le seul succès de la mutation, même si la relecture qui suit a échoué (R10) : le client est
    // enregistré, et la liste dit elle-même son échec de lecture.
    if (succeeded) resetForm()
  } finally {
    isSubmitting.value = false
  }

  // Une révision « n'existe plus » (404) quitte la révision (C-S6, tour 1) : chaque nouvel essai recevrait le même
  // 404, et le composable a déjà relu la liste, d'où la ligne est partie. L'échec reste dit dans le formulaire, que
  // `resetForm()` aurait vidé de son alerte — comme la suppression d'un client en révision le fait au-dessus du tableau.
  const leftRevision =
    !succeeded && null !== editing && editingId.value === editing && 'not-found' === errorMessage.value?.reason
  if (leftRevision) {
    resetForm()
    errorPlace.value = 'form'
  }

  if (isUnmounted()) return
  await nextTick()

  if (succeeded) {
    // Le formulaire vidé repasse en création : le focus va à son titre, puis l'issue est dite — sauf si la personne
    // est allée ailleurs pendant l'attente (saisir dans un champ, lire la liste) : l'issue est dite sans le lui prendre.
    focusThenAnnounce(focusIsLost(formActions.value) ? formHeading.value : null, outcome)
  } else if (leftRevision) {
    // Le formulaire a changé de mode, « Annuler » a disparu : un focus perdu va au titre, qui dit le nouveau mode ;
    // l'alerte du formulaire dit l'échec, rien n'est annoncé de plus.
    if (focusIsLost(formActions.value)) formHeading.value?.focus()
  } else {
    // Échec : l'alerte du formulaire le dit ; le focus revient là où la personne était.
    restoreFocusIfLost(focused)
  }
}

async function handleRemove(client: AdminMcpClient, event: MouseEvent): Promise<void> {
  // Refusé avant la confirmation : un second clic parti avant le rendu trouve « Supprimer » encore actif, et son
  // DELETE rendrait un 404 (« n'existe plus ») d'un client que le premier vient de supprimer.
  if (isMutating.value) return

  // Le décompte est celui de la dernière lecture : il peut avoir changé depuis, le serveur coupe toutes celles qui restent.
  // Trois formes (aucune, une, n connexions) : le nombre est passé en pluriel explicite, comme ailleurs dans le projet,
  // plutôt que de compter sur vue-i18n, qui le déduirait aussi du paramètre nommé `count`.
  const count = client.activeConnections
  const confirmed = window.confirm(t('admin.mcpClients.confirmRemove', { name: client.name, count }, count))
  if (!confirmed) return

  // Le bouton cliqué, pour lui rendre le focus si la suppression échoue (il est désactivé pendant l'appel), et sa
  // ligne, qu'une suppression réussie fait disparaître avec le focus qui y était resté.
  const clicked = event.currentTarget instanceof HTMLElement ? event.currentTarget : null
  const row = clicked?.closest('tr') ?? null
  cancelAnnouncement()
  errorPlace.value = 'remove'
  removingId.value = client.id
  let succeeded = false

  try {
    succeeded = await remove(client.id)
  } finally {
    removingId.value = null
  }

  // Le formulaire ne garde pas en révision un client qui n'existe plus : supprimé à l'instant, ou déjà disparu (404).
  if (editingId.value === client.id) {
    if (succeeded) {
      resetForm()
    } else if ('not-found' === errorMessage.value?.reason) {
      resetForm()
      // `resetForm()` efface la place de l'erreur : le 404 se dit toujours au-dessus du tableau.
      errorPlace.value = 'remove'
    }
  }

  if (isUnmounted()) return
  await nextTick()

  if (succeeded) {
    // La ligne et son bouton ont disparu : un focus perdu, ou resté dans la ligne, va au titre de la liste, d'où la
    // lecture reprend, puis l'issue est dite. Un focus parti ailleurs pendant l'attente — la personne saisit déjà le
    // client suivant dans le formulaire — reste où il est : l'issue est dite sans le déplacer.
    focusThenAnnounce(focusIsLost(row) ? listHeading.value : null, 'removed')
  } else {
    // Échec : l'alerte au-dessus du tableau le dit ; le focus revient au bouton cliqué, s'il existe encore.
    restoreFocusIfLost(null !== clicked && clicked.isConnected ? clicked : listHeading.value)
  }
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <!-- `tabindex="-1"` : le titre reçoit le focus quand le formulaire change de mode sous la personne
           (« Modifier », « Annuler », un envoi réussi), sans entrer dans l'ordre de tabulation. -->
      <h2
        ref="formHeading"
        class="h6 fw-bold text-white mb-3"
        tabindex="-1"
      >
        {{ isEditing ? t('admin.mcpClients.edit') : t('admin.mcpClients.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <!-- L'identifiant public est celui que nomment les connexions et les consentements : il ne se révise
             pas (le serveur refuse la clé). En révision, il est montré plutôt que proposé à la saisie. -->
        <div
          v-if="isEditing"
          class="mb-3"
        >
          <p class="form-label text-body-secondary mb-1">
            {{ t('admin.mcpClients.clientIdLabel') }}
          </p>
          <p class="mb-1 fw-semibold text-white text-break">
            {{ editingClientId }}
          </p>
          <p class="form-text mb-0">
            {{ t('admin.mcpClients.clientIdImmutableHelp') }}
          </p>
        </div>
        <BaseTextInput
          v-else
          id="admin-mcp-client-id"
          v-model="form.clientId"
          :label="t('admin.mcpClients.clientIdLabel')"
          :aria-describedby="fieldDescribedBy"
          required
        />

        <BaseTextInput
          id="admin-mcp-client-name"
          v-model="form.name"
          :label="t('admin.mcpClients.nameLabel')"
          :aria-describedby="fieldDescribedBy"
          required
        />

        <BaseTextarea
          id="admin-mcp-client-redirects"
          v-model="form.redirectUris"
          :label="t('admin.mcpClients.redirectUrisLabel')"
          :aria-describedby="fieldDescribedBy"
          :rows="3"
          required
        />

        <div
          v-if="formErrorShown"
          :id="FORM_ERROR_ID"
          class="text-danger-emphasis small mb-3"
          role="alert"
        >
          <p class="mb-0">
            {{ errorText }}
          </p>
          <p
            v-if="violationsText"
            class="mb-0"
          >
            {{ violationsText }}
          </p>
        </div>

        <div
          ref="formActions"
          class="d-flex gap-2"
        >
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isMutating"
          >
            {{ t('admin.mcpClients.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            :disabled="isMutating"
            @click="cancelEdit"
          >
            {{ t('admin.mcpClients.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <!-- Reçoit le focus quand la ligne supprimée emporte le bouton qui l'avait. -->
      <h2
        ref="listHeading"
        class="h6 fw-bold text-white mb-3"
        tabindex="-1"
      >
        {{ t('admin.mcpClients.listTitle') }}
      </h2>

      <!-- Toujours affichés, liste lue ou non : ce que coupe une suppression se lit avant le geste. -->
      <p class="text-body-secondary mb-2">
        {{ t('admin.mcpClients.removalWarning') }}
      </p>
      <p class="form-text mb-3">
        {{ t('admin.mcpClients.cimdNote') }}
      </p>

      <!-- La seule région d'annonce de la page, présente dès le montage et vide : seul son contenu change, écrit
           après le déplacement du focus (`useFocusThenAnnounce`). Hors du conteneur `aria-busy`, qui la ferait taire. -->
      <p
        role="status"
        class="text-body-secondary small mb-3"
      >
        {{ outcomeText }}
      </p>

      <p
        v-if="removeErrorShown"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ errorText }}
      </p>

      <p
        v-if="showsLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.mcpClients.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger-emphasis mb-0"
        role="alert"
      >
        {{ t('admin.mcpClients.loadError') }}
      </p>
      <!-- Non lue n'est pas vide (#748, R4) : « Aucun client » seulement une fois la liste lue. -->
      <p
        v-else-if="!isLoaded"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.notLoaded') }}
      </p>
      <p
        v-else-if="0 === clients.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.mcpClients.empty') }}
      </p>
      <!-- `position-relative` : l'en-tête « Actions » `visually-hidden` (position absolue) trouve son ancêtre
           positionné dans ce conteneur défilant, au lieu d'élargir la page à 320 px (#748, comme « Mes connexions MCP »). -->
      <div
        v-else
        class="table-responsive position-relative"
        :aria-busy="isLoading"
      >
        <table class="table table-dark align-middle mb-0">
          <caption class="visually-hidden">
            {{ t('admin.mcpClients.caption') }}
          </caption>
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.mcpClients.columns.clientId') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpClients.columns.name') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpClients.columns.registration') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpClients.columns.redirectUris') }}
              </th>
              <th scope="col">
                {{ t('admin.mcpClients.columns.activeConnections') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.mcpClients.columns.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="client in clients"
              :key="client.id"
            >
              <!-- L'en-tête de la ligne, comme « Mes connexions MCP » : un lecteur d'écran annonce le client avant
                   chaque cellule. Un identifiant CIMD est une URL : `text-break` la coupe plutôt que d'élargir le tableau. -->
              <th
                scope="row"
                class="fw-normal text-break"
              >
                {{ client.clientId }}
              </th>
              <td>{{ client.name }}</td>
              <td>
                {{ registrationLabel(client) }}
                <!-- Le signal est un texte, pas une couleur ni une icône seule : il dit aussi le geste qui reste. -->
                <span
                  v-if="client.shadowsVerifiedDomain"
                  class="d-block form-text"
                >
                  {{ t('admin.mcpClients.shadowsVerifiedDomain') }}
                </span>
              </td>
              <td class="text-break">
                <ul class="list-unstyled mb-0">
                  <!-- Clé par position : rien ne garantit ici deux redirections distinctes, et la liste ne se réordonne pas. -->
                  <li
                    v-for="(uri, index) in client.redirectUris"
                    :key="index"
                  >
                    {{ uri }}
                  </li>
                </ul>
              </td>
              <td>{{ client.activeConnections }}</td>
              <td class="text-end text-nowrap">
                <button
                  v-if="isRevisable(client)"
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  :disabled="isMutating"
                  @click="startEdit(client)"
                >
                  {{ t('admin.mcpClients.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  :disabled="isMutating"
                  @click="handleRemove(client, $event)"
                >
                  {{ t('admin.mcpClients.deleteAction') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Comme « Mes connexions MCP » : un conteneur sans hauteur imposée n'a jamais à défiler à la verticale (#748, constat 18). */
.table-responsive {
  overflow-y: hidden;
}
</style>
