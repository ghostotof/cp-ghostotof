<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminUsers } from '../../../application/admin/users/useAdminUsers'
import { useAuth, ROLE_SUPER } from '../../../application/auth/useAuth'
import { SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES, isSupportedLocale, type Locale } from '../../../domain/portfolio/entities/Locale'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'

const { t } = useI18n()
const { users, isLoading, hasError, errorMessage, invite, setSuperAdmin, resendInvitation, remove, changePassword } = useAdminUsers()
const { user: currentUser } = useAuth()

// --- Invitation ---
const inviteEmail = ref('')
const inviteLocale = ref('fr')
const isInviting = ref(false)
const invitedUsername = ref<string | null>(null)
const resendSuccessForUserId = ref<number | null>(null)

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))

function selectedLocale(): Locale {
  return isSupportedLocale(inviteLocale.value) ? inviteLocale.value : 'fr'
}

/** Messages de succès ponctuels : effacés au début de toute autre action pour ne pas rester affichés. */
function clearFeedback(): void {
  invitedUsername.value = null
  resendSuccessForUserId.value = null
}

async function handleInvite(): Promise<void> {
  clearFeedback()
  isInviting.value = true
  const created = await invite(inviteEmail.value, selectedLocale())
  isInviting.value = false

  if (created) {
    invitedUsername.value = created.username
    inviteEmail.value = ''
  }
}

// --- Menu d'actions par ligne (kebab « ⋯ ») ---
// Les actions d'une ligne (promotion, renvoi, mot de passe, suppression) étaient
// affichées côte à côte : trop de boutons, colonne large et illisible. Elles
// sont regroupées derrière un bouton « ⋯ » qui ouvre un menu ; un seul menu
// ouvert à la fois (openMenuForUserId).
const openMenuForUserId = ref<number | null>(null)
const actionsColumnRef = ref<HTMLElement | null>(null)

function toggleMenu(userId: number): void {
  openMenuForUserId.value = openMenuForUserId.value === userId ? null : userId
}

function closeMenu(): void {
  openMenuForUserId.value = null
}

/**
 * Bootstrap 5 n'est chargé qu'en CSS (pas de bundle JS) : la fermeture au clic
 * extérieur / touche Échap est gérée ici, comme le menu « Contenu » de
 * AdminLayout. Le conteneur <tbody> sert de périmètre « intérieur ».
 */
function onDocumentClick(event: MouseEvent): void {
  if (null === openMenuForUserId.value) {
    return
  }
  if (null !== actionsColumnRef.value && !actionsColumnRef.value.contains(event.target as Node)) {
    closeMenu()
  }
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if ('Escape' === event.key) {
    closeMenu()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})

// --- Rôles / statut ---
const errorText = computed(() => (errorMessage.value ? t(`admin.users.errors.${errorMessage.value.reason}`) : null))

function isCurrentUser(user: AdminUser): boolean {
  return user.username === currentUser.value?.username
}

function isSuperAdmin(user: AdminUser): boolean {
  return user.roles.includes(ROLE_SUPER)
}

async function handleToggleSuperAdmin(user: AdminUser): Promise<void> {
  closeMenu()
  clearFeedback()
  await setSuperAdmin(user.id, !isSuperAdmin(user))
}

// --- Renvoi d'invitation ---
async function handleResend(user: AdminUser): Promise<void> {
  closeMenu()
  clearFeedback()
  await resendInvitation(user.id, selectedLocale())

  if (!errorMessage.value) {
    resendSuccessForUserId.value = user.id
  }
}

// --- Changement de mot de passe ---
const changingPasswordForUserId = ref<number | null>(null)
const newPassword = ref('')
const isSubmittingPassword = ref(false)
const passwordChanged = ref(false)

function startChangePassword(user: AdminUser): void {
  closeMenu()
  clearFeedback()
  changingPasswordForUserId.value = user.id
  newPassword.value = ''
  passwordChanged.value = false
}

function cancelChangePassword(): void {
  changingPasswordForUserId.value = null
  newPassword.value = ''
}

async function handleSubmitPassword(): Promise<void> {
  if (null === changingPasswordForUserId.value) {
    return
  }

  isSubmittingPassword.value = true
  passwordChanged.value = false

  await changePassword(changingPasswordForUserId.value, newPassword.value)

  isSubmittingPassword.value = false

  if (!errorMessage.value) {
    passwordChanged.value = true
    newPassword.value = ''
  }
}

