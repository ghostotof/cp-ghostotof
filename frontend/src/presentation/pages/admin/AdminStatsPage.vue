<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminStats } from '../../../application/admin/stats/useAdminStats'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminStat } from '../../../domain/admin/stats/entities/AdminStat'

const { t } = useI18n()
const { stats, isLoading, hasError, errorMessage, load, create, update, remove } = useAdminStats()

const localeOptions = SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: LOCALE_NATIVE_NAMES[locale] }))
const selectedLocale = ref<Locale>('fr')

watch(selectedLocale, (locale) => load(locale), { immediate: true })

const editingId = ref<number | null>(null)
const form = reactive({ value: '', label: '', iconKey: '', position: 0 })
const isSubmitting = ref(false)

const isEditing = computed(() => null !== editingId.value)
const errorText = computed(() => (errorMessage.value ? t(`admin.stats.errors.${errorMessage.value.reason}`) : null))

function resetForm(): void {
  editingId.value = null
  form.value = ''
  form.label = ''
  form.iconKey = ''
  form.position = 0
}

function startEdit(stat: AdminStat): void {
  editingId.value = stat.id
  form.value = stat.value
  form.label = stat.label
  form.iconKey = stat.iconKey
  form.position = stat.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = { locale: selectedLocale.value, value: form.value, label: form.label, iconKey: form.iconKey, position: form.position }

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

async function handleDelete(stat: AdminStat): Promise<void> {
  if (!window.confirm(t('admin.stats.confirmDelete', { label: stat.label }))) {
    return
  }

  await remove(stat.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <BaseSelect
        id="admin-stats-locale"
        v-model="selectedLocale"
        :label="t('admin.localeLabel')"
        :options="localeOptions"
      />
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.stats.edit') : t('admin.stats.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseTextInput
          id="admin-stat-value"
          v-model="form.value"
          :label="t('admin.stats.valueLabel')"
          required
        />
        <BaseTextInput
          id="admin-stat-label"
          v-model="form.label"
          :label="t('admin.stats.labelLabel')"
          required
        />
        <BaseTextInput
          id="admin-stat-icon-key"
          v-model="form.iconKey"
          :label="t('admin.stats.iconKeyLabel')"
          required
        />
        <BaseNumberInput
          id="admin-stat-position"
          v-model="form.position"
          :label="t('admin.stats.positionLabel')"
          :step="1"
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
            {{ t('admin.stats.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.stats.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.stats.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.stats.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.stats.loadError') }}
      </p>
      <p
        v-else-if="0 === stats.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.stats.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.stats.valueLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.stats.labelLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.stats.iconKeyLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.stats.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.stats.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="stat in stats"
              :key="stat.id"
            >
              <td>{{ stat.value }}</td>
              <td>{{ stat.label }}</td>
              <td>{{ stat.iconKey }}</td>
              <td>{{ stat.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEdit(stat)"
                >
                  {{ t('admin.stats.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDelete(stat)"
                >
                  {{ t('admin.stats.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
