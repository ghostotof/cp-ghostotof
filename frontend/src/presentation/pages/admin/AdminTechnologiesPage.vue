<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminExperienceTechnologies } from '../../../application/admin/technologies/useAdminExperienceTechnologies'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import type { AdminExperienceTechnology } from '../../../domain/admin/technologies/entities/AdminExperienceTechnology'

const { t } = useI18n()
const { technologies, isLoading, hasError, errorMessage, create, update, remove } = useAdminExperienceTechnologies()

const editingId = ref<number | null>(null)
const form = reactive({ name: '', years: 0, iconKey: '', relatedTechnologyName: '' })
const isSubmitting = ref(false)

const isEditing = computed(() => null !== editingId.value)

const errorText = computed(() => (errorMessage.value ? t(`admin.technologies.errors.${errorMessage.value.reason}`) : null))

function resetForm(): void {
  editingId.value = null
  form.name = ''
  form.years = 0
  form.iconKey = ''
  form.relatedTechnologyName = ''
}

function startEdit(technology: AdminExperienceTechnology): void {
  editingId.value = technology.id
  form.name = technology.name
  form.years = technology.years
  form.iconKey = technology.iconKey ?? ''
  form.relatedTechnologyName = technology.relatedTechnologyName ?? ''
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    name: form.name,
    years: form.years,
    iconKey: '' === form.iconKey ? null : form.iconKey,
    relatedTechnologyName: '' === form.relatedTechnologyName ? null : form.relatedTechnologyName,
  }

  if (null !== editingId.value) {
    await update(editingId.value, input)
  } else {
    await create(input)
  }

  isSubmitting.value = false

  if (!errorMessage.value) {
    resetForm()
  }
}

async function handleDelete(technology: AdminExperienceTechnology): Promise<void> {
  if (!window.confirm(t('admin.technologies.confirmDelete', { name: technology.name }))) {
    return
  }

  await remove(technology.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.technologies.edit') : t('admin.technologies.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseTextInput
          id="admin-tech-name"
          v-model="form.name"
          :label="t('admin.technologies.nameLabel')"
          required
        />
        <BaseNumberInput
          id="admin-tech-years"
          v-model="form.years"
          :label="t('admin.technologies.yearsLabel')"
          required
        />
        <BaseTextInput
          id="admin-tech-icon-key"
          v-model="form.iconKey"
          :label="t('admin.technologies.iconKeyLabel')"
        />
        <BaseTextInput
          id="admin-tech-related"
          v-model="form.relatedTechnologyName"
          :label="t('admin.technologies.relatedTechnologyLabel')"
        />

        <p
          v-if="errorText"
          class="text-danger small"
          role="alert"
        >
          {{ errorText }}
        </p>

        <div class="d-flex gap-2">
          <button
            type="submit"
            class="btn btn-gradient"
            :disabled="isSubmitting"
          >
            {{ t('admin.technologies.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.technologies.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.technologies.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.technologies.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.technologies.loadError') }}
      </p>
      <p
        v-else-if="0 === technologies.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.technologies.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.technologies.nameLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.technologies.yearsLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.technologies.iconKeyLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.technologies.relatedTechnologyLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.technologies.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="technology in technologies"
              :key="technology.id"
            >
              <td>{{ technology.name }}</td>
              <td>{{ technology.years }}</td>
              <td>{{ technology.iconKey ?? '—' }}</td>
              <td>{{ technology.relatedTechnologyName ?? '—' }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEdit(technology)"
                >
                  {{ t('admin.technologies.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDelete(technology)"
                >
                  {{ t('admin.technologies.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