async function handleDelete(user: AdminUser): Promise<void> {
  closeMenu()

  if (!window.confirm(t('admin.users.confirmDelete', { username: user.username }))) {
    return
  }

  clearFeedback()
  await remove(user.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.users.invite.title') }}
      </h2>

      <form
        novalidate
        class="admin-user-invite-form d-flex flex-column flex-sm-row gap-3 align-items-sm-end"
        @submit.prevent="handleInvite"
      >
        <div class="flex-grow-1">
          <BaseTextInput
            id="admin-user-invite-email"
            v-model="inviteEmail"
            type="email"
            :label="t('admin.users.invite.emailLabel')"
            required
          />
        </div>
        <div>
          <BaseSelect
            id="admin-user-invite-locale"
            v-model="inviteLocale"
            :label="t('admin.users.invite.localeLabel')"
            :options="localeOptions"
          />
        </div>
        <button
          type="submit"
          class="btn btn-gradient mb-3"
          :disabled="isInviting"
        >
          {{ isInviting ? t('admin.users.invite.sending') : t('admin.users.invite.submit') }}
        </button>
      </form>

      <p
        v-if="invitedUsername"
        class="text-success small mb-0"
      >
        {{ t('admin.users.invite.success', { username: invitedUsername }) }}
      </p>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.users.listTitle') }}
      </h2>

      <p
        v-if="errorText"
        class="text-danger"
        role="alert"
      >
        {{ errorText }}
      </p>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.users.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.users.loadError') }}
      </p>
      <p
        v-else-if="0 === users.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.users.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.users.usernameLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.users.emailLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.users.rolesLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.users.statusLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.users.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody ref="actionsColumnRef">
            <template
              v-for="user in users"
              :key="user.id"
            >
              <tr>
                <td>{{ user.username }}</td>
                <td>
                  <span v-if="user.email">{{ user.email }}</span>
                  <span
                    v-else
                    class="text-body-secondary"
                  >—</span>
                </td>
                <td>{{ user.roles.join(', ') }}</td>
                <td>
                  <span
                    class="badge"
                    :class="'pending' === user.status ? 'text-bg-warning' : 'text-bg-success'"
                  >
                    {{ 'pending' === user.status ? t('admin.users.statusPending') : t('admin.users.statusActive') }}
                  </span>
                </td>
                <td class="text-end">
                  <div class="admin-user-actions">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-light"
                      aria-haspopup="true"
                      :aria-expanded="openMenuForUserId === user.id"
                      :aria-label="t('admin.users.actionsFor', { username: user.username })"
                      @click="toggleMenu(user.id)"
                    >
                      <span aria-hidden="true">⋯</span>
                    </button>

                    <ul
                      v-if="openMenuForUserId === user.id"
                      class="dropdown-menu dropdown-menu-end show mt-1"
                      data-bs-theme="dark"
                    >
                      <li>
                        <button
                          type="button"
                          class="dropdown-item"
                          :disabled="isCurrentUser(user)"
                          :title="isCurrentUser(user) ? t('admin.users.cannotChangeOwnRoles') : undefined"
                          @click="handleToggleSuperAdmin(user)"
                        >
                          {{ isSuperAdmin(user) ? t('admin.users.demote') : t('admin.users.promote') }}
                        </button>
                      </li>
                      <li v-if="'pending' === user.status">
                        <button
                          type="button"
                          class="dropdown-item"
                          @click="handleResend(user)"
                        >
                          {{ t('admin.users.resendInvitation') }}
                        </button>
                      </li>
                      <li>
                        <button
                          type="button"
                          class="dropdown-item"
                          @click="startChangePassword(user)"
                        >
                          {{ t('admin.users.changePassword') }}
                        </button>
                      </li>
                      <li>
                        <hr class="dropdown-divider">
                      </li>
                      <li>
                        <button
                          type="button"
                          class="dropdown-item text-danger"
                          :disabled="isCurrentUser(user)"
                          :title="isCurrentUser(user) ? t('admin.users.cannotDeleteSelf') : undefined"
                          @click="handleDelete(user)"
                        >
                          {{ t('admin.users.delete') }}
                        </button>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
              <tr v-if="resendSuccessForUserId === user.id">
                <td
                  colspan="5"
                  class="text-success small"
                >
                  {{ t('admin.users.resendSuccess') }}
                </td>
              </tr>
              <tr v-if="changingPasswordForUserId === user.id">
                <td colspan="5">
                  <form
                    novalidate
                    class="admin-user-password-form d-flex flex-column gap-2 py-2"
                    @submit.prevent="handleSubmitPassword"
                  >
                    <BaseTextInput
                      :id="`admin-user-${user.id}-new-password`"
                      v-model="newPassword"
                      type="password"
                      :label="t('admin.users.newPasswordLabel')"
                      required
                    />

                    <p
                      v-if="passwordChanged"
                      class="text-success small mb-0"
                    >
                      {{ t('admin.users.passwordChanged') }}
                    </p>

                    <div class="d-flex gap-2">
                      <button
                        type="submit"
                        class="btn btn-gradient btn-sm"
                        :disabled="isSubmittingPassword"
                      >
                        {{ t('admin.users.save') }}
                      </button>
                      <button
                        type="button"
                        class="btn btn-outline-light btn-sm"
                        @click="cancelChangePassword"
                      >
                        {{ t('admin.users.cancel') }}
                      </button>
                    </div>
                  </form>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-user-actions {
  position: relative;
  display: inline-block;
}

/* Sans Popper (pas de bundle JS Bootstrap), on ancre le menu sous le bouton. */
.admin-user-actions .dropdown-menu {
  display: block;
  position: absolute;
  top: 100%;
  right: 0;
  left: auto;
  z-index: 1030;
  min-width: 12rem;
}
</style>
