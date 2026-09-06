<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAdminContributions } from '../../../application/admin/contributions/useAdminContributions'
import BaseTextInput from '../../ui/BaseTextInput.vue'
import BaseTextarea from '../../ui/BaseTextarea.vue'
import BaseNumberInput from '../../ui/BaseNumberInput.vue'
import BaseSelect from '../../ui/BaseSelect.vue'
import { SUPPORTED_LOCALES, type Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminContribution } from '../../../domain/admin/contributions/entities/AdminContribution'

const { t } = useI18n()
const { contributions, isLoading, hasError, errorMessage, create, update, remove } = useAdminContributions()

const editingId = ref<number | null>(null)

/**
 * Formulaire typé explicitement : sans annotation, `reactive` infère `locale`
 * au type littéral de sa valeur initiale (`'fr'`), et le sélecteur de langue
 * ne compile plus dès qu'on choisit l'anglais.
 */
interface ContributionForm {
  locale: Locale
  title: string
  project: string
  reference: string
  url: string
  summary: string
  body: string
  position: number
}

const form = reactive<ContributionForm>({
  locale: SUPPORTED_LOCALES[0],
  title: '',
  project: '',
  reference: '',
  url: '',
  summary: '',
  body: '',
  position: 0,
})
const isSubmitting = ref(false)

const isEditing = computed(() => null !== editingId.value)

const errorText = computed(() => (errorMessage.value ? t(`admin.contributions.errors.${errorMessage.value.reason}`) : null))

const localeOptions = computed(() => SUPPORTED_LOCALES.map((locale) => ({ value: locale, label: locale.toUpperCase() })))

function resetForm(): void {
  editingId.value = null
  form.locale = SUPPORTED_LOCALES[0]
  form.title = ''
  form.project = ''
  form.reference = ''
  form.url = ''
  form.summary = ''
  form.body = ''
  form.position = 0
}

function startEdit(contribution: AdminContribution): void {
  editingId.value = contribution.id
  form.locale = contribution.locale as Locale
  form.title = contribution.title
  form.project = contribution.project
  form.reference = contribution.reference
  form.url = contribution.url
  form.summary = contribution.summary
  form.body = contribution.body
  form.position = contribution.position
}

async function handleSubmit(): Promise<void> {
  isSubmitting.value = true

  const input = {
    locale: form.locale,
    title: form.title,
    project: form.project,
    reference: form.reference,
    url: form.url,
    summary: form.summary,
    body: form.body,
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

async function handleDelete(contribution: AdminContribution): Promise<void> {
  if (!window.confirm(t('admin.contributions.confirmDelete', { title: contribution.title }))) {
    return
  }

  await remove(contribution.id)
}
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ isEditing ? t('admin.contributions.edit') : t('admin.contributions.create') }}
      </h2>

      <form
        novalidate
        @submit.prevent="handleSubmit"
      >
        <BaseSelect
          id="admin-contribution-locale"
          v-model="form.locale"
          :label="t('admin.contributions.localeLabel')"
          :options="localeOptions"
        />
        <BaseTextInput
          id="admin-contribution-title"
          v-model="form.title"
          :label="t('admin.contributions.titleLabel')"
          required
        />
        <BaseTextInput
          id="admin-contribution-project"
          v-model="form.project"
          :label="t('admin.contributions.projectLabel')"
          required
        />
        <BaseTextInput
          id="admin-contribution-reference"
          v-model="form.reference"
          :label="t('admin.contributions.referenceLabel')"
          required
        />
        <BaseTextInput
          id="admin-contribution-url"
          v-model="form.url"
          :label="t('admin.contributions.urlLabel')"
          required
        />
        <BaseTextarea
          id="admin-contribution-summary"
          v-model="form.summary"
          :label="t('admin.contributions.summaryLabel')"
          :rows="3"
          required
        />
        <BaseTextarea
          id="admin-contribution-body"
          v-model="form.body"
          :label="t('admin.contributions.bodyLabel')"
          :rows="14"
          required
          aria-describedby="admin-contribution-body-help"
        />
        <div
          id="admin-contribution-body-help"
          class="form-text mb-3"
        >
          {{ t('admin.contributions.bodyHelp') }}
        </div>
        <BaseNumberInput
          id="admin-contribution-position"
          v-model="form.position"
          :label="t('admin.contributions.positionLabel')"
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
            {{ t('admin.contributions.save') }}
          </button>
          <button
            v-if="isEditing"
            type="button"
            class="btn btn-outline-light"
            @click="resetForm"
          >
            {{ t('admin.contributions.cancel') }}
          </button>
        </div>
      </form>
    </div>

    <div class="surface-panel p-3 p-sm-4">
      <h2 class="h6 fw-bold text-white mb-3">
        {{ t('admin.contributions.listTitle') }}
      </h2>

      <p
        v-if="isLoading"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.contributions.loading') }}
      </p>
      <p
        v-else-if="hasError"
        class="text-danger mb-0"
        role="alert"
      >
        {{ t('admin.contributions.loadError') }}
      </p>
      <p
        v-else-if="0 === contributions.length"
        class="text-body-secondary mb-0"
      >
        {{ t('admin.contributions.empty') }}
      </p>
      <div
        v-else
        class="table-responsive"
      >
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">
                {{ t('admin.contributions.localeLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.contributions.titleLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.contributions.projectLabel') }}
              </th>
              <th scope="col">
                {{ t('admin.contributions.positionLabel') }}
              </th>
              <th scope="col">
                <span class="visually-hidden">{{ t('admin.contributions.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="contribution in contributions"
              :key="contribution.id"
            >
              <td>{{ contribution.locale.toUpperCase() }}</td>
              <td>{{ contribution.title }}</td>
              <td>{{ contribution.project }} · {{ contribution.reference }}</td>
              <td>{{ contribution.position }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-light me-2"
                  @click="startEdit(contribution)"
                >
                  {{ t('admin.contributions.editAction') }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  @click="handleDelete(contribution)"
                >
                  {{ t('admin.contributions.deleteAction') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
