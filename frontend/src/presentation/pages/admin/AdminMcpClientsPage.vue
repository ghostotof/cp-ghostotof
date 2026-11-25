<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminMcpClients } from '../../../application/admin/mcp/useAdminMcpClients'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
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

/** L'UUID de la ligne en cours de révision (`AdminMcpClient.id`, jamais `clientId`), `null` en création. */
const editingId = ref<string | null>(null)
/** L'identifiant public du client révisé, montré sans être proposé à la saisie. */
const editingClientId = ref('')
const isSubmitting = ref(false)

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
  editingId.value = client.id
  editingClientId.value = client.clientId
  form.clientId = ''
  form.name = client.name
  form.redirectUris = client.redirectUris.join('\n')
  errorPlace.value = null
}

async function handleSubmit(): Promise<void> {
  if (isSubmitting.value) return

  isSubmitting.value = true
  errorPlace.value = 'form'
  const redirectUris = parseRedirectUris(form.redirectUris)

  try {
    // Chaque corps est construit champ par champ, jamais par décomposition du formulaire : le serveur refuse
    // une clé en trop (400), et `clientId` ne se révise pas.
    const succeeded =
      null !== editingId.value
        ? await revise(editingId.value, { name: form.name, redirectUris })
        : await register({ clientId: form.clientId, name: form.name, redirectUris })

    // Vidé sur le seul succès de la mutation, même si la relecture qui suit a échoué (R10) : le client est
    // enregistré, et la liste dit elle-même son échec de lecture.
    if (succeeded) resetForm()
  } finally {
    isSubmitting.value = false
  }
}

async function handleRemove(client: AdminMcpClient): Promise<void> {
  // Le décompte est celui de la dernière lecture : il peut avoir changé depuis, le serveur coupe toutes celles qui restent.
  // Trois formes (aucune, une, n connexions) : le nombre est passé en pluriel explicite, comme ailleurs dans le projet,
  // plutôt que de compter sur vue-i18n, qui le déduirait aussi du paramètre nommé `count`.
  const count = client.activeConnections
  const confirmed = window.confirm(t('admin.mcpClients.confirmRemove', { name: client.name, count }, count))
  if (!confirmed) return

  errorPlace.value = 'remove'
  const succeeded = await remove(client.id)

  // Le formulaire ne garde pas en révision un client qui n'existe plus.
  if (succeeded && editingId.value === client.id) resetForm()
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
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

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmitting"
          >
            {{ t('admin.mcpClients.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.mcpClients.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.mcpClients.listTitle') }}
      </h2>

      <!-- Toujours affichés, liste lue ou non : ce que coupe une suppression se lit avant le geste. -->
      <p class="text-body-secondary mb-2">
        {{ t('admin.mcpClients.removalWarning') }}
      </p>
      <p class="form-text mb-3">
        {{ t('admin.mcpClients.cimdNote') }}
      </p>

      <p
        v-if="removeErrorShown"
        class="text-danger-emphasis small"
        role="alert"
      >
        {{ errorText }}
      </p>

      <p
        v-if="isLoading"
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
                  @click="startEdit(client)"
                >
                  {{ t('admin.mcpClients.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleRemove(client)"
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
