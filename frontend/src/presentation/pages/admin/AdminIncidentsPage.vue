<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminIncidents } from '../../../application/admin/incidents/useAdminIncidents'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseDateInput from '../../ui/BaseDateInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import { SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminIncident } from '../../../domain/admin/incidents/entities/AdminIncident'

const { t } = useI18n()
const { incidents, isLoading, hasError, errorMessage, create, update, remove } = useAdminIncidents()

const editingId = ref<number | null>(null)

/**
 * Typé explicitement : sans annotation, `reactive` infère `locale` au type
 * littéral de sa valeur initiale et le sélecteur de langue ne compile plus.
 */
interface IncidentForm {
  locale: Locale
  title: string
  version: string
  occurredAt: string
  impact: string
  rootCause: string
  resolution: string
  invariant: string
  position: number
}

const form = reactive<IncidentForm>({
  locale: SUPPORTED_LOCALES[0],
  title: '',
  version: '',
  occurredAt: '',
  impact: '',
  rootCause: '',
  resolution: '',
  invariant: '',
  position: 0,
})
const isSubmitting = ref(false)

const isEditing = computed(() => null !== editingId.value)

const errorText = computed(() => (errorMessage.value ? t(`admin.incidents.errors.${errorMessage.value.reason}`) : null))

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

function resetForm(): void {
  editingId.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.title = ''
  form.version = ''
  form.occurredAt = ''
  form.impact = ''
  form.rootCause = ''
  form.resolution = ''
  form.invariant = ''
  form.position = 0
}

function startEdit(incident: AdminIncident): void {
  editingId.value = incident.id
  form.locale = incident.locale as Locale
  form.title = incident.title
  form.version = incident.version
  form.occurredAt = incident.occurredAt
  form.impact = incident.impact
  form.rootCause = incident.rootCause
  form.resolution = incident.resolution
  form.invariant = incident.invariant
  form.position = incident.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    title: form.title,
    version: form.version,
    occurredAt: form.occurredAt,
    impact: form.impact,
    rootCause: form.rootCause,
    resolution: form.resolution,
    invariant: form.invariant,
    position: form.position,
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

async function handleDelete(incident: AdminIncident): Promise<void> {
  if (!window.confirm(t('admin.incidents.confirmDelete', { title: incident.title }))) {
    return
  }

  await remove(incident.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.incidents.edit') : t('admin.incidents.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseSelect
          id="admin-incident-locale"
          v-model="form.locale"
          :label="t('admin.incidents.localeLabel')"
          :options="localeOptions"
        />
        <BaseTextInput
          id="admin-incident-title"
          v-model="form.title"
          :label="t('admin.incidents.titleLabel')"
          required
        />
        <BaseTextInput
          id="admin-incident-version"
          v-model="form.version"
          :label="t('admin.incidents.versionLabel')"
          required
        />
        <BaseDateInput
          id="admin-incident-occurred-at"
          v-model="form.occurredAt"
          :label="t('admin.incidents.occurredAtLabel')"
          required
        />
        <BaseTextarea
          id="admin-incident-impact"
          v-model="form.impact"
          :label="t('admin.incidents.impactLabel')"
          :rows="3"
          required
        />
        <BaseTextarea
          id="admin-incident-root-cause"
          v-model="form.rootCause"
          :label="t('admin.incidents.rootCauseLabel')"
          :rows="5"
          required
        />
        <BaseTextarea
          id="admin-incident-resolution"
          v-model="form.resolution"
          :label="t('admin.incidents.resolutionLabel')"
          :rows="3"
          required
        />
        <BaseTextarea
          id="admin-incident-invariant"
          v-model="form.invariant"
          :label="t('admin.incidents.invariantLabel')"
          :rows="4"
          required
          aria-describedby="admin-incident-invariant-help"
        />
        <div
          id="admin-incident-invariant-help"
          class="form-text mb-3"
        >
          {{ t('admin.incidents.invariantHelp') }}
        </div>
        <BaseNumberInput
          id="admin-incident-position"
          v-model="form.position"
          :label="t('admin.incidents.positionLabel')"
          required
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
            {{ t('admin.incidents.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.incidents.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.incidents.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.incidents.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.incidents.loadError') }}
      </p>
      <p
        v-else-if="0 === incidents.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.incidents.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.incidents.localeLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.incidents.occurredAtLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.incidents.versionLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.incidents.titleLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.incidents.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.incidents.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="incident in incidents"
              :key="incident.id"
            >
              <td>{{ incident.locale.toUpperCase() }}</td>
              <td>{{ incident.occurredAt }}</td>
              <td>{{ incident.version }}</td>
              <td>{{ incident.title }}</td>
              <td>{{ incident.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEdit(incident)"
                >
                  {{ t('admin.incidents.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDelete(incident)"
                >
                  {{ t('admin.incidents.deleteAction') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
