<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminUsers } from '../../../application/admin/users/useAdminUsers'
import { useAuth } from '../../../application/auth/useAuth'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'

const { t } = useI18n()
const { users, isLoading, hasError, errorMessage, remove, changePassword } = useAdminUsers()
const { user: currentUser } = useAuth()

const changingPasswordForUserId = ref<number | null>(null)
const newPassword = ref('')
const isSubmittingPassword = ref(false)
const passwordChanged = ref(false)

const errorText = computed(() => (errorMessage.value ? t(`admin.users.errors.${errorMessage.value.reason}`) : null))

function isCurrentUser(user: AdminUser): boolean {
  return user.username === currentUser.value?.username
}

function startChangePassword(user: AdminUser): void {
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
  if (!window.confirm(t('admin.users.confirmDelete', { username: user.username }))) {
    return
  }

  await remove(user.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.users.listTitle') }}
      </h2>

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
                {{ t('admin.users.rolesLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.users.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <template
              v-for="user in users"
              :key="user.id"
            >
              <tr>
                <td>{{ user.username }}</td>
                <td>{{ user.roles.join(', ') }}</td>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-light me-2"
                    @click="startChangePassword(user)"
                  >
                    {{ t('admin.users.changePassword') }}
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="isCurrentUser(user)"
                    :title="isCurrentUser(user) ? t('admin.users.cannotDeleteSelf') : undefined"
                    @click="handleDelete(user)"
                  >
                    {{ t('admin.users.delete') }}
                  </button>
                </td>
              </tr>
              <tr v-if="changingPasswordForUserId === user.id">
                <td colspan="3">
                  <form
                    novalidate
                    class="d-flex flex-column gap-2 py-2"
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
                    <p
                      v-if="errorText"
                      class="text-danger small mb-0"
                      role="alert"
                    >
                      {{ errorText }}
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
